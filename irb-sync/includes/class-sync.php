<?php
/**
 * Product Sync Trigger and Processing Hooks.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IRB_Sync
{
    private static $synced_in_request = [];

    /**
     * Initialize all synchronization hooks.
     */
    public static function init()
    {
        // Hook product creation and updates
        add_action('woocommerce_new_product', [__CLASS__, 'sync_product_hook'], 20, 1);
        add_action('woocommerce_update_product', [__CLASS__, 'sync_product_hook'], 20, 1);

        // Hook post trashing and deletion
        add_action('wp_trash_post', [__CLASS__, 'trash_product_hook'], 10, 1);
        add_action('untrashed_post', [__CLASS__, 'untrash_product_hook'], 10, 1);
        add_action('before_delete_post', [__CLASS__, 'delete_product_hook'], 10, 1);

        // AJAX hooks for bulk synchronization
        add_action('wp_ajax_irb_get_all_product_ids', [__CLASS__, 'ajax_get_all_product_ids']);
        add_action('wp_ajax_irb_sync_chunk', [__CLASS__, 'ajax_sync_chunk']);
    }

    /**
     * Hook to handle product creation or updates.
     */
    public static function sync_product_hook($product_id)
    {
        $opt = get_option('irb_sync_options');
        $enable_auto = isset($opt['enable_auto_sync']) ? $opt['enable_auto_sync'] : 'yes';

        if ($enable_auto !== 'yes') {
            return;
        }

        // Prevent infinite loops / multiple saves during same page request
        if (in_array($product_id, self::$synced_in_request)) {
            return;
        }

        self::$synced_in_request[] = $product_id;

        $res = self::perform_sync($product_id);
        if (is_wp_error($res)) {
            irb_log("Sync Hook Failed for product {$product_id}: " . $res->get_error_message(), 'error');
        } else {
            irb_log("Sync Hook Successful for product {$product_id}");
        }
    }

    /**
     * Perform the actual synchronization of a single product.
     * 
     * @param int $product_id
     * @return bool|WP_Error
     */
    public static function perform_sync($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', 'Product not found locally.');
        }

        if ($product->is_type('variation')) {
            return new WP_Error('invalid_type', 'Cannot sync variations directly.');
        }

        $api = new IRB_API();

        // 1. Get destination product ID from meta
        $dest_product_id = get_post_meta($product_id, '_irb_sync_destination_product_id', true);
        $dest_product = null;

        // 2. If meta exists, check if product still exists on destination
        if (!empty($dest_product_id)) {
            $dest_product = $api->get_product($dest_product_id);
            if (is_wp_error($dest_product)) {
                // If remote product was deleted, reset local meta
                $dest_product_id = '';
                delete_post_meta($product_id, '_irb_sync_destination_product_id');
                $dest_product = null;
            }
        }

        // 3. Match by SKU if not found via ID meta
        $sku = $product->get_sku();
        if (empty($dest_product_id) && !empty($sku)) {
            $existing = $api->get_product_by_sku($sku);
            if ($existing) {
                $dest_product_id = $existing['id'];
                update_post_meta($product_id, '_irb_sync_destination_product_id', $dest_product_id);
                $dest_product = $existing;
            }
        }

        // 4. Prepare product payload
        $payload = IRB_Product::get_api_payload($product, $api, $dest_product);
        if (is_wp_error($payload)) {
            return $payload;
        }

        // 5. Send API Request
        if (!empty($dest_product_id)) {
            irb_log("Updating product ID {$product_id} (Remote: {$dest_product_id})");
            $response = $api->update_product($dest_product_id, $payload);
        } else {
            irb_log("Creating product ID {$product_id} on remote");
            $response = $api->create_product($payload);
            if (!is_wp_error($response) && isset($response['id'])) {
                $dest_product_id = $response['id'];
                update_post_meta($product_id, '_irb_sync_destination_product_id', $dest_product_id);
            }
        }

        if (is_wp_error($response)) {
            return $response;
        }

        // 6. If it's a Variable Product, sync its Variations
        if ($product->is_type('variable')) {
            $children_ids = $product->get_children();
            $dest_variation_ids = [];

            foreach ($children_ids as $var_id) {
                $variation_obj = wc_get_product($var_id);
                if (!$variation_obj) {
                    continue;
                }

                $dest_var_id = get_post_meta($var_id, '_irb_sync_destination_variation_id', true);
                $dest_var = null;

                $var_sku = $variation_obj->get_sku();
                if (!empty($dest_var_id)) {
                    $existing_var = $api->request('GET', "products/{$dest_product_id}/variations/{$dest_var_id}");
                    if (is_wp_error($existing_var)) {
                        $dest_var_id = '';
                        delete_post_meta($var_id, '_irb_sync_destination_variation_id');
                    } else {
                        $dest_var = $existing_var;
                    }
                }

                if (empty($dest_var_id) && !empty($var_sku)) {
                    $existing_var = $api->get_variation_by_sku($dest_product_id, $var_sku);
                    if ($existing_var) {
                        $dest_var_id = $existing_var['id'];
                        update_post_meta($var_id, '_irb_sync_destination_variation_id', $dest_var_id);
                        $dest_var = $existing_var;
                    }
                }

                $var_payload = IRB_Product::get_variation_api_payload($variation_obj, $dest_var);

                if (!empty($dest_var_id)) {
                    irb_log("Updating variation {$var_id} (Remote variation: {$dest_var_id}) of parent {$dest_product_id}");
                    $var_res = $api->update_variation($dest_product_id, $dest_var_id, $var_payload);
                } else {
                    irb_log("Creating variation {$var_id} under parent {$dest_product_id}");
                    $var_res = $api->create_variation($dest_product_id, $var_payload);
                    if (!is_wp_error($var_res) && isset($var_res['id'])) {
                        $dest_var_id = $var_res['id'];
                        update_post_meta($var_id, '_irb_sync_destination_variation_id', $dest_var_id);
                    }
                }

                if (is_wp_error($var_res)) {
                    irb_log("Failed to sync variation ID {$var_id}: " . $var_res->get_error_message(), 'error');
                } elseif ($dest_var_id) {
                    $dest_variation_ids[] = $dest_var_id;
                }
            }

            // Sync/Delete obsolete remote variations that do not exist locally anymore
            $remote_variations = $api->request('GET', "products/{$dest_product_id}/variations", [], ['per_page' => 100]);
            if (!is_wp_error($remote_variations) && is_array($remote_variations)) {
                foreach ($remote_variations as $remote_var) {
                    $remote_var_id = $remote_var['id'];
                    if (!in_array($remote_var_id, $dest_variation_ids)) {
                        irb_log("Deleting obsolete remote variation {$remote_var_id} from parent {$dest_product_id}");
                        $api->delete_variation($dest_product_id, $remote_var_id, true);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Hook to trash a product on destination when it's trashed locally.
     */
    public static function trash_product_hook($post_id)
    {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        $dest_product_id = get_post_meta($post_id, '_irb_sync_destination_product_id', true);
        if ($dest_product_id) {
            $api = new IRB_API();
            irb_log("Trashing product ID {$post_id} on remote (Remote ID: {$dest_product_id})");
            $api->delete_product($dest_product_id, false);
        }
    }

    /**
     * Hook to restore/untrash a product on destination.
     */
    public static function untrash_product_hook($post_id)
    {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        $dest_product_id = get_post_meta($post_id, '_irb_sync_destination_product_id', true);
        if ($dest_product_id) {
            irb_log("Restoring product ID {$post_id} on remote");
            self::perform_sync($post_id);
        }
    }

    /**
     * Hook to permanently delete a product.
     */
    public static function delete_product_hook($post_id)
    {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        $dest_product_id = get_post_meta($post_id, '_irb_sync_destination_product_id', true);
        if ($dest_product_id) {
            $api = new IRB_API();
            irb_log("Permanently deleting product ID {$post_id} on remote (Remote ID: {$dest_product_id})");
            $api->delete_product($dest_product_id, true);
        }
    }

    /**
     * AJAX action to fetch all product IDs.
     */
    public static function ajax_get_all_product_ids()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $query = new WC_Product_Query([
            'limit'  => -1,
            'status' => 'any',
            'return' => 'ids',
        ]);
        $products = $query->get_products();

        wp_send_json_success([
            'ids'   => $products,
            'total' => count($products)
        ]);
    }

    /**
     * AJAX action to sync a chunk/batch of product IDs.
     */
    public static function ajax_sync_chunk()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];

        if (empty($product_ids)) {
            wp_send_json_error('No product IDs provided.');
        }

        $results = [];
        foreach ($product_ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                $results[$id] = [
                    'success' => false,
                    'name'    => 'Unknown',
                    'sku'     => '',
                    'message' => 'Product not found locally.'
                ];
                continue;
            }

            $res = self::perform_sync($id);
            if (is_wp_error($res)) {
                $results[$id] = [
                    'success' => false,
                    'name'    => $product->get_name(),
                    'sku'     => $product->get_sku(),
                    'message' => $res->get_error_message()
                ];
            } else {
                $results[$id] = [
                    'success' => true,
                    'name'    => $product->get_name(),
                    'sku'     => $product->get_sku(),
                    'message' => 'Success'
                ];
            }
        }

        wp_send_json_success([
            'results' => $results
        ]);
    }
}
