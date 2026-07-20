<?php
/**
 * API Wrapper for WooCommerce REST API.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IRB_API
{
    private $url;
    private $key;
    private $secret;

    public function __construct()
    {
        $opt = get_option('irb_sync_options');

        $this->url    = isset($opt['destination_url']) ? trailingslashit($opt['destination_url']) : '';
        $this->key    = isset($opt['consumer_key']) ? trim($opt['consumer_key']) : '';
        $this->secret = isset($opt['consumer_secret']) ? trim($opt['consumer_secret']) : '';
    }

    /**
     * Send HTTP request to Destination WooCommerce REST API.
     */
    private function request($method, $path, $data = [], $params = [])
    {
        if (empty($this->url) || empty($this->key) || empty($this->secret)) {
            return new WP_Error('missing_credentials', 'API Credentials are not fully configured.');
        }

        $endpoint = $this->url . 'wp-json/wc/v3/' . ltrim($path, '/');
        
        if (!empty($params)) {
            $endpoint = add_query_arg($params, $endpoint);
        }

        $args = [
            'method'    => $method,
            'headers'   => [
                'Authorization' => 'Basic ' . base64_encode($this->key . ':' . $this->secret),
                'Content-Type'  => 'application/json; charset=utf-8'
            ],
            'timeout'   => 45,
            'sslverify' => apply_filters('irb_sync_sslverify', false) // set to false to avoid local SSL validation errors
        ];

        if ($method !== 'GET' && !empty($data)) {
            $args['body'] = json_encode($data);
        }

        irb_log("API Request: {$method} {$endpoint}");

        $response = wp_remote_request($endpoint, $args);

        if (is_wp_error($response)) {
            irb_log("API Request failed: " . $response->get_error_message(), 'error');
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $message = isset($decoded['message']) ? $decoded['message'] : 'HTTP Error Code: ' . $code;
            irb_log("API Error Response (Code: {$code}): " . $body, 'error');
            return new WP_Error('api_error', $message, $decoded);
        }

        return $decoded;
    }

    /**
     * Test connection to the destination site.
     */
    public function test_connection()
    {
        // Use a lightweight endpoint to check credentials
        return $this->request('GET', 'system_status', [], ['context' => 'view']);
    }

    /**
     * Get a product by ID from destination store.
     */
    public function get_product($id)
    {
        return $this->request('GET', "products/{$id}");
    }

    /**
     * Find a product by SKU on the destination store.
     */
    public function get_product_by_sku($sku)
    {
        if (empty($sku)) {
            return false;
        }

        $response = $this->request('GET', 'products', [], ['sku' => $sku]);

        if (is_wp_error($response) || empty($response)) {
            return false;
        }

        // Return first matching product
        return isset($response[0]) ? $response[0] : false;
    }

    /**
     * Create a product on the destination store.
     */
    public function create_product($data)
    {
        return $this->request('POST', 'products', $data);
    }

    /**
     * Update an existing product on the destination store.
     */
    public function update_product($id, $data)
    {
        return $this->request('PUT', "products/{$id}", $data);
    }

    /**
     * Trash or delete a product on the destination store.
     */
    public function delete_product($id, $force = false)
    {
        return $this->request('DELETE', "products/{$id}", [], ['force' => $force ? 'true' : 'false']);
    }

    /**
     * Find variation by SKU under a parent product.
     */
    public function get_variation_by_sku($parent_id, $sku)
    {
        if (empty($sku) || empty($parent_id)) {
            return false;
        }

        $response = $this->request('GET', "products/{$parent_id}/variations", [], ['sku' => $sku]);

        if (is_wp_error($response) || empty($response)) {
            return false;
        }

        return isset($response[0]) ? $response[0] : false;
    }

    /**
     * Create variation for a parent product.
     */
    public function create_variation($parent_id, $data)
    {
        return $this->request('POST', "products/{$parent_id}/variations", $data);
    }

    /**
     * Update an existing variation.
     */
    public function update_variation($parent_id, $variation_id, $data)
    {
        return $this->request('PUT', "products/{$parent_id}/variations/{$variation_id}", $data);
    }

    /**
     * Delete/trash a variation.
     */
    public function delete_variation($parent_id, $variation_id, $force = true)
    {
        return $this->request('DELETE', "products/{$parent_id}/variations/{$variation_id}", [], ['force' => $force ? 'true' : 'false']);
    }

    /**
     * Get product category by slug.
     */
    public function get_category_by_slug($slug)
    {
        $response = $this->request('GET', 'products/categories', [], ['slug' => $slug]);
        if (is_wp_error($response) || empty($response)) {
            return false;
        }
        return isset($response[0]) ? $response[0] : false;
    }

    /**
     * Create product category.
     */
    public function create_category($data)
    {
        return $this->request('POST', 'products/categories', $data);
    }

    /**
     * Get product tag by slug.
     */
    public function get_tag_by_slug($slug)
    {
        $response = $this->request('GET', 'products/tags', [], ['slug' => $slug]);
        if (is_wp_error($response) || empty($response)) {
            return false;
        }
        return isset($response[0]) ? $response[0] : false;
    }

    /**
     * Create product tag.
     */
    public function create_tag($data)
    {
        return $this->request('POST', 'products/tags', $data);
    }
}