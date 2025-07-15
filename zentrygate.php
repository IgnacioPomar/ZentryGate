<?php
/**
 * Plugin Name: ZentryGate
 * Plugin URI: https://github.com/IgnacioPomar/ZentryGate
 * Description: Plugin para la gestión de eventos con control de aforo.
 * Version: 1.0.0
 * Author: Ignacio Pomar Ballestero
 * Author URI: https://github.com/IgnacioPomar
 * License: GPL2
 */
defined ('ABSPATH') || exit ();

// Global plugin version (used during activation and future upgrades)
global $zentrygateDbVersion, $zentrygatePluginVersion;
$zentrygateDbVersion = '1.0.0';
$zentrygatePluginVersion = '1.0.0';

if (! defined ('ZENTRYGATE_PLUGIN_DIR'))
{
	define ('ZENTRYGATE_PLUGIN_DIR', plugin_dir_path (__FILE__));
}

require_once ZENTRYGATE_PLUGIN_DIR . 'admin/adminMenu.php';
require_once ZENTRYGATE_PLUGIN_DIR . 'includes/install.php';
require_once ZENTRYGATE_PLUGIN_DIR . 'ZentryGate/Plugin.php';
require_once ZENTRYGATE_PLUGIN_DIR . 'ZentryGate/Auth.php';
require_once ZENTRYGATE_PLUGIN_DIR . 'ZentryGate/AdministratorPage.php';
require_once ZENTRYGATE_PLUGIN_DIR . 'ZentryGate/UserPage.php';

// Activation hook
register_activation_hook (__FILE__, 'zg_activate_plugin');

use ZentryGate\Plugin;

new Plugin ();