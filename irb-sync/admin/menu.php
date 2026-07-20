<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'irb_sync_menu');

function irb_sync_menu()
{
    add_menu_page(
        'IRB Sync',
        'IRB Sync',
        'manage_options',
        'irb-sync',
        'irb_sync_settings_page',
        'dashicons-update',
        58
    );
}