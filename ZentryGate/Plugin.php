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

		// Redirects handles (stripe)
		add_action ('template_redirect', [ Payments\StripeCheckout::class, 'handleStripeRedirects']);

		// Admin menu and settings
		add_action ('admin_menu', [ WpAdminPanel::class, 'registerMenus']);
		add_action ('admin_init', [ \ZentryGate\AdminPanel\Texts::class, 'registerFormTextsSettings']);
		add_action ('admin_post_zg_edit_user', [ \ZentryGate\AdminPanel\Users::class, 'processEditUser']);

		add_action ('admin_post_nopriv_zg_register', [ \ZentryGate\Auth::class, 'handleRegisterPostEntryPoint']); // no logados
		add_action ('admin_post_zg_register', [ \ZentryGate\Auth::class, 'handleRegisterPostEntryPoint']); // logados

		// add plugin style
		if (! wp_style_is ('zentrygate-styles', 'enqueued'))
		{
			wp_enqueue_style ('zentrygate-styles', ZENTRYGATE_URL . 'css/zentrygate.css', [ ], ZENTRYGATE_VERSION_PLUGIN);
		}
	}
}