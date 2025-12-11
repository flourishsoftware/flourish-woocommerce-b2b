<?php

namespace FlourishWooCommercePlugin\Importer;

defined('ABSPATH') || exit;

use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Attribute;

class FlourishItems
{
    public $items = [];

    public function __construct($items)
    {
        $this->items = $items;
    }

    /**
     * Map Flourish items to WooCommerce products.
     *
     * @return array
     * @throws \Exception
     */
    public function map_items_to_woocommerce_products()
    {
        if (!count($this->items)) {
            throw new \Exception("No items to map.");
        }
        
        return array_map([$this, 'map_flourish_item_to_woocommerce_product'], $this->items);
    }

    /**
     * Save items as WooCommerce products.
     *
     * @param array $item_sync_options Options to determine which fields to update.
     * @return int Number of products imported or updated.
     */
    public function save_as_woocommerce_products($item_sync_options = [],$webhook_status)
    {
        $imported_count = 0;

        foreach ($this->map_items_to_woocommerce_products() as $product) {
            if (!strlen($product['sku'])) {
                continue;
            }

            $wc_product = $this->get_existing_or_new_product($product['sku'],$product['uom']);
           // $new_product = $wc_product->get_id() === 0;

            // Update product attributes
            $product_id = $this->update_product_attributes($wc_product, $product, $item_sync_options,$webhook_status);

           
            // Assign category if applicable
            if ((!empty($product['item_category']) )) {
                $this->assign_product_category($product['item_category'], $product_id);
            }
 
            // Assign brand if available
            if (!empty($product['brand'])) {
                error_log('Assigning brand: ' . $product['brand'] . ' to product ID: ' . $product_id);
                $this->assign_product_brand($product['brand'], $product_id);
            }


            // Trigger custom action after import
            do_action('flourish_item_imported', $product, $product_id);

            if ($product_id > 0) {
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Get an existing product by SKU or create a new one.
     *
     * @param string $sku
     * @return WC_Product_Simple
     */
    private function get_existing_or_new_product($sku,$uom)
    {
		 
        // Check for existing products by SKU
       // $existing_products = wc_get_products([
            //'sku' => $sku,
            //'limit' => 1,
       // ]);
           
		   $product_id=wc_get_product_id_by_sku($sku);            
            $attribute_exists = false;
            $attribute_uom=''; 
         
        if (!empty($product_id)) {
           $product = wc_get_product($product_id); // Get the full product object

            // Check if the existing product is a variable product
           if ($product->is_type('variable'))
           { 
             

            // If the product is a variation, get its parent
            if ($product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                $parent_product = wc_get_product($parent_id);
                error_log("Found variation SKU. Parent variable product ID: " . $parent_id);
                return $parent_product;
            }

            // Return the found product
            error_log("Found product ID: " . $product->get_id());
            return $product;
        }
        else
        {
            return $product;
        }
            
        }
        else
        {
            $attributes = wc_get_attribute_taxonomies();
            foreach ($attributes as $attribute) {  
            $taxonomy = 'pa_'. $attribute->attribute_name;
                if ($taxonomy === 'pa_'.sanitize_title($uom)) {
                $attribute_exists = true;
                $attribute_uom=$uom;
                break;
                }
            }      
        if ($attribute_exists)
        {
            
        $new_product = new WC_Product_Variable(); // Create a variable product
        }
        else
        {
             
        $new_product = new WC_Product_Simple(); // Create a variable product
       
        }
        $new_product->set_sku($sku); // Assign the SKU
        $new_product->set_status('draft'); // set  product status "draft"
        $new_product->save(); // Save to generate an ID

        error_log("New variable product created with ID: " . $new_product->get_id());
        return $new_product;
   
     }
    }


    /**
     * Update product attributes.
     *
     * @param WC_Product_Simple $wc_product
     * @param array $product
     * @param array $item_sync_options
     */
    private function update_product_attributes($wc_product, $product, $item_sync_options,$webhook_status)
    {
       
       // Save meta fields
       $this->save_custom_fields_automated($wc_product, $product);
        if (empty($item_sync_options['name']) || $item_sync_options['name']) {
            $wc_product->set_name($product['name']);
        }

        // Only set description if the product doesn't already have one
        $current_description = $wc_product->get_description();
        if (empty($current_description) && (!isset($item_sync_options['description']) || $item_sync_options['description'] === true)) {
            $wc_product->set_description($product['description']);
        }

        if (empty($item_sync_options['price']) || $item_sync_options['price']) {
            if($webhook_status === false)
            {
                $wc_product->set_price($product['price']);
                $wc_product->set_regular_price($product['price']);
                update_post_meta($wc_product->get_id(), '_price', $product['price']);
                update_post_meta($wc_product->get_id(), '_case_price', $product['price']);
            }
            else
            {
                $price = get_post_meta($wc_product->get_id(), '_case_price', true);
                $wc_product->set_price($price);
                $wc_product->set_regular_price($price);
                update_post_meta($wc_product->get_id(), '_price', $price);
                update_post_meta($wc_product->get_id(), '_case_price', $price);
            }
            
        }

        $wc_product->set_sku($product['sku']);
       
        // Enable stock management and set stock quantity
       
            if (method_exists($wc_product, 'set_manage_stock')) {
                $wc_product->set_manage_stock(true);
            } else 
            {
                $wc_product->update_meta_data('_manage_stock', 'yes');
            }
        
       
        $product_id = $wc_product->get_id();
        $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
        $flourish_stock = $product['inventory_quantity'];
        if ($flourish_stock >= 0) {
            $woocommerce_stock = abs($flourish_stock - $reserved_stock);
        } else {
            // Skip calculation or set a default value
            $woocommerce_stock = 0; // or null if you want to ignore
        }
        $wc_product->set_stock_quantity($woocommerce_stock); 
        // Save the product and get its ID
        $product_id = $wc_product->save(); // Persist changes to the database. 
        if ($wc_product->is_type('variable')) {
            $this->create_attributes($wc_product, $product);
            wc_delete_product_transients($wc_product->get_id()); // Clear product cache
        }
        
        
        return $product_id;
    }
    /**
 * Clean up orphaned variations when product attributes are updated
 */
public function cleanup_orphaned_variations_after_attribute_update($product_id)
{
    $product = wc_get_product($product_id);
    
    if (!$product || !$product->is_type('variable')) {
        return;
    }

    // Get current product attributes
    $current_attributes = $product->get_attributes();
    $valid_attribute_values = [];
    
    // Collect all valid attribute-value combinations
    foreach ($current_attributes as $attribute) {
        if ($attribute->get_variation()) {
            $taxonomy = $attribute->get_name();
            $terms = $attribute->get_terms();
            
            if (!empty($terms)) {
                foreach ($terms as $term) {
                    $valid_attribute_values['attribute_' . $taxonomy] = $term->name;
                }
            }
        }
    }

    // Get all existing variations
    $existing_variations = $product->get_children();
    
    foreach ($existing_variations as $variation_id) {
        $should_delete = false;
        
        // Get all variation attributes
        $variation_attributes = get_post_meta($variation_id);
        
        foreach ($variation_attributes as $meta_key => $meta_value) {
            if (strpos($meta_key, 'attribute_') === 0) {
                $value = is_array($meta_value) ? $meta_value[0] : $meta_value;
                
                // Check if this attribute-value combination is still valid
                if (!isset($valid_attribute_values[$meta_key]) || 
                    !in_array($value, (array)$valid_attribute_values[$meta_key])) {
                    $should_delete = true;
                    error_log("Variation {$variation_id} has invalid attribute: {$meta_key} = {$value}");
                    break;
                }
            }
        }
        
        if ($should_delete) {
            wp_delete_post($variation_id, true);
            error_log("Deleted orphaned variation: {$variation_id}");
        }
    }
    
    // Clear caches
    wc_delete_product_transients($product_id);
    wp_cache_delete($product_id, 'posts');
}
public function create_attributes_update($wc_product)
{
    $price = get_post_meta($wc_product->get_id(), '_case_price', true);
    $wc_product->set_price($price);
    $wc_product->set_regular_price($price);
    update_post_meta($wc_product->get_id(), '_price', $price);
    update_post_meta($wc_product->get_id(), '_case_price', $price);
    
    // Fetch the UOM value from the product meta
    $uom = get_post_meta($wc_product->get_id(), 'uom', true);
   
    if (empty($uom)) {           
        return; // Exit if no UOM value exists
    }

    // Clean up existing variations first
    //$this->cleanup_orphaned_variations_after_attribute_update($wc_product->get_id());
    
    $attributes = wc_get_attribute_taxonomies();
    $product_attributes = [];
    
    foreach ($attributes as $attribute) {
        $taxonomy = 'pa_'. $attribute->attribute_name;
        
        // Check if the attribute name slug matches the UOM value
        if ($taxonomy === 'pa_'.sanitize_title($uom) && taxonomy_exists($taxonomy)) {
            // Get existing product attributes to see what terms are already assigned
            $existing_attributes = $wc_product->get_attributes();
            $selected_terms = [];
            
            // If product already has this attribute, use its selected terms
            if (isset($existing_attributes[$taxonomy])) {
                $existing_options = $existing_attributes[$taxonomy]->get_options();
                $selected_terms = $existing_options;
                error_log("Using existing attribute terms for {$taxonomy}: " . implode(', ', $selected_terms));
            } else {
                // If no existing attribute, get the first term only (to create single variation)
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                if (!empty($terms)) {
                    // Only use the first term to create a single variation
                    $first_term = reset($terms);
                    $selected_terms = [$first_term->slug];
                    error_log("Using first term for {$taxonomy}: " . $first_term->slug);
                }
            }
            
            if (!empty($selected_terms)) {
                // Create product attribute with only selected terms
                $product_attribute = new WC_Product_Attribute();
                $product_attribute->set_name($taxonomy);
                $product_attribute->set_options($selected_terms);
                $product_attribute->set_visible(true);
                $product_attribute->set_variation(true);
                $product_attributes[] = $product_attribute;
            }
        }
    }

    // Set the product attributes on the WC_Product_Variable object
    $wc_product->set_attributes($product_attributes);
    $wc_product->save();

    // Generate Variations only for selected terms
    $attributes_data = [];
    foreach ($product_attributes as $attribute) {
        $taxonomy = $attribute->get_name();
        $options = $attribute->get_options();
        
        // Convert slugs to names for variation generation
        $term_names = [];
        foreach ($options as $slug) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term) {
                $term_names[] = $term->name;
            }
        }
        $attributes_data[$taxonomy] = $term_names;
    }

    if (!empty($attributes_data)) {
        $this->generate_product_variations($wc_product->get_id(), $attributes_data);
    }

    error_log('Attributes synced and variations created for product ID: ' . $wc_product->get_id());
}

/**
 * Helper method to get only the terms that should be used for a specific product
 * This method can be customized based on your business logic
 */
private function get_selected_terms_for_product($product_id, $taxonomy, $uom)
{
    // Method 1: Check if product already has specific terms assigned
    $product = wc_get_product($product_id);
    if ($product) {
        $existing_attributes = $product->get_attributes();
        if (isset($existing_attributes[$taxonomy])) {
            return $existing_attributes[$taxonomy]->get_options();
        }
    }
    
    // Method 2: Use product meta to determine which terms to use
    $preferred_term = get_post_meta($product_id, 'preferred_' . $uom . '_term', true);
    if ($preferred_term) {
        $term = get_term_by('name', $preferred_term, $taxonomy);
        return $term ? [$term->slug] : [];
    }
    
    // Method 3: Default to first term only (creates single variation)
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1]);
    return !empty($terms) ? [$terms[0]->slug] : [];
}

/**
 * Alternative method that forces single variation creation
 */
public function create_single_variation_update($wc_product, $preferred_term = null)
{
    $price = get_post_meta($wc_product->get_id(), '_case_price', true);
    $wc_product->set_price($price);
    $wc_product->set_regular_price($price);
    update_post_meta($wc_product->get_id(), '_price', $price);
    update_post_meta($wc_product->get_id(), '_case_price', $price);
    
    $uom = get_post_meta($wc_product->get_id(), 'uom', true);
   
    if (empty($uom)) {           
        return;
    }

    // Force cleanup of all existing variations
    $this->force_cleanup_variations($wc_product->get_id());
    
    $attributes = wc_get_attribute_taxonomies();
    $product_attributes = [];
    
    foreach ($attributes as $attribute) {
        $taxonomy = 'pa_'. $attribute->attribute_name;
        
        if ($taxonomy === 'pa_'.sanitize_title($uom) && taxonomy_exists($taxonomy)) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            
            if (!empty($terms)) {
                // Use preferred term or first available term
                $selected_term = null;
                if ($preferred_term) {
                    foreach ($terms as $term) {
                        if ($term->name === $preferred_term || $term->slug === $preferred_term) {
                            $selected_term = $term;
                            break;
                        }
                    }
                }
                
                // Fallback to first term
                if (!$selected_term) {
                    $selected_term = reset($terms);
                }
                
                if ($selected_term) {
                    $product_attribute = new WC_Product_Attribute();
                    $product_attribute->set_name($taxonomy);
                    $product_attribute->set_options([$selected_term->slug]);
                    $product_attribute->set_visible(true);
                    $product_attribute->set_variation(true);
                    $product_attributes[] = $product_attribute;
                    
                    error_log("Creating single variation with term: " . $selected_term->name);
                }
            }
        }
    }

    $wc_product->set_attributes($product_attributes);
    $wc_product->save();

    // Generate single variation
    if (!empty($product_attributes)) {
        $attributes_data = [];
        foreach ($product_attributes as $attribute) {
            $taxonomy = $attribute->get_name();
            $options = $attribute->get_options();
            
            $term_names = [];
            foreach ($options as $slug) {
                $term = get_term_by('slug', $slug, $taxonomy);
                if ($term) {
                    $term_names[] = $term->name;
                }
            }
            $attributes_data[$taxonomy] = $term_names;
        }

        $this->generate_product_variations($wc_product->get_id(), $attributes_data);
    }

    error_log('Single variation created for product ID: ' . $wc_product->get_id());
}
    /** fetch the woocommerce attributes */
    public function create_attributes($wc_product, $product)
    {
       
        // Fetch the UOM value from the product meta
        $uom = get_post_meta($wc_product->get_id(), 'uom', true);
       
        if (empty($uom)) {           
            update_post_meta($wc_product->get_id(), $product['uom'], true); 
            return; // Exit if no UOM value exists
        }

        $attributes = wc_get_attribute_taxonomies(); // Fetches all attribute taxonomies
        $product_attributes = [];

        foreach ($attributes as $attribute) {
            $taxonomy = 'pa_'. $attribute->attribute_name; // Example: pa_size  pa_g
            //$attribute_label='base_uom_'. $attribute->attribute_name; // Example:Base UOM - ea   base_uom_g


            // Check if the attribute name slug matches the UOM value
        if ($taxonomy === 'pa_'.sanitize_title($uom) && taxonomy_exists($taxonomy)) {
                // Fetch terms for the attribute
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                if (!empty($terms)) {
                    
                    $term_names = wp_list_pluck($terms, 'slug'); // Get term names
                    // Assign attribute to the product
                    $product_attribute = new WC_Product_Attribute();
                    $product_attribute->set_name($taxonomy);
                    $product_attribute->set_options($term_names);
                    $product_attribute->set_visible(true);
                    $product_attribute->set_variation(true);
                    $product_attributes[] = $product_attribute;
                }
            }
        }

        // Set the product attributes on the WC_Product_Variable object
        $wc_product->set_attributes($product_attributes);
        $wc_product->save();

        // Step 2: Generate Variations
        // Create variations based on the attributes
        $attributes_data = [];
        foreach ($product_attributes as $attribute) {
            $taxonomy = $attribute->get_name(); // Example: base_uom_g
            $options = $attribute->get_options(); // Example: Small, Medium, Large
            $attributes_data[$taxonomy] = $options;
        }

        if (!empty($attributes_data)) {
            $this->generate_product_variations($wc_product->get_id(), $attributes_data);
        }

        error_log('Attributes synced and variations created for product ID: ' . $wc_product->get_id());
    }


    private function generate_product_variations($product_id, $attributes_data)
    {
        // Fetch the product
        $product = wc_get_product($product_id);
    
        // Exit if the product is not a variable type
        if (!$product || !$product->is_type('variable')) {
            return;
        }
    
        // Check stock status; skip stock calculations if the product is out of stock
        if ($product->get_stock_status() === 'outofstock') {
            //error_log("Product ID $product_id is out of stock. No variations created.");
            //return;
        }
    
        // Ensure the attributes exist, if not, create them
        foreach ($attributes_data as $taxonomy => $options) {
            if (!taxonomy_exists($taxonomy)) {
                // Create taxonomy if it doesn't exist
                $this->create_attribute_taxonomy($taxonomy);
            }
    
            // Check if the terms exist for the attribute, if not, create them
            foreach ($options as $option) {
                $option = trim($option); // Trim whitespace
                if (!empty($option) && !term_exists($option, $taxonomy)) {
                    // Create the term if it doesn't exist
                    $result = wp_insert_term($option, $taxonomy);
                } 
            }
            
        }
    
        // Proceed with creating the variations
        $combinations = $this->get_attribute_combinations($attributes_data);
        $num_variations = count($combinations);
    
        $index = 0;
    
        foreach ($combinations as $combination) {
    
            // Check for existing variations to avoid duplicates
            $existing_variations = $product->get_children();
            $variation_exists = false;
    

            foreach ($existing_variations as $variation_id) {
                $existing_variation = wc_get_product($variation_id);
                $attributes_match = true;
    
                foreach ($combination as $taxonomy => $term_name) {
                    $existing_value = get_post_meta($variation_id, 'attribute_' . $taxonomy, true);
                    if ($existing_value !== $term_name) {
                        $attributes_match = false;
                        break;
                    }
                }
    
                if ($attributes_match) {
                    error_log("Variation already exists for combination: " . implode(', ', $combination));
                    $variation_exists = true;
                    break;
                }
            }
    
            if ($variation_exists) {
                $custom_price_multiplier = 1;
                // Loop through the combination to calculate the custom price multiplier
                foreach ($combination as $taxonomy => $term_name) {
                    $term = get_term_by('name', $term_name, $taxonomy);
                    if ($term) {  
                        // Get the quantity from the term metadata
                        $quantity = get_term_meta($term->term_id, 'quantity', true);
                        if ($quantity) {
                            error_log("Custom Field Value for term {$term_name}: " . var_export($quantity, true));
                            $custom_price_multiplier *= floatval($quantity); // Convert to numeric
                        }
                    }
                }
        
                // Ensure product price is numeric
                $product_price = floatval($product->get_price());
                $variation_price = $product_price * $custom_price_multiplier;
        
                // Set variation price
                update_post_meta($variation_id, '_regular_price', $variation_price);
                update_post_meta($variation_id, '_price', $variation_price);
                continue; // Skip creating duplicate variations
            }
    
            $variation_data = [
                'post_title' => $product->get_name() . ' - ' . implode(', ', $combination),
                'post_name' => 'product-' . $product_id . '-variation-' . sanitize_title(implode('-', $combination)),
                'post_status' => 'publish',
                'post_parent' => $product_id,
                'post_type' => 'product_variation',
            ];
    
            // Create the variation post
            $variation_id = wp_insert_post($variation_data);
    
            // Set variation attributes
            foreach ($attributes_data as $taxonomy => $options) {
                $variation_attribute = $taxonomy;
                $value = $combination[$taxonomy];
                update_post_meta($variation_id, 'attribute_' . $variation_attribute, $value);
            }
    
            $custom_price_multiplier = 1;
    
            // Loop through the combination to calculate the custom price multiplier
            foreach ($combination as $taxonomy => $term_name) {
                $term = get_term_by('name', $term_name, $taxonomy);
                if ($term) {  
                    // Get the quantity from the term metadata
                    $quantity = get_term_meta($term->term_id, 'quantity', true);
                    if ($quantity) {
                        error_log("Custom Field Value for term {$term_name}: " . var_export($quantity, true));
                        $custom_price_multiplier *= floatval($quantity); // Convert to numeric
                    }
                }
            }
    
            // Ensure product price is numeric
            $product_price = floatval($product->get_price());
            $variation_price = $product_price * $custom_price_multiplier;
    
            // Set variation price
            update_post_meta($variation_id, '_regular_price', $variation_price);
            update_post_meta($variation_id, '_price', $variation_price);
    
            // Update stock only if the product is in stock
            //if ($product->get_stock_quantity() > 0) {
                // Get the stock quantity from the term's metadata
                //$variation_stock = get_term_meta($term->term_id, 'quantity', true);
                //if ($variation_stock) {
                    // Set the stock for the variation using the term's quantity value
                    //update_post_meta($variation_id, '_stock', intval($variation_stock)); // Use the term's quantity for stock
                    //error_log("Variation Stock for {$term->name}: " . $variation_stock); // Log stock value for debugging
                //} else {
                    //update_post_meta($variation_id, '_stock', 0); // If no quantity found, set stock to 10
                //}
    
                //update_post_meta($variation_id, '_stock_status', 'instock');
                //update_post_meta($variation_id, '_manage_stock', 'no');
            //} else {
                //update_post_meta($variation_id, '_stock_status', 'outofstock');
                //update_post_meta($variation_id, '_manage_stock', 'no');
                //update_post_meta($variation_id, '_stock', 0); // No stock for variations
            //}
    
            // Set the default variation (first variation)
            if ($index === 0) {
                update_post_meta($product_id, '_default_attributes', [
                    $taxonomy => $combination[$taxonomy],
                ]);
            }
    
            $index++;
        }
    
        // Log success
        error_log("Variations successfully generated for product ID: $product_id");
    }
    
    /**
     * Generate all possible combinations of attributes.
     *
     * @param array $attributes_data Attribute data in the format ['attribute_slug' => ['term1', 'term2']].
     * @return array An array of combinations where each combination is an associative array.
     */
    private function get_attribute_combinations($attributes_data)
    {
    $combinations = [[]]; // Start with an empty combination

    foreach ($attributes_data as $attribute => $terms) {
        $new_combinations = [];

        foreach ($combinations as $combination) {
            foreach ($terms as $term) {
                $new_combinations[] = array_merge($combination, [$attribute => $term]);
            }
        }

        $combinations = $new_combinations;
    }

    return $combinations;
    }

    /**
     * Create attribute taxonomy if it doesn't exist
     */
    private function create_attribute_taxonomy($taxonomy)
    {
        // Create the taxonomy for the attribute if it doesn't exist
        $args = array(
            'label' => ucfirst($taxonomy),
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => $taxonomy),
        );
    
        register_taxonomy($taxonomy, 'product', $args);
    }
    

    /**
     * Save custom fields dynamically.
     *
     * @param WC_Product_Simple $wc_product
     * @param array $product
     */
    private function save_custom_fields_automated($wc_product, $product)
    {
        $fields = [
            'uom' => 'uom',
            'uom_description' => 'uom_description',
            'unit_weight' => 'unit_weight',
            'weight_uom' => 'weight_uom',
            'weight_uom_description' => 'weight_uom_description',
        ];
       
        foreach ($fields as $meta_key => $field_name) {
            if (isset($product[$field_name])) {
                $wc_product->update_meta_data($meta_key, $product[$field_name]);
            }
        }

        $wc_product->update_meta_data('flourish_item_id', $product['flourish_item_id']);
    }

    /* Assign a brand to a product
    * 
    * @param string $brand Brand name
    * @param int $product_id WooCommerce product ID
    */
    public function assign_product_brand($brand, $product_id) {
        if (empty($brand) || empty($product_id)) {
            error_log("Missing brand or product ID - Brand: {$brand}, Product ID: {$product_id}");
            return false;
        }
        
        // Use the correct taxonomy
        $taxonomy = 'product_brand';
        
        // Check if the term exists (case-insensitive search)
        $existing_terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'name__like' => $brand
        ]);
        
        $term_id = null;
        
        // Look for an exact match (case-insensitive)
        if (!empty($existing_terms)) {
            foreach ($existing_terms as $existing_term) {
                if (strtolower($existing_term->name) === strtolower($brand)) {
                    $term_id = $existing_term->term_id;
                    error_log("Found existing brand term with ID: {$term_id} for brand: {$brand}");
                    break;
                }
            }
        }
        
        // If no term found, create it
        if (!$term_id) {
            $term = wp_insert_term($brand, $taxonomy);
            if (is_wp_error($term)) {
                error_log('Error creating brand term: ' . $term->get_error_message());
                return false;
            }
            $term_id = $term['term_id'];
            error_log("Created new brand term with ID: {$term_id} for brand: {$brand}");
        }
        
        // Clear existing brands and assign the new one
        $result = wp_set_object_terms($product_id, array($term_id), $taxonomy, false);
        
        if (is_wp_error($result)) {
            error_log('Error assigning brand term: ' . $result->get_error_message());
            return false;
        } else {
            error_log("Successfully assigned brand term ID {$term_id} to product ID {$product_id}");
        }
        
        // Clear cache
        clean_object_term_cache($product_id, $taxonomy);
        wp_cache_flush(); // More aggressive cache clearing
        
        return true;
    }

    /**
     * Assign a category to the WooCommerce product.
     *
     * @param string $category_name The category name to assign.
     * @param int $product_id The WooCommerce product ID.
     * @throws \Exception If there is an error inserting the category term.
     */
    private function assign_product_category($category_name, $product_id)
    {
        $term = term_exists($category_name, 'product_cat');

        if (!$term) {
            $term = wp_insert_term($category_name, 'product_cat');
        }

        if (!is_wp_error($term)) {
            $term_id = $term['term_id'] ?? $term['term_taxonomy_id'];
            wp_set_object_terms($product_id, (int)$term_id, 'product_cat');
        } else {
            throw new \Exception("Error inserting category term.");
        }
    }

    /**
     * Map a single Flourish item to a WooCommerce product.
     *
     * @param array $flourish_item
     * @return array
     */
    private function map_flourish_item_to_woocommerce_product($flourish_item)
    {
        return [
            'flourish_item_id' => $flourish_item['id'],
            'item_category' => $flourish_item['item_category'],
            'name' => $flourish_item['item_name'],
            'description' => $flourish_item['item_description'],
            'sku' => $flourish_item['sku'],
            'price' => $flourish_item['price'],
            'uom' => $flourish_item['uom'],
            'uom_description' => $flourish_item['uom_description'],
            'unit_weight' => $flourish_item['unit_weight'],
            'weight_uom' => $flourish_item['weight_uom'],
            'weight_uom_description' => $flourish_item['weight_uom_description'],            
            'inventory_quantity' => $flourish_item['inventory_quantity'],
            'brand'=>$flourish_item['brand'],
        ];
    }
}



