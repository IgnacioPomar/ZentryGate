<?php

/**
 * ZentryGate\Payments\StripeCheckout
 * Clase mínima para crear una Stripe Checkout Session (modo pago único).
 *
 * Requisitos:
 * - SDK Stripe instalado con Composer: ejecutar "composer install" en la raíz del plugin.
 *
 * Uso típico:
 *   $button = new \ZentryGate\Payments\StripeCheckout();
 *   $result = $button->createSession(
 *       amountCents: 10000,                 // 100,00 EUR
 *       currency: 'EUR',
 *       concepto: 'Evento X - Cena Oficial',
 *       customerEmail: 'user@example.com',
 *       metadata: ['reservationId' => '123', 'eventId' => '45', 'sectionId' => 'abc', 'userId' => '7'],
 *       successUrl: 'https://tu-sitio.com/evento?zg_action=payment_success&eventId=45&sectionId=abc&resId=123',
 *       cancelUrl:  'https://tu-sitio.com/evento?zg_action=payment_cancel&eventId=45&sectionId=abc&resId=123'
 *   );
 *   if ($result['ok']) {
 *       // Guarda $result['paymentIntentId'] en tus reservas ANTES de redirigir: los webhooks
 *       // de caducidad/cancelación/fallo localizan la reserva por ese identificador.
 *       \ZentryGate\Payments\StripeCheckout::redirectToCheckout($result['url']);
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

			// El SDK lo carga el autoloader de Composer desde zentrygate.php.
			if (ZENTRYGATE_STRIPE_READY)
			{
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
					error_log ('[Stripe] SDK no disponible: ejecuta "composer install" en ' . ZENTRYGATE_DIR);
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
		 * @return array { ok: bool, url?: string, sessionId?: string, paymentIntentId?: string, error?: string }
		 *
		 *         No redirige: devuelve la sesión creada para que el llamador pueda enlazarla con sus
		 *         reservas (guardar el paymentIntentId) ANTES de mandar al usuario a Stripe.
		 */
		public function createSession (int $amountCents, string $currency, string $concepto, string $customerEmail, array $metadata, string $successUrl, string $cancelUrl): array
		{
			// Validaciones básicas
			if (! $this->ready || ! $this->client)
			{
				return [ 'ok' => false, 'error' => 'Stripe no está inicializado. Revisa la instalación del SDK (composer install) o la clave secreta.'];
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
			$sessionTtl = 30 * 60; // 30 minutos
			$expiresAt = time () + $sessionTtl;
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
			$idempotencyKey = $this->buildIdempotencyKey ($amountCents, $currency, $successUrl, $cancelUrl, $metaStr, $sessionTtl);

			// La metadata se replica en el PaymentIntent: Stripe NO la copia de la sesión al PI, y los
			// eventos payment_intent.* solo traen la del propio PI. Sin esto, esos handlers no tienen
			// forma de saber a qué reservas se refieren si el enlace por paymentIntentId fallara.
			$params = [ 'mode' => 'payment', 'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'expires_at' => $expiresAt, 'line_items' => [ [ 'quantity' => 1, 'price_data' => [ 'currency' => $currency, 'unit_amount' => $amountCents, 'product_data' => [ 'name' => $concepto]]]],
					'metadata' => $metaStr, 'payment_intent_data' => [ 'metadata' => $metaStr]];

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

				// En mode=payment Stripe crea el PaymentIntent junto con la sesión, así que payment_intent
				// viene ya relleno. Se devuelve para que el llamador lo guarde en sus reservas.
				return [ 'ok' => true, 'url' => (string) $session->url, 'sessionId' => (string) $session->id, 'paymentIntentId' => (string) ($session->payment_intent ?? '')];
			}
			catch (\Throwable $e)
			{

				return [ 'ok' => false, 'error' => 'No se pudo iniciar el pago en Stripe. Inténtalo de nuevo en unos minutos.', 'details' => $e->getMessage ()];
			}
		}


		/**
		 * Redirige el navegador a la Checkout Session y termina la ejecución.
		 */
		public static function redirectToCheckout (string $url): void
		{
			add_filter ('allowed_redirect_hosts', function ($hosts)
			{
				$hosts [] = 'checkout.stripe.com';
				$hosts [] = 'stripe.com';
				return $hosts;
			});

			wp_safe_redirect ($url);
			exit ();
		}


		/**
		 * Genera una idempotency key estable para evitar sesiones duplicadas.
		 * Si hay reservationId en metadata, la usa como base.
		 */
		private function buildIdempotencyKey (int $amountCents, string $currency, string $successUrl, string $cancelUrl, array $metadata, int $sessionTtl = 1800): string
		{
			// Constante base (ej. la reserva o el usuario+evento)
			$base = $metadata ['reservationId'] ?? (($metadata ['userId'] ?? '') . '_' . ($metadata ['eventId'] ?? '') . '_' . ($metadata ['sectionId'] ?? ''));

			// Ventana temporal del tamaño de la vida de la sesión: dentro de la misma ventana, repetir
			// la llamada devuelve la sesión ya creada (protege del doble clic y de un reintento sobre una
			// sesión que sigue viva, donde Stripe permite reintentar el pago). Pasada la ventana, la sesión
			// anterior ya ha caducado y hace falta una clave nueva para poder crear otra: sin esto, la clave
			// de idempotencia (que vive 24 h en Stripe) devolvería una sesión muerta y el pago sería
			// imposible de reintentar.
			$window = intdiv (time (), max (60, $sessionTtl));

			// Payload reducido solo a lo que define "este intento"
			$payload = wp_json_encode ([ 'amount' => $amountCents, 'currency' => $currency, 'reservationId' => $metadata ['reservationId'] ?? null, 'items' => $metadata ['items'] ?? null, 'window' => $window], JSON_UNESCAPED_UNICODE);

			// Hash corto reproducible
			$hash = substr (hash ('sha256', $payload), 0, 12);

			// Ensamblar idempotency key
			return 'chk_' . preg_replace ('/[^a-zA-Z0-9_\-]/', '_', (string) $base) . '_' . $hash;
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
