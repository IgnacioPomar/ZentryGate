<?php

namespace ZentryGate;

/**
 * Handles authentication, cookie consent, and session management for ZentryGate plugin.
 */
class Auth
{
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


	/**
	 * Load and decode cookie data.
	 */
	private static function loadCookieData (): void
	{
		if (! empty ($_COOKIE ['ZentryGate']))
		{
			$decodedCookie = base64_decode (str_pad (strtr ($_COOKIE ['ZentryGate'], '-_', '+/'), strlen ($_COOKIE ['ZentryGate']) % 4, '=', STR_PAD_RIGHT));
			$data = json_decode ($decodedCookie, true);
			if (is_array ($data))
			{
				self::$cookieData = $data;
			}
		}
	}


	private static function saveCookieData (): void
	{
		$cookieContent = rtrim (strtr (base64_encode (json_encode (self::$cookieData)), '+/', '-_'), '=');
		setcookie ('ZentryGate', $cookieContent, time () + 365 * 24 * 60 * 60, "/");
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
			if (isset ($_POST ['zg_email'], $_POST ['zg_password']))
			{
				if (self::checkLoginForm ())
				{
					// Login successful: create session file and set cookie
					$nonce = bin2hex (random_bytes (16));
					$fileId = bin2hex (random_bytes (16));

					$cookieDta = &self::$cookieData;
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
			else
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
    <form method="post" class="zg-cookie-form" aria-labelledby="zg-cookie-title">
        <?php

		wp_nonce_field ('zg_cookie_consent_action', 'zg_cookie_consent_nonce');
		?>
        <div class="zg-form-header">
            <h2 id="zg-cookie-title"><?=esc_html_e ('Asistencia a las jornadas: cookie necesaria', 'zentrygate');?></h2>
        </div>
        <div class="zg-form-body">
            <p><?=esc_html_e ('El sistema de reserva de las jornadas utiliza una cookie necesaria, para mantener la sesión y hacer más fluida la navegación. Por favor, acepta su uso para poder reservar tu asistencia.', 'zentrygate');?></p>
        </div>
        <div class="zg-form-footer">
            <button type="submit" name="accept_ZentryGate_cookie" class="button button-primary">
                <?=esc_html_e ('Aceptar cookie de reservas', 'zentrygate');?>
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
    <form method="post" class="zg-login-form" aria-labelledby="zg-login-title">
        <?=wp_nonce_field ('zg_login_action', 'zg_login_nonce');?>
        <div class="zg-form-header">
            <h2 id="zg-login-title"><?=esc_html_e ('Iniciar sesión', 'zentrygate');?></h2>
        </div>
        <div class="zg-form-body">
            <p><?=esc_html_e ('Introduce tus credenciales para acceder al sistema de reservas.', 'zentrygate');?></p>
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
                    placeholder="<?=esc_attr_e ('••••••••••', 'zentrygate');?>"
                    required
                    aria-required="true"
                >
            </label>
        </div>
        <div class="zg-form-footer">
            <button type="submit" name="zg_login" class="button button-primary">
                <?=esc_html_e ('Acceder', 'zentrygate');?>
            </button>
            <p class="zg-pass-recovery">
                    <a href="<?=esc_url (add_query_arg ('zg_action', 'pass_recovery'));?>">
                        <?=esc_html_e ('¿Has olvidado tu contraseña?', 'zentrygate');?>
                    </a>
                </p>
        </div>
    </form>
    <?php
	}


	/**
	 * Check if the user is still enabled and load user data.
	 */
	private static function checkUserStillEnabled (string $email): void
	{
		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgUsers WHERE email = %s", $email), ARRAY_A);

		if ($user && $user ['isEnabled'])
		{
			self::$userData = [ 'name' => $user ['name'], 'isAdmin' => (bool) $user ['isAdmin'], 'isEnabled' => (bool) $user ['isEnabled'], 'lastLogin' => current_time ('mysql')];
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

		self::$userData = [ 'name' => $user ['name'], 'isAdmin' => (bool) $user ['isAdmin'], 'isEnabled' => (bool) $user ['isEnabled'], 'lastLogin' => current_time ('mysql')];

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

		if (! $user || ! self::isResetTokenValid ($user ['resetRequestedAt'], self::RESET_TOKEN_MAX_MINUTES * MINUTE_IN_SECONDS) || $user ['resetToken'] !== $token)
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
    <form method="post" class="zg-recovery-change-password-form" aria-labelledby="zg-change-title">
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
                    placeholder="<?php

esc_attr_e ('••••••••••', 'zentrygate');
		?>"
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
                    placeholder="<?php

esc_attr_e ('••••••••••', 'zentrygate');
		?>"
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
    <form method="get" class="zg-recovery-ask-email-form" aria-labelledby="zg-recovery-title">
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
            <p><?php

		esc_html_e ('Introduce tu correo electrónico para recibir un enlace de recuperación.', 'zentrygate');
		?></p>
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
	private static function isResetTokenValid (?string $resetRequestedAt, int $intervalSec): bool
	{
		if (empty ($resetRequestedAt))
		{
			return false;
		}
		$requested = strtotime ($resetRequestedAt);
		return (time () - $requested) < $intervalSec;
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

		// Rate‐limit: avoid sending too many reset requests
		if (self::isResetTokenValid ($user ['resetRequestedAt'], self::RESET_TOKEN_COOL_DOWN * MINUTE_IN_SECONDS))
		{
			return false;
		}

		// 3. Si el token aún es válido, lo reutilizamos; si no, generamos uno nuevo
		if (self::isResetTokenValid ($user ['resetRequestedAt'], self::RESET_TOKEN_MAX_MINUTES * MINUTE_IN_SECONDS) && ! empty ($user ['resetToken']))
		{
			$token = $user ['resetToken'];
		}
		else
		{
			$token = bin2hex (random_bytes (32));

			// 4. Guardar token + timestamp de solicitud
			$now = current_time ('mysql');
			$updated = $wpdb->update ("{$wpdb->prefix}zgUsers", [ 'resetToken' => $token, 'resetRequestedAt' => $now], [ 'email' => $email], [ '%s', '%s'], [ '%s']);
			if (false === $updated)
			{
				return false;
			}
		}

		// 5. Envío de email
		$reset_link = add_query_arg ([ 'zg_action' => 'pass-recovery', 'zg_recover_email' => rawurlencode ($email), 'token' => $token], get_permalink ());
		$subject = sprintf (__ ('Recupera tu contraseña en %s', 'zentrygate'), wp_specialchars_decode (get_bloginfo ('name'), ENT_QUOTES));
		$message = sprintf (__ ("Hola %1\$s,\n\nHaz clic en este enlace (válido durante %2\$d minutos) para restablecer tu contraseña:\n\n%3\$s\n\nSi no lo solicitaste, ignora este correo.", 'zentrygate'), esc_html ($user ['name']), self::RESET_TOKEN_MAX_MINUTES, esc_url ($reset_link));
		$headers = [ 'Content-Type: text/plain; charset=UTF-8'];

		return (bool) wp_mail ($email, $subject, $message, $headers);
	}
}
