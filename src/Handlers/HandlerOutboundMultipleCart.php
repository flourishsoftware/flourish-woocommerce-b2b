<?php

/**
 * Class Handler: WooCommerce Multi-Cart with Save Feature
 * Description: Allow users to save multiple carts and checkout using saved carts.
 */

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\Handlers\HandlerOutboundUpdateCart;
class HandlerOutboundMultipleCart
{
   
    /**
     * Registers hooks and actions for the plugin functionality.
     */
    public function register_hooks()
    {
        add_action('wp_enqueue_scripts', [$this, 'mc_enqueue_cart_scripts']);
        // Save Cart Button Shortcode
        add_action('woocommerce_cart_actions', [$this, 'custom_return_to_shop_button'], 10); // Custom Return to Shop button
        // Save to cart handler
        add_action('wp_ajax_mc_save_cart', [$this, 'mc_save_cart']);
        // Save cart page shortcode
        add_shortcode('mc_saved_carts', [$this, 'mc_saved_carts_shortcode']);
        add_action('woocommerce_cart_is_empty', [$this, 'mc_add_saved_carts_to_cart_page']);
        add_action('woocommerce_before_cart', [$this, 'mc_add_saved_carts_to_cart_page'], 10); // Default priority
        add_action('wp_ajax_mc_pre_toggle_saved_carts', [$this, 'mc_pre_toggle_saved_carts']);
        // Load saved cart handler
        add_action('wp_ajax_mc_load_saved_cart', [$this, 'mc_load_saved_cart']);
        // Add this to handle AJAX requests for updating the saved cart
        add_action('wp_ajax_mc_update_saved_cart_ajax', [$this, 'mc_update_saved_cart_ajax']);
        // Delete saved cart handler
        add_action('wp_ajax_mc_delete_saved_cart', [$this, 'mc_delete_saved_cart']);
        // Delete the saved cart after the order is placed
        add_action('woocommerce_thankyou', [$this, 'mc_delete_saved_cart_after_order']);
        add_action('wp_ajax_mc_cleanup_cart', [$this, 'mc_cleanup_cart']);
        add_action('wp_ajax_nopriv_mc_cleanup_cart', [$this, 'mc_cleanup_cart']);
        add_action('woocommerce_cart_item_removed', [$this, 'mc_handle_cart_item_removed'], 10, 2);
        add_action('wp_ajax_mc_clear_current_cart', [$this,'mc_clear_current_cart']); 
        add_action('wp_ajax_nopriv_mc_clear_current_cart', [$this,'mc_clear_current_cart']);
    }   

    public function mc_clear_current_cart() {
        // Check if WooCommerce is available
        if (!function_exists('WC')) {
            wp_send_json_error(['message' => 'WooCommerce is not available.']);
            return;
        }

        // Get confirmation status
        $confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] === 'yes';
        // Get the loaded cart name from the session
        $loaded_cart_name = WC()->session->get('mc_current_cart_name');

        if ($confirmed && !$loaded_cart_name) {
            // Get the current cart items
            $current_cart_items = WC()->cart->get_cart();

            foreach ($current_cart_items as $cart_item_key => $cart_item) {
                $adjust_st = new HandlerOutboundUpdateCart();

                if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
                    foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                        $taxonomy = str_replace('attribute_', '', $attribute_key);
                        $term = get_term_by('name', $attribute_value, $taxonomy);
                        if ($term) {
                            $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                            $adjust_quantity = $cart_item['quantity'] * (int)$term_quantity;
                            $adjust_st->adjust_stock($cart_item['product_id'], $adjust_quantity);
                        }
                    }
                } else {
                    $adjust_st->adjust_stock($cart_item['product_id'], $cart_item['quantity']);
                }
            }

            // Clear the current cart
            WC()->cart->empty_cart();
            // Send a success response
            wp_send_json_success([
                'message' => 'Cart cleared successfully.'
            ]);
        }

        if (!$loaded_cart_name) {
            wp_send_json_error(['message' => 'No saved cart is currently loaded.']);            
        }

        // Retrieve the saved carts for the current user
        $user_id = get_current_user_id();
        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true);
        if (!isset($multi_carts[$loaded_cart_name])) {
            wp_send_json_error(['message' => 'The loaded cart no longer exists.']);
            return;
        }

        // Retrieve the loaded cart items
        $loaded_cart_items = $multi_carts[$loaded_cart_name];
        // Compare the current cart items with the loaded cart items
        $current_cart_items = WC()->cart->get_cart();
        $unmatched_cart_items = []; // Array to store items not matching the loaded cart

        foreach ($current_cart_items as $cart_item_key => $cart_item) {
            // Check if the current cart item matches any item in the loaded cart
            $matched = false;
            foreach ($loaded_cart_items as $loaded_item_key => $loaded_item) {
                if (
                    $cart_item['product_id'] == $loaded_item['product_id'] &&
                    $cart_item['variation_id'] == $loaded_item['variation_id'] &&
                    $cart_item['quantity'] == $loaded_item['quantity']
                ) {
                    $matched = true;
                    break;
                }
            }
    
            // If no match is found, add the item to the unmatched items array
            if (!$matched) {
                $unmatched_cart_items[$cart_item_key] = $cart_item;
            }
        }

        // Process unmatched cart items for stock adjustments
        if (!empty($unmatched_cart_items)) {
            $adjust_st = new HandlerOutboundUpdateCart();

            foreach ($unmatched_cart_items as $cart_item_key => $cart_item) {
                if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
                    foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                        // Clean attribute key (remove "attribute_")
                        $taxonomy = str_replace('attribute_', '', $attribute_key);

                        // Get the term by its name in the corresponding taxonomy
                        $term = get_term_by('name', $attribute_value, $taxonomy);
                        if ($term) {
                            $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                            $adjust_quantity = $cart_item['quantity'] * (int)$term_quantity;
                            $adjust_st->adjust_stock($cart_item['product_id'], $adjust_quantity);
                        }
                    }
                } else {
                    // Adjust stock for non-variation products
                    $adjust_st->adjust_stock($cart_item['product_id'], $cart_item['quantity']);
                }
            }
        }

        // Clear the current cart
        WC()->cart->empty_cart();
        WC()->session->__unset('mc_current_cart_name');

        // Reset the session for the loaded cart
        WC()->session->set('mc_current_cart_name', null);

        // Force session save to ensure changes take effect
        remove_action('woocommerce_before_cart', 'wc_print_notices', 10);
        remove_action('woocommerce_cart_is_empty', 'wc_print_notices', 10);

        // Send a success response
        wp_send_json_success([
            'message' => 'Cart cleared successfully.',
            'unmatched_items_processed' => !empty($unmatched_cart_items)
        ]);
    }

        

    /**
     * Enqueues scripts and styles for the cart page.
     */
    public function mc_enqueue_cart_scripts()
    {
        if (is_cart()) {
            wp_enqueue_script('mc-save-cart', plugin_dir_url(dirname(__FILE__)) . '../assets/js/flourish-custom-script.js', ['jquery'], '1.0', true);
            wp_localize_script('mc-save-cart', 'mc_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mc_ajax_nonce')
            ]);
            // Enqueue CSS file
            wp_enqueue_style('mc-cart-styles', plugin_dir_url(dirname(__FILE__)) . '../assets/css/save-cart-style.css', [], '1.0', 'all');
        }
    }

    public function mc_cleanup_cart()
    {
        // Clean up expired saved carts
        mc_remove_expired_saved_carts();
    }

     
    /**
     * AJAX handler to save the current cart under a specified name.
     */
    public function mc_save_cart()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to save the cart.']);
        }

        if (empty($_POST['cart_name'])) {
            wp_send_json_error(['message' => 'Cart name is required.']);
        }

        // Clean up expired saved carts
        mc_remove_expired_saved_carts();

        $cart_name = sanitize_text_field($_POST['cart_name']);
        $user_id = get_current_user_id();

        // Fetch existing saved carts or initialize empty
        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true) ?: [];

        // Check if cart name already exists
        if (isset($multi_carts[$cart_name])) {
            wp_send_json_error(['message' => 'A cart with this name already exists.']);
        }

        // Get current WooCommerce cart items
        $cart_items = WC()->cart->get_cart();
        if (empty($cart_items)) {
            wp_send_json_error(['message' => 'Your cart is empty.']);
        }

        // Save the cart
        $multi_carts[$cart_name] = $cart_items;
        update_user_meta($user_id, 'mc_multi_carts', $multi_carts);

        // Clear the WooCommerce cart
        WC()->cart->empty_cart();

        wp_send_json_success(['message' => 'Cart saved successfully as "' . $cart_name . '".']);
    }

    /**
     * Retrieves saved carts for the logged-in user.
     *
     * @return array List of saved carts.
     */
    public function mc_get_saved_carts()
    {
        if (!is_user_logged_in()) {
            return [];
        }

        $user_id = get_current_user_id();
        return get_user_meta($user_id, 'mc_multi_carts', true) ?: [];
    }

    /**
     * Shortcode to display saved carts section.
     *
     * @return string HTML output for saved carts.
     */
    public function mc_saved_carts_shortcode() { 
    
        // Clean up expired saved carts
        mc_remove_expired_saved_carts();
    
        $user_id = get_current_user_id();
        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true) ?: [];  
        ob_start();
    
        if (!empty($multi_carts)) {
           
            echo '<div id="mc-saved-carts-section">';
            echo '<h3><button id="mc-view-saved-carts-btn" class="button">Click to view your Saved Carts</button></h3>'; // Button to toggle visibility of saved carts
            echo '<div id="mc-saved-carts-list"  class="mc-hidden">'; // Initially hide the saved carts
            echo '<table style="width:auto;">';
    
            foreach ($multi_carts as $cart_name => $cart_items) {
    
                echo '<tr>
                    <td align="left"><strong>' . esc_html($cart_name) . '</strong></td>
                    <td align="left"><button class="button mc-load-cart-btn" data-cart-name="' . esc_attr($cart_name) . '">Select Cart and Checkout</button></td>
                    <td align="left"><button class="button mc-delete-cart-btn" data-cart-name="' . esc_attr($cart_name) . '">Delete</button></td>
                </tr>';
            }
    
            echo '</table>';
            echo '</div>'; // End the saved carts list div
            echo '</div>';
        }
    
        // Only store and add the notice if the cart is newly loaded
        $cart_name = WC()->session->get('mc_current_cart_name'); // Check if a cart name exists in the session
        //WC()->session->__unset('mc_current_cart_name');
        // Ensure the notice is displayed only once by checking a session variable set by WooCommerce
        if ($cart_name && !empty($cart_name)) {
            // Check if the notice has already been added for the session and if we are on the cart page
            if (is_cart()) {
                wc_add_notice('<strong>You are viewing the saved cart: ' . esc_html($cart_name) . '</strong>', 'success');
                }
        }
    
        return ob_get_clean();
    }
    


    /**
     * Adds the saved carts section to the cart page if conditions are met.
     */
    public function mc_add_saved_carts_to_cart_page()
    {
         
            if (!is_user_logged_in()) {
                $login_url = wc_get_page_permalink('myaccount');
                $redirect_url = add_query_arg('redirect_to', urlencode(wc_get_cart_url()), $login_url);
                echo '<p class="alert-box-message">Please <a class="log-link" href="' . esc_url($redirect_url) . '"> login</a> to add or view your saved cart.</p>';
            }
            else {
                echo do_shortcode('[mc_saved_carts]');
            }            
             
         
    }

    function mc_pre_toggle_saved_carts()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'User not logged in.']);
            return;
        }

        $user_id = get_current_user_id();
        $loaded_cart_name = WC()->session->get('mc_current_cart_name');
        $current_cart_items = WC()->cart->get_cart();
        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true);

        if (!$loaded_cart_name || empty($multi_carts) || !isset($multi_carts[$loaded_cart_name])) {
            $unsaved_items_exist = false;
        }
        if (!empty($current_cart_items)) {
            // Get the saved cart items
            $saved_cart_items = $multi_carts[$loaded_cart_name];
            $unsaved_items_exist = false;

            // Compare current cart items with saved cart items
            foreach ($current_cart_items as $cart_item_key => $cart_item) {
                $found = false;
                foreach ($saved_cart_items as $saved_item_key => $saved_item) {
                    if ($cart_item['product_id'] === $saved_item['product_id'] && $cart_item['variation_id'] === $saved_item['variation_id'] &&
                        $cart_item['quantity'] === $saved_item['quantity']) {
                        $adjust_st = new HandlerOutboundUpdateCart;

                        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
                            foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                                // Clean attribute key (remove "attribute_")
                                $taxonomy = str_replace('attribute_', '', $attribute_key);
        
                                // Get the term by its name in the corresponding taxonomy
                                $term = get_term_by('name', $attribute_value, $taxonomy);
                                if ($term) {
                                    $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                                    $adjust_quantity = $cart_item['quantity'] * (int)$term_quantity;
                                    //$adjust_st->adjust_stock($cart_item['product_id'], $adjust_quantity);
                                }
                            }
                        } else {
                            // Adjust stock for non-variation products
                            //$adjust_st->adjust_stock($cart_item['product_id'], $cart_item['quantity']);
                        }
                        $found = true;
                        break;
                    }
                }
                if (!$found) {                    
                    $unsaved_items_exist = true;
                    break;
                }
            }
        }

        if ($unsaved_items_exist) {
            wp_send_json_success([
                'unsaved_items' => $unsaved_items_exist,
                'message' => 'Please update your cart. Unsaved items exist and will not be saved to the selected saved cart.',
            ]);
        } else {
            wp_send_json_success(['unsaved_items' => $unsaved_items_exist]);
        }
    }

    
    /**
     * AJAX handler to load a saved cart into the current WooCommerce cart.
     */
    public function mc_load_saved_cart()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to load a saved cart.']);
        }

        if (empty($_POST['cart_name'])) {
            wp_send_json_error(['message' => 'Cart name is required.']);
        }

        $cart_name = sanitize_text_field($_POST['cart_name']);
        $user_id = get_current_user_id();

        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true) ?: [];

        if (!isset($multi_carts[$cart_name])) {
            wp_send_json_error(['message' => 'Saved cart not found.']);
        }

        // Clear the current cart and load the saved cart
        WC()->cart->empty_cart();  
       
        WC()->session->set('mc_current_cart_name', $cart_name); // Get the current loaded cart name
        //WC()->session->__unset('mc_current_cart_name');
        foreach ($multi_carts[$cart_name] as $cart_item_key => $cart_item) {
            $cart_item['cart_item_data']['saved_cart_item'] = 1;
            $cart_item['cart_item_data']['mc_cart_name'] = $cart_name;
             $cart_item['cart_item_data']['reservation_expiration_time'] =  $cart_item['reservation_expiration_time'];

             $cart_test_key =  WC()->cart->add_to_cart($cart_item['product_id'], $cart_item['quantity'], $cart_item['variation_id'], $cart_item['variation'], $cart_item['cart_item_data']);
             $cart_test = WC()->cart->get_cart_item($cart_test_key);
             if ($cart_test) {
                // Get the added cart item using the cart item key
                $cart_items = WC()->cart->get_cart_item($cart_test_key);
            
                // Ensure the reservation_expiration_time exists, and update it if necessary
                if (isset($cart_items['reservation_expiration_time'])) {
                    // Use the existing reservation expiration time from the saved cart
                    $reservation_expiration_time = $cart_item['reservation_expiration_time'];
                }
            
                // Update the reservation_expiration_time in the cart item data
                WC()->cart->cart_contents[$cart_test_key]['reservation_expiration_time'] = $reservation_expiration_time;
            
                // Optionally, update the cart item in WooCommerce (optional but can ensure data consistency)
                WC()->cart->set_session();
                $cart_item = WC()->cart->get_cart_item($cart_test_key);
            }
           
            }
     

        wp_send_json_success(['redirect_url' => wc_get_cart_url()]);
    }

    public function mc_update_saved_cart_ajax()
    {
        if (!is_user_logged_in() || !isset($_POST['cart_name'])) {
            wp_send_json_error(['message' => 'Invalid request.']);
            return;
        }

        $cart_name = sanitize_text_field($_POST['cart_name']);
        $this->mc_update_saved_cart($cart_name, WC()->cart->get_cart());

        wp_send_json_success(['message' => '"' . $cart_name . '" cart updated successfully.']);
    }

    public function mc_update_saved_cart($cart_name, $cart_items)
    {
        $user_id = get_current_user_id();
        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true) ?: [];

        if (isset($multi_carts[$cart_name])) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = $cart_item['product_id'];
                $variation_id = $cart_item['variation_id'];
                $quantity = $cart_item['quantity'];
                $variation = $cart_item['variation'];
                $reservation_expiration_time =  $cart_item['reservation_expiration_time'];

                // Check if item already exists in the saved cart
                $exists = false;
                foreach ($multi_carts[$cart_name] as &$saved_item) {
                    if ($saved_item['product_id'] == $product_id && $saved_item['variation_id'] == $variation_id) {
                        $saved_item['quantity'] = $quantity;
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $multi_carts[$cart_name][] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'variation_id' => $variation_id,
                        'variation' => $variation,
                        'reservation_expiration_time'=> $reservation_expiration_time,
                    ];
                }
            }
            update_user_meta($user_id, 'mc_multi_carts', $multi_carts);
        }
    }


    /**
     * AJAX handler to delete a saved cart for the logged-in user.
     */
    public function mc_delete_saved_cart()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to delete a saved cart.']);
        }
    
        if (empty($_POST['cart_name'])) {
            wp_send_json_error(['message' => 'Cart name is required.']);
        }
    
        $cart_name_to_delete = sanitize_text_field($_POST['cart_name']);
        $user_id = get_current_user_id();
    
        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true) ?: [];
        $loaded_cart_name = WC()->session->get('mc_current_cart_name');
    
        if (isset($multi_carts[$cart_name_to_delete])) {
            $cart_items_to_delete = $multi_carts[$cart_name_to_delete];
    
            // Restore stock for items in the specific cart being deleted
            foreach ($cart_items_to_delete as $cart_item_key => $cart_item_data) {
                // Restore stock for expired item
                if (isset($cart_item_data['variation_id']) && isset($cart_item_data['variation'])) {
                    restore_stock_on_remove($cart_item_key, (object) ['removed_cart_contents' => [$cart_item_key => $cart_item_data]]);
                } else {
                    // Handle simple products
                    restore_stock_on_remove($cart_item_key, (object) ['removed_cart_contents' => [$cart_item_key => $cart_item_data]]);
                }
            }
    
            // Remove the saved cart
            unset($multi_carts[$cart_name_to_delete]);
    
            // Update the saved carts in user meta
            update_user_meta($user_id, 'mc_multi_carts', $multi_carts);
    
            // Check if the deleted cart is the currently loaded cart
            if ($cart_name_to_delete === $loaded_cart_name) {
                WC()->session->__unset('mc_current_cart_name');
                WC()->cart->empty_cart(); // Empty the cart as it was the loaded cart
            }
    
            // Force session save to ensure changes take effect
            WC()->session->set('mc_current_cart_name', null);
    
            wp_send_json_success(['message' => 'Saved cart deleted successfully.']);
        } else {
            wp_send_json_error(['message' => 'Saved cart not found.']);
        }
    }
    
    /**
     * AJAX handler to Delete the saved cart after the order is placed
     */
    public function mc_delete_saved_cart_after_order($order_id)
    {
        if (!is_user_logged_in()) {
            return; // Only handle logged-in users
        }

        $user_id = get_current_user_id();
        $cart_name = WC()->session->get('mc_current_cart_name'); // Retrieve the saved cart name used for checkout

        if (!$cart_name) {
            return; // No saved cart associated with this order
        }

        // Retrieve user's saved carts
        $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true) ?: [];

        // Check if the cart exists and delete it
        if (isset($multi_carts[$cart_name])) {
            unset($multi_carts[$cart_name]);
            update_user_meta($user_id, 'mc_multi_carts', $multi_carts);

            // Optionally clear the session value
            WC()->session->__unset('mc_current_cart_name');
            WC()->session->set('mc_current_cart_name', null);
        }
    }

    public function mc_handle_cart_item_removed($cart_item_key, $cart)
    {
        $loaded_cart_name = WC()->session->get('mc_current_cart_name');
        if (!$loaded_cart_name) {
            return;
        }

        $cart_items = WC()->cart->get_cart();
        $all_items_removed = true;

        foreach ($cart_items as $cart_item) {
            if (isset($cart_item['mc_cart_name']) && $cart_item['mc_cart_name'] === $loaded_cart_name) {
                $all_items_removed = false;
                break;
            }
        }

        if ($all_items_removed) {
            // Remove the saved cart if all items are removed
            $user_id = get_current_user_id();
            $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true);

            if (isset($multi_carts[$loaded_cart_name])) {
                unset($multi_carts[$loaded_cart_name]);
                update_user_meta($user_id, 'mc_multi_carts', $multi_carts);
                // Clear the session for the loaded cart name
                WC()->session->__unset('mc_current_cart_name');
                WC()->session->set('mc_current_cart_name', null);
                wc_add_notice("The saved cart '{$loaded_cart_name}' has been deleted because all its items were removed.", 'error');
            }
        }
    }
    public function custom_return_to_shop_button()
    {
         
        if (is_user_logged_in()) {
            $loaded_cart_name = WC()->session->get('mc_current_cart_name');

            if ($loaded_cart_name) {
                echo '<a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="button return-to-shop" style="float: left;">' . __('Add to more items', 'woocommerce') . '</a>';
                echo '<div id="mc-update-cart-section" style="display: none;">
                        <button id="mc-update-saved-cart" class="button" data-cart-name="' . esc_attr($loaded_cart_name) . '">Update Saved Cart</button>
                    </div>';
                
            } else {

                
?>
                <span id="mc-save-cart-btn" class="button" style="float: left;  cursor: pointer; display: inline-block; ">Save to Cart</span>

                <!-- Popup Form -->
                <div id="mc-save-cart-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 300px; padding: 20px; background: #fff; box-shadow: 0 4px 8px rgba(0,0,0,0.2); z-index: 1000; border-radius: 5px;">
                    <div style="text-align: right;">
                        <button id="mc-close-cart-modal" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                    </div>
                    <div>                        
                        <input type="text" id="mc-cart-name" placeholder="Cart Name"  style="width: 100%; padding: 8px; margin-bottom: 15px;" />                        
                        <button id="mc-save-cart-submit" class="button">Save</button>
                    </div>
                </div>

                <!-- Background Overlay -->
                <div id="mc-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999;"></div>
<?php
                 
            }
        }
        
         
}   
         
}  

function mc_remove_expired_saved_carts()
{
    if (!is_user_logged_in()) {
        return;
    }

    $adjust_st = new HandlerOutboundUpdateCart;

    $user_id = get_current_user_id(); 
   $multi_carts = get_user_meta($user_id, 'mc_multi_carts', true) ?: [];
    $current_time = time(); // Current timestamp
    $updated_carts = $multi_carts;

    foreach ($multi_carts as $cart_name => $cart_items) {
        foreach ($cart_items as $cart_item_key => $cart_item) {
            // Check if reservation expiration time exists and has expired
            if (
                isset($cart_item['reservation_expiration_time']) &&
                $cart_item['reservation_expiration_time'] < $current_time
            ) {
                // Handle variations
                if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
                    foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                        // Clean attribute key (remove "attribute_")
                        $taxonomy = str_replace('attribute_', '', $attribute_key);

                        // Get the term by its name in the corresponding taxonomy
                        $term = get_term_by('name', $attribute_value, $taxonomy);
                        if ($term) {
                            $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                            $adjust_quantity = $cart_item['quantity'] * (int)$term_quantity;
                            $adjust_st->adjust_stock($cart_item['product_id'], $adjust_quantity);
                        }
                    }
                } else {
                    // Adjust stock for non-variation products
                    $adjust_st->adjust_stock($cart_item['product_id'], $cart_item['quantity']);
                }
    
                // Remove the expired cart item
                unset($updated_carts[$cart_name][$cart_item_key]);
                WC()->session->__unset('mc_current_cart_name');
                WC()->session->set('mc_current_cart_name', null);
            }
        }

        // Clean up empty cart names
        if (empty($updated_carts[$cart_name])) {
            unset($updated_carts[$cart_name]);
            WC()->session->__unset('mc_current_cart_name');
            WC()->session->set('mc_current_cart_name', null);
        }
         
    }

    // Update user meta if carts have changed
    if ($updated_carts !== $multi_carts) {
       update_user_meta($user_id, 'mc_multi_carts', $updated_carts);
    }
}

// Custom function to restore stock for cart items
function restore_stock_on_remove($cart_item_key, $cart)
{
    $logger = wc_get_logger();
    $logger->log('info', json_encode($cart), array('source' => 'restore_stock_on_remove_log'));

    $restore_quantity = 0;
    $cart_item = $cart->removed_cart_contents[$cart_item_key] ?? null;

    $logger->log('info', json_encode($cart_item), array('source' => 'restore_stock_on_remove_log'));

    if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
        $variation_id = $cart_item['variation_id']; // Get the variation ID
        $variation_attributes = $cart_item['variation']; // Get the variation attributes (e.g., attribute_base-uom-ea)
        foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
            // Clean attribute key (remove "attribute_")
            $taxonomy = str_replace('attribute_', '', $attribute_key);
            // Get the term by its name in the corresponding taxonomy
            $term = get_term_by('name', $attribute_value, $taxonomy);
            if ($term) {
                $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                $restore_quantity = $cart_item['quantity'] * (int) $term_quantity;
            }
        }
    } else {
        $restore_quantity = $cart_item['quantity'];
    }
    if ($cart_item) {
        $adjust_st = new HandlerOutboundUpdateCart;
        $adjust_st->adjust_stock($cart_item['product_id'], $restore_quantity);
    }
}
