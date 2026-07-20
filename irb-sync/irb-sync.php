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

// Load admin menu and settings
require_once IRB_SYNC_PATH . 'admin/menu.php';
require_once IRB_SYNC_PATH . 'admin/settings.php';

// Bootstrap plugin on plugins_loaded to verify WooCommerce is active
add_action('plugins_loaded', 'irb_sync_init_plugin');

function irb_sync_init_plugin()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>IRB WooCommerce Sync:</strong> WooCommerce is not active. Please install and activate WooCommerce to use this plugin.</p></div>';
        });
        return;
    }

    // Load helpers and classes
    require_once IRB_SYNC_PATH . 'includes/helpers.php';
    require_once IRB_SYNC_PATH . 'includes/class-api.php';
    require_once IRB_SYNC_PATH . 'includes/class-product.php';
    require_once IRB_SYNC_PATH . 'includes/class-sync.php';

    // Initialize synchronization logic
    IRB_Sync::init();
}

/**
 * Handle connection testing action.
 */
add_action('admin_post_irb_test_connection', 'irb_test_connection');

function irb_test_connection()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Load classes if not loaded (for safety in admin-post)
    if (!class_exists('IRB_API')) {
        require_once IRB_SYNC_PATH . 'includes/class-api.php';
        require_once IRB_SYNC_PATH . 'includes/helpers.php';
    }

    $api = new IRB_API();
    $response = $api->test_connection();

    if (is_wp_error($response)) {
        set_transient('irb_connection_result', [
            'status'  => 'error',
            'message' => $response->get_error_message()
        ], 30);
    } else {
        set_transient('irb_connection_result', [
            'status'  => 'success',
            'message' => 'Connection Successful'
        ], 30);
    }

    wp_redirect(admin_url('admin.php?page=irb-sync'));
    exit;
}

// Activation hook
register_activation_hook(__FILE__, function () {
    add_option('irb_sync_version', IRB_SYNC_VERSION);
});