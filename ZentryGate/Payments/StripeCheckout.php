<?php

/**
 * ZentryGate\Payments\StripeCheckout
 * Clase mínima para crear una Stripe Checkout Session (modo pago único).
 *
 * Requisitos:
 * - SDK Stripe sin Composer en: /ZentryGate/stripe/stripe-php (contiene init.php).
 *
 * Uso típico:
 *   $button = new \ZentryGate\Payments\StripeCheckoutButton($secretKey);
 *   $result = $button->payNow(
 *       amountCents: 10000,                 // 100,00 EUR
 *       currency: 'EUR',
 *       concepto: 'Evento X - Cena Oficial',
 *       customerEmail: 'user@example.com',
 *       metadata: ['reservationId' => '123', 'eventId' => '45', 'sectionId' => 'abc', 'userId' => '7'],
 *       successUrl: 'https://tu-sitio.com/evento?zg_action=payment_success&eventId=45&sectionId=abc&resId=123',
 *       cancelUrl:  'https://tu-sitio.com/evento?zg_action=payment_cancel&eventId=45&sectionId=abc&resId=123'
 *   );
 *   if ($result['ok']) {
 *       // Redirige al usuario a Stripe
 *       wp_safe_redirect($result['url']);
 *       exit;
 *   } else {
 *       error_log('[Stripe] ' . $result['error']); // opcional
 *       // Muestra mensaje al usuario
 *   }
 */
namespace ZentryGate\Payments;

if (! class_exists ('\ZentryGate\Payments\StripeCheckout'))
{

	class StripeCheckout
	{
		/** @var string */
		private $secretKey;

		/** @var \Stripe\StripeClient|null */
		private $client = null;

		/** @var bool */
		private $ready = false;


		/**
		 *
		 * @param string $secretKey
		 *        	Clave secreta de Stripe (live o test).
		 */
		public function __construct ()
		{
			$settings = get_option ('zentrygate_stripe_settings', [ ]);
			$this->secretKey = $settings ['secret'] ?? '';

			// Carga el SDK de Stripe sin Composer.
			// Ajusta la ruta si tu estructura difiere.
			$sdkPath = ZENTRYGATE_DIR . '/vendor/stripe-php/init.php';

			if (file_exists ($sdkPath))
			{
				require_once $sdkPath;
				try
				{
					$this->client = new \Stripe\StripeClient ($this->secretKey);
					$this->ready = true;
				}
				catch (\Throwable $e)
				{
					$this->ready = false;
					if (defined ('WP_DEBUG') && WP_DEBUG)
					{
						error_log ('[Stripe] Error iniciando StripeClient: ' . $e->getMessage ());
					}
				}
			}
			else
			{
				if (defined ('WP_DEBUG') && WP_DEBUG)
				{
					error_log ('[Stripe] No se encontró init.php en: ' . $sdkPath);
				}
			}
		}


		/**
		 * Crea una Checkout Session (mode=payment) y devuelve la URL para redirigir al usuario.
		 *
		 * @param int $amountCents
		 *        	Importe en céntimos (>=1).
		 * @param string $currency
		 *        	Moneda (p.ej. 'EUR').
		 * @param string $concepto
		 *        	Texto del producto (p.ej. "Evento - Sección").
		 * @param string $customerEmail
		 *        	Email del pagador (opcional pero recomendado).
		 * @param array $metadata
		 *        	Metadatos (reservationId, eventId, sectionId, userId, etc.).
		 * @param string $successUrl
		 *        	URL de éxito (regreso desde Checkout).
		 * @param string $cancelUrl
		 *        	URL de cancelación (regreso si usuario cancela).
		 * @return array { ok: bool, url?: string, sessionId?: string, error?: string }
		 */
		public function payNow (int $amountCents, string $currency, string $concepto, string $customerEmail, array $metadata, string $successUrl, string $cancelUrl): array
		{
			// Validaciones básicas
			if (! $this->ready || ! $this->client)
			{
				return [ 'ok' => false, 'error' => 'Stripe no está inicializado. Revisa la ruta del SDK o la clave secreta.'];
			}
			if ($amountCents < 1)
			{
				return [ 'ok' => false, 'error' => 'Importe inválido (amountCents debe ser >= 1).'];
			}
			$currency = strtoupper (trim ($currency));
			if ($currency === '')
			{
				return [ 'ok' => false, 'error' => 'Moneda inválida.'];
			}
			$concepto = trim ($concepto);
			if ($concepto === '')
			{
				return [ 'ok' => false, 'error' => 'Concepto vacío.'];
			}
			$successUrl = trim ($successUrl);
			$cancelUrl = trim ($cancelUrl);
			if ($successUrl === '' || $cancelUrl === '')
			{
				return [ 'ok' => false, 'error' => 'Debes indicar successUrl y cancelUrl.'];
			}

			// Construye parámetros de la Checkout Session
			$expiresAt = time () + 30 * 60; // 30 minutos
			$meta = is_array ($metadata) ? $metadata : [ ];

			// Stripe recomienda strings en metadata
			$metaStr = [ ];
			foreach ($meta as $k => $v)
			{
				if (is_scalar ($v))
				{
					$metaStr [$k] = (string) $v;
				}
				else
				{
					// Aplana/serializa con límite por seguridad
					$metaStr [$k] = substr (json_encode ($v, JSON_UNESCAPED_UNICODE), 0, 500);
				}
			}

			// Trazabilidad opcional: client_reference_id con reservationId si existe
			$clientReferenceId = isset ($metaStr ['reservationId']) ? $metaStr ['reservationId'] : null;

			// Idempotencia: usa reservationId si existe; si no, hash de parámetros
			$idempotencyKey = $this->buildIdempotencyKey ($amountCents, $currency, $concepto, $successUrl, $cancelUrl, $metaStr);

			$params = [ 'mode' => 'payment', 'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'expires_at' => $expiresAt, 'line_items' => [ [ 'quantity' => 1, 'price_data' => [ 'currency' => $currency, 'unit_amount' => $amountCents, 'product_data' => [ 'name' => $concepto]]]],
					'metadata' => $metaStr];

			if (! empty ($customerEmail))
			{
				$params ['customer_email'] = $customerEmail;
			}
			if ($clientReferenceId)
			{
				$params ['client_reference_id'] = $clientReferenceId;
			}

			// var_dump ($params); die ();

			try
			{
				$session = $this->client->checkout->sessions->create ($params, [ 'idempotency_key' => $idempotencyKey]);

				add_filter ('allowed_redirect_hosts', function ($hosts)
				{
					$hosts [] = 'checkout.stripe.com';
					$hosts [] = 'stripe.com';
					return $hosts;
				});

				wp_safe_redirect ($session->url);
				exit ();
			}
			catch (\Throwable $e)
			{

				return [ 'ok' => false, 'error' => 'No se pudo iniciar el pago en Stripe. Inténtalo de nuevo en unos minutos.', 'details' => $e->getMessage ()];
			}
		}


		/**
		 * Genera una idempotency key estable para evitar sesiones duplicadas.
		 * Si hay reservationId en metadata, la usa como base.
		 */
		private function buildIdempotencyKey (int $amountCents, string $currency, string $concepto, string $successUrl, string $cancelUrl, array $metadata): string
		{
			$base = $metadata ['reservationId'] ?? null;
			$payload = json_encode ([ 'amount' => $amountCents, 'currency' => strtoupper ($currency), 'name' => $concepto, 'success' => $successUrl, 'cancel' => $cancelUrl, 'meta' => $metadata], JSON_UNESCAPED_UNICODE);

			$hash = substr (hash ('sha256', $payload), 0, 24);
			if ($base)
			{
				// Mantén estable para la misma reserva
				return 'chk_' . preg_replace ('/[^a-zA-Z0-9_\-]/', '_', (string) $base) . '_' . $hash;
			}
			return 'chk_' . $hash;
		}


		public static function handleStripeRedirects (): void
		{
			if (isset ($_GET ['zg-stripe-action']) && $_GET ['zg-stripe-action'] === 'call-stripe' && \ZentryGate\Auth::isLoggedIn ())
			{
				$handler = new \ZentryGate\UserPage (\ZentryGate\Auth::getSessionData ());
				$handler->handlerStripePayment ();
			}
		}
	}
}
