<?php
/**
 * Plugin Name: IRB WooCommerce Sync
 * Description: Sync WooCommerce products between stores.
 * Version: 1.0.0
 * Author: Mayank
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IRB_SYNC_VERSION', '1.0.0');
define('IRB_SYNC_PATH', plugin_dir_path(__FILE__));
define('IRB_SYNC_URL', plugin_dir_url(__FILE__));

require_once IRB_SYNC_PATH . 'admin/menu.php';
require_once IRB_SYNC_PATH . 'admin/settings.php';

require_once IRB_SYNC_PATH . 'includes/helpers.php';
require_once IRB_SYNC_PATH . 'includes/class-api.php';
require_once IRB_SYNC_PATH . 'includes/class-product.php';
require_once IRB_SYNC_PATH . 'includes/class-sync.php';

register_activation_hook(__FILE__, function () {
    add_option('irb_sync_version', IRB_SYNC_VERSION);
});