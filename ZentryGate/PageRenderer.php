<?php

namespace ZentryGate;

class PageRenderer
{


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
