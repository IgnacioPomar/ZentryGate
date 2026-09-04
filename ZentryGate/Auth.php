<?php

namespace ZentryGate;

/**
 * Handles authentication, cookie consent, and session management for ZentryGate plugin.
 * Versión simplificada de cookies:
 * - Presencia de cookie = cookies aceptadas.
 * - Cookie "@" -> aceptadas pero no logueado.
 * - Cookie "nonce@userId" -> se carga el usuario por id (no se valida el nonce).
 */
class Auth
{
	private const COOKIE_NAME = 'ZentryGate_v2';
	private const SESSION_TTL = 30 * DAY_IN_SECONDS;
	private const LOGON_ON_VALIDATE = TRUE;
	public static bool $isEmailVerified = false;
	protected static array $lastErrors = [ ];
	private static string $lastMailStatus = '';
	private static bool $isInitialized = false;

	// ❌ Eliminado: $sessionDir y $cookieData
	// private static string $sessionDir;
	// private static ?array $cookieData = null;

	// ✅ Cookie simplificada como string plano
	private static ?string $cookieRaw = null;
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

		// ❌ Eliminado: sistema de sesiones en disco
		// self::$sessionDir = rtrim(sys_get_temp_dir(), '/') . '/Zentrygate/';
		// if (!is_dir(self::$sessionDir)) { mkdir(self::$sessionDir, 0700, true); }

		self::$cookieRaw = null;
		self::$userData = null;

		self::loadCookieValue ();
		self::processEarlyActions ();
		self::$isInitialized = true;
	}


	/**
	 * Lógica temprana del ciclo:
	 * - Si no hay cookie y hay POST de aceptación -> fija "@"
	 * - Si hay login por POST y credenciales válidas -> fija "nonce@userId"
	 * - Si hay cookie ya presente:
	 * - "@" => aceptadas sin login
	 * - "<algo>@<id>" => carga usuario por id (sin validar nonce)
	 */
	private static function processEarlyActions (): void
	{
		// 1) Aceptación de cookies
		if (self::$cookieRaw === null)
		{
			if (isset ($_POST ['accept_ZentryGate_cookie']))
			{
				self::saveCookieValue ('@');
			}
			return;
		}

		// 2) Login por formulario
		if (isset ($_POST ['zg_email'], $_POST ['zg_password']))
		{
			if (self::checkLoginForm ())
			{
				$userId = (int) (self::$userData ['userId'] ?? 0);
				if ($userId > 0)
				{
					self::saveCookieValue (self::buildSessionCookie ($userId));
				}
				else
				{
					self::saveCookieValue ('@');
				}
			}
			else
			{
				self::$lastErrors [] = __ ('Usuario inexistente o contraseña no valida.', 'zentrygate');
				self::saveCookieValue ('@');
			}
			return;
		}

		// 3) Con cookie presente, intenta cargar usuario si el token de sesión es válido
		$cookie = (string) self::$cookieRaw;

		if ($cookie === '@')
		{
			return; // aceptadas sin login
		}

		$session = self::verifySessionCookie ($cookie);
		if ($session === null)
		{
			return; // token ausente, corrupto, con firma inválida o caducado
		}

		self::loadUserById ($session ['userId']); // rellena self::$userData si existe y está habilitado

		// Renovación deslizante: si queda menos de la mitad del TTL, reemitir el token
		if (self::$userData !== null && $session ['expires'] - time () < self::SESSION_TTL / 2)
		{
			self::saveCookieValue (self::buildSessionCookie ($session ['userId']));
		}
	}


	/**
	 * Clave usada para firmar el token de sesión. Deriva de las claves de
	 * WordPress (wp_salt), con un prefijo propio para separar el uso de esta
	 * clave del resto de cookies/nonces que WordPress firma con la misma sal.
	 */
	private static function getCookieSecret (): string
	{
		return wp_salt ('auth') . '|zentrygate_session_v1';
	}


	/**
	 * Construye un token de sesión firmado: "{userId}|{expira}|{hmac}".
	 * El hmac impide falsificar el userId o alargar la expiración sin conocer
	 * la clave del servidor.
	 */
	private static function buildSessionCookie (int $userId, int $ttl = self::SESSION_TTL): string
	{
		$expires = time () + $ttl;
		$payload = $userId . '|' . $expires;
		$hmac = hash_hmac ('sha256', $payload, self::getCookieSecret ());
		return $payload . '|' . $hmac;
	}


	/**
	 * Verifica un token de sesión firmado. Devuelve ['userId'=>int,'expires'=>int]
	 * si la firma es válida y no ha caducado, o null en cualquier otro caso.
	 */
	private static function verifySessionCookie (string $cookie): ?array
	{
		$parts = explode ('|', $cookie, 3);
		if (count ($parts) !== 3)
		{
			return null;
		}

		[ $idPart, $expiresPart, $hmac] = $parts;
		if (! ctype_digit ($idPart) || ! ctype_digit ($expiresPart))
		{
			return null;
		}

		$userId = (int) $idPart;
		$expires = (int) $expiresPart;
		if ($userId <= 0 || $expires <= time ())
		{
			return null;
		}

		$payload = $idPart . '|' . $expiresPart;
		$expectedHmac = hash_hmac ('sha256', $payload, self::getCookieSecret ());
		if (! hash_equals ($expectedHmac, $hmac))
		{
			return null;
		}

		return [ 'userId' => $userId, 'expires' => $expires];
	}


	/**
	 * Decodifica un email codificado en base64 URL-safe (usado en enlaces de email).
	 */
	private static function decodeUrlSafeEmail (string $b64): ?string
	{
		$b64 = strtr ($b64, '-_', '+/');
		$pad = strlen ($b64) % 4;
		if ($pad) $b64 .= str_repeat ('=', 4 - $pad);
		$email = base64_decode ($b64, true);
		return $email !== false ? $email : null;
	}


	/**
	 * Verificación de email (sin cambios de fondo).
	 */
	public static function handleEmailVerification (): bool
	{
		if (! isset ($_GET ['e'], $_GET ['token']))
		{
			return false;
		}

		$eParam = isset ($_GET ['e']) ? (string) wp_unslash ($_GET ['e']) : '';
		$email = self::decodeUrlSafeEmail ($eParam);
		if ($email === null)
		{
			return false;
		}

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
	 * Traza de los envíos de correo. Guarda además el último motivo para poder mostrarlo
	 * a un administrador, ya que el aviso público es neutro y oculta los fallos.
	 */
	private static function logMail (string $event, array $ctx = [ ]): void
	{
		self::$lastMailStatus = $event;
		error_log ('[ZentryGate][mail] ' . $event . ($ctx ? ' ' . wp_json_encode ($ctx, JSON_UNESCAPED_UNICODE) : ''));
	}


	/**
	 * Enganchado a 'wp_mail_failed': WP Mail SMTP publica ahí el error real de PHPMailer/SMTP.
	 */
	public static function logMailFailure (\WP_Error $error): void
	{
		$data = $error->get_error_data ();
		error_log ('[ZentryGate][mail] wp_mail_failed ' . $error->get_error_message () . ' ' . wp_json_encode (is_array ($data) ? $data : [ 'data' => $data], JSON_UNESCAPED_UNICODE));
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
	 * Carga el valor plano de la cookie (string).
	 */
	private static function loadCookieValue (): void
	{
		$raw = $_COOKIE [self::COOKIE_NAME] ?? null;
		self::$cookieRaw = is_string ($raw) ? $raw : null;
	}


	/**
	 * Guarda la cookie como string plano.
	 */
	private static function saveCookieValue (string $value): void
	{
		$siteHost = wp_parse_url (home_url (), PHP_URL_HOST);

		$options = [ 'expires' => time () + 365 * 24 * 60 * 60, // 1 año
		'path' => '/', 'domain' => $siteHost, 'secure' => is_ssl (), 'httponly' => true, 'samesite' => 'Lax'];

		// IMPORTANTE: no debe haberse enviado salida antes de esto
		setcookie (self::COOKIE_NAME, $value, $options);
		self::$cookieRaw = $value;
	}


	/**
	 * Check if the ZentryGate cookie is accepted.
	 * Ahora: presencia de cookie = aceptadas.
	 */
	public static function isCookieAccepted (): bool
	{
		if (self::$cookieRaw === null)
		{
			self::loadCookieValue ();
		}
		return self::$cookieRaw !== null;
	}


	/**
	 * Check if we have a valid user session (loaded by cookie or by form).
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
		?>
        <form method="post" class="zg-cookie-form" aria-labelledby="zg-cookie-title" action="<?=PLugin::$permalink?>">
            <?php
		wp_nonce_field ('zg_cookie_consent_action', 'zg_cookie_consent_nonce');
		?>
            <div class="zg-form-header">
                <div class="zg-form-eyebrow"><?=esc_html__ ('Aviso de cookies', 'zentrygate');?></div>
                <h2 id="zg-cookie-title"><?=esc_html__ ('Uso de cookies', 'zentrygate');?></h2>
            </div>
            <div class="zg-form-body">
                <?php
		$page_id = intval (get_option ('zg_cookie_prompt_page'));
		$buttonText = "Continuar";
		if ($page_id)
		{
			echo apply_filters ('the_content', get_post_field ('post_content', $page_id));
			$buttonText = get_the_title ($page_id);
		}
		else
		{
			echo '<p>' . esc_html__ ('Por favor, acepta el uso de cookies para poder reservar tu asistencia a las jornadas.', 'zentrygate') . '</p>';
		}
		?>
            </div>
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
            <?php
		wp_nonce_field ('zg_login_action', 'zg_login_nonce');
		?>
            <div class="zg-form-header">
                <div class="zg-form-eyebrow"><?=esc_html__ ('Acceso', 'zentrygate');?></div>
                <h2 id="zg-login-title"><?=esc_html__ ('Inicia sesión', 'zentrygate');?></h2>
            </div>
            <?php
		if (! empty (self::$lastErrors))
		{
			self::renderErrors (self::$lastErrors);
		}
		?>
            <div class="zg-form-body">
                <?php
		$page_id = intval (get_option ('zg_login_form_page'));
		if ($page_id)
		{
			echo apply_filters ('the_content', get_post_field ('post_content', $page_id));
		}
		else
		{
			echo '<p>' . esc_html__ ('Por favor, inicia sesión para acceder al sistema de reservas.', 'zentrygate') . '</p>';
		}
		?>
                <label for="zg_email">
                    <?=esc_html__ ('Correo electrónico', 'zentrygate');?>
                    <input
                        type="email"
                        id="zg_email"
                        name="zg_email"
                        placeholder="<?=esc_attr__ ('ejemplo@correo.com', 'zentrygate');?>"
                        required
                        aria-required="true"
                    >
                </label>
                <br>
                <label for="zg_password">
                    <?=esc_html__ ('Contraseña', 'zentrygate');?>
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
                    <?=esc_html__ ('Acceder', 'zentrygate');?>
                </button>
                <p class="zg-auth-links">
                    <a class="zg-pass-recovery" href="<?=esc_url (add_query_arg ('zg_action', 'pass_recovery', PLugin::$permalink));?>">
                        <?=esc_html__ ('¿Has olvidado tu contraseña?', 'zentrygate');?>
                    </a>
                    &nbsp;·&nbsp;
                    <a class="zg-register" href="<?=esc_url (add_query_arg ('zg_action', 'register', PLugin::$permalink));?>">
                        <?=esc_html__ ('¿No tienes cuenta? Regístrate', 'zentrygate');?>
                    </a>
                </p>
            </div>
        </form>
        <?php
	}


	/**
	 * Rellena self::$userData con los datos de usuario.
	 */
	private static function fillUserData (array $user): void
	{
		self::$userData = [ 'name' => $user ['name'], 'userId' => $user ['id'], 'email' => $user ['email'], 'isAdmin' => (bool) $user ['isAdmin'], 'isEnabled' => (bool) $user ['isEnabled'], 'lastLogin' => current_time ('mysql')];
	}


	/**
	 * Conservado por compatibilidad: carga por email y comprueba enabled.
	 */
	private static function checkUserStillEnabled (string $email): void
	{
		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgUsers WHERE email = %s", $email), ARRAY_A);

		if ($user && $user ['isEnabled'])
		{
			self::fillUserData ($user);
		}
		else
		{
			self::$userData = null;
		}
	}


	/**
	 * NUEVO: carga por id (para el flujo de cookie "<nonce>@<id>").
	 */
	private static function loadUserById (int $userId): void
	{
		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgUsers WHERE id = %d", $userId), ARRAY_A);

		if ($user && ! empty ($user ['isEnabled']))
		{
			self::fillUserData ($user);
		}
		else
		{
			self::$userData = null;
		}
	}


	/**
	 * Check login credentials from form and store session if successful.
	 * (Ahora solo rellena self::$userData; la cookie se gestiona en processEarlyActions()).
	 */
	private static function checkLoginForm (): bool
	{
		if ($_SERVER ['REQUEST_METHOD'] !== 'POST' || ! isset ($_POST ['zg_email'], $_POST ['zg_password']))
		{
			return false;
		}

		if (! isset ($_POST ['zg_login_nonce']) || ! wp_verify_nonce (wp_unslash ($_POST ['zg_login_nonce']), 'zg_login_action'))
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
	private const RESET_TOKEN_RESEND_GUARD_SEC = 20;


	private static function processRecoveryChangePassword (): bool
	{
		self::$lastErrors = [ ];

		if ($_SERVER ['REQUEST_METHOD'] !== 'POST' || ! isset ($_POST ['zg_recover_email'], $_POST ['zg_new_password'], $_POST ['zg_confirm_password'], $_POST ['token']))
		{
			self::$lastErrors [] = __ ('Error en el formulario. Por favor, solicita un nuevo enlace.', 'zentrygate');
			return false;
		}

		if (! isset ($_POST ['zg_pass_reset_nonce']) || ! wp_verify_nonce (wp_unslash ($_POST ['zg_pass_reset_nonce']), 'zg_pass_reset_action'))
		{
			self::$lastErrors [] = __ ('Error en el formulario. Por favor, recarga la página e inténtalo de nuevo.', 'zentrygate');
			return false;
		}

		$email = self::decodeUrlSafeEmail ((string) wp_unslash ($_POST ['zg_recover_email']));
		$token = sanitize_text_field (wp_unslash ($_POST ['token']));
		$newPassword = (string) $_POST ['zg_new_password'];
		$confirmPassword = (string) $_POST ['zg_confirm_password'];

		if ($email === null || self::findUserForPasswordReset ($email, $token) === null)
		{
			self::$lastErrors [] = __ ('El enlace de cambio de contraseña no es válido o ha expirado.', 'zentrygate');
			return false;
		}

		if ($newPassword === '' || $confirmPassword === '')
		{
			self::$lastErrors [] = __ ('Debes introducir y confirmar la contraseña.', 'zentrygate');
			return false;
		}

		if ($newPassword !== $confirmPassword)
		{
			self::$lastErrors [] = __ ('Las contraseñas no coinciden.', 'zentrygate');
			return false;
		}

		global $wpdb;
		$hashedPassword = password_hash ($newPassword, PASSWORD_DEFAULT);
		$updated = $wpdb->update ("{$wpdb->prefix}zgUsers", [ 'passwordHash' => $hashedPassword, 'resetToken' => null, 'resetRequestedAt' => null], [ 'email' => $email], [ '%s', '%s', '%s'], [ '%s']);

		return (bool) $updated;
	}


	/**
	 * Renderiza el formulario para establecer una nueva contraseña,
	 * usando los parámetros GET 'zg_recover_email' (base64 URL-safe) y 'token'.
	 */
	public static function renderPasswordResetForm (): void
	{
		$email = isset ($_GET ['zg_recover_email']) ? (string) wp_unslash ($_GET ['zg_recover_email']) : '';
		$token = isset ($_GET ['token']) ? sanitize_text_field (wp_unslash ($_GET ['token'])) : '';
		// Como en el enlace del correo: get_permalink () devuelve la página padre cuando el
		// plugin se renderiza embebido, y ahí el aviso de éxito o de error no llegaría a verse.
		$redirectBase = ! empty (Plugin::$permalink) ? Plugin::$permalink : get_permalink ();
		$redirectTo = esc_url (add_query_arg ([ 'zg_action' => 'pass-reset'], $redirectBase));
		$actionUrl = esc_url (admin_url ('admin-post.php'));

		$errors = self::flashTake ('zg_err_', $_GET ['errkey'] ?? '');
		if (! empty ($errors))
		{
			self::renderErrors ($errors);
		}
		?>
        <form method="post" class="zg-recovery-change-password-form" aria-labelledby="zg-change-title" action="<?=$actionUrl?>">
            <input type="hidden" name="action" value="zg_password_reset">
            <input type="hidden" name="redirect_to" value="<?=$redirectTo;?>">
            <?php
		// Mantener email (b64) y token en POST
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
				echo '<p class="error">' . esc_html__ ('El correo electrónico proporcionado no es válido.', 'zentrygate') . '</p>';
				return false;
			}

			// El aviso posterior es siempre neutro para no revelar si el email existe; el motivo
			// real del envío queda en el log y solo se muestra a administradores.
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
		// El formulario de registro ya revela si un correo está dado de alta ("Ya existe una
		// cuenta con ese correo electrónico"), así que ocultarlo aquí no protegía de nada y
		// dejaba al usuario sin saber que el problema era no estar inscrito.
		if (self::$lastMailStatus === 'no_user')
		{
			self::renderRecoveryUnknownEmail ();
			return;
		}

		?>
        <div class="zg-notice zg-notice-success" role="alert" aria-live="polite" aria-labelledby="zg-recovery-requested-title">
            <strong id="zg-recovery-requested-title"><?=esc_html__ ('Solicitud de recuperación enviada', 'zentrygate');?></strong>
            <p><?=esc_html__ ('Si la cuenta está activa, recibirás en breve un correo con el enlace para restablecer tu contraseña. Revisa también la carpeta de spam.', 'zentrygate');?></p>
            <p class="zg-auth-links"><a href="<?=esc_url (add_query_arg ('zg_action', 'login', Plugin::$permalink));?>"><?=esc_html__ ('Ir a pantalla de login', 'zentrygate');?></a></p>
            <?php
		self::renderMailDebugNotice ();
		?>
        </div>
        <?php
	}


	/**
	 * Motivo real del último intento de envío, solo para administradores o con ZG_MAIL_DEBUG:
	 * el aviso de arriba es deliberadamente neutro y oculta cualquier fallo.
	 */
	public static function renderMailDebugNotice (): void
	{
		if (self::$lastMailStatus === '')
		{
			return;
		}

		if (! (defined ('ZG_MAIL_DEBUG') && ZG_MAIL_DEBUG) && ! current_user_can ('manage_options'))
		{
			return;
		}

		echo '<p class="zg-mail-debug"><code>[ZentryGate] ' . esc_html (self::$lastMailStatus) . '</code></p>';
	}

	/**
	 * Aviso cuando el correo no corresponde a ninguna cuenta: se le ofrece darse de alta.
	 */
	public static function renderRecoveryUnknownEmail (): void
	{
		?>
        <div class="zg-notice zg-notice-warn" role="alert" aria-live="assertive" aria-labelledby="zg-recovery-unknown-title">
            <strong id="zg-recovery-unknown-title"><?=esc_html__ ('No hay ninguna cuenta con ese correo electrónico', 'zentrygate');?></strong>
            <p><?=esc_html__ ('Comprueba que lo has escrito bien. Si todavía no te has inscrito, puedes crear una cuenta ahora.', 'zentrygate');?></p>
            <p class="zg-auth-links">
                <a href="<?=esc_url (add_query_arg ('zg_action', 'register', Plugin::$permalink));?>"><?=esc_html__ ('Crear una cuenta', 'zentrygate');?></a>
                <a href="<?=esc_url (add_query_arg ('zg_action', 'pass_recovery', Plugin::$permalink));?>"><?=esc_html__ ('Probar con otro correo', 'zentrygate');?></a>
                <a href="<?=esc_url (add_query_arg ('zg_action', 'login', Plugin::$permalink));?>"><?=esc_html__ ('Ir a pantalla de login', 'zentrygate');?></a>
            </p>
            <?php
		self::renderMailDebugNotice ();
		?>
        </div>
        <?php
	}



	/**
	 * Render the password recovery form to ask for the email.
	 * (handleRecoveryGet ya procesa el caso en que venga 'zg_recover_email' en el GET,
	 * así que aquí solo queda pintar el formulario para pedirlo.)
	 */
	public static function renderRecoveryForm (): void
	{
		self::renderRecoveryAskEmailForm ();
	}


	/**
	 * Renderiza el formulario para pedir el email de recuperación.
	 */
	public static function renderRecoveryAskEmailForm (): void
	{
		?>
        <form method="get" class="zg-recovery-ask-email-form" aria-labelledby="zg-recovery-title" action="<?=PLugin::$permalink?>">
            <input type="hidden" name="zg_action" value="pass_recovery">
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
                <button type="submit" class="button button-primary">
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
	 */
	private static function isStillValidToken (?string $resetRequestedAt, int $intervalSec): bool
	{
		if (empty ($resetRequestedAt))
		{
			return false;
		}
		// resetRequestedAt se guarda con current_time ('mysql'), o sea en hora del sitio.
		// strtotime () lo interpretaría como UTC (WordPress fija esa zona en PHP), lo que adelanta
		// la marca tantas horas como el gmt_offset y produce intervalos negativos: con Europe/Madrid
		// cualquier intervalo se daba por vigente durante las 2 h siguientes a la solicitud.
		$requested = (int) get_gmt_from_date ($resetRequestedAt, 'U');
		$elapsed = time () - $requested;

		// Una marca en el futuro (reloj desajustado, edición manual en BD) daría un intervalo
		// negativo, es decir vigente para siempre: la damos por no válida.
		return $elapsed >= 0 && $elapsed < $intervalSec;
	}


	/**
	 * Busca el usuario habilitado cuyo token de reseteo de contraseña coincide y sigue vigente.
	 */
	private static function findUserForPasswordReset (string $email, string $token): ?array
	{
		global $wpdb;
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT email, isEnabled, resetToken, resetRequestedAt
               FROM {$wpdb->prefix}zgUsers
              WHERE email = %s", $email), ARRAY_A);

		if (! $user || ! (bool) $user ['isEnabled'] || ! self::isStillValidToken ($user ['resetRequestedAt'], self::RESET_TOKEN_MAX_MINUTES * MINUTE_IN_SECONDS) || $user ['resetToken'] !== $token)
		{
			return null;
		}

		return $user;
	}


	public static function isValidResetToken (): bool
	{
		if (! isset ($_GET ['zg_recover_email'], $_GET ['token']))
		{
			return false;
		}

		$email = self::decodeUrlSafeEmail ((string) wp_unslash ($_GET ['zg_recover_email']));
		$token = sanitize_text_field (wp_unslash ($_GET ['token']));

		return $email !== null && self::findUserForPasswordReset ($email, $token) !== null;
	}


	/**
	 * Genera o reenvía el token de recuperación y envía el email.
	 * El aviso que ve el visitante es siempre neutro, así que aquí se deja traza (self::logMail)
	 * de cada salida: sin ella un fallo de envío es indistinguible de un éxito.
	 */
	public static function sendPasswordResetToken (string $email): bool
	{
		global $wpdb;

		// Obtener usuario y saber si ya había un token válido
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT email, name, isEnabled, resetToken, resetRequestedAt
               FROM {$wpdb->prefix}zgUsers
              WHERE email = %s", $email), ARRAY_A);

		if (! $user)
		{
			self::logMail ('no_user', [ 'email' => $email]);
			return false;
		}

		if (! (bool) $user ['isEnabled'])
		{
			self::logMail ('disabled', [ 'email' => $email]);
			return false;
		}

		// Guarda dura contra flood: doble clic o recarga inmediata del formulario
		if (self::isStillValidToken ($user ['resetRequestedAt'], self::RESET_TOKEN_RESEND_GUARD_SEC))
		{
			self::logMail ('flood_guard', [ 'email' => $email, 'resetRequestedAt' => $user ['resetRequestedAt']]);
			return false;
		}

		if (! empty ($user ['resetToken']) && self::isStillValidToken ($user ['resetRequestedAt'], self::RESET_TOKEN_COOL_DOWN * MINUTE_IN_SECONDS))
		{
			// Dentro del cooldown reenviamos el token vigente en lugar de descartar la petición
			// en silencio: si el primer envío falló, el usuario tiene que poder reintentar.
			$token = $user ['resetToken'];
			self::logMail ('resend_existing_token', [ 'email' => $email, 'resetRequestedAt' => $user ['resetRequestedAt']]);
		}
		else
		{
			// Token nuevo
			$token = bin2hex (random_bytes (32));

			// Guardar token + timestamp de solicitud
			$now = current_time ('mysql');
			$updated = $wpdb->update ("{$wpdb->prefix}zgUsers", [ 'resetToken' => $token, 'resetRequestedAt' => $now], [ 'email' => $email], [ '%s', '%s'], [ '%s']);
			if (false === $updated)
			{
				self::logMail ('db_update_failed', [ 'email' => $email, 'dbError' => $wpdb->last_error]);
				return false;
			}
		}

		// Envío de email
		$emailEncoded = rtrim (strtr (base64_encode ($email), '+/', '-_'), '=');

		// get_permalink () devuelve la portada cuando la plantilla renderiza el plugin embebido,
		// así que usamos el permalink que la propia plantilla nos ha pasado.
		$base = ! empty (Plugin::$permalink) ? Plugin::$permalink : get_permalink ();
		$reset_link = add_query_arg ([ 'zg_action' => 'pass-reset', 'zg_recover_email' => $emailEncoded, 'token' => $token], $base);
		$subject = sprintf (__ ('Recupera tu contraseña en %s', 'zentrygate'), wp_specialchars_decode (get_bloginfo ('name'), ENT_QUOTES));

		// El cuerpo es text/plain: nada de esc_html () ni esc_url (), que introducen entidades HTML
		// (esc_url convierte los '&' del enlace en '&#038;' y rompe los parámetros del token).
		$message = sprintf (__ ("Hola %1\$s,\n\nHaz clic en este enlace (válido durante %2\$d minutos) para restablecer tu contraseña:\n\n%3\$s\n\nSi no lo solicitaste, ignora este correo.", 'zentrygate'), $user ['name'], self::RESET_TOKEN_MAX_MINUTES, esc_url_raw ($reset_link));

		// Sin cabecera From: WP Mail SMTP impone su propio remitente (y el mailer de Gmail obliga
		// a que sea la cuenta autenticada), así que fijarlo aquí solo crearía un conflicto.
		$headers = [ 'Content-Type: text/plain; charset=UTF-8'];

		if (! wp_mail ($email, $subject, $message, $headers))
		{
			self::logMail ('wp_mail_false', [ 'email' => $email]);
			return false;
		}

		self::logMail ('sent', [ 'email' => $email]);
		return true;
	}


	public static function renderAskUserCheckEmail (): void
	{
		?>
        <div class="zg-notice zg-notice-success" role="alert" aria-live="polite" aria-labelledby="zg-check-email-title">
            <strong id="zg-check-email-title">
                <?php

esc_html_e ('Registro guardado', 'zentrygate');
		?>
            </strong>
            <p>
                <?php

esc_html_e ('Registro completado. Es necesario validar el login.', 'zentrygate');
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
		$extraFields = self::getRegisterSchemaArray ();

		$isChecked = function (string $name): bool
		{
			return isset ($_POST [$name]) && ($_POST [$name] === '1' || $_POST [$name] === 'on');
		};

		// Como en el enlace del correo: get_permalink () devuelve la página padre cuando el
		// plugin se renderiza embebido, y ahí el aviso de éxito o de error no llegaría a verse.
		$redirectBase = ! empty (Plugin::$permalink) ? Plugin::$permalink : get_permalink ();
		$redirectTo = esc_url (add_query_arg ([ 'zg_action' => 'register'], $redirectBase));
		$actionUrl = esc_url (admin_url ('admin-post.php'));
		?>
        <form method="post" class="zg-register-form" aria-labelledby="zg-register-title" novalidate action="<?=$actionUrl?>">
            <input type="hidden" name="action" value="zg_register">
            <input type="hidden" name="zg_action" value="register">
            <input type="hidden" name="redirect_to" value="<?=$redirectTo;?>">

            <div class="zg-form-header">
                <div class="zg-form-eyebrow"><?=esc_html__ ('Nueva cuenta', 'zentrygate');?></div>
                <h2 id="zg-register-title"><?=esc_html__ ('Introduce tus datos para registrarte', 'zentrygate');?></h2>
            </div>

            <?php
		$old = [ ];
		if ($hasOldData)
		{
			$errors = self::flashTake ('zg_err_', $_GET ['errkey'] ?? '');
			$old = self::flashTake ('zg_old_', $_GET ['oldkey'] ?? '');

			if (! empty ($errors))
			{
				self::renderErrors ($errors);
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
                <label for="zg_reg_name">
                    <?=esc_html__ ('Nombre y apellidos', 'zentrygate');?>
                    <input
                        type="text"
                        id="zg_reg_name"
                        name="name"
                        value="<?=isset ($old ['name']) ? esc_attr (wp_unslash ($old ['name'])) : '';?>"
                        placeholder="<?=esc_attr__ ('Nombre y apellidos', 'zentrygate');?>"
                        required
                        aria-required="true"
                        autocomplete="name"
                    >
                </label>

                <label for="zg_reg_email">
                    <?=esc_html__ ('Correo electrónico', 'zentrygate');?>
                    <input
                        type="email"
                        id="zg_reg_email"
                        name="email"
                        value="<?=isset ($old ['email']) ? esc_attr (wp_unslash ($old ['email'])) : '';?>"
                        placeholder="<?=esc_attr__ ('ejemplo@correo.com', 'zentrygate');?>"
                        required
                        aria-required="true"
                        autocomplete="email"
                    >
                </label>

                <label for="zg_hp_name" id="zg_hp_label">
                    <?php

echo 'Hall Name'; // honeypot ?>
                    <input
                        id="zg_hp_name"
                        name="zg_hp_name"
                        value=""
                        placeholder="Nombre y apellidos"
                    >
                </label>

                <label for="zg_reg_password">
                    <?=esc_html__ ('Contraseña', 'zentrygate');?>
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
                    <?=esc_html__ ('Repite la contraseña', 'zentrygate');?>
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
		if (! empty ($extraFields))
		{
			echo '<div class="zg-field-divider"><span>' . esc_html__ ('Datos del evento', 'zentrygate') . '</span></div>';

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

				$postName = 'other[' . $tag . ']';
				$value = isset ($old ['other'] [$tag]) ? wp_unslash ($old ['other'] [$tag]) : '';

				echo '<div class="zg-field zg-field-' . esc_attr ($type) . '">';

				switch ($type)
				{
					case 'textarea':
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<textarea id="' . esc_attr ($id) . '" name="' . esc_attr ($postName) . '" rows="3">' . esc_textarea (is_string ($value) ? $value : '') . '</textarea></label>';
						break;

					case 'checkbox':
						$checked = $isChecked ($postName);
						echo '<label class="zg-checkbox">' . '<input type="checkbox" id="' . esc_attr ($id) . '" name="' . esc_attr ($postName) . '" value="1" ' . ($checked ? 'checked ' : '') . '>' . ' ' . esc_html ($label) . ($req ? ' *' : '') . '</label>';
						break;

					case 'select':
						$choices = isset ($cfg ['choices']) && is_array ($cfg ['choices']) ? $cfg ['choices'] : [ ];
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<select id="' . esc_attr ($id) . '" name="' . esc_attr ($postName) . '">';
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
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<select id="' . esc_attr ($id) . '" name="other[' . esc_attr ($tag) . '][]" multiple size="4">';
						foreach ($choices as $opt)
						{
							$sel = in_array ((string) $opt, array_map ('strval', $vals), true) ? ' selected' : '';
							echo '<option value="' . esc_attr ($opt) . '"' . $sel . '>' . esc_html ($opt) . '</option>';
						}
						echo '</select></label>';
						break;

					default:
						echo '<label for="' . esc_attr ($id) . '">' . esc_html ($label) . ($req ? ' *' : '') . '<input type="' . esc_attr ($type) . '" id="' . esc_attr ($id) . '" name="' . esc_attr ($postName) . '" value="' . esc_attr (is_string ($value) ? $value : '') . '">' . '</label>';
						break;
				}

				echo '</div>';
			}
		}
		?>
            </div>

            <div class="zg-form-footer">
                <button type="submit" name="zg_register_submit" class="button button-primary">
                    <?=esc_html__ ('Enviar', 'zentrygate');?>
                </button>

                <p class="zg-auth-links">
                    <a href="<?=esc_url (add_query_arg ('zg_action', 'login'));?>">
                        <?=esc_html__ ('¿Ya tienes cuenta? Inicia sesión', 'zentrygate');?>
                    </a>
                    &nbsp;·&nbsp;
                    <a href="<?=esc_url (add_query_arg ('zg_action', 'pass_recovery'));?>">
                        <?=esc_html__ ('¿Olvidaste tu contraseña?', 'zentrygate');?>
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
		$ok = self::handleRegisterPost ();
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


	public static function handlePasswordResetPostEntryPoint (): void
	{
		$redirect = isset ($_POST ['redirect_to']) ? esc_url_raw (wp_unslash ($_POST ['redirect_to'])) : home_url ('/');
		$emailParam = isset ($_POST ['zg_recover_email']) ? (string) wp_unslash ($_POST ['zg_recover_email']) : '';
		$tokenParam = isset ($_POST ['token']) ? sanitize_text_field (wp_unslash ($_POST ['token'])) : '';

		if (self::processRecoveryChangePassword ())
		{
			$url = add_query_arg ([ 'zg_action' => 'pass-reset', 'zg_notice' => 'success'], $redirect);
		}
		else
		{
			$errkey = self::flashSet ('zg_err_', self::$lastErrors);
			$args = [ 'zg_action' => 'pass-reset', 'zg_notice' => 'errors', 'errkey' => $errkey];

			// Si el token sigue siendo válido (p.ej. el error fue que las contraseñas no
			// coincidían), mantenemos email y token para reintentar sin pedir otro enlace.
			$email = self::decodeUrlSafeEmail ($emailParam);
			if ($email !== null && self::findUserForPasswordReset ($email, $tokenParam) !== null)
			{
				$args ['zg_recover_email'] = $emailParam;
				$args ['token'] = $tokenParam;
			}

			$url = add_query_arg ($args, $redirect);
		}
		wp_safe_redirect ($url);
		exit ();
	}


	/**
	 * Procesa el POST del registro.
	 */
	public static function handleRegisterPost (): bool
	{
		self::$lastErrors = [ ];

		// 1.0) CSRF
		if (! isset ($_POST ['zg_register_nonce']) || ! wp_verify_nonce (wp_unslash ($_POST ['zg_register_nonce']), 'zg_register_action'))
		{
			self::$lastErrors [] = __ ('Error en el formulario. Por favor, recarga la página e inténtalo de nuevo.', 'zentrygate');
			return false;
		}

		// 1.1) Honeypot
		if (isset ($_POST ['zg_hp_name']) && trim ((string) $_POST ['zg_hp_name']) !== '')
		{
			self::$lastErrors [] = __ ('Error en el formulario. Por favor, recarga la página e inténtalo de nuevo.', 'zentrygate');
			return true; // respuesta neutra
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

		// 3) Validar campos requeridos del JSON
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

		// 5) Insertar
		$passwordHash = password_hash ($password, PASSWORD_DEFAULT);
		$verifyToken = bin2hex (random_bytes (32));
		$unsubscribeToken = bin2hex (random_bytes (32));
		$nonceHash = bin2hex (random_bytes (32));
		$status = 'active';
		$isEnabled = 1; // habilitado (según tu configuración actual)
		$otherJson = wp_json_encode ($otherClean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$data = [ 'email' => $email, 'name' => $name, 'passwordHash' => $passwordHash, 'status' => $status, 'isAdmin' => 0, 'isEnabled' => $isEnabled, 'otherData' => $otherJson, 'nonceHash' => $nonceHash, 'verifyToken' => $verifyToken, 'unsubscribeToken' => $unsubscribeToken, 'failedLoginCount' => 0];
		$format = [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d'];

		$ok = $wpdb->insert ($table, $data, $format);
		if (! $ok)
		{
			self::$lastErrors [] = __ ('No se pudo crear la cuenta en este momento. Inténtalo más tarde.', 'zentrygate');
			if (! empty ($wpdb->last_error))
			{
				error_log ('[ZentryGate] handleRegisterPost insert error: ' . $wpdb->last_error);
			}
			return false;
		}

		// Cargar el usuario recién creado y rellenar userData
		$user = $wpdb->get_row ($wpdb->prepare ("SELECT * FROM {$wpdb->prefix}zgUsers WHERE email = %s", $email), ARRAY_A);
		if ($user && $user ['isEnabled'])
		{
			self::fillUserData ($user);
		}

		// ✅ Tras registro, dejamos cookie aceptada pero SIN login automático (como flujo mínimo).
		// Si quisieras auto-login, sustituye por nonce@id (ver notas en el mensaje anterior).
		self::saveCookieValue ('@');

		// Redirección post-registro (como tenías)
		wp_redirect (home_url ('/inscripcion/'));

		return true;
	}
}
