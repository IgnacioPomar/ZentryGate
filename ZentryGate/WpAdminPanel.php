<?php

namespace ZentryGate;

class WpAdminPanel
{


	public static function registerMenus ()
	{
		add_menu_page ('ZentryGate Admin', 'ZentryGate', 'manage_options', 'zentrygate', [ \ZentryGate\AdminPanel\Dashboard::class, 'render'], 'dashicons-groups');
		add_submenu_page ('zentrygate', 'Usuarios Admin', 'Usuarios', 'manage_options', 'zentrygate_users', [ \ZentryGate\AdminPanel\Users::class, 'renderUsers']);
		add_submenu_page ('zentrygate', 'Gestión de Eventos', 'Eventos', 'manage_options', 'zentrygate_events', [ \ZentryGate\AdminPanel\Events::class, 'renderEvents']);
		add_submenu_page ('zentrygate', 'Textos Formularios', 'Textos Formularios', 'manage_options', 'zentrygate_form_texts', [ \ZentryGate\AdminPanel\Texts::class, 'renderFormTexts']);
	}


	// --------------------------------------------------------------
	// Users Page
	// --------------------------------------------------------------
	public static function renderEvents ()
	{ /* ... */
	}
}

