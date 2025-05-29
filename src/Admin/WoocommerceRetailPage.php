<?php
namespace FlourishWooCommercePlugin\Admin;

class WoocommerceRetailPage
{
    public function __construct()
    {
        // Register the necessary WooCommerce hooks.
        $this->register_hooks();
    }
 
    public function register_hooks()
    {
        // Handle stock adjustments on adding/removing cart items
        add_action('woocommerce_add_to_cart', [$this, 'reduce_stock_on_add'], 10, 2);
        add_action('woocommerce_cart_item_removed', [$this, 'restore_stock_on_remove'], 10, 2);
    }
     
       

    public function reduce_stock_on_add($cart_item_key, $product_id)
    {
         
        // Retrieve the cart item using the cart item key
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
           if (!$cart_item) {
            return;
        }

        // Get the cart quantity
        $cart_quantity = $cart_item['quantity'];

        // Check if the cart item has a variation
        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
            $variation_id = $cart_item['variation_id']; // Get the variation ID
            $variation_attributes = $cart_item['variation']; // Get the variation attributes (e.g., attribute_base-uom-ea)
            
            foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                // Clean attribute key (remove "attribute_")
                $taxonomy = 'pa_'. str_replace('attribute_base-uom-', '', $attribute_key);
                // Get the term by its name in the corresponding taxonomy
                $term = get_term_by('name', $attribute_value, $taxonomy);
                if ($term) {
                    $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                    $adjust_quantity = $cart_quantity * (int)$term_quantity;
                    $this->adjust_stock($product_id, -$adjust_quantity);
                    
                }
            }
        } else {
            // If no variation, adjust stock based on cart quantity directly
            $this->adjust_stock($product_id, -$cart_quantity);
        }
    }

    public function restore_stock_on_remove($cart_item_key, $cart)
    {
        $restore_quantity = 0;
        $cart_item = $cart->removed_cart_contents[$cart_item_key] ?? null;
        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
            $variation_id = $cart_item['variation_id']; // Get the variation ID
            $variation_attributes = $cart_item['variation']; // Get the variation attributes (e.g., attribute_base-uom-ea)
            foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                // Clean attribute key (remove "attribute_")
                $taxonomy = 'pa_'. str_replace('attribute_base-uom-', '', $attribute_key);
                // Get the term by its name in the corresponding taxonomy
                $term = get_term_by('name', $attribute_value, $taxonomy);
                if ($term) {
                    $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                    $restore_quantity = $cart_item['quantity']* (int)$term_quantity;
                }
            }
        }else {
            $restore_quantity = $cart_item['quantity'];
        }
        if ($cart_item) {
            
            $this->adjust_stock($cart_item['product_id'], $restore_quantity);
        }
    }

    private function adjust_stock($product_id, $quantity_change)
    {
        $product = wc_get_product($product_id);
        if ($product && $product->managing_stock()) {
            $current_stock = $product->get_stock_quantity();
            $new_stock = max(0, $current_stock + $quantity_change);
            $product->set_stock_quantity($new_stock);
            $product->save();
        }
    } 
    
}
