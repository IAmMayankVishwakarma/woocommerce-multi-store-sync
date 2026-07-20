<?php
/**
 * Plugin Name: IRB WooCommerce Sync
 * Plugin URI: https://indianracingbazaar.com
 * Description: Sync WooCommerce products from Indian Racing Bazaar to RaceImports.
 * Version: 1.0.0
 * Author: Mayank
 * License: GPL2
 */
require_once IRB_SYNC_PATH . 'includes/class-api-client.php';
if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('IRB_SYNC_VERSION', '1.0.0');
define('IRB_SYNC_PATH', plugin_dir_path(__FILE__));
define('IRB_SYNC_URL', plugin_dir_url(__FILE__));

// Include files
require_once IRB_SYNC_PATH . 'admin/menu.php';
require_once IRB_SYNC_PATH . 'admin/settings.php';

// Plugin Activation
register_activation_hook(__FILE__, 'irb_sync_activate');

function irb_sync_activate()
{
    add_option('irb_sync_version', IRB_SYNC_VERSION);
}

// Plugin Deactivation
register_deactivation_hook(__FILE__, 'irb_sync_deactivate');

function irb_sync_deactivate()
{
    // Reserved for future use
}