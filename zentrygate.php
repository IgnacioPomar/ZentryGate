<?php
/**
 * Plugin Name: ZentryGate
 * Plugin URI: https://github.com/IgnacioPomar/ZentryGate
 * Description: Plugin para la gestión de eventos con control de aforo.
 * Version: 1.1.1
 * Author: Ignacio Pomar Ballestero
 * Author URI: https://github.com/IgnacioPomar
 * License: GPL2
 */
defined ('ABSPATH') || exit ();

// Plugin version defines. Renaneber change also the comments above.
define ('ZENTRYGATE_VERSION_DB', '1.2.0');
define ('ZENTRYGATE_VERSION_PLUGIN', '1.2.0');

if (! defined ('ZENTRYGATE_DIR'))
{
	define ('ZENTRYGATE_DIR', plugin_dir_path (__FILE__));
	define ('ZENTRYGATE_URL', plugin_dir_url (__FILE__));
}

// TODO: Migrar a POO
// TODO: cambio de contraseña del admin panel separarlo del panel de usuario estandar
require_once ZENTRYGATE_DIR . 'admin/eventDetails.php';

require_once ZENTRYGATE_DIR . 'ZentryGate/WpAdminPanel.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/AdminPanel/Dashboard.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/AdminPanel/Texts.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/AdminPanel/Users.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/AdminPanel/Events.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/Install.php';

require_once ZENTRYGATE_DIR . 'ZentryGate/Plugin.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/Auth.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/AdministratorPage.php';
require_once ZENTRYGATE_DIR . 'ZentryGate/UserPage.php';

// Activation hook
register_activation_hook (__FILE__, [ \ZentryGate\Install::class, 'activate']);

use ZentryGate\Plugin;

new Plugin ();