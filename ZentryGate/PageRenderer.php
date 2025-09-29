<?php

namespace ZentryGate;

class PageRenderer
{


	public function __construct ($permalink)
	{
		PLugin::$permalink = $permalink;
	}


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
			$notice = $_GET ['zg_notice'] ?? '';
			// Remember: the login actions were handled in Auth::processEarlyActions
			// (because they set coockies, and so, they need to be processed before headers are sent)

			switch ($action)
			{
				case 'login':
				default:
					Auth::renderLoginForm ();
					break;
				case 'register':

					if ($notice === 'check_email')
					{
						Auth::renderAskUserCheckEmail ();
					}
					else if ($notice === 'errors')
					{
						Auth::renderRegisterForm (true); // <<< ver siguiente punto
						break;
					}
					else
					{
						Auth::renderRegisterForm ();
					}

					break;
				case 'verify':
					if (Auth::handleEmailVerification ())
					{
						if (Auth::LOGON_ON_VALIDATE)
						{
							$session = Auth::getSessionData ();
							$pageContentHandler = null;

							$pageContentHandler = $session ['isAdmin'] ? new AdministratorPage ($session) : new UserPage ($session);

							$pageContentHandler->render ();
						}
						else
						{
							Auth::renderVerificationSuccess ();
						}
					}
					else
					{
						Auth::renderVerificationFailed ();
					}
					break;
				case 'pass_recovery': // Only sends the email with the token
					if (Auth::handleRecoveryGet ())
					{
						Auth::renderRecoveryRequested (); // siempre éxito neutral
					}
					else
					{
						Auth::renderRecoveryForm ();
					}
					break;

				// --- FORMULARIO DE RESET (desde enlace del email con token) ---
				case 'pass-reset':
					if ($notice === 'errors')
					{
						// El usuario ha tratado de cambiar las password pero ha dado problemas
						// En este caso... ¿como podría ocurrir? ¿contraseñas distintas o que no cumplan con la política?

						Auth::renderRecoveryAskEmailForm ();
					}
					else if ($notice === 'success')
					{
						// El usuario ha hecho el post de envio de contraseña. Mensaje de exito y damos enlace a login
						Auth::renderPasswordResetSuccess ();
					}
					else if (Auth::isValidResetToken ())
					{
						// Entrada desde email con token válido: mostramos el formulario de cambio password
						Auth::renderPasswordResetForm ();
					}
					else
					{
						// Token inválido o expirado
						Auth::renderPassResetFailed ();
					}
					break;
			}
		}
	}
}
