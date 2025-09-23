<?php

namespace ZentryGate;

/**
 * ZentryGate Plugin Class
 *
 * Handles the addition of a custom page template and its loading.
 */
class Plugin
{


	public function __construct ()
	{
		// Work with cookies and sessions
		add_action ('init', [ Auth::class, 'init']);

		// add plugin style
		if (! wp_style_is ('zentrygate-styles', 'enqueued'))
		{
			wp_enqueue_style ('zentrygate-styles', ZENTRYGATE_URL . 'css/zentrygate.css', [ ], ZENTRYGATE_VERSION_PLUGIN);
		}
	}
}