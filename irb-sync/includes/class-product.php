<?php
/**
 * Product Mapper and Price Adjustment Helper.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IRB_Product
{
    /**
     * Map a source WC_Product into a WooCommerce REST API product payload.
     *
     * @param WC_Product  $product         The local product object.
     * @param IRB_API     $api_client      API Client instance.
     * @param array|null  $dest_product    Existing destination product data.
     * @return array                       API payload.
     */
    public static function get_api_payload($product, $api_client, $dest_product = null)
    {
        $opt = get_option('irb_sync_options');
        $price_diff = isset($opt['price_difference']) ? floatval($opt['price_difference']) : 500.0;

        // Price adjustments
        $regular_price = $product->get_regular_price();
        $sale_price    = $product->get_sale_price();

        $dest_regular_price = '';
        $dest_sale_price    = '';

        if ($regular_price !== '') {
            $dest_regular_price = max(0.0, floatval($regular_price) - $price_diff);
            $dest_regular_price = strval($dest_regular_price);
        }
        if ($sale_price !== '') {
            $dest_sale_price = max(0.0, floatval($sale_price) - $price_diff);
            $dest_sale_price = strval($dest_sale_price);
        }

        // Gather images
        $source_images = [];
        $image_id = $product->get_image_id();
        if ($image_id) {
            $img_url = wp_get_attachment_url($image_id);
            if ($img_url) {
                $source_images[] = ['src' => $img_url];
            }
        }
        // Gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_ids)) {
            foreach ($gallery_ids as $gal_id) {
                $img_url = wp_get_attachment_url($gal_id);
                if ($img_url) {
                    $source_images[] = ['src' => $img_url];
                }
            }
        }

        // Map images to prevent duplicates
        $existing_images = isset($dest_product['images']) ? $dest_product['images'] : [];
        $mapped_images   = self::map_images($source_images, $existing_images);

        // Sync and map categories
        $mapped_categories = self::map_categories($product->get_category_ids(), $api_client);

        // Sync and map tags
        $mapped_tags = self::map_tags($product->get_tag_ids(), $api_client);

        // Map attributes
        $mapped_attributes = self::map_attributes($product);

        $payload = [
            'name'              => $product->get_name(),
            'type'              => $product->get_type(),
            'status'            => $product->get_status(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku'               => $product->get_sku(),
            'manage_stock'      => $product->get_manage_stock(),
            'stock_status'      => $product->get_stock_status(),
            'weight'            => $product->get_weight(),
            'dimensions'        => [
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            ],
            'categories'        => $mapped_categories,
            'tags'              => $mapped_tags,
            'images'            => $mapped_images,
            'attributes'        => $mapped_attributes
        ];

        if ($product->get_manage_stock()) {
            $payload['stock_quantity'] = $product->get_stock_quantity();
        }

        if ($product->get_type() !== 'variable') {
            $payload['regular_price'] = $dest_regular_price;
            $payload['sale_price']    = $dest_sale_price;
        }

        return $payload;
    }

    /**
     * Map a local variation product to REST API variation payload.
     *
     * @param WC_Product_Variation $variation      Local variation object.
     * @param array|null           $dest_variation Existing destination variation data.
     * @return array                               API payload.
     */
    public static function get_variation_api_payload($variation, $dest_variation = null)
    {
        $opt = get_option('irb_sync_options');
        $price_diff = isset($opt['price_difference']) ? floatval($opt['price_difference']) : 500.0;

        $regular_price = $variation->get_regular_price();
        $sale_price    = $variation->get_sale_price();

        $dest_regular_price = '';
        $dest_sale_price    = '';

        if ($regular_price !== '') {
            $dest_regular_price = max(0.0, floatval($regular_price) - $price_diff);
            $dest_regular_price = strval($dest_regular_price);
        }
        if ($sale_price !== '') {
            $dest_sale_price = max(0.0, floatval($sale_price) - $price_diff);
            $dest_sale_price = strval($dest_sale_price);
        }

        // Gather single image for variation
        $image_id = $variation->get_image_id();
        $mapped_image = null;
        if ($image_id) {
            $img_url = wp_get_attachment_url($image_id);
            if ($img_url) {
                $existing_image = isset($dest_variation['image']) ? [$dest_variation['image']] : [];
                $mapped_img_array = self::map_images([['src' => $img_url]], $existing_image);
                if (!empty($mapped_img_array)) {
                    $mapped_image = $mapped_img_array[0];
                }
            }
        }

        // Map variation attributes
        $mapped_attributes = [];
        $variation_attributes = $variation->get_variation_attributes();
        foreach ($variation_attributes as $attr_slug => $value) {
            $taxonomy = str_replace('attribute_', '', $attr_slug);
            $name = wc_attribute_label($taxonomy, $variation);
            
            if (taxonomy_exists($taxonomy)) {
                $term = get_term_by('slug', $value, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $value = $term->name;
                }
            }

            $mapped_attributes[] = [
                'name'   => $name,
                'option' => $value
            ];
        }

        $payload = [
            'regular_price' => $dest_regular_price,
            'sale_price'    => $dest_sale_price,
            'sku'           => $variation->get_sku(),
            'manage_stock'  => $variation->get_manage_stock(),
            'stock_status'  => $variation->get_stock_status(),
            'weight'        => $variation->get_weight(),
            'dimensions'    => [
                'length' => $variation->get_length(),
                'width'  => $variation->get_width(),
                'height' => $variation->get_height(),
            ],
            'attributes'    => $mapped_attributes
        ];

        if ($mapped_image) {
            $payload['image'] = $mapped_image;
        }

        if ($variation->get_manage_stock()) {
            $payload['stock_quantity'] = $variation->get_stock_quantity();
        }

        return $payload;
    }

    /**
     * Map category terms to remote WooCommerce categories.
     */
    private static function map_categories($category_ids, $api_client)
    {
        $mapped = [];
        if (empty($category_ids)) {
            return $mapped;
        }

        foreach ($category_ids as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $dest_cat = $api_client->get_category_by_slug($term->slug);
            
            if ($dest_cat) {
                $mapped[] = ['id' => $dest_cat['id']];
            } else {
                $parent_dest_id = 0;
                if ($term->parent) {
                    $parent_term = get_term($term->parent, 'product_cat');
                    if ($parent_term && !is_wp_error($parent_term)) {
                        $parent_dest_cat = $api_client->get_category_by_slug($parent_term->slug);
                        if ($parent_dest_cat) {
                            $parent_dest_id = $parent_dest_cat['id'];
                        } else {
                            $parent_dest_id = self::create_dest_category_recursive($parent_term, $api_client);
                        }
                    }
                }

                $new_cat_data = [
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => $term->description,
                    'parent'      => $parent_dest_id
                ];
                
                $created_cat = $api_client->create_category($new_cat_data);
                if (!is_wp_error($created_cat) && isset($created_cat['id'])) {
                    $mapped[] = ['id' => $created_cat['id']];
                }
            }
        }

        return $mapped;
    }

    /**
     * Create category hierarchies recursively on the destination site.
     */
    private static function create_dest_category_recursive($term, $api_client)
    {
        $parent_dest_id = 0;
        if ($term->parent) {
            $parent_term = get_term($term->parent, 'product_cat');
            if ($parent_term && !is_wp_error($parent_term)) {
                $parent_dest_cat = $api_client->get_category_by_slug($parent_term->slug);
                if ($parent_dest_cat) {
                    $parent_dest_id = $parent_dest_cat['id'];
                } else {
                    $parent_dest_id = self::create_dest_category_recursive($parent_term, $api_client);
                }
            }
        }

        $new_cat_data = [
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => $parent_dest_id
        ];

        $created = $api_client->create_category($new_cat_data);
        if (!is_wp_error($created) && isset($created['id'])) {
            return $created['id'];
        }
        return 0;
    }

    /**
     * Map product tags to remote store.
     */
    private static function map_tags($tag_ids, $api_client)
    {
        $mapped = [];
        if (empty($tag_ids)) {
            return $mapped;
        }

        foreach ($tag_ids as $tag_id) {
            $term = get_term($tag_id, 'product_tag');
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $dest_tag = $api_client->get_tag_by_slug($term->slug);
            if ($dest_tag) {
                $mapped[] = ['id' => $dest_tag['id']];
            } else {
                $new_tag_data = [
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => $term->description
                ];
                $created_tag = $api_client->create_tag($new_tag_data);
                if (!is_wp_error($created_tag) && isset($created_tag['id'])) {
                    $mapped[] = ['id' => $created_tag['id']];
                }
            }
        }

        return $mapped;
    }

    /**
     * Map product attributes from local to API structure.
     */
    private static function map_attributes($product)
    {
        $mapped = [];
        $attributes = $product->get_attributes();
        if (empty($attributes)) {
            return $mapped;
        }

        foreach ($attributes as $attr_name => $attr_obj) {
            if (is_a($attr_obj, 'WC_Product_Attribute')) {
                if ($attr_obj->is_taxonomy()) {
                    $options = [];
                    $terms = $attr_obj->get_terms();
                    foreach ($terms as $term) {
                        $options[] = $term->name;
                    }
                    $name = wc_attribute_label($attr_obj->get_name(), $product);
                } else {
                    $options = $attr_obj->get_options();
                    $name = $attr_obj->get_name();
                }

                $mapped[] = [
                    'name'      => $name,
                    'position'  => $attr_obj->get_position(),
                    'visible'   => $attr_obj->get_visible(),
                    'variation' => $attr_obj->get_variation(),
                    'options'   => $options
                ];
            }
        }

        return $mapped;
    }

    /**
     * Helper to match existing images to prevent duplicates.
     */
    public static function map_images($source_images, $destination_existing_images = [])
    {
        $mapped = [];
        if (empty($source_images)) {
            return $mapped;
        }

        foreach ($source_images as $src_img) {
            $src_url      = $src_img['src'];
            $src_filename = basename(parse_url($src_url, PHP_URL_PATH));

            $matched_id = null;
            if (!empty($destination_existing_images)) {
                foreach ($destination_existing_images as $dest_img) {
                    $dest_url = isset($dest_img['src']) ? $dest_img['src'] : '';
                    if (empty($dest_url)) {
                        continue;
                    }
                    $dest_filename = basename(parse_url($dest_url, PHP_URL_PATH));

                    // Direct name check
                    if ($src_filename === $dest_filename) {
                        $matched_id = $dest_img['id'];
                        break;
                    }
                }
            }

            if ($matched_id) {
                $mapped[] = ['id' => $matched_id];
            } else {
                $mapped[] = ['src' => $src_url];
            }
        }

        return $mapped;
    }
}
