<?php
namespace FlourishWooCommercePlugin\Admin;
 
class ProductCustomFields
{
    public function __construct()
    {
        // Register the necessary WooCommerce hooks.
        $this->register_hooks();
    }
 
    public function register_hooks()
    {
        //Add the action hooks to display and save custom fields.
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_custom_fields']);
        //Add "Hold Stock" below stock field in the Inventory tab
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'add_custom_fields_inventory']);       
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_fields']);
        //Enforce min/max quantities in the cart
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_min_max_order_quantity'], 10, 3);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_min_max_order_quantity']);
    }

    public function add_custom_fields_inventory()
    {
        global $post;

        $fields = [
            '_held_stock' => [
                'label' => __('Held Stock', 'woocommerce'),
                'description' => __('Stock temporarily set aside when a customer adds a product to their cart.', 'woocommerce')
            ],
            '_reserved_stock' => [
                'label' => __('Reserved Stock', 'woocommerce'),
                'description' => __('Stock allocated for confirmed orders but not yet fulfilled.', 'woocommerce')
            ]
        ];
        
        echo '<div class="options_group">';
        foreach ($fields as $id => $field) {
            woocommerce_wp_text_input([
                'id' => $id,
                'label' => $field['label'],
                'description' => $field['description'],
                'type' => 'number',
                'value' => get_post_meta($post->ID, $id, true) ?: 0,
                'desc_tip' => true,
                'custom_attributes' => ['readonly' => 'readonly']
            ]);
        }
        echo '</div>'; 
    }


    public function add_custom_fields()
    {
            global $post;
            // Get the WC_Product object.
            $wc_product = wc_get_product($post->ID);
            $product_id = $wc_product->get_id();
            // Retrieve the regular price.
            $regular_price = get_post_meta($product_id, '_price', true);
            // Define the fields and their properties.
            $fields = [
                'unit_weight' => [
                    'label' => __('Unit Weight', 'flourish-woocommerce'),
                    'description' => __('Enter the weight of the unit', 'flourish-woocommerce'),
                    'type' => 'text',
                    'value' => get_post_meta($post->ID, 'unit_weight', true),
                ],
                '_case_size_base_uom' => [
                    'label' => __('Case Size in Base UOM', 'flourish-woocommerce'),
                    'description' => __('Enter the number of base units in a case', 'flourish-woocommerce'),
                    'type' => 'number',
                    'value' => 1,
                ],
                'uom' => [
                    'label' => __('Case Alias', 'flourish-woocommerce'),
                    'description' => __('Enter an alias for the case (e.g., Box, Carton)', 'flourish-woocommerce'),
                    'type' => 'text',
                    'value' => get_post_meta($post->ID, 'uom', true),
                ],
                'uom_description' => [
                    'label' => __('Case Alias Description', 'flourish-woocommerce'),
                    'description' => __('Enter an Description for the case alias(e.g., Each, Gram)', 'flourish-woocommerce'),
                    'type' => 'text',
                    'value' => get_post_meta($post->ID, 'uom_description', true),
                ],
                'price' => [
                    'label' => __('Case Price', 'flourish-woocommerce'),
                    'description' => __('Enter the price for the case', 'flourish-woocommerce'),
                    'type' => 'number',
                    'value' => $regular_price,
                ],
                '_min_order_quantity' => [
                    'label' => __('Minimum Order Quantity', 'woocommerce'),
                    'description' => __('Set the minimum quantity customers can order.', 'woocommerce'),
                    'type' => 'text',
                    'value' => get_post_meta($post->ID, '_min_order_quantity', true),
                    'custom_attributes' => ['step' => '1', 'min' => '1', 'maxlength' => '4'],
                ],
                '_max_order_quantity' => [
                    'label' => __('Maximum Order Quantity', 'woocommerce'),
                    'description' => __('Set the maximum quantity customers can order.', 'woocommerce'),
                    'type' => 'text',
                    'value' => get_post_meta($post->ID, '_max_order_quantity', true),
                    'custom_attributes' => ['step' => '1', 'min' => '1', 'maxlength' => '4'],
                ]
            ];
 
            echo '<div class="options_group">';
 
            // Loop through the fields and output them dynamically
            foreach ($fields as $id => $field)
            {
                woocommerce_wp_text_input([
                'id' => $id,
                'label' => $field['label'],
                'description' => $field['description'],
                'type' => $field['type'],
                'value' => isset($field['value']) ? $field['value'] : '',
                'desc_tip' => true,
                'custom_attributes' => isset($field['custom_attributes']) ? $field['custom_attributes'] : []
                ]);
            }
 
            echo '</div>';
    }
         
    public function save_custom_fields($post_id)
    {
        $fields = [
            'unit_weight' => 'text',
            '_case_size_base_uom' => 'text',
            'uom' => 'text',
            'uom_description' => 'text',
            'price' => 'text',
            '_min_order_quantity'=>'number',
            '_max_order_quantity'=>'number',
        ];
        foreach ($fields as $field => $type) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
    }
 
   // Function to validate quantity during Add to Cart
   public function validate_min_max_order_quantity($passed, $product_id, $quantity)
   {
       $min_quantity = get_post_meta($product_id, '_min_order_quantity', true);
       $max_quantity = get_post_meta($product_id, '_max_order_quantity', true);
 
       if (!empty($min_quantity) && $quantity < $min_quantity) {
           wc_add_notice(sprintf(__('You must purchase at least %s of this product.', 'your-text-domain'), $min_quantity), 'error');
           $passed = false;
       }
 
       if (!empty($max_quantity) && $quantity > $max_quantity) {
           wc_add_notice(sprintf(__('You can only purchase a maximum of %s of this product.', 'your-text-domain'), $max_quantity), 'error');
           $passed = false;
       }
       return $passed;
   }
 
   // Function to validate quantity in the Cart page
   public function validate_cart_min_max_order_quantity()
   {
       foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
           $product_id = $cart_item['product_id'];
           $quantity = $cart_item['quantity'];
 
           $min_quantity = get_post_meta($product_id, '_min_order_quantity', true);
           $max_quantity = get_post_meta($product_id, '_max_order_quantity', true);
 
           if (!empty($min_quantity) && $quantity < $min_quantity) {
               wc_add_notice(sprintf(__('Product "%s" requires a minimum quantity of %s.', 'your-text-domain'), $cart_item['data']->get_name(), $min_quantity), 'error');
           }
 
           if (!empty($max_quantity) && $quantity > $max_quantity) {
               wc_add_notice(sprintf(__('Product "%s" allows a maximum quantity of %s.', 'your-text-domain'), $cart_item['data']->get_name(), $max_quantity), 'error');
           }
       }
   }
}  