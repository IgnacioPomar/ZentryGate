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
		if (isset ($_GET ['DEBUG'])) echo "<!-- DEBUG MODE ENABLED -->\n";
		if (! Auth::isCookieAccepted ())
		{
			Auth::renderCookiePrompt ();
		}
		else if (Auth::isLoggedIn ())
		{
			$session = Auth::getSessionData ();
			$pageContentHandler = null;

			if ($session ['isAdmin'])
			{
				$pageContentHandler = new AdministratorPage ($session);
			}
			else
			{
				$pageContentHandler = new UserPage ($session);
			}

			$pageContentHandler->render ();
		}
		else
		{

			if (isset ($_GET ['zg_action']) && 'pass_recovery' === $_GET ['zg_action'])
			{
				Auth::renderRecoveryForm ();
			}
			else
			{
				Auth::renderLoginForm ();
			}
		}
	}
}
