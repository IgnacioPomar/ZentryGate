<?php

namespace ZentryGate;

/**
 * UserPage
 *
 * Muestra el formulario de suscripción a secciones del evento activo.
 * Gestiona las suscripciones y desuscripciones.
 *
 * Requiere que el usuario esté identificado (email en sesión).
 *
 * Flujo:
 * - Selecciona el primer evento futuro (si no hay, mensaje único y fin)
 * - Carga secciones y reglas
 * - Carga disponibilidad desde zgCapacity
 * - Carga suscripciones del usuario
 * - Render:
 * - Si no hay suscripciones, muestra solo secciones estándar
 * - Si hay suscripciones, evalúa reglas y muestra páginas + secciones estándar + ocultas permitidas por reglas
 * - En cada sección, muestra estado (suscrito/no, pendiente/abonado, disponibilidad) y botón para suscribir/desuscribir
 * - Maneja POST de suscribir/desuscribir (solo si hay evento)
 * - Verifica nonce y datos
 * - Suscribir:
 * - Verifica no estar ya suscrito
 * - Verifica sección válida
 * - Verifica disponibilidad precargada
 * - Transacción:
 * - Bloquea fila de capacidad (o la crea si no existe)
 * - Incrementa usedCapacity si cabe
 * - Inserta reserva en zgReservations con estado 'unpaid' o 'confirmed' según precio
 * - Mensaje éxito/error
 * - Desuscribir:
 * - Verifica que exista la reserva
 * - Transacción:
 * - Borra reserva
 * - Decrementa usedCapacity si hay fila
 * - Mensaje éxito/error
 *
 * Cosas pendientes (You Aren't Gonna Need It):
 * - Soportar más estados de reserva (espera, cancelada, expirada)
 * - Al cancelar una reserva pagada, emitir reembolso (parcial o total)
 * - Al cancelar una sección, cancelar en cascada las que dependen de ella por reglas
 */
class UserPage
{
	private array $sessionData;

	// Config
	private bool $paymentsEnabled = TRUE;

	// Evento y datos cargados en constructor
	private ?array $event = null; // ['id','name','date']
	private array $sectionsStandard = [ ]; // [sectionId => sectionData+availability]
	private array $sectionsHidden = [ ]; // [sectionId => sectionData+availability]
	private array $sectionsAll = [ ]; // merge of the above two
	private array $rules = [ ]; // reglas crudas tal cual JSON
	private array $availability = [ ]; // [sectionId => ['code'=>'available|few|none','spots'=>int|null,'text'=>string]]

	// Suscripciones del usuario (por sección)
	private array $userSubscriptions = [ ]; // [sectionId => ['status'=>'confirmed|unpaid','paid'=>bool,'reservationId'=>int]]

	// Flash messages (solo se rellenan en handlePost)
	private static array $messages = [ ];


	public function __construct (array $sessionData)
	{
		$this->sessionData = $sessionData;

		// Seleccionar primer evento futuro
		$this->selectEvent ();

		// Si no hay evento, no hay nada más que precargar
		if (! $this->event)
		{
			return;
		}

		// Cargar secciones estándar/ocultas + reglas
		$this->loadSectionsAndRules ();

		// Cargar disponibilidad desde zgCapacity (y preparar mensajes)
		$this->loadAvailability ();

		// Comprobar suscripciones del usuario
		$this->loadUserSubscriptions ();
	}


	/*
	 * =======================
	 * Main render
	 * =======================
	 */
	public function render ()
	{
		if (isset ($_GET ['zg-stripe-action']))
		{
			$this->renderStripe ();
			return; // Esto es la gestionde Stripe: no tiene sentido mostrar el resto
		}

		$name = ' ' . isset ($this->sessionData ['name']) ? sanitize_text_field ($this->sessionData ['name']) : '';
		echo '<div class="wrap zg-user-page">';
		echo '<h1>' . esc_html__ ('Gestionando la inscripción de ', 'zentrygate') . $name . '</h1> <div class="zg-user-content">';

		// 3.1) Si no hay evento, mensaje único
		if (! $this->event)
		{
			echo '<div class="zg-notice zg-notice-info"><p>No está abierta la inscripción a ningún evento</p></div>';
			return;
		}

		// 3.2.1) Gestionar POST (único punto que añade mensajes)
		$this->handlePost ();

		$this->renderMessages ();

		if (! empty ($this->userSubscriptions)) // El usuario tiene alguna subscripción
		{
			// 3.2.2) Primero páginas por reglas…
			$this->renderPagesByRules ();

			// …después construir lista de secciones a mostrar: estándar + ocultas habilitadas por reglas
			$sectionsToShow = $this->sectionsStandard; // copia
			$allowedHidden = $this->getAllowedHiddenSectionIdsByRules ();
			foreach ($allowedHidden as $sid)
			{
				if (isset ($this->sectionsHidden [$sid]))
				{
					$sectionsToShow [$sid] = $this->sectionsHidden [$sid];
				}
			}
			$this->renderFormSubscriptions ($sectionsToShow);
		}
		else
		{
			// 3.2.3) Usuario no suscrito → solo estándar
			$this->renderFormSubscriptions ($this->sectionsStandard);
		}

		echo '</div></div>';
	}


	public function renderStripe (): void
	{
		switch ($_GET ['zg-stripe-action'])
		{
			case 'success':
				echo '<div class="zg-notice zg-notice-success"><p>Estamos procesando tu pago. Gracias.</p></div>';
				// TODO: comprobar estado real del pago y mostrar detalles
				break;
			case 'cancel':
				echo '<div class="zg-notice zg-notice-error"><p>El pago ha sido cancelado. No se ha realizado ningún cargo.</p></div>';
				break;
			case 'call-stripe':
				// HAn pulsado al botón de pagar... si llega aquí es que la redirección a Stripe ha fallado
				$this->renderMessages ();
				break;
			default:
				echo '<div class="zg-notice zg-notice-info"><p>Acción desconocida.</p></div>';
				break;
		}
	}


	public function handlerStripePayment (): void
	{

		// Requisitos básicos
		if (! $this->event)
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'No hay evento seleccionado para procesar el pago.'];
			return;
		}

		$eventId = (int) $this->event ['id'];
		$userId = (int) ($this->sessionData ['userId'] ?? 0);
		$email = (string) ($this->sessionData ['email'] ?? '');

		if ($userId <= 0)
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'Usuario no identificado.'];
			return;
		}

		// Reunir suscripciones pendientes de pago del evento actual
		// $sectionsToCharge = [ ]; // [{sid, label, price}]
		$conceptParts = [ ]; // [label1, label2, ...]
		$itemsMeta = [ ]; // [{eventId, sectionId}]
		$amountCents = 0;

		foreach ($this->userSubscriptions as $sid => $sub)
		{

			// Solo consideramos secciones del evento actual (las keys de userSubscriptions ya son sectionId del evento cargado)
			// y que estén pendientes de pago
			$needsPayment = ! empty ($sub ['requiresPayment']); // según tu nuevo nombre en el constructor
			if (! $needsPayment)
			{
				continue;
			}

			// Debe existir la sección y tener precio > 0
			if (! isset ($this->sectionsAll [$sid]))
			{
				continue;
			}
			$sec = $this->sectionsAll [$sid];
			$price = (float) ($sec ['price'] ?? 0.0);
			if ($price <= 0)
			{
				continue;
			}

			$label = (string) ($sec ['label'] ?? ('Sección ' . $sid));

			// Sumar al total (no hay descuentos de momento)
			$amountCents += (int) round ($price * 100);

			// Concepto y metadata
			$conceptParts [] = $label;
			$itemsMeta [] = [ 'eventId' => (string) $eventId, 'sectionId' => (string) $sid];
		}

		if ($amountCents <= 0 || empty ($itemsMeta))
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'No tienes importes pendientes de pago en este momento.'];
			return;
		}

		// Concepto: concatenación de etiquetas de secciones
		$concepto = implode (' + ', $conceptParts);

		$metaPayload = [ 'userId' => (string) $userId, 'items' => $itemsMeta];

		// URLs de retorno
		$successUrl = esc_url (add_query_arg ([ 'zg-stripe-action' => 'success'], get_permalink ()));
		$cancelUrl = esc_url (add_query_arg ([ 'zg-stripe-action' => 'cancel'], get_permalink ()));

		// Llamada a tu helper de Stripe
		$btn = new \ZentryGate\Payments\StripeCheckout ();
		$res = $btn->payNow ($amountCents, 'EUR', $concepto, $email, $metaPayload, $successUrl, $cancelUrl);

		// var_dump ($res); die ();

		$errorMsg = $res ['error'] ?? 'No se pudo iniciar el proceso de pago.';
		self::$messages [] = [ 'type' => 'error', 'text' => $errorMsg, 'details' => $res ['details'] ?? ''];
	}


	/*
	 * =======================
	 * Constructor helpers
	 * =======================
	 */
	private function selectEvent (): void
	{
		global $wpdb;
		$row = $wpdb->get_row ("SELECT id, name, `date`
			   FROM {$wpdb->prefix}zgEvents
			  WHERE `date` >= CURDATE()
			  ORDER BY `date` ASC, id ASC
			  LIMIT 1", ARRAY_A);

		if (! $row)
		{
			$this->event = null;
			return;
		}

		$this->event = [ 'id' => (int) $row ['id'], 'name' => (string) $row ['name'], 'date' => (string) $row ['date']];
	}


	private function loadSectionsAndRules (): void
	{
		global $wpdb;
		$evId = (int) $this->event ['id'];

		$row = $wpdb->get_row ($wpdb->prepare ("SELECT sectionsJson, rulesJson
				   FROM {$wpdb->prefix}zgEvents
				  WHERE id = %d", $evId), ARRAY_A);

		$sections = json_decode ($row ['sectionsJson'] ?? '[]', true) ?: [ ];
		$this->rules = json_decode ($row ['rulesJson'] ?? '[]', true) ?: [ ];

		$this->sectionsStandard = [ ];
		$this->sectionsHidden = [ ];

		foreach ($sections as $s)
		{
			$sid = (string) ($s ['id'] ?? '');
			if ($sid === '')
			{
				continue;
			}
			$data = [ 'id' => $sid, 'label' => (string) ($s ['label'] ?? ('Sección ' . $sid)), 'capacity' => (int) ($s ['capacity'] ?? 0), 'price' => (float) ($s ['price'] ?? 0), 'isHidden' => ! empty ($s ['isHidden'])];
			if ($data ['isHidden'])
			{
				$this->sectionsHidden [$sid] = $data;
			}
			else
			{
				$this->sectionsStandard [$sid] = $data;
			}
			$this->sectionsAll [$sid] = $data;
		}
	}


	private function loadAvailability (): void
	{
		global $wpdb;
		$this->availability = [ ];

		$evId = (int) $this->event ['id'];

		if (empty ($this->sectionsAll))
		{
			return;
		}

		$ids = array_map ('strval', array_keys ($this->sectionsAll));
		$in = implode ("','", array_map ('esc_sql', $ids));

		$capRows = $wpdb->get_results ($wpdb->prepare ("SELECT sectionId, maxCapacity, usedCapacity
				   FROM {$wpdb->prefix}zgCapacity
				  WHERE eventId = %d
				    AND sectionId IN ('$in')", $evId), ARRAY_A) ?? [ ];

		$capMap = [ ];
		foreach ($capRows as $r)
		{
			$capMap [(string) $r ['sectionId']] = [ 'max' => (int) $r ['maxCapacity'], 'used' => (int) $r ['usedCapacity']];
		}

		foreach ($this->sectionsAll as $sid => $s)
		{
			$max = isset ($capMap [$sid]) ? $capMap [$sid] ['max'] : (int) $s ['capacity'];
			$used = isset ($capMap [$sid]) ? $capMap [$sid] ['used'] : 0;

			// max=0 => ilimitado
			if ($max === 0)
			{
				$this->availability [$sid] = [ 'code' => 'available', 'spots' => null, 'text' => 'Plazas ilimitadas'];
				continue;
			}

			$spots = max (0, $max - $used);
			if ($spots === 0)
			{
				$code = 'none';
				$text = 'Sin plazas';
			}
			elseif ($spots <= 5)
			{
				$code = 'few';
				$text = 'Pocas plazas';
			}
			else
			{
				$code = 'available';
				$text = 'Disponibles';
			}

			$this->availability [$sid] = [ 'code' => $code, 'spots' => $spots, 'text' => $text];
		}
	}


	/**
	 * Carga en $this->userSubscriptions las reservas del usuario para el evento actual.
	 * - Requiere $this->event['id'] y $this->sessionData['userId'].
	 * - Indexa por sectionId.
	 * - Marca isActive si status NO es cancelled/expired.
	 * - Incluye campos relevantes para UI (payment, fechas, etc.).
	 */
	private function loadUserSubscriptions (): void
	{
		global $wpdb;

		$this->userSubscriptions = [ ];

		$eventId = isset ($this->event ['id']) ? (int) $this->event ['id'] : 0;
		$userId = (int) ($this->sessionData ['userId'] ?? 0);

		if ($eventId <= 0 || $userId <= 0)
		{
			return;
		}

		$tableReservations = $wpdb->prefix . 'zgReservations';

		// Trae todas las reservas del usuario en el evento.
		// La UNIQUE (eventId, sectionId, userId) garantiza como mucho 1 fila activa por sección,
		// pero por seguridad ordenamos por updatedAt/createdAt para quedarnos con la más reciente si hubiera históricos.
		$rows = $wpdb->get_results ($wpdb->prepare ("SELECT id, userId, eventId, sectionId, status, paymentStatus,
                    amountCents, currency, paymentIntentId, latestChargeId,
                    refundedCents, receiptUrl, stripePayload,
                    waitlistPosition, expiresAt, confirmedAt, cancelledAt, checkedInAt,
                    attendanceStatus, createdAt, updatedAt
               FROM {$tableReservations}
              WHERE userId = %d AND eventId = %d
              ORDER BY updatedAt DESC, createdAt DESC", $userId, $eventId), ARRAY_A);

		if (empty ($rows))
		{
			return;
		}

		// Mapa de secciones conocidas por id (para adjuntar meta útil como label/price)
		$sections = [ ];
		foreach ($this->sectionsAll as $s)
		{
			$sid = (string) ($s ['id'] ?? '');
			if ($sid !== '')
			{
				$sections [$sid] = $s;
			}
		}

		$bySection = [ ];

		foreach ($rows as $r)
		{
			$sid = (string) $r ['sectionId'];
			// Si ya tenemos esa sección, mantenemos la primera (la más reciente por el ORDER BY).
			if (isset ($bySection [$sid]))
			{
				continue;
			}

			$status = (string) $r ['status'];
			$isActive = ! in_array ($status, [ 'cancelled', 'expired'], true);

			$sectionMeta = $sections [$sid] ?? null;
			$label = $sectionMeta ['label'] ?? null;
			$price = isset ($sectionMeta ['price']) ? (float) $sectionMeta ['price'] : null;
			$hidden = isset ($sectionMeta ['isHidden']) ? (bool) $sectionMeta ['isHidden'] : null;

			// Derivados útiles para la UI
			$requiresPayment = ($status === 'pending_payment' && ($r ['paymentStatus'] ?? 'none') === 'none');
			$canUnsubscribe = $isActive && in_array ($status, [ 'held', 'pending_payment', 'confirmed', 'waiting_list'], true);

			$bySection [$sid] = [ 'reservationId' => (int) $r ['id'], 'sectionId' => $sid, 'eventId' => (int) $r ['eventId'], 'status' => $status, 'paymentStatus' => (string) $r ['paymentStatus'], 'amountCents' => isset ($r ['amountCents']) ? (int) $r ['amountCents'] : null,
					'currency' => $r ['currency'] ?? null, 'paymentIntentId' => $r ['paymentIntentId'] ?? null, 'latestChargeId' => $r ['latestChargeId'] ?? null, 'refundedCents' => isset ($r ['refundedCents']) ? (int) $r ['refundedCents'] : null, 'receiptUrl' => $r ['receiptUrl'] ?? null,
					'stripePayload' => $r ['stripePayload'] ?? null, // JSON (string)
					'waitlistPosition' => isset ($r ['waitlistPosition']) ? (int) $r ['waitlistPosition'] : null, 'expiresAt' => $r ['expiresAt'] ?? null, 'confirmedAt' => $r ['confirmedAt'] ?? null, 'cancelledAt' => $r ['cancelledAt'] ?? null, 'checkedInAt' => $r ['checkedInAt'] ?? null,
					'attendanceStatus' => $r ['attendanceStatus'] ?? 'none', 'createdAt' => $r ['createdAt'] ?? null, 'updatedAt' => $r ['updatedAt'] ?? null, 

					// Ayudas para la vista
					'isActive' => $isActive, 'requiresPayment' => $requiresPayment, 'canUnsubscribe' => $canUnsubscribe, 

					// Meta de la sección (si existe en el evento)
					'sectionLabel' => $label, 'sectionPrice' => $price, 'sectionIsHidden' => $hidden];
		}

		$this->userSubscriptions = $bySection;
	}


	/*
	 * =======================
	 * Rendering helpers
	 * =======================
	 */
	private function renderMessages (): void
	{
		foreach (self::$messages as $m)
		{
			$cls = $m ['type'] === 'error' ? 'zg-notice-error' : 'zg-notice-success';
			echo '<div class="zg-notice ' . esc_attr ($cls) . '">' . esc_html ($m ['text']);
			if (isset ($m ['details']) && $m ['details'] !== '')
			{
				echo '<br/><small>' . esc_html ($m ['details']) . '</small>';
			}

			echo '</div>';
		}
	}


	private function renderFormSubscriptions (array $sections): void
	{
		if (empty ($sections))
		{
			echo '<p>No hay secciones disponibles en este momento.</p>';
			return;
		}

		$totalDueCents = 0; // acumularemos aquí el coste pendiente

		echo '<ul style="list-style:none;padding:0;margin:0">';

		foreach ($sections as $sid => $section)
		{
			if (! isset ($this->availability [$sid]))
			{
				continue; // Saltar secciones en las que desconocemos las plazas
			}

			$label = (string) $section ['label'];
			$price = (float) $section ['price'];

			$isSubscribed = isset ($this->userSubscriptions [$sid]);
			$sub = $isSubscribed ? $this->userSubscriptions [$sid] : null;

			$avail = $this->availability [$sid];
			$availText = $avail ['text'];

			echo '<li style="margin:0 0 1rem 0;padding:1rem;border:1px solid #eee;border-radius:8px">';
			echo '<div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">';
			echo '<div>';
			echo '<strong>' . esc_html ($label) . '</strong>';
			echo ($price > 0 ? ' — ' . esc_html (number_format ($price, 2)) . ' €' : '');
			echo '</div>';

			echo '<div>';

			$allowButton = TRUE;
			$buttonText = 'Suscribirme';
			$buttonValue = 'subscribe_section';

			if ($isSubscribed)
			{
				// YAGNI: soportar e infomar del resto de estados

				$needsPayment = $sub ['requiresPayment'];

				$estadoTxt = $needsPayment ? ' (pendiente de pago)' : ($this->sectionsAll [$sid] ['price'] > 0 ? ' (abonado)' : '(Suscrito)');

				echo '<span style="margin-right:1rem"> ' . $estadoTxt . '</span>';

				// Enlace de pago si pendiente y habilitado
				if ($needsPayment)
				{
					$totalDueCents += (int) round (100 * $price);
				}

				$buttonText = 'Desuscribirme';
				$buttonValue = 'unsubscribe_section';
			}
			else
			{
				// Mensaje de disponibilidad
				echo '<span style="margin-right:1rem">' . esc_html ($availText) . '</span>';

				// Botón suscribirse (solo si no está sin plazas)
				$allowButton = ($avail ['code'] !== 'none');
			}

			// Formulario de
			echo '<form method="post" style="display:inline">';

			wp_nonce_field ("zg_subscribe_{$this->event ['id']}_" . (string) $section ['id'], '_zg_nonce');
			echo '<button type="submit" class="button button-primary" name="zg_direct_action" value="' . $buttonValue . '"' . ($allowButton ? '' : ' disabled') . '>' . $buttonText . '</button>';
			echo '<input type="hidden" name="eventId" value="' . esc_attr ($this->event ['id']) . '"/>';
			echo '<input type="hidden" name="sectionId" value="' . esc_attr ($sid) . '"/>';
			echo '</form>';

			echo '</div></div>';
			echo '</li>';
		}

		echo '</ul>';

		// Total pendiente de pago
		if ($this->paymentsEnabled && $totalDueCents > 0)
		{
			$totalDueEuros = number_format ($totalDueCents / 100, 2);
			echo '<div style="margin-top:1rem;padding:1rem;border:1px solid #ccc;background:#f9f9f9;border-radius:8px">';
			echo '<strong>Total pendiente de pago: ' . esc_html ($totalDueEuros) . ' €</strong>';

			echo '<a class="button" href="' . esc_url (add_query_arg ([ 'zg-stripe-action' => 'call-stripe'], get_permalink ())) . '" style="margin-right:.5rem">Abonar</a>';
			echo '</div>';
		}
	}


	/**
	 * Evalúa reglas y muestra el contenido de las páginas a mostrar
	 * en <div class="ruleMessage">...</div>
	 */
	private function renderPagesByRules (): void
	{
		$ctx = $this->evaluateRules (); // ['showPageIds'=>[], 'allowSectionIds'=>[]]
		$pageIds = $ctx ['showPageIds'];

		if (empty ($pageIds))
		{
			return;
		}

		foreach ($pageIds as $pid)
		{
			$pid = absint ($pid);
			if (! $pid)
			{
				continue;
			}
			$post = get_post ($pid);
			if ($post && $post->post_status === 'publish')
			{
				echo '<div class="ruleMessage">';
				echo apply_filters ('the_content', $post->post_content);
				echo '</div>';
			}
		}
	}


	private function getAllowedHiddenSectionIdsByRules (): array
	{
		$ctx = $this->evaluateRules ();
		return $ctx ['allowSectionIds'];
	}


	/**
	 * Regla: se disparan acciones si el usuario está suscrito (confirmed o unpaid)
	 * a TODAS las secciones listadas en 'triggers'.
	 * Devuelve arrays únicos de showPageIds y allowSectionIds.
	 */
	private function evaluateRules (): array
	{
		$showPageIds = [ ];
		$allowSectionIds = [ ];

		if (empty ($this->rules))
		{
			return [ 'showPageIds' => [ ], 'allowSectionIds' => [ ]];
		}

		$userHas = array_keys ($this->userSubscriptions);
		$userHasSet = array_fill_keys (array_map ('strval', $userHas), true);

		foreach ($this->rules as $rule)
		{
			$triggers = array_map ('strval', (array) ($rule ['triggers'] ?? [ ]));
			if (empty ($triggers))
			{
				continue;
			}

			$allMet = true;
			foreach ($triggers as $t)
			{
				if (empty ($userHasSet [$t]))
				{
					$allMet = false;
					break;
				}
			}
			if (! $allMet)
			{
				continue;
			}

			$actions = (array) ($rule ['actions'] ?? [ ]);
			foreach ($actions as $a)
			{
				if (isset ($a ['showPage']))
				{
					$showPageIds [] = (int) $a ['showPage'];
				}
				if (isset ($a ['allowSectionSubscription']))
				{
					$allowSectionIds [] = (string) $a ['allowSectionSubscription'];
				}
			}
		}

		$showPageIds = array_values (array_unique (array_filter ($showPageIds)));
		$allowSectionIds = array_values (array_unique (array_filter ($allowSectionIds)));

		return [ 'showPageIds' => $showPageIds, 'allowSectionIds' => $allowSectionIds];
	}


	/*
	 * =======================
	 * POST handling (mensajes solo aquí)
	 * =======================
	 */
	private function handlePost (): void
	{
		if (! $this->event)
		{
			return;
		}

		// Solo aceptar POST reales
		if (($_SERVER ['REQUEST_METHOD'] ?? '') !== 'POST')
		{
			return;
		}

		// Usuario debe estar habilitado
		if (isset ($this->sessionData ['isEnabled']) && ! $this->sessionData ['isEnabled'])
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'Tu usuario no está habilitado para realizar esta acción.'];
			return;
		}

		// Normalizar inputs (evita slashes mágicos)
		$action = (string) ($_POST ['zg_direct_action'] ?? '');
		$eventId = (int) ($_POST ['eventId'] ?? 0);
		$sectionId = (string) ($_POST ['sectionId'] ?? '');
		$nonce = (string) ($_POST ['_zg_nonce'] ?? '');

		// Limpiar/unslash
		$action = sanitize_key (wp_unslash ($action));
		$sectionId = sanitize_text_field (wp_unslash ($sectionId));
		$nonce = wp_unslash ($nonce);

		if ($action !== 'subscribe_section' && $action !== 'unsubscribe_section')
		{
			return; // ignorar otras acciones
		}

		// Validaciones básicas
		if ($eventId !== (int) $this->event ['id'] || $sectionId === '')
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'Solicitud no válida.'];
			return;
		}

		// Nonce específico por acción
		$expectedAction = "zg_subscribe_{$eventId}_{$sectionId}";
		if (! wp_verify_nonce ($nonce, $expectedAction))
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'Token inválido.'];
			return; // No sigas
		}

		// --- Autorización de la sección/acción ---

		// ¿existe la sección en este evento?
		$isStd = isset ($this->sectionsStandard [$sectionId]);
		$isHidden = isset ($this->sectionsHidden [$sectionId]);
		if (! $isStd && ! $isHidden)
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'Sección no válida para este evento.'];
			return;
		}

		if ($action === 'subscribe_section')
		{
			// Si es oculta, solo permitir si las reglas la habilitan para este usuario
			if ($isHidden)
			{
				$allowedHidden = $this->getAllowedHiddenSectionIdsByRules (); // ya implementado
				if (! in_array ($sectionId, $allowedHidden, true))
				{
					self::$messages [] = [ 'type' => 'error', 'text' => 'No tienes acceso a esta sección.'];
					return;
				}
			}

			// Evitar suscripción duplicada
			if (isset ($this->userSubscriptions [$sectionId]))
			{
				self::$messages [] = [ 'type' => 'error', 'text' => 'Ya estás inscrito en esta sección.'];
				return;
			}

			// Ejecutar
			$this->doSubscribe ($eventId, $sectionId);
		}
		else
		{ // unsubscribe_section
		  // Debe existir suscripción previa para desuscribir
			if (! isset ($this->userSubscriptions [$sectionId]))
			{
				self::$messages [] = [ 'type' => 'error', 'text' => 'No tienes una reserva en esta sección.'];
				return;
			}

			$this->doUnsubscribe ($eventId, $sectionId);
		}

		// Refrescar datos tras la operación (estado en memoria para el render)
		$this->loadAvailability ();
		$this->loadUserSubscriptions ();
	}


	private function doSubscribe (int $eventId, string $sectionId): bool
	{
		global $wpdb;

		$userId = (int) ($this->sessionData ['userId'] ?? 0);
		if ($userId <= 0)
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'Debes iniciar sesión para suscribirte.'];
			return false;
		}

		// Localizar la sección en la configuración del evento
		$section = null;
		foreach ($this->sectionsAll as $s)
		{
			if ((string) ($s ['id'] ?? '') === (string) $sectionId)
			{
				$section = $s;
				break;
			}
		}
		if (! $section)
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'La sección indicada no existe en este evento.'];
			return false;
		}

		$tableReservations = $wpdb->prefix . 'zgReservations';
		$tableCapacity = $wpdb->prefix . 'zgCapacity';
		$nowSql = 'NOW()';

		$price = (float) ($section ['price'] ?? 0.0);
		$status = ($price > 0) ? 'pending_payment' : 'confirmed';
		$payStatus = ($price > 0) ? 'none' : 'succeeded';
		$amountCt = ($price > 0) ? (int) round ($price * 100) : null;
		$currency = ($price > 0) ? 'EUR' : null;
		$confirmSql = ($status === 'confirmed') ? $nowSql : 'NULL';

		// Iniciar transacción
		$wpdb->query ('START TRANSACTION');

		// 1) Bloquear/leer capacidad existente: si no hay fila => bloqueado
		$capRow = $wpdb->get_row ($wpdb->prepare ("SELECT eventId, sectionId, maxCapacity, usedCapacity
               FROM {$tableCapacity}
              WHERE eventId=%d AND sectionId=%s
              FOR UPDATE", $eventId, $sectionId), ARRAY_A);

		if (! $capRow)
		{
			// No existe fila de capacidad => inscripciones bloqueadas
			$wpdb->query ('ROLLBACK');
			self::$messages [] = [ 'type' => 'error', 'text' => 'Las inscripciones para esta sección están temporalmente bloqueadas.'];
			return false;
		}

		$maxCap = (int) $capRow ['maxCapacity']; // 0 = ilimitado
		$usedCap = (int) $capRow ['usedCapacity'];

		// 2) Evitar duplicados: si ya tiene reserva activa (no cancelada/expirada), salir en verde
		$existing = $wpdb->get_row ($wpdb->prepare ("SELECT id, status
               FROM {$tableReservations}
              WHERE userId=%d AND eventId=%d AND sectionId=%s
              LIMIT 1", $userId, $eventId, $sectionId), ARRAY_A);

		if ($existing && ! in_array ($existing ['status'], [ 'cancelled', 'expired'], true))
		{
			$wpdb->query ('ROLLBACK');
			self::$messages [] = [ 'type' => 'success', 'text' => 'Ya estabas suscrito a esta sección.'];
			return true;
		}

		// 3) Comprobar espacio
		$hasSpace = ($maxCap === 0) || ($usedCap < $maxCap);
		if (! $hasSpace)
		{
			$wpdb->query ('ROLLBACK');
			self::$messages [] = [ 'type' => 'error', 'text' => 'No quedan plazas disponibles en esta sección.'];
			return false;
		}

		// 4) Incrementar capacidad usada
		$inc = $wpdb->query ($wpdb->prepare ("UPDATE {$tableCapacity}
                SET usedCapacity = usedCapacity + 1
              WHERE eventId=%d AND sectionId=%s", $eventId, $sectionId));
		if ($inc === false)
		{
			$wpdb->query ('ROLLBACK');
			self::$messages [] = [ 'type' => 'error', 'text' => 'No se pudo actualizar la capacidad.'];
			return false;
		}

		// 5) Insertar reserva
		$sql = "INSERT INTO {$tableReservations}
            (userId, eventId, sectionId, status, paymentStatus, amountCents, currency, confirmedAt, createdAt, updatedAt)
            VALUES (%d, %d, %s, %s, %s, %s, %s, {$confirmSql}, {$nowSql}, {$nowSql})";

		$ok = $wpdb->query ($wpdb->prepare ($sql, $userId, $eventId, $sectionId, $status, $payStatus, $amountCt, $currency));

		if ($ok === false)
		{
			$wpdb->query ('ROLLBACK');

			$msg = 'No se pudo crear la reserva.';
			if ($wpdb->last_error)
			{
				if (stripos ($wpdb->last_error, 'duplicate') !== false || stripos ($wpdb->last_error, 'uq_reservation') !== false)
				{
					$msg = 'Ya existe una reserva para este usuario en esta sección.';
				}
			}
			self::$messages [] = [ 'type' => 'error', 'text' => $msg];
			return false;
		}

		// 6) Commit y mensaje
		$wpdb->query ('COMMIT');

		if ($status === 'confirmed')
		{
			self::$messages [] = [ 'type' => 'success', 'text' => 'Te has inscrito correctamente.'];
		}
		else
		{
			self::$messages [] = [ 'type' => 'success', 'text' => 'Plaza reservada. Completa el pago para confirmar tu inscripción.'];
		}

		return true;
	}


	private function doUnsubscribe (int $eventId, string $sectionId): bool
	{
		global $wpdb;

		$userId = (int) ($this->sessionData ['userId'] ?? 0);
		if ($userId <= 0)
		{
			self::$messages [] = [ 'type' => 'error', 'text' => 'Debes iniciar sesión para darte de baja.'];
			return false;
		}

		$tableReservations = $wpdb->prefix . 'zgReservations';
		$tableCapacity = $wpdb->prefix . 'zgCapacity';

		// Inicia transacción
		$wpdb->query ('START TRANSACTION');

		// Bloquear la reserva del usuario para este evento/sección
		$reservation = $wpdb->get_row ($wpdb->prepare ("SELECT id, status
               FROM {$tableReservations}
              WHERE userId=%d AND eventId=%d AND sectionId=%s
              FOR UPDATE", $userId, $eventId, $sectionId), ARRAY_A);

		if (! $reservation)
		{
			$wpdb->query ('ROLLBACK');
			self::$messages [] = [ 'type' => 'error', 'text' => 'No se encontró una suscripción para cancelar.'];
			return false;
		}

		$statusBefore = (string) $reservation ['status'];
		$consumedCapacity = in_array ($statusBefore, [ 'confirmed', 'pending_payment', 'held'], true);

		// Borrado duro de la reserva
		$deleted = $wpdb->query ($wpdb->prepare ("DELETE FROM {$tableReservations} WHERE id=%d", (int) $reservation ['id']));

		if ($deleted === false)
		{
			$wpdb->query ('ROLLBACK');
			self::$messages [] = [ 'type' => 'error', 'text' => 'No se pudo eliminar la suscripción.'];
			return false;
		}

		// Si consumía plaza, liberar capacidad (si no existe fila de capacidad, no se considera error)
		if ($consumedCapacity)
		{
			$capUpdated = $wpdb->query ($wpdb->prepare ("UPDATE {$tableCapacity}
                    SET usedCapacity = GREATEST(usedCapacity - 1, 0)
                  WHERE eventId=%d AND sectionId=%s", $eventId, $sectionId));

			if ($capUpdated === false)
			{
				$wpdb->query ('ROLLBACK');
				self::$messages [] = [ 'type' => 'error', 'text' => 'No se pudo actualizar la capacidad.'];
				return false;
			}
		}

		// Confirmar transacción
		$wpdb->query ('COMMIT');

		self::$messages [] = [ 'type' => 'success', 'text' => 'Tu suscripción ha sido eliminada correctamente.'];
		return true;
	}

	/*
	 * =======================
	 * Utility
	 * =======================
	 */
}
