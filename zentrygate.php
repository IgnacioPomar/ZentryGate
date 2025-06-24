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
defined('ABSPATH') || exit();

// Global plugin version (used during activation and future upgrades)
global $zentrygateDbVersion, $zentrygatePluginVersion;
$zentrygateDbVersion = '1.0.0';
$zentrygatePluginVersion = '1.0.0';

// Activation hook
require_once plugin_dir_path(__FILE__) . 'includes/install.php';
register_activation_hook(__FILE__, 'zg_activate_plugin');

// Wordpress administration menu
require_once plugin_dir_path(__FILE__) . 'admin/adminMenu.php';
