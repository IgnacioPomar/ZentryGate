<?php

namespace ZentryGate\Payments;

class StripeWebhook
{


	public static function register (): void
	{
		add_action ('rest_api_init', function ()
		{
			register_rest_route ('zentrygate/v1', '/stripe/webhook', [ 'methods' => 'POST', 'callback' => [ self::class, 'handle'], 'permission_callback' => '__return_true']);
		});
	}

	/** @var \Stripe\StripeClient */
	private static $client;


	private static function bootStripe (): void
	{
		static $booted = false;
		if ($booted) return;

		$settings = get_option ('zentrygate_stripe_settings', [ ]);
		$secretKey = $settings ['secret'] ?? '';
		$sdkPath = ZENTRYGATE_DIR . '/vendor/stripe-php/init.php';

		if (! file_exists ($sdkPath))
		{
			if (defined ('WP_DEBUG') && WP_DEBUG) error_log ('[Stripe] init.php no encontrado en ' . $sdkPath);
			throw new \RuntimeException ('Stripe SDK no disponible');
		}

		require_once $sdkPath;
		self::$client = new \Stripe\StripeClient ($secretKey);
		$booted = true;
	}


	public static function handle (\WP_REST_Request $request)
	{
		try
		{
			self::bootStripe ();

			$payload = $request->get_body (); // raw JSON
			$sig = $request->get_header ('stripe-signature') ?? ''; // $sig = $_SERVER ['HTTP_STRIPE_SIGNATURE'] ?? '';
			$settings = get_option ('zentrygate_stripe_settings', [ ]);
			$whSecret = $settings ['webhook_secret'] ?? '';

			if (! $whSecret)
			{
				// Acepta 200 para que Stripe no reintente indefinidamente, pero loguea.
				if (defined ('WP_DEBUG') && WP_DEBUG) error_log ('[Stripe] Falta webhook_secret en ajustes.');
				return new \WP_REST_Response ([ 'ok' => true], 200);
			}

			// Verifica firma
			$event = \Stripe\Webhook::constructEvent ($payload, $sig, $whSecret);

			// Registrar o tocar el evento en DB (idempotencia persistente)
			$eventId = (string) $event->id;
			$type = (string) $event->type;
			$stripeCreated = isset ($event->created) ? (int) $event->created : null;
			$eventArr = is_array ($event) ? $event : $event->toArray (); // objeto Stripe => array

			// Idempotencia a nivel “evento”: si ya procesaste este event_id, devuelve 200.
			StripeEventsRepo::registerOrTouch ($eventId, $type, $eventArr, $stripeCreated);
			if (StripeEventsRepo::isProcessed ($eventId))
			{
				return new \WP_REST_Response ([ 'ok' => true, 'dup' => true], 200);
			}

			// Despacha por tipo
			switch ($event->type)
			{
				case 'checkout.session.completed':
					self::onCheckoutSessionCompleted ($event->data->object);
					break;

				case 'payment_intent.succeeded':
					self::onPaymentIntentSucceeded ($event->data->object);
					break;

				case 'payment_intent.payment_failed':
					self::onPaymentIntentFailed ($event->data->object);
					break;

				case 'charge.refunded':
				case 'charge.refund.updated': // por si actualiza de parcial a total
					self::onChargeRefunded ($event->data->object);
					break;

				case 'checkout.session.async_payment_succeeded':
					// Para métodos async (p.ej. iDEAL), confirma al llegar aquí
					self::onCheckoutSessionCompleted ($event->data->object);
					break;

				case 'checkout.session.async_payment_failed':
					// Marca fallo de pago si procede
					self::onAsyncFailed ($event->data->object);
					break;

				case 'checkout.session.expired':

					self::onCheckoutSessionExpired ($event->data->object);
					break;
				case 'payment_intent.canceled':

					self::onPaymentIntentCanceled ($event->data->object);
					break;

				default:
					// No hacemos nada para otros eventos
					break;
			}

			// Marca como procesado (idempotencia basada en Event ID)
			// self::markProcessed ($event->id);
			StripeEventsRepo::markProcessed ($eventId, 200);

			return new \WP_REST_Response ([ 'ok' => true], 200);
		}
		catch (\UnexpectedValueException $e)
		{
			// JSON inválido
			return new \WP_REST_Response ([ 'ok' => false, 'err' => 'invalid_json'], 400);
		}
		catch (\Stripe\Exception\SignatureVerificationException $e)
		{
			// Firma inválida
			return new \WP_REST_Response ([ 'ok' => false, 'err' => 'invalid_signature'], 400);
		}
		catch (\Throwable $e)
		{
			if (defined ('WP_DEBUG') && WP_DEBUG) error_log ('[Stripe] Webhook error: ' . $e->getMessage ());
			// Devuelve 200 para evitar tormenta de reintentos si es error lógico nuestro
			return new \WP_REST_Response ([ 'ok' => true, 'warn' => 'handled_with_error'], 200);
		}
	}


	/**
	 * Marca evento como procesado usando transients (24h)
	 */
	private static function markProcessed (string $eventId): void
	{
		set_transient ('zg_stripe_evt_' . $eventId, 1, DAY_IN_SECONDS);
	}


	private static function alreadyProcessed (string $eventId): bool
	{
		return (bool) get_transient ('zg_stripe_evt_' . $eventId);
	}


	/**
	 * checkout.session.completed -> dejamos constancia de la sesión y, opcionalmente, creamos/actualizamos reservas a estado intermedio
	 */
	private static function onCheckoutSessionCompleted ($session): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';

		// Metadata: tú enviaste 'userId' y 'items' (JSON) en payNow()
		$meta = (array) ($session->metadata ?? [ ]);
		$userId = isset ($meta ['userId']) ? (int) $meta ['userId'] : 0;
		$items = [ ];
		if (isset ($meta ['items']))
		{
			$decoded = json_decode ((string) $meta ['items'], true);
			if (is_array ($decoded)) $items = $decoded;
		}

		$amountCents = (int) ($session->amount_total ?? 0);
		$currency = strtoupper ((string) ($session->currency ?? ''));
		$piId = (string) ($session->payment_intent ?? '');
		$now = current_time ('mysql');

		// Guarda el payload completo (debe ser JSON válido por tu CHECK)
		$payloadJson = json_encode ($session, JSON_UNESCAPED_UNICODE);

		foreach ($items as $item)
		{
			$eventId = (int) ($item ['eventId'] ?? 0);
			$sectionId = (string) ($item ['sectionId'] ?? '');

			if (! $userId || ! $eventId || $sectionId === '')
			{
				continue;
			}

			// UPSERT: si existe la fila (por unique eventId-sectionId-userId) actualizamos; si no, insertamos
			$exists = (int) $wpdb->get_var ($wpdb->prepare ("SELECT id FROM {$table} WHERE userId=%d AND eventId=%d AND sectionId=%s", $userId, $eventId, $sectionId));

			$data = [ 'userId' => $userId, 'eventId' => $eventId, 'sectionId' => $sectionId, 'status' => 'pending_payment', // Confirmaremos con payment_intent.succeeded
			'paymentStatus' => 'processing', 'amountCents' => $amountCents ?: null, 'currency' => $currency ?: null, 'paymentIntentId' => $piId ?: null, 'updatedAt' => $now, 'stripePayload' => $payloadJson];

			if ($exists)
			{
				$wpdb->update ($table, $data, [ 'id' => $exists]);
			}
			else
			{
				$data ['createdAt'] = $now;
				$wpdb->insert ($table, $data);
			}
		}
	}


	private static function onCheckoutSessionExpired ($session): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';
		$piId = (string) ($session->payment_intent ?? '');
		$now = current_time ('mysql');
		$payloadJson = json_encode ($session, JSON_UNESCAPED_UNICODE);

		// self::log('Marking session expired', ['pi' => $piId]);

		$wpdb->query ($wpdb->prepare ("UPDATE {$table}
            SET paymentStatus='failed', updatedAt=%s, stripePayload=%s
          WHERE paymentIntentId=%s", $now, $payloadJson, $piId));

		// Si gestionas aforo/bloqueos temporales, libéralo aquí.
	}


	private static function onPaymentIntentCanceled ($pi): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';
		$piId = (string) $pi->id;
		$now = current_time ('mysql');
		$payloadJson = json_encode ($pi, JSON_UNESCAPED_UNICODE);

		// self::log('Marking PI canceled', ['pi' => $piId]);

		$wpdb->query ($wpdb->prepare ("UPDATE {$table}
            SET paymentStatus='canceled', updatedAt=%s, stripePayload=%s
          WHERE paymentIntentId=%s", $now, $payloadJson, $piId));
	}


	/**
	 * payment_intent.succeeded -> confirmamos pago y marcamos asistencia financiera
	 */
	private static function onPaymentIntentSucceeded ($pi): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';

		$piId = (string) $pi->id;
		$amount = (int) ($pi->amount_received ?? $pi->amount ?? 0);
		$curr = strtoupper ((string) ($pi->currency ?? ''));
		$now = current_time ('mysql');

		// Extrae cargo y recibo si existen
		$latestChargeId = null;
		$receiptUrl = null;
		if (! empty ($pi->charges) && ! empty ($pi->charges->data))
		{
			$ch = $pi->charges->data [0];
			$latestChargeId = (string) $ch->id;
			$receiptUrl = (string) ($ch->receipt_url ?? '');
		}

		// Busca todas las reservas con ese paymentIntentId y confírmalas
		$rows = $wpdb->get_results ($wpdb->prepare ("SELECT id FROM {$table} WHERE paymentIntentId = %s", $piId));

		$payloadJson = json_encode ($pi, JSON_UNESCAPED_UNICODE);

		if ($rows)
		{
			foreach ($rows as $r)
			{
				$wpdb->update ($table, [ 'status' => 'confirmed', 'paymentStatus' => 'succeeded', 'amountCents' => $amount ?: null, 'currency' => $curr ?: null, 'latestChargeId' => $latestChargeId, 'receiptUrl' => $receiptUrl, 'confirmedAt' => $now, 'updatedAt' => $now,
						'stripePayload' => $payloadJson], [ 'id' => (int) $r->id]);
			}
		}
		else
		{
			// Si no hay filas (p.ej. no procesamos checkout.session.completed), intentamos reconstruir por metadata del PI
			$meta = (array) ($pi->metadata ?? [ ]);
			$userId = isset ($meta ['userId']) ? (int) $meta ['userId'] : 0;
			$items = [ ];
			if (isset ($meta ['items']))
			{
				$decoded = json_decode ((string) $meta ['items'], true);
				if (is_array ($decoded)) $items = $decoded;
			}

			foreach ($items as $item)
			{
				$eventId = (int) ($item ['eventId'] ?? 0);
				$sectionId = (string) ($item ['sectionId'] ?? '');
				if (! $userId || ! $eventId || $sectionId === '') continue;

				$exists = (int) $wpdb->get_var ($wpdb->prepare ("SELECT id FROM {$table} WHERE userId=%d AND eventId=%d AND sectionId=%s", $userId, $eventId, $sectionId));

				$data = [ 'userId' => $userId, 'eventId' => $eventId, 'sectionId' => $sectionId, 'status' => 'confirmed', 'paymentStatus' => 'succeeded', 'amountCents' => $amount ?: null, 'currency' => $curr ?: null, 'paymentIntentId' => $piId, 'latestChargeId' => $latestChargeId,
						'receiptUrl' => $receiptUrl, 'confirmedAt' => $now, 'updatedAt' => $now, 'stripePayload' => $payloadJson];

				if ($exists)
				{
					$wpdb->update ($table, $data, [ 'id' => $exists]);
				}
				else
				{
					$data ['createdAt'] = $now;
					$wpdb->insert ($table, $data);
				}
			}
		}
	}


	/**
	 * payment_intent.payment_failed -> marca fallo
	 */
	private static function onPaymentIntentFailed ($pi): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';
		$piId = (string) $pi->id;
		$now = current_time ('mysql');

		$payloadJson = json_encode ($pi, JSON_UNESCAPED_UNICODE);

		$wpdb->query ($wpdb->prepare ("UPDATE {$table}
         SET paymentStatus='failed',
             status=CASE WHEN status='pending_payment' THEN 'pending_payment' ELSE status END,
             updatedAt=%s,
             stripePayload=%s
       WHERE paymentIntentId=%s", $now, $payloadJson, $piId));
	}


	/**
	 * charge.refunded / charge.refund.updated -> actualiza reembolsos (total o parcial)
	 */
	private static function onChargeRefunded ($charge): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';

		$chargeId = (string) $charge->id;
		$refunded = (int) ($charge->amount_refunded ?? 0);
		$total = (int) ($charge->amount ?? 0);
		$now = current_time ('mysql');

		$status = ($refunded >= $total && $total > 0) ? 'refunded' : 'partially_refunded';
		$payloadJson = json_encode ($charge, JSON_UNESCAPED_UNICODE);

		$wpdb->query ($wpdb->prepare ("UPDATE {$table}
          SET paymentStatus=%s,
              refundedCents=%d,
              latestChargeId=%s,
              updatedAt=%s,
              stripePayload=%s
        WHERE latestChargeId=%s OR paymentIntentId=%s", $status, $refunded, $chargeId, $now, $payloadJson, $chargeId, (string) ($charge->payment_intent ?? '')));
	}


	/**
	 * checkout.session.async_payment_failed -> marca fallo de pagos asíncronos
	 */
	private static function onAsyncFailed ($session): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';
		$piId = (string) ($session->payment_intent ?? '');
		$now = current_time ('mysql');

		$payloadJson = json_encode ($session, JSON_UNESCAPED_UNICODE);

		$wpdb->query ($wpdb->prepare ("UPDATE {$table}
          SET paymentStatus='failed',
              updatedAt=%s,
              stripePayload=%s
        WHERE paymentIntentId=%s", $now, $payloadJson, $piId));
	}
}

