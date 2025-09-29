<?php

namespace ZentryGate;

/**
 * Handles authentication, cookie consent, and session management for ZentryGate plugin.
 */
class Auth
{
	protected static array $lastErrors = [ ];
	private static bool $isInitialized = false;
	private static string $sessionDir;
	private static ?array $cookieData = null;
	private static ?array $userData = null;


	/**
	 * Initialize the Auth system (should be called on 'init' hook)
	 */
	public static function init (): void
	{
		if (self::$isInitialized)
		{
			return;
		}

		self::$sessionDir = rtrim (sys_get_temp_dir (), '/') . '/Zentrygate/';
		if (! is_dir (self::$sessionDir))
		{
			mkdir (self::$sessionDir, 0700, true);
		}

		self::$cookieData = null;
		self::$userData = null;

		self::loadCookieData ();
		self::processEarlyActions ();
		self::$isInitialized = true;
	}


	// Guarda data temporalmente y devuelve una clave
	public static function flashSet (string $prefix, array $data, int $ttl = 300): string
	{
		$key = bin2hex (random_bytes (8));
		set_transient ($prefix . $key, $data, $ttl);
		return $key;
	}


	// Lee y borra el flash
	public static function flashTake (string $prefix, string $key): array
	{
		if (! $key) return [ ];
		$data = get_transient ($prefix . $key);
		if ($data !== false) delete_transient ($prefix . $key);
		return is_array ($data) ? $data : [ ];
	}


	/**
	 * Pinta un bloque de errores accesible.
	 * Acepta strings, arrays anidados y/o WP_Error.
	 */
	public static function renderErrors (array $errors): void
	{
		// Normalizar: aplanar y limpiar
		$out = [ ];

		$push = static function ($msg) use ( &$out)
		{
			if (is_scalar ($msg))
			{
				$s = trim ((string) $msg);
				if ($s !== '')
				{
					$out [] = $s;
				}
			}
		};

		foreach ($errors as $e)
		{
			if ($e instanceof \WP_Error)
			{
				foreach ($e->get_error_messages () as $m)
				{
					$push ($m);
				}
			}
			elseif (is_array ($e))
			{
				$it = new \RecursiveIteratorIterator (new \RecursiveArrayIterator ($e));
				foreach ($it as $m)
				{
					$push ($m);
				}
			}
			else
			{
				$push ($e);
			}
		}

		$out = array_values (array_unique ($out));
		if (empty ($out))
		{
			return;
		}

		$heading_id = 'zg-errors-title-' . uniqid ('', false);
		?>
        <div class="zg-alert zg-alert-error" role="alert" aria-live="assertive" aria-labelledby="<?php

		echo esc_attr ($heading_id);
		?>">
            <strong id="<?php

		echo esc_attr ($heading_id);
		?>">
                <?php

		echo esc_html__ ('Por favor, corrige los siguientes errores:', 'zentrygate');
		?>
            </strong>
            <ul class="zg-alert-list">
                <?php

		foreach ($out as $msg)
		:
			?>
                    <li><?php

			echo esc_html ($msg);
			?></li>
                <?php
		endforeach
		;
		?>
            </ul>
        </div>
        <?php
	}


	/**
	 * Load and decode cookie data.
	 */
	private static function loadCookieData (): void
	{
		self::$cookieData = null;

		$raw = $_COOKIE ['ZentryGate'] ?? '';
		if ($raw === '')
		{
			return;
		}

		// Base64url -> base64
		$b64 = strtr ($raw, '-_', '+/');

		// Añade padding hasta múltiplo de 4
		$remainder = strlen ($b64) % 4;
		if ($remainder)
		{
			$b64 .= str_repeat ('=', 4 - $remainder);
		}

		// Decodifica en modo estricto
		$decoded = base64_decode ($b64, true);
		if ($decoded === false)
		{
			return;
		}

		// Decodifica JSON
		$data = json_decode ($decoded, true);
		if (json_last_error () === JSON_ERROR_NONE && is_array ($data))
		{
			self::$cookieData = $data;
		}
	}


	private static function saveCookieData (): void
	{
		// Serializa de forma segura
		$json = json_encode (self::$cookieData, JSON_UNESCAPED_SLASHES);
		if ($json === false)
		{
			// Si algo raro pasa, no intentes setear la cookie
			return;
		}

		// base64url (sin padding)
		$b64 = rtrim (strtr (base64_encode ($json), '+/', '-_'), '=');

		$siteHost = wp_parse_url (home_url (), PHP_URL_HOST);

		$options = [ 'expires' => time () + 365 * 24 * 60 * 60, // 1 año
		'path' => '/', // raíz del sitio
		'domain' => $siteHost, // HostOnly; usa 'agj.madrid' si quieres incluir subdominios
		'secure' => is_ssl (), // true si el sitio está en https
		'httponly' => true, // no accesible desde JS
		'samesite' => 'Lax' // navegación desde email/link OK
		];

		// IMPORTANTE: no debe haberse enviado salida antes de esto
		setcookie ('ZentryGate', $b64, $options);
	}


	/**
	 * This method should be called early in the request lifecycle to ensure session data is available.
	 *
	 * Checks if the cookie was accepted, and if th Acceptance Post was submitted, it generates the cookie.
	 * If the cookie is accepted:
	 * - If there is a POST request for login, it clears sessionData, validates credentials, saves to disk, and sets the cookie.
	 * - If there is no POST request, it validates the session from the file if it exists and fills sessionData.
	 */
	private static function processEarlyActions (): void
	{
		if (self::$cookieData === null)
		{
			if (isset ($_POST ['accept_ZentryGate_cookie']))
			{
				self::$cookieData = [ 'accepted' => true];
				self::saveCookieData ();
			}
		}
		else
		{
			$cookieDta = &self::$cookieData;
			if (isset ($_POST ['zg_email'], $_POST ['zg_password']))
			{
				if (self::checkLoginForm ())
				{
					// Login successful: create session file and set cookie
					$nonce = bin2hex (random_bytes (16));
					$fileId = bin2hex (random_bytes (16));

					$cookieDta ['sessId'] = $fileId;
					$cookieDta ['nonce'] = $nonce;
					$cookieDta ['emailHash'] = md5 (trim ($_POST ['zg_email']));

					self::saveCookieData ();

					// YAGNI: Improve the security with a session table with multiples sessions per user
					// Generate the session file
					$session = [ 'nonce' => $nonce, 'email' => trim ($_POST ['zg_email'])];
					$sessionFile = self::$sessionDir . $fileId . '.json';
					file_put_contents ($sessionFile, json_encode ($session));
				}
				else
				{
					// Login failed, clear session data
					self::$cookieData = [ 'accepted' => true];
					self::saveCookieData ();
				}
			}
			else if (isset ($cookieDta ['sessId']))
			{
				// Coockie exists, and no post request for login: check session file
				$cookieDta = &self::$cookieData;
				$sessionFile = self::$sessionDir . $cookieDta ['sessId'] . '.json';

				if (file_exists ($sessionFile))
				{
					$sessionJson = file_get_contents ($sessionFile);
					$session = json_decode ($sessionJson, true);

					if (is_array ($session) && isset ($session ['nonce']) && $session ['nonce'] === $cookieDta ['nonce'] && $cookieDta ['emailHash'] === md5 ($session ['email']))
					{
						// Check if the user is enabled, and load user data
						self::checkUserStillEnabled ($session ['email']);
					}
					else
					{
						// The nonce does not match... so its a hacking attempt: There is no valid login, without information
					}
				}
				else
				{
					// There is no session file... it may be expired, or a hacking attempt: There is no valid login, without information
				}
			}
			else
			{
				// The cookie was accepted, but there is no POST request for login, nor we have a valid session id to check.
			}
		}
	}


	/**
	 * Check if the ZentryGate cookie is accepted.
	 */
	public static function isCookieAccepted (): bool
	{
		return self::$cookieData !== null;
	}


	/**
	 * Check if we have a valid user session (loader by coockie or by Form).
	 */
	public static function isLoggedIn (): bool
	{
		return self::$userData !== null;
	}


	/**
	 * Get the session data for the logged-in user.
	 */
	public static function getSessionData (): array
	{
		return self::$userData ?? [ ];
	}


	/**
	 * Render the cookie acceptance form.
	 */
	public static function renderCookiePrompt (): void
	{
		// Asegúrate de llamar a wp_enqueue_style o inline CSS para .zg-cookie-form si lo deseas
		?>
    <form method="post" class="zg-cookie-form" aria-labelledby="zg-cookie-title" action="<?=PLugin::$permalink?>">
        <?php

		wp_nonce_field ('zg_cookie_consent_action', 'zg_cookie_consent_nonce');
		$page_id = intval (get_option ('zg_cookie_prompt_page'));
		$buttonText = "Continuar";
		if ($page_id)
		{
			echo apply_filters ('the_content', get_post_field ('post_content', $page_id));
			$buttonText = get_the_title ($page_id);
		}
		else
		{
			echo '<p>' . esc_html_e ('Por favor, acepta el uso de cookies para poder reservar tu asistencia a las jornadas.', 'zentrygate') . '</p>';
		}
		?>        
        <div class="zg-form-footer">
            <button type="submit" name="accept_ZentryGate_cookie" class="button button-primary">
                <?=$buttonText?>
            </button>
        </div>
    </form>
    <?php
	}


	/**
	 * Render the login form.
	 */
	public static function renderLoginForm (): void
	{
		?>
    <form method="post" class="zg-login-form" aria-labelledby="zg-login-title" action="<?=PLugin::$permalink?>">
        <?=wp_nonce_field ('zg_login_action', 'zg_login_nonce');?>
        <?php
		$page_id = intval (get_option ('zg_login_form_page'));
		if ($page_id)
		{
			echo apply_filters ('the_content', get_post_field ('post_content', $page_id));
		}
		else
		{
			echo '<p>' . esc_html_e ('Por favor, inicia sesión para acceder al sistema de reservas.', 'zentrygate') . '</p>';
		}
		?>
        
        
        <div class="zg-form-body">
            <label for="zg_email">
                <?=esc_html_e ('Correo electrónico', 'zentrygate');?>
                <input
                    type="email"
                    id="zg_email"
                    name="zg_email"
                    placeholder="<?=esc_attr_e ('ejemplo@correo.com', 'zentrygate');?>"
                    required
                    aria-required="true"
                >
            </label>
            <br>
            <label for="zg_password">
                <?=esc_html_e ('Contraseña', 'zentrygate');?>
                <input
                    type="password"
                    id="zg_password"
                    name="zg_password"
                    placeholder=""
                    required
                    aria-required="true"
                >
            </label>
        </div>
        <div class="zg-form-footer">
            <button type="submit" name="zg_login" class="button button-primary">
                <?=esc_html_e ('Acceder', 'zentrygate');?>
            </button>
            <p class="zg-auth-links">
                <a class="zg-pass-recovery" href="<?=esc_url (add_query_arg ('zg_action', 'pass_recovery'));?>">
                    <?=esc_html_e ('¿Has olvidado tu contraseña?', 'zentrygate');?>
                </a>
                &nbsp;·&nbsp;
                <a class="zg-register" href="<?=esc_url (add_query_arg ('zg_action', 'register'));?>">
                    <?=esc_html_e ('¿No tienes cuenta? Regístrate', 'zentrygate');?>
                </a>
            </p>
        </div>
    </form>
    <?php
	}


	/**
	 * Check if the user is still enabled and load user data.
	 */
	private static function fillUserData (array $user): void
	{
		self::$userData = [ 'name' => $user ['name'], 'userId' => $user ['id'], 'email' => $user ['email'], 'isAdmin' => (bool) $user ['isAdmin'], 'isEnabled' => (bool) $user ['isEnabled'], 'lastLogin' => current_time ('mysql')];
	}


	private static function checkUserStillEnabled (string $email): void
	{
		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgUsers WHERE email = %s", $email), ARRAY_A);

		if ($user && $user ['isEnabled'])
		{
			// User is enabled, fill user data
			self::fillUserData ($user);
		}
		else
		{
			self::$userData = null; // User is not enabled or does not exist
		}
	}


	/**
	 * Check login credentials from form and store session if successful.
	 */
	private static function checkLoginForm (): bool
	{
		if ($_SERVER ['REQUEST_METHOD'] !== 'POST' || ! isset ($_POST ['zg_email'], $_POST ['zg_password']))
		{
			return false;
		}

		$email = trim ($_POST ['zg_email']);
		$password = $_POST ['zg_password'];

		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgUsers WHERE email = %s", $email), ARRAY_A);

		if (! $user || ! password_verify ($password, $user ['passwordHash']) || ! $user ['isEnabled'])
		{
			return false;
		}

		self::fillUserData ($user);

		return self::$userData ['isEnabled'] ?? false;
	}
	private const RESET_TOKEN_MAX_MINUTES = 30;
	private const RESET_TOKEN_COOL_DOWN = 1;


	private static function processRecoveryChangePassword (): bool
	{
		if ($_SERVER ['REQUEST_METHOD'] !== 'POST' || ! isset ($_POST ['zg_recover_email'], $_POST ['zg_new_password'], $_POST ['zg_recovery_token']))
		{
			return false;
		}

		$email = sanitize_email ($_POST ['zg_recover_email']);
		$newPassword = $_POST ['zg_new_password'];
		$token = sanitize_text_field ($_POST ['zg_recovery_token']);

		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgUsers WHERE email = %s", $email), ARRAY_A);

		if (! $user || ! self::isStillValidToken ($user ['resetRequestedAt'], self::RESET_TOKEN_MAX_MINUTES * MINUTE_IN_SECONDS) || $user ['resetToken'] !== $token)
		{
			return false;
		}

		// Update password and clear reset token
		$hashedPassword = password_hash ($newPassword, PASSWORD_DEFAULT);
		$updated = $wpdb->update ("{$wpdb->prefix}zgUsers", [ 'passwordHash' => $hashedPassword, 'resetToken' => null, 'resetRequestedAt' => null], [ 'email' => $email], [ '%s', '%s', '%s'], [ '%s']);

		return (bool) $updated;
	}


	/**
	 * Renderiza el formulario para establecer una nueva contraseña,
	 * usando los parámetros GET 'zg_recover_email' y 'token'.
	 */
	public static function renderRecoveryCangepasswordForm (): void
	{
		// Obtener y sanear parámetros
		$email = isset ($_GET ['zg_recover_email']) ? sanitize_email (wp_unslash ($_GET ['zg_recover_email'])) : '';
		$token = isset ($_GET ['token']) ? sanitize_text_field (wp_unslash ($_GET ['token'])) : '';

		?>
    <form method="post" class="zg-recovery-change-password-form" aria-labelledby="zg-change-title" action="<?=PLugin::$permalink?>">
        <?php
		// Mantener acción y datos en POST
		printf ('<input type="hidden" name="zg_recover_email" value="%s">', esc_attr ($email));
		printf ('<input type="hidden" name="token" value="%s">', esc_attr ($token));
		// Nonce para seguridad
		wp_nonce_field ('zg_pass_reset_action', 'zg_pass_reset_nonce');
		?>
        <div class="zg-form-header">
            <h2 id="zg-change-title"><?php

		esc_html_e ('Establecer nueva contraseña', 'zentrygate');
		?></h2>
        </div>
        <div class="zg-form-body">
            <label for="zg_new_password">
                <?php

		esc_html_e ('Nueva contraseña', 'zentrygate');
		?>
                <input
                    type="password"
                    id="zg_new_password"
                    name="zg_new_password"
                    placeholder=""
                    required
                    aria-required="true"
                >
            </label>
            <br>
            <label for="zg_confirm_password">
                <?php

		esc_html_e ('Repite la contraseña', 'zentrygate');
		?>
                <input
                    type="password"
                    id="zg_confirm_password"
                    name="zg_confirm_password"
                    placeholder=""
                    required
                    aria-required="true"
                >
            </label>
        </div>
        <div class="zg-form-footer">
            <button
                type="submit"
                name="zg_change_password"
                class="button button-primary"
            >
                <?php

		esc_html_e ('Cambiar contraseña', 'zentrygate');
		?>
            </button>
        </div>
    </form>
    <?php
	}


	public static function handleRecoveryGet (): bool
	{
		if (isset ($_GET ['zg_recover_email']))
		{

			$email = sanitize_email ($_GET ['zg_recover_email']);
			if (! is_email ($email))
			{
				echo '<p class="error">' . esc_html_e ('El correo electrónico proporcionado no es válido.', 'zentrygate') . '</p>';
				return false;
			}

			self::sendPasswordResetToken ($email);
			return true;
		}
		else
		{
			return false;
		}
	}


	/**
	 * Render the notice after requesting password recovery.
	 * Always a neutral message: "if your email exists, you will receive instructions"
	 */
	public static function renderRecoveryRequested (): void
	{
		?>
    <div class="zg-notice zg-notice-success" role="alert" aria-live="polite" aria-labelledby="zg-recovery-requested-title">
        <strong id="zg-recovery-requested-title"><?=esc_html_e ('Solicitud de recuperación enviada', 'zentrygate');?></strong>
        <p><?=esc_html_e ('Si el correo electrónico proporcionado existe en nuestro sistema, recibirás por correo electrónico un enlace para restablecer tu contraseña.', 'zentrygate');?></p>
        <p class="zg-auth-links"><a href="<?=esc_url (add_query_arg ('zg_action', 'login'));?>"><?=esc_html_e ('Ir a pantalla de login', 'zentrygate');?></a></p>
    </div><?php
	}


	/**
	 * Render the password recovery form to ask for the email.
	 */
	public static function renderRecoveryForm (): void
	{
		if (isset ($_GET ['zg_recover_email']))
		{
			$email = sanitize_email ($_GET ['zg_recover_email']);
			if (! is_email ($email))
			{
				echo '<p class="error">' . esc_html_e ('El correo electrónico proporcionado no es válido.', 'zentrygate') . '</p>';
				return;
			}

			if (self::processRecoveryChangePassword ())
			{
				echo '<p class="success">' . esc_html_e ('Se ha Cambiado correctamente la contraseña.', 'zentrygate') . '</p>';
				// echo '<p><a href="' . get_permalink () . '">' . esc_html__ ('Volver al inicio de sesión', 'zentrygate') . '</a></p>';
				self::renderLoginForm ();
				return;
			}

			if (self::sendPasswordResetToken ($email))
			{
				echo '<p class="success">' . esc_html_e ('Se ha enviado un enlace de recuperación a tu correo electrónico.', 'zentrygate') . '</p>';
			}

			self::renderRecoveryCangepasswordForm ();
		}
		else
		{
			self::renderRecoveryAskEmailForm ();
		}
	}


	/**
	 * Renderiza el formulario para pedir el email de recuperación.
	 */
	public static function renderRecoveryAskEmailForm (): void
	{
		?>
    <form method="get" class="zg-recovery-ask-email-form" aria-labelledby="zg-recovery-title" action="<?=PLugin::$permalink?>">
    <input type="hidden" name="zg_action" value="pass_recovery">
        <?php
		// Nonce para seguridad
		wp_nonce_field ('zg_pass_recovery_action', 'zg_pass_recovery_nonce');
		?>
        <div class="zg-form-header">
            <h2 id="zg-recovery-title"><?php

		esc_html_e ('Recuperar contraseña', 'zentrygate');
		?></h2>
        </div>
        <div class="zg-form-body">
            <?php
		$page_id = intval (get_option ('zg_recovery_form_page'));
		if ($page_id)
		{
			echo apply_filters ('the_content', get_post_field ('post_content', $page_id));
		}
		else
		{

			esc_html_e ('Introduce tu correo electrónico para recibir un enlace de recuperación.', 'zentrygate');
		}
		?>
            <label for="zg_recover_email">
                <?php

		esc_html_e ('Correo electrónico', 'zentrygate');
		?>
                <input
                    type="email"
                    id="zg_recover_email"
                    name="zg_recover_email"
                    placeholder="<?php

		esc_attr_e ('ejemplo@correo.com', 'zentrygate');
		?>"
                    required
                    aria-required="true"
                >
            </label>
        </div>
        <div class="zg-form-footer">
            <button
                type="submit"
                class="button button-primary"
            >
                <?php

		esc_html_e ('Enviar enlace', 'zentrygate');
		?>
            </button>
        </div>
    </form>
    <?php
	}


	/**
	 * Comprueba si un token sigue siendo válido, dado el momento de solicitud.
	 *
	 * @param string|null $resetRequestedAt
	 *        	Fecha de solicitud en formato 'Y-m-d H:i:s' o null.
	 * @param int $intervalSec
	 *        	Intervalo de validez en segundos.
	 * @return bool True si el token aún no ha expirado.
	 */
	private static function isStillValidToken (?string $resetRequestedAt, int $intervalSec): bool
	{
		if (empty ($resetRequestedAt))
		{
			return false;
		}
		$requested = strtotime ($resetRequestedAt);
		return (time () - $requested) < $intervalSec;
	}


	public static function isValidResetToken (): bool
	{
		if (! isset ($_GET ['zg_recover_email'], $_GET ['token']))
		{
			return false;
		}

		$email = sanitize_email (wp_unslash ($_GET ['zg_recover_email']));
		$token = sanitize_text_field (wp_unslash ($_GET ['token']));

		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT email, isEnabled, resetToken, resetRequestedAt
               FROM {$wpdb->prefix}zgUsers
              WHERE email = %s", $email), ARRAY_A);

		if (! $user || ! (bool) $user ['isEnabled'] || ! self::isStillValidToken ($user ['resetRequestedAt'], self::RESET_TOKEN_MAX_MINUTES * MINUTE_IN_SECONDS) || $user ['resetToken'] !== $token)
		{
			return false;
		}

		return true;
	}


	/**
	 * Genera o reenvía el token de recuperación y envía el email.
	 *
	 * @param string $email
	 * @return bool
	 */
	public static function sendPasswordResetToken (string $email): bool
	{
		global $wpdb;

		// Obtener usuario y saber si ya había un token válido
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT email, name, isEnabled, resetToken, resetRequestedAt
               FROM {$wpdb->prefix}zgUsers
              WHERE email = %s", $email), ARRAY_A);

		if (! $user || ! (bool) $user ['isEnabled'])
		{
			return false;
		}

		var_dump ($user);

		// Rate‐limit: avoid sending too many reset requests
		if (self::isStillValidToken ($user ['resetRequestedAt'], self::RESET_TOKEN_COOL_DOWN * MINUTE_IN_SECONDS))
		{
			return false;
		}
		else
		{
			// We have returned if the token was still valid, so now we know it is expired or not existing.
			$token = bin2hex (random_bytes (32));

			var_dump ($token);

			// 4. Guardar token + timestamp de solicitud
			$now = current_time ('mysql');
			$updated = $wpdb->update ("{$wpdb->prefix}zgUsers", [ 'resetToken' => $token, 'resetRequestedAt' => $now], [ 'email' => $email], [ '%s', '%s'], [ '%s']);
			if (false === $updated)
			{
				return false;
			}

			var_dump ($updated);
		}

		// 5. Envío de email. Es get, así que el permalink es perfecto
		$emailEncoded = rtrim (strtr (base64_encode ($email), '+/', '-_'), '=');
		$reset_link = add_query_arg ([ 'zg_action' => 'pass-reset', 'zg_recover_email' => $emailEncoded, 'token' => $token], get_permalink ());
		$subject = sprintf (__ ('Recupera tu contraseña en %s', 'zentrygate'), wp_specialchars_decode (get_bloginfo ('name'), ENT_QUOTES));
		$message = sprintf (__ ("Hola %1\$s,\n\nHaz clic en este enlace (válido durante %2\$d minutos) para restablecer tu contraseña:\n\n%3\$s\n\nSi no lo solicitaste, ignora este correo.", 'zentrygate'), esc_html ($user ['name']), self::RESET_TOKEN_MAX_MINUTES, esc_url ($reset_link));
		$headers = [ 'Content-Type: text/plain; charset=UTF-8'];

		return (bool) wp_mail ($email, $subject, $message, $headers);
	}


	public static function renderAskUserCheckEmail (): void
	{
		?>
    <div class="zg-notice zg-notice-success" role="alert" aria-live="polite" aria-labelledby="zg-check-email-title">
        <strong id="zg-check-email-title">
            <?php

		esc_html_e ('Registro completado', 'zentrygate');
		?>
        </strong>
        <p>
            <?php

		esc_html_e ('Por favor, revisa tu correo electrónico y haz clic en el enlace de verificación para activar tu cuenta.', 'zentrygate');
		?>
        </p>
        
        <p class="zg-auth-links">
            <a href="<?=esc_url (add_query_arg ('zg_action', 'login'));?>">
                <?php

		esc_html_e ('Ir a pantalla de login', 'zentrygate');
		?>
            </a>
        </p>
        
    </div>
    <?php
	}


	public static function renderVerificationSuccess (): void
	{
		?>
    <div class="zg-notice zg-notice-success" role="alert" aria-live="polite" aria-labelledby="zg-verification-success-title">
        <strong id="zg-verification-success-title">
            <?php

		esc_html_e ('Cuenta verificada', 'zentrygate');
		?>
        </strong>
        <p>
            <?php

		esc_html_e ('Tu cuenta ha sido verificada correctamente. Ya puedes iniciar sesión.', 'zentrygate');
		?>
        </p>
        <p>
            <a href="<?=esc_url (add_query_arg ('zg_action', 'login'));?>">
                <?php

		esc_html_e ('Ir al inicio de sesión', 'zentrygate');
		?>
            </a>
        </p>
    </div>
    <?php
	}


	public static function renderPasswordResetSuccess (): void
	{
		?>
    <div class="zg-notice zg-notice-success" role="alert" aria-live="polite" aria-labelledby="zg-verification-success-title">
        <strong id="zg-verification-success-title">
            <?php

		esc_html_e ('Password cambiada', 'zentrygate');
		?>
        </strong>
        <p>
            <?php

		esc_html_e ('Has cambiado la contraseña. Ya puedes iniciar sesión con la nueva.', 'zentrygate');
		?>
        </p>
        <p>
            <a href="<?=esc_url (add_query_arg ('zg_action', 'login'));?>">
                <?php

		esc_html_e ('Ir al inicio de sesión', 'zentrygate');
		?>
            </a>
        </p>
    </div>
    <?php
	}


	public static function renderPassResetFailed (): void
	{
		?>
    <div class="zg-notice zg-notice-error" role="alert" aria-live="assertive" aria-labelledby="zg-verification-failed-title">
        <strong id="zg-verification-failed-title">
            <?php

		esc_html_e ('Enlace caducado', 'zentrygate');
		?>
        </strong>
        <p>
            <?php

		esc_html_e ('El enlace de cambio de contraseña no es válido o ha expirado. Por favor, solicita un nuevo enlace.', 'zentrygate');
		?>
        </p>
        <p class="zg-auth-links">
            <a href="<?=esc_url (add_query_arg ('zg_action', 'register'));?>">
                <?php

		esc_html_e ('Reintentar registrarse', 'zentrygate');
		?>
            </a>
        </p>
    </div>
    <?php
	}


	public static function handleEmailVerification (): bool
	{
		if (! isset ($_GET ['e'], $_GET ['token']))
		{
			return false;
		}

		$eParam = isset ($_GET ['e']) ? (string) wp_unslash ($_GET ['e']) : '';
		$b64 = strtr ($eParam, '-_', '+/');
		$pad = strlen ($b64) % 4;
		if ($pad) $b64 .= str_repeat ('=', 4 - $pad);
		$email = base64_decode ($b64, true);

		$token = sanitize_text_field (wp_unslash ($_GET ['token']));

		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT email, isEnabled, verifyToken
               FROM {$wpdb->prefix}zgUsers
              WHERE email = %s", $email), ARRAY_A);

		if (! $user || (bool) $user ['isEnabled'] || $user ['verifyToken'] !== $token)
		{
			return false;
		}

		// Verificación correcta: activar usuario y limpiar token
		$updated = $wpdb->update ("{$wpdb->prefix}zgUsers", [ 'isEnabled' => 1, 'verifyToken' => null], [ 'email' => $email], [ '%d', '%s'], [ '%s']);
		if (false === $updated)
		{

			return false;
		}

		return true;
	}


	public static function renderVerificationFailed (): void
	{
		?>
    <div class="zg-notice zg-notice-error" role="alert" aria-live="assertive" aria-labelledby="zg-verification-failed-title">
        <strong id="zg-verification-failed-title">
            <?php

		esc_html_e ('Verificación fallida', 'zentrygate');
		?>
        </strong>
        <p>
            <?php

		esc_html_e ('El enlace de verificación no es válido o ha expirado. Por favor, solicita un nuevo enlace.', 'zentrygate');
		?>
        </p>
        <p class="zg-auth-links">
            <a href="<?=esc_url (add_query_arg ('zg_action', 'register'));?>">
                <?php

		esc_html_e ('Reintentar registrarse', 'zentrygate');
		?>
            </a>
        </p>
    </div>
    <?php
	}

	/**
	 * Esquema configurable de campos extra de registro.
	 * En el futuro se cargará desde BBDD/opciones.
	 * name: etiqueta visible; tag: clave en otherData; type: text|tel|email|date|textarea|checkbox|select|multiselect
	 */
	protected static string $schemaJson = <<<JSON
	[
	    {"name":"DNI/NIE","tag":"nif","type":"text","required":true},
		{"name":"Empresa","tag":"org","type":"text","required":true},
		{"name":"Cargo","tag":"role","type":"text","required":true},
		{"name":"Teléfono","tag":"phone","type":"tel","required":true}
	]
	JSON;


	/**
	 * Decodifica el esquema de registro.
	 */
	protected static function getRegisterSchemaArray (): array
	{
		$arr = json_decode (self::$schemaJson, true);
		return is_array ($arr) ? $arr : [ ];
	}


	/**
	 * Render the register form.
	 */
	public static function renderRegisterForm (bool $hasOldData = false): void
	{
		// Esquema configurable en JSON (futuro: cargar de opciones/BBDD)
		$extraFields = self::getRegisterSchemaArray ();

		// Helpers locales para IDs/atributos seguros

		$isChecked = function (string $name): bool
		{
			return isset ($_POST [$name]) && ($_POST [$name] === '1' || $_POST [$name] === 'on');
		};

		$redirectTo = esc_url (add_query_arg ([ 'zg_action' => 'register'], get_permalink ()));
		$actionUrl = esc_url (admin_url ('admin-post.php'));

		?>
        <form method="post" class="zg-register-form" aria-labelledby="zg-register-title" novalidate action="<?=$actionUrl?>">
        <input type="hidden" name="action" value="zg_register">
        <input type="hidden" name="zg_action" value="register">
        <input type="hidden" name="redirect_to" value="<?=$redirectTo;?>">
    
            

            <h2 id="zg-register-title"><?=esc_html_e ('Crear cuenta', 'zentrygate');?></h2>

            <?php
		$old = [ ];
		if ($hasOldData)
		{
			$errors = self::flashTake ('zg_err_', $_GET ['errkey'] ?? '');
			$old = self::flashTake ('zg_old_', $_GET ['oldkey'] ?? '');

			if (! empty ($errors))
			{
				self::renderErrors ($errors); // pinta tu bloque de errores
			}
		}

		$page_id = intval (get_option ('zg_register_form_page'));
		if ($page_id)
		{
			echo apply_filters ('the_content', get_post_field ('post_content', $page_id));
		}
		wp_nonce_field ('zg_register_action', 'zg_register_nonce', false);
		?>
		
		

            <div class="zg-form-body">
                <!-- Nombre (mínimo, fuera de JSON) -->
                <label for="zg_reg_name">
                    <?=esc_html_e ('Nombre y apellidos', 'zentrygate');?>
                    <input
                        type="text"
                        id="zg_reg_name"
                        name="name"
                        value="<?=isset ($old ['name']) ? esc_attr (wp_unslash ($old ['name'])) : '';?>"
                        placeholder="<?=esc_attr_e ('Nombre y apellidos', 'zentrygate');?>"
                        required
                        aria-required="true"
                        autocomplete="name"
                    >
                </label>

                <!-- Email (mínimo, fuera de JSON) -->
                <label for="zg_reg_email">
                    <?=esc_html_e ('Correo electrónico', 'zentrygate');?>
                    <input
                        type="email"
                        id="zg_reg_email"
                        name="email"
                        value="<?=isset ($old ['email']) ? esc_attr (wp_unslash ($old ['email'])) : '';?>"
                        placeholder="<?=esc_attr_e ('ejemplo@correo.com', 'zentrygate');?>"
                        required
                        aria-required="true"
                        autocomplete="email"
                    >
                </label>
                
                
                <label for="zg_hp_name" id="zg_hp_label">
                    <?php
		// honeypot field, should be hidden with CSS
		echo 'Hall Name';
		?>
                    <input
                        id="zg_hp_name"
                        name="zg_hp_name"
                        value=""
                        placeholder="Nombre y apellidos"
                    >
                </label>

                <!-- Password + confirmación (mínimo, fuera de JSON) -->
                <label for="zg_reg_password">
                    <?=esc_html_e ('Contraseña', 'zentrygate');?>
                    <input
                        type="password"
                        id="zg_reg_password"
                        name="password"
                        placeholder=""
                        required
                        aria-required="true"
                        autocomplete="new-password"
                    >
                </label>
                <label for="zg_reg_password2">
                    <?=esc_html_e ('Repite la contraseña', 'zentrygate');?>
                    <input
                        type="password"
                        id="zg_reg_password2"
                        name="password2"
                        placeholder=""
                        required
                        aria-required="true"
                        autocomplete="new-password"
                    >
                </label>

                <?php
		// Campos extra (configurables por JSON)
		if (! empty ($extraFields))
		{
			foreach ($extraFields as $cfg)
			{
				$label = isset ($cfg ['name']) ? (string) $cfg ['name'] : '';
				$tag = isset ($cfg ['tag']) ? (string) $cfg ['tag'] : '';
				$type = isset ($cfg ['type']) ? (string) $cfg ['type'] : 'text';
				$req = ! empty ($cfg ['required']);
				$id = $tag;

				if ($label === '' || $tag === '')
				{
					continue;
				}

				// El nombre de POST de estos campos va en 'other[...]'
				$postName = 'other[' . $tag . ']';
				$value = isset ($old ['other'] [$tag]) ? wp_unslash ($old ['other'] [$tag]) : '';

				echo '<div class="zg-field zg-field-' . esc_attr ($type) . '">';

				switch ($type)
				{
					case 'textarea':
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<textarea id="' . esc_attr ($id) . '" name="' . esc_attr ($postName) . '" ' . 'rows="3">' . esc_textarea (is_string ($value) ? $value : '') . '</textarea></label>';
						break;

					case 'checkbox':
						// Para checkbox usamos valor "1"
						$checked = $isChecked ($postName);
						echo '<label class="zg-checkbox">' . '<input type="checkbox" id="' . esc_attr ($id) . '" name="' . esc_attr ($postName) . '" value="1" ' . ($checked ? 'checked ' : '') . '>' . ' ' . esc_html ($label) . ($req ? ' *' : '') . '</label>';
						break;

					case 'select':
						$choices = isset ($cfg ['choices']) && is_array ($cfg ['choices']) ? $cfg ['choices'] : [ ];
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<select id="' . esc_attr ($id) . '" name="' . esc_attr ($postName) . '" ' . '>';
						echo '<option value="">' . esc_html__ ('-- Selecciona --', 'zentrygate') . '</option>';
						foreach ($choices as $opt)
						{
							$sel = ((string) $value === (string) $opt) ? ' selected' : '';
							echo '<option value="' . esc_attr ($opt) . '"' . $sel . '>' . esc_html ($opt) . '</option>';
						}
						echo '</select></label>';
						break;

					case 'multiselect':
						$choices = isset ($cfg ['choices']) && is_array ($cfg ['choices']) ? $cfg ['choices'] : [ ];
						$vals = isset ($old ['other'] [$tag]) && is_array ($old ['other'] [$tag]) ? array_map ('wp_unslash', $old ['other'] [$tag]) : [ ];
						// Para multi, usamos name="other[tag][]" y multiple
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<select id="' . esc_attr ($id) . '" name="other[' . esc_attr ($tag) . '][]" multiple ' . ' size="4">';
						foreach ($choices as $opt)
						{
							$sel = in_array ((string) $opt, array_map ('strval', $vals), true) ? ' selected' : '';
							echo '<option value="' . esc_attr ($opt) . '"' . $sel . '>' . esc_html ($opt) . '</option>';
						}
						echo '</select></label>';
						break;

					default:
						// text | email | tel | date ...
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<input type="' . esc_attr ($type) . '"' . ' id="' . esc_attr ($id) . '"' . ' name="' . esc_attr ($postName) . '"' . ' value="' . esc_attr (is_string ($value) ? $value : '') . '" ' . '>' . '</label>';
						break;
				}

				echo '</div>';
			}
		}
		?>
            </div>

            <div class="zg-form-footer">
                <button type="submit" name="zg_register_submit" class="button button-primary">
                    <?=esc_html_e ('Crear cuenta', 'zentrygate');?>
                </button>

                <p class="zg-auth-links">
                    <a href="<?=esc_url (add_query_arg ('zg_action', 'login'));?>">
                        <?=esc_html_e ('¿Ya tienes cuenta? Inicia sesión', 'zentrygate');?>
                    </a>
                    &nbsp;·&nbsp;
                    <a href="<?=esc_url (add_query_arg ('zg_action', 'pass_recovery'));?>">
                        <?=esc_html_e ('¿Olvidaste tu contraseña?', 'zentrygate');?>
                    </a>
                </p>
            </div>
        </form>
        <?php
	}


	// Captura campos del form de registro (SIN contraseñas)
	protected static function captureRegisterOldInput (): array
	{
		$old = [ ];
		$old ['name'] = sanitize_text_field (wp_unslash ($_POST ['name'] ?? ''));
		$old ['email'] = sanitize_email (wp_unslash ($_POST ['email'] ?? ''));
		$old ['other'] = [ ];

		if (isset ($_POST ['other']) && is_array ($_POST ['other']))
		{
			foreach ($_POST ['other'] as $k => $v)
			{
				if (is_array ($v))
				{
					$old ['other'] [$k] = array_map (static fn ($x) => sanitize_text_field (wp_unslash ((string) $x)), $v);
				}
				else
				{
					$old ['other'] [$k] = sanitize_text_field (wp_unslash ((string) $v));
				}
			}
		}
		return $old;
	}


	public static function handleRegisterPostEntryPoint (): void
	{
		$ok = self::handleRegisterPost (); // valida nonce, inserta, envía email...
		$redirect = isset ($_POST ['redirect_to']) ? esc_url_raw (wp_unslash ($_POST ['redirect_to'])) : home_url ('/');
		if ($ok)
		{
			$url = add_query_arg ([ 'zg_action' => 'register', 'zg_notice' => 'check_email'], $redirect);
		}
		else
		{
			$errkey = self::flashSet ('zg_err_', self::$lastErrors);
			$oldkey = self::flashSet ('zg_old_', self::captureRegisterOldInput ());
			$url = add_query_arg ([ 'zg_action' => 'register', 'zg_notice' => 'errors', 'errkey' => $errkey, 'oldkey' => $oldkey], $redirect);
		}
		wp_safe_redirect ($url);
		exit ();
	}


	/**
	 * Procesa el POST del registro.
	 * Devuelve true si el usuario se creó (y se intentó enviar el email); false si hubo errores.
	 */
	public static function handleRegisterPost (): bool
	{
		self::$lastErrors = [ ];

		// 1) Nonce
		$nonce = isset ($_POST ['zg_register_nonce']) ? wp_unslash ($_POST ['zg_register_nonce']) : '';
		if (! $nonce || ! wp_verify_nonce ($nonce, 'zg_register_action'))
		{
			self::$lastErrors [] = __ ('La sesión ha caducado. Por favor, recarga la página e inténtalo de nuevo.', 'zentrygate');
			return false;
		}

		// 1.1) Honeypot (campo oculto)
		if (isset ($_POST ['zg_hp_name']) && trim ((string) $_POST ['zg_hp_name']) !== '')
		{
			// Campo oculto rellenado: bot
			self::$lastErrors [] = __ ('Error en el formulario. Por favor, recarga la página e inténtalo de nuevo.', 'zentrygate');
			return true; // Decimos que se ha enviado un email para no dar pistas al bot
		}

		// 2) Campos mínimos
		$name = isset ($_POST ['name']) ? sanitize_text_field (wp_unslash ($_POST ['name'])) : '';
		$email = isset ($_POST ['email']) ? sanitize_email (wp_unslash ($_POST ['email'])) : '';
		$password = (string) ($_POST ['password'] ?? '');
		$password2 = (string) ($_POST ['password2'] ?? '');

		if ($name === '')
		{
			self::$lastErrors [] = __ ('El nombre es obligatorio.', 'zentrygate');
		}
		if (! is_email ($email))
		{
			self::$lastErrors [] = __ ('Debes indicar un correo electrónico válido.', 'zentrygate');
		}
		if ($password === '' || $password2 === '')
		{
			self::$lastErrors [] = __ ('Debes introducir y confirmar la contraseña.', 'zentrygate');
		}
		elseif ($password !== $password2)
		{
			self::$lastErrors [] = __ ('Las contraseñas no coinciden.', 'zentrygate');
		}

		// YAGNI: Validación de fortaleza de la contraseña (opcional)

		// 3) Validar campos requeridos del JSON (self::$schemaJson)
		$schema = self::getRegisterSchemaArray ();
		$otherRaw = (isset ($_POST ['other']) && is_array ($_POST ['other'])) ? $_POST ['other'] : [ ];
		$otherClean = [ ];

		foreach ($schema as $cfg)
		{
			if (! is_array ($cfg))
			{
				continue;
			}
			$label = isset ($cfg ['name']) ? (string) $cfg ['name'] : '';
			$tag = isset ($cfg ['tag']) ? (string) $cfg ['tag'] : '';
			$type = isset ($cfg ['type']) ? (string) $cfg ['type'] : 'text';
			$req = ! empty ($cfg ['required']);

			if ($label === '' || $tag === '')
			{
				continue;
			}

			$val = $otherRaw [$tag] ?? null;

			switch ($type)
			{
				case 'multiselect':
					$vals = is_array ($val) ? array_map ('wp_unslash', $val) : [ ];
					$vals = array_map ('sanitize_text_field', $vals);
					if ($req && count (array_filter ($vals, static fn ($v) => trim ((string) $v) !== '')) === 0)
					{
						self::$lastErrors [] = sprintf (__ ('El campo "%s" es obligatorio.', 'zentrygate'), $label);
					}
					$otherClean [$tag] = $vals;
					break;

				case 'checkbox':
					$checked = isset ($otherRaw [$tag]) && ($otherRaw [$tag] === '1' || $otherRaw [$tag] === 'on');
					if ($req && ! $checked)
					{
						self::$lastErrors [] = sprintf (__ ('Debes marcar "%s".', 'zentrygate'), $label);
					}
					$otherClean [$tag] = $checked ? 1 : 0;
					break;

				case 'textarea':
					$s = is_string ($val) ? wp_kses_post (wp_unslash ($val)) : '';
					if ($req && $s === '')
					{
						self::$lastErrors [] = sprintf (__ ('El campo "%s" es obligatorio.', 'zentrygate'), $label);
					}
					$otherClean [$tag] = $s;
					break;

				default:
					$s = is_string ($val) ? sanitize_text_field (wp_unslash ($val)) : '';
					if ($req && $s === '')
					{
						self::$lastErrors [] = sprintf (__ ('El campo "%s" es obligatorio.', 'zentrygate'), $label);
					}
					$otherClean [$tag] = $s;
					break;
			}
		}

		if (! empty (self::$lastErrors))
		{
			return false;
		}

		// 4) Email único
		global $wpdb;
		$table = $wpdb->prefix . 'zgUsers';
		$exists = $wpdb->get_var ($wpdb->prepare ("SELECT id FROM {$table} WHERE email = %s AND deletedAt IS NULL LIMIT 1", $email));
		if ($exists)
		{
			self::$lastErrors [] = __ ('Ya existe una cuenta con ese correo electrónico.', 'zentrygate');
			return false;
		}

		// 5) Preparar inserción
		$passwordHash = password_hash ($password, PASSWORD_DEFAULT);
		$verifyToken = bin2hex (random_bytes (32));
		$unsubscribeToken = bin2hex (random_bytes (32));
		$status = 'active'; // hoy activo, en el futuro podrás usar 'pending'
		$isEnabled = 0; // no habilitado hasta verificar email
		$otherJson = wp_json_encode ($otherClean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$data = [ 'email' => $email, 'name' => $name, 'passwordHash' => $passwordHash, 'status' => $status, 'isAdmin' => 0, 'isEnabled' => $isEnabled, 'otherData' => $otherJson, 'verifyToken' => $verifyToken, 'unsubscribeToken' => $unsubscribeToken, 'failedLoginCount' => 0 // emailVerifiedAt = NULL por defecto
		                                                                                                                                                                                                                                                                           // lockedUntil = NULL por defecto
		];
		$format = [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d'];

		$ok = $wpdb->insert ($table, $data, $format);
		if (! $ok)
		{
			// Mensaje genérico + detalle técnico en debug si quieres
			self::$lastErrors [] = __ ('No se pudo crear la cuenta en este momento. Inténtalo más tarde.', 'zentrygate');
			if (! empty ($wpdb->last_error))
			{
				// error técnico opcional para log
				error_log ('[ZentryGate] handleRegisterPost insert error: ' . $wpdb->last_error);
			}
			return false;
		}

		// 6) Enviar email de verificación
		$verifyUrl = $_POST ['redirect_to'] ?? get_permalink ();
		$verifyUrl = esc_url_raw ($verifyUrl);

		$emailEncoded = rtrim (strtr (base64_encode ($email), '+/', '-_'), '=');

		$verifyUrl = add_query_arg ([ 'zg_action' => 'verify', 'token' => $verifyToken, 'e' => $emailEncoded], $verifyUrl);

		$blogname = wp_specialchars_decode (get_bloginfo ('name'), ENT_QUOTES);
		$subject = sprintf (__ ('Confirma tu cuenta en %s', 'zentrygate'), $blogname);

		// Cuerpo HTML sencillo
		$body = '<p>' . sprintf (__ ('Hola %s,', 'zentrygate'), esc_html ($name)) . '</p>';
		$body .= '<p>' . esc_html__ ('Gracias por registrarte. Para activar tu cuenta, confirma tu correo haciendo clic en el siguiente enlace:', 'zentrygate') . '</p>';
		$body .= '<p><a href="' . esc_url ($verifyUrl) . '">' . esc_html ($verifyUrl) . '</a></p>';
		$body .= '<p>' . esc_html__ ('Si no has solicitado esta cuenta, puedes ignorar este mensaje.', 'zentrygate') . '</p>';

		$headers = [ 'Content-Type: text/html; charset=UTF-8'];

		$sent = wp_mail ($email, $subject, $body, $headers);

		// Nota: si el email falla, el usuario queda creado pero no habilitado.
		// Puedes ofrecer una acción “reenviar verificación” desde la UI.
		if (! $sent)
		{
			error_log ('[ZentryGate] handleRegisterPost: fallo al enviar email de verificación a ' . $email);
			// No lo marcamos como error bloqueante para evitar duplicidades de alta.
			// Tu pantalla de success puede mostrar "Te hemos enviado un email... Si no lo recibes, podrás reenviarlo."
		}

		return true;
	}
}
