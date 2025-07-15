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
			// Render the login form for non-logged-in users
			Auth::renderLoginForm ();
		}
	}
}
