<?php
/**
 * Admin settings page for IRB WooCommerce Sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function(){
    register_setting('irb_sync_group', 'irb_sync_options');
});

function irb_sync_settings_page()
{
    $opt = get_option('irb_sync_options', [
        'destination_url' => '',
        'consumer_key'    => '',
        'consumer_secret' => '',
        'price_difference' => 500,
        'enable_auto_sync' => 'yes'
    ]);
    ?>
    <div class="wrap">
        <h1>IRB WooCommerce Sync Settings</h1>
        
        <form method="post" action="options.php">
            <?php settings_fields('irb_sync_group'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Destination Store URL</th>
                    <td>
                        <input type="url" class="regular-text" name="irb_sync_options[destination_url]" value="<?php echo esc_attr($opt['destination_url']); ?>" placeholder="https://raceimport.com" required>
                        <p class="description">Enter the URL of the destination store (e.g., https://raceimport.com).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">WooCommerce Consumer Key</th>
                    <td>
                        <input type="text" class="regular-text" name="irb_sync_options[consumer_key]" value="<?php echo esc_attr($opt['consumer_key']); ?>" required>
                        <p class="description">Consumer Key from the destination store (WooCommerce > Settings > Advanced > REST API).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">WooCommerce Consumer Secret</th>
                    <td>
                        <input type="password" class="regular-text" name="irb_sync_options[consumer_secret]" value="<?php echo esc_attr($opt['consumer_secret']); ?>" required>
                        <p class="description">Consumer Secret from the destination store.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Price Difference (INR)</th>
                    <td>
                        <input type="number" class="small-text" name="irb_sync_options[price_difference]" value="<?php echo esc_attr($opt['price_difference']); ?>" required>
                        <p class="description">How much lower (in Rs) should the price be on the destination store? (e.g. 500).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable Auto Sync</th>
                    <td>
                        <select name="irb_sync_options[enable_auto_sync]">
                            <option value="yes" <?php selected($opt['enable_auto_sync'], 'yes'); ?>>Yes, sync on product save/update</option>
                            <option value="no" <?php selected($opt['enable_auto_sync'], 'no'); ?>>No, manual sync only</option>
                        </select>
                        <p class="description">Enable to automatically sync products when created, updated, or published.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Settings'); ?>
        </form>
        
        <hr>
        
        <h2>Test Connection</h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="irb_test_connection">
            <?php submit_button('Test Connection to Destination', 'secondary'); ?>
        </form>
        
        <?php
        $result = get_transient('irb_connection_result');
        if ($result) {
            if ($result['status'] === 'success') {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> ' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html($result['message']) . '</p></div>';
            }
            delete_transient('irb_connection_result');
        }
        ?>
        
        <hr>
        
        <h2>Bulk Synchronization</h2>
        <div class="irb-sync-box">
            <p>Sync all your existing products to the destination store. This will process products in batches of 5 to avoid timeouts.</p>
            <button id="irb-start-sync" class="button button-primary">Start Bulk Sync</button>
            <span id="irb-sync-status" style="margin-left: 15px; font-weight: bold; vertical-align: middle;"></span>
            
            <div class="irb-progress-bg">
                <div class="irb-progress-bar"></div>
            </div>
            
            <div class="irb-log-console"></div>
        </div>
    </div>

    <style>
        .irb-sync-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 20px;
            margin-top: 20px;
            max-width: 800px;
        }
        .irb-progress-bg {
            background: #eee;
            border-radius: 4px;
            height: 20px;
            width: 100%;
            margin: 15px 0;
            overflow: hidden;
            display: none;
        }
        .irb-progress-bar {
            background: #007cba;
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }
        .irb-log-console {
            background: #1e1e1e;
            color: #00ff00;
            font-family: monospace;
            padding: 15px;
            height: 200px;
            overflow-y: scroll;
            margin-top: 15px;
            display: none;
            border-radius: 4px;
        }
        .irb-log-line {
            margin-bottom: 5px;
            font-size: 13px;
        }
        .irb-log-error {
            color: #ff3333;
        }
        .irb-log-success {
            color: #33ff33;
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var productIds = [];
        var totalProducts = 0;
        var currentIndex = 0;
        var chunkSize = 5;
        var successCount = 0;
        var failCount = 0;

        $('#irb-start-sync').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('.irb-progress-bg, .irb-log-console').show();
            $('.irb-progress-bar').css('width', '0%');
            $('#irb-sync-status').text('Fetching product list...');
            $('.irb-log-console').empty();

            successCount = 0;
            failCount = 0;

            $.post(ajaxurl, {
                action: 'irb_get_all_product_ids'
            }, function(response) {
                if (response.success) {
                    productIds = response.data.ids;
                    totalProducts = response.data.total;
                    currentIndex = 0;

                    logMessage('Found ' + totalProducts + ' products to sync.', 'info');
                    
                    if (totalProducts === 0) {
                        $('#irb-sync-status').text('No products found to sync.');
                        $btn.prop('disabled', false);
                        return;
                    }

                    syncNextChunk();
                } else {
                    logMessage('Error fetching product list: ' + response.data, 'error');
                    $btn.prop('disabled', false);
                }
            });
        });

        function syncNextChunk() {
            if (currentIndex >= totalProducts) {
                $('.irb-progress-bar').css('width', '100%');
                $('#irb-sync-status').text('Sync completed! ' + successCount + ' succeeded, ' + failCount + ' failed.');
                logMessage('*** BULK SYNC PROCESS FINISHED ***', 'success');
                $('#irb-start-sync').prop('disabled', false);
                return;
            }

            var chunk = productIds.slice(currentIndex, currentIndex + chunkSize);
            var progressPercent = Math.round((currentIndex / totalProducts) * 100);
            $('.irb-progress-bar').css('width', progressPercent + '%');
            $('#irb-sync-status').text('Syncing products ' + (currentIndex + 1) + ' to ' + Math.min(currentIndex + chunkSize, totalProducts) + ' of ' + totalProducts + '...');

            $.post(ajaxurl, {
                action: 'irb_sync_chunk',
                product_ids: chunk
            }, function(response) {
                if (response.success) {
                    var results = response.data.results;
                    $.each(results, function(id, res) {
                        var statusClass = res.success ? 'success' : 'error';
                        var msg = '[' + (res.sku || 'No SKU') + '] ' + res.name + ' - ' + res.message;
                        logMessage(msg, statusClass);

                        if (res.success) {
                            successCount++;
                        } else {
                            failCount++;
                        }
                    });

                    currentIndex += chunkSize;
                    syncNextChunk();
                } else {
                    logMessage('Chunk failed: ' + response.data, 'error');
                    currentIndex += chunkSize;
                    failCount += chunk.length;
                    syncNextChunk();
                }
            });
        }

        function logMessage(text, type) {
            var $console = $('.irb-log-console');
            var className = 'irb-log-line';
            if (type === 'success') {
                className += ' irb-log-success';
            } else if (type === 'error') {
                className += ' irb-log-error';
            }
            $console.append('<div class="' + className + '">' + text + '</div>');
            $console.scrollTop($console[0].scrollHeight);
        }
    });
    </script>
    <?php
}