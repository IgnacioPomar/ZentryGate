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

		// El SDK lo carga el autoloader de Composer desde zentrygate.php.
		if (! ZENTRYGATE_STRIPE_READY)
		{
			if (defined ('WP_DEBUG') && WP_DEBUG) error_log ('[Stripe] SDK no disponible: ejecuta "composer install" en ' . ZENTRYGATE_DIR);
			throw new \RuntimeException ('Stripe SDK no disponible');
		}

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
			$whSecret = $settings ['zg_stripe_webhook_secret'] ?? '';

			if (! $whSecret)
			{
				// Acepta 200 para que Stripe no reintente indefinidamente, pero loguea.
				if (defined ('WP_DEBUG') && WP_DEBUG) error_log ('[Stripe] Falta webhook_secret en ajustes.');
				return new \WP_REST_Response ([ 'ok' => true, 'msg' => 'Falta webhook_secret en ajustes.'], 200);
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
			if (isset ($eventId) && $eventId !== '')
			{
				StripeEventsRepo::markFailed ($eventId, 500, $e->getMessage ());
			}
			// Devuelve 500 para que Stripe reintente el envío del evento automáticamente
			return new \WP_REST_Response ([ 'ok' => false, 'err' => 'internal_error'], 500);
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

		// Para métodos de pago asíncronos la sesión puede llegar aquí como "completed"
		// pero todavía no pagada (payment_status='unpaid'). Solo confirmamos si Stripe
		// indica que el pago ya se completó; si no, la confirmación real llegará más
		// tarde por payment_intent.succeeded o checkout.session.async_payment_succeeded
		// (que reinvoca esta misma función, ya con payment_status='paid').
		$isPaid = ((string) ($session->payment_status ?? '')) === 'paid';

		// Metadata: tú enviaste 'userId' y 'items' (JSON) en payNow()
		$meta = [ ];
		// 1) metadata desde la session
		if (isset ($session->metadata))
		{
			// Si es StripeObject moderno:
			if ($session->metadata instanceof \Stripe\StripeObject)
			{
				$meta = $session->metadata->toArray ();
				// Si es un stdClass u objeto:
			}
			elseif (is_object ($session->metadata))
			{
				$meta = json_decode (json_encode ($session->metadata), true) ?: [ ];
				// Si ya es array:
			}
			elseif (is_array ($session->metadata))
			{
				$meta = $session->metadata;
			}
		}

		// 2) Extraer userId e items (string JSON) desde la session
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
			$itemAmountCents = isset ($item ['amountCents']) ? (int) $item ['amountCents'] : null;

			if (! $userId || ! $eventId || $sectionId === '')
			{
				continue;
			}

			// La unicidad de (eventId, sectionId, userId) garantiza como máximo una fila.
			$exists = (int) $wpdb->get_var ($wpdb->prepare ("SELECT id FROM {$table} WHERE userId=%d AND eventId=%d AND sectionId=%s", $userId, $eventId, $sectionId));

			if (! $isPaid)
			{
				// Pago asíncrono aún no confirmado: solo dejamos constancia de la sesión, sin confirmar la reserva.
				if ($exists)
				{
					$wpdb->update ($table, [ 'paymentStatus' => 'processing', 'paymentIntentId' => $piId ?: null, 'updatedAt' => $now, 'stripePayload' => $payloadJson], [ 'id' => $exists], [ '%s', '%s', '%s', '%s'], [ '%d']);
				}
				continue;
			}

			// No pisamos amountCents/currency de filas existentes: ya guardan el importe de su propia
			// sección (fijado en el alta), y $amountCents aquí es el total del carrito, no por sección.
			$setFinal = [ 'status' => 'confirmed', 'paymentStatus' => 'succeeded', 'paymentIntentId' => $piId ?: null, 'updatedAt' => $now, 'stripePayload' => $payloadJson];

			if ($exists)
			{
				$wpdb->update ($table, $setFinal, [ 'id' => $exists], [ '%s', '%s', '%s', '%s', '%s'], [ '%d']);
			}
			else
			{
				// No existía fila (caso borde): usa el importe individual de la metadata del item;
				// si no vino (sesiones antiguas), el total del carrito como último recurso.
				$insert = array_merge ($setFinal, [ 'userId' => $userId, 'eventId' => $eventId, 'sectionId' => $sectionId, 'amountCents' => $itemAmountCents ?? ($amountCents ?: null), 'currency' => $currency ?: null, 'createdAt' => $now]);
				$wpdb->insert ($table, $insert);

				if (defined ('WP_DEBUG') && WP_DEBUG) error_log ('[ZG][Stripe] insertFinal u=' . $userId . ' e=' . $eventId . ' s=' . $sectionId . ' => ' . var_export ($wpdb->insert_id, true) . ' err=' . $wpdb->last_error);
			}
		}
	}


	private static function onCheckoutSessionExpired ($session): void
	{
		$piId = (string) ($session->payment_intent ?? '');
		$now = current_time ('mysql');
		$payloadJson = json_encode ($session, JSON_UNESCAPED_UNICODE);

		self::terminateReservationsByPaymentIntent ($piId, 'expired', 'failed', $payloadJson, $now);
	}


	private static function onPaymentIntentCanceled ($pi): void
	{
		$piId = (string) $pi->id;
		$now = current_time ('mysql');
		$payloadJson = json_encode ($pi, JSON_UNESCAPED_UNICODE);

		self::terminateReservationsByPaymentIntent ($piId, 'cancelled', 'canceled', $payloadJson, $now);
	}


	/**
	 * Cierra definitivamente las reservas ligadas a un PaymentIntent (sesión expirada o PI cancelado)
	 * y libera el aforo de las que seguían consumiendo plaza. Idempotente: si una reserva ya no está
	 * en un estado que consume plaza (p. ej. porque ya se procesó este mismo evento antes), no vuelve
	 * a decrementar usedCapacity ni a tocar su status.
	 */
	private static function terminateReservationsByPaymentIntent (string $piId, string $newStatus, string $newPaymentStatus, string $payloadJson, string $now): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'zgReservations';

		if ($piId === '')
		{
			return;
		}

		$capacityConsumingStatuses = [ 'held', 'pending_payment'];

		$wpdb->query ('START TRANSACTION');

		$rows = $wpdb->get_results ($wpdb->prepare ("SELECT id, eventId, sectionId, status FROM {$table} WHERE paymentIntentId=%s FOR UPDATE", $piId), ARRAY_A);

		foreach ((array) $rows as $row)
		{
			$wasConsumingCapacity = in_array ($row ['status'], $capacityConsumingStatuses, true);

			$set = [ 'paymentStatus' => $newPaymentStatus, 'updatedAt' => $now, 'stripePayload' => $payloadJson];
			if ($wasConsumingCapacity)
			{
				$set ['status'] = $newStatus;
			}

			$wpdb->update ($table, $set, [ 'id' => (int) $row ['id']]);

			if ($wasConsumingCapacity)
			{
				CapacityRepo::release ((int) $row ['eventId'], (string) $row ['sectionId']);
			}
		}

		$wpdb->query ('COMMIT');
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
				// No tocamos amountCents/currency: cada fila ya guarda el importe de su propia sección
				// (fijado en el alta); $amount aquí es el total del PaymentIntent, no por sección.
				$wpdb->update ($table, [ 'status' => 'confirmed', 'paymentStatus' => 'succeeded', 'latestChargeId' => $latestChargeId, 'receiptUrl' => $receiptUrl, 'confirmedAt' => $now, 'updatedAt' => $now,
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

				// Usa el importe individual de la sección si vino en la metadata; si no, el total del PI como último recurso
				$itemAmountCents = isset ($item ['amountCents']) ? (int) $item ['amountCents'] : ($amount ?: null);

				$exists = (int) $wpdb->get_var ($wpdb->prepare ("SELECT id FROM {$table} WHERE userId=%d AND eventId=%d AND sectionId=%s", $userId, $eventId, $sectionId));

				$data = [ 'userId' => $userId, 'eventId' => $eventId, 'sectionId' => $sectionId, 'status' => 'confirmed', 'paymentStatus' => 'succeeded', 'amountCents' => $itemAmountCents, 'currency' => $curr ?: null, 'paymentIntentId' => $piId, 'latestChargeId' => $latestChargeId,
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

