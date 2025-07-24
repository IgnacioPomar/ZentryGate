<?php


function zg_admin_menu ()
{
	add_menu_page ('ZentryGate Admin', 'ZentryGate', 'manage_options', 'zentrygate', 'zg_render_dashboard_page', 'dashicons-groups');

	add_submenu_page ('zentrygate', 'Usuarios Admin', 'Usuarios', 'manage_options', 'zentrygate_users', 'zg_render_users_page');
	add_submenu_page ('zentrygate', 'Gestión de Eventos', 'Eventos', 'manage_options', 'zentrygate_events', 'zg_render_events_page');
	add_submenu_page ('zentrygate', 'Textos Formularios', 'Textos Formularios', 'manage_options', 'zentrygate_form_texts', 'zg_render_form_texts_page');
}
add_action ('admin_menu', 'zg_admin_menu');

require_once plugin_dir_path (__FILE__) . 'users.php';
require_once plugin_dir_path (__FILE__) . 'events.php';
require_once plugin_dir_path (__FILE__) . 'eventDetails.php';
require_once plugin_dir_path (__FILE__) . 'utils.php';
