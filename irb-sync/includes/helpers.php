<?php
/**
 * Helper functions for IRB WooCommerce Sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log a message to the WooCommerce logger.
 *
 * Logs will be visible in WooCommerce > Status > Logs.
 *
 * @param string $message The message to log.
 * @param string $level   The log level (info, notice, warning, error).
 */
if (!function_exists('irb_log')) {
    function irb_log($message, $level = 'info') {
        // Convert array/object to string if needed
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = array('source' => 'irb-sync');
            $logger->log($level, $message, $context);
        } else {
            error_log('IRB-Sync: [' . strtoupper($level) . '] ' . $message);
        }
    }
}
