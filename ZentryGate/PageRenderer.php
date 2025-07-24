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

		echo '<!DOCTYPE html>';
		echo '<html lang="es">';
		echo '<head>';
		echo '<meta charset="UTF-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		echo '<title>' . esc_html ($title) . '</title>';
		echo '<link rel="stylesheet" href="' . esc_url (plugin_dir_url (__FILE__) . '../rsc/zentrygate.css') . '">';
		echo '</head>';
		echo '<body class="zentrygate-plugin-page">';
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
