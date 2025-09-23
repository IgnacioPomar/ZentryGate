<?php

namespace ZentryGate;

class PageRenderer
{


	public function echoHeader ($title = '')
	{
		if (empty ($title))
		{
			$title = 'ZentryGate';
		}

		echo '<!DOCTYPE html>' . PHP_EOL;
		echo '<html lang="es">' . PHP_EOL;
		echo '<head>' . PHP_EOL;
		echo '<meta charset="UTF-8">' . PHP_EOL;
		echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL;
		echo '<title>' . esc_html ($title) . '</title>' . PHP_EOL;

		// Asegura que los enqueues se hayan ejecutado
		do_action ('wp_enqueue_scripts');

		// Solo CSS encolados
		wp_print_styles ();

		// Solo JS destinados al <head>
		wp_print_head_scripts ();

		echo '</head>' . PHP_EOL;
		echo '<body class="zentrygate-plugin-page">' . PHP_EOL;
	}


	public function echoFooter ()
	{
		echo '</body>';
		echo '</html>';
	}


	public function renderPluginPageContents ()
	{
		if (! Auth::isCookieAccepted ())
		{
			Auth::renderCookiePrompt ();
		}
		else if (Auth::isLoggedIn ())
		{
			$session = Auth::getSessionData ();
			$pageContentHandler = null;

			$pageContentHandler = $session ['isAdmin'] ? new AdministratorPage ($session) : new UserPage ($session);

			$pageContentHandler->render ();
		}
		else
		{
			$action = $_GET ['zg_action'] ?? 'login';
			// Remember: the login actions were handled in Auth::processEarlyActions
			// (because they set coockies, and so, they need to be processed before headers are sent)

			switch ($action)
			{
				case 'login':
				default:
					Auth::renderLoginForm ();
					break;
				case 'register':
					if ($_SERVER ['REQUEST_METHOD'] === 'POST' && isset ($_POST ['zg_register_submit']))
					{
						// Debe: validar nonce, email único, password policy, captchas si aplican, consentimiento
						if (Auth::handleRegisterPost ())
						{
							Auth::renderVerifyEMailForm ();
							break;
						}
					}
					Auth::renderRegisterForm (); // email, nombre, password, aceptar T&C/cookies
					break;
				case 'verify':
					$token = $_GET ['token'] ?? '';
					if (Auth::handleEmailVerification ($token))
					{
						Auth::renderVerificationSuccess ();
					}
					else
					{
						Auth::renderVerificationFailed ();
					}
					break;
				case 'pass_recovery':
					if ($_SERVER ['REQUEST_METHOD'] === 'POST' && isset ($_POST ['zg_recovery_submit']))
					{
						// Genera resetToken + resetRequestedAt y envía email con enlace
						// (mensaje SIEMPRE neutral: “si tu email existe, recibirás instrucciones”)
						Auth::handleRecoveryPost ();
						Auth::renderRecoveryRequested (); // siempre éxito neutral
						break;
					}
					Auth::renderRecoveryForm (); // sólo un campo email + nonce
					break;

				// --- FORMULARIO DE RESET (desde enlace del email con token) ---
				case 'reset':
					$token = $_GET ['token'] ?? '';
					if (! Auth::isValidResetToken ($token))
					{
						Auth::renderInvalidOrExpiredToken ();
						break;
					}

					if ($_SERVER ['REQUEST_METHOD'] === 'POST' && isset ($_POST ['zg_reset_submit']))
					{
						if (Auth::handlePasswordResetPost ($token))
						{
							// Tras reset, puedes logarle automáticamente o llevarle a login
							Auth::renderPasswordResetSuccess ();
							break;
						}
					}
					Auth::renderPasswordResetForm ($token); // password + confirm + nonce
					break;
			}
		}
	}
}
