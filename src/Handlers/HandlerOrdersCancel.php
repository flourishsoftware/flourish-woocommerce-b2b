<?php

namespace FlourishWooCommercePlugin\Handlers;
defined( 'ABSPATH' ) || exit;
use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersSyncNow;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersOutbound;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersRetail;
use FlourishWooCommercePlugin\Handlers\SettingsHandler;

class HandlerOrdersCancel
{

    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_filter('woocommerce_my_account_my_orders_actions',[$this,'show_cancel_button'], 10, 2);
        add_action('init', [$this, 'process_cancel_order']);
        add_action('woocommerce_order_status_cancelled', [$this, 'send_order_cancel_request_to_flourish'], 20, 1);
    }

    // Add the cancel action only for created order status
    public function show_cancel_button($actions, $order)
    {
        // Only apply to the "My Account > Orders" page
        if (!is_account_page()) {
            return $actions;
        }

        // Retrieve settings
        // Check for an existing destination in Flourish
        $flourish_api = $this->initializeFlourishAPI();
        $order_type = $this->existing_settings['flourish_order_type'] ?? '';
       
        // Ensure proper comparison
        if ($order_type == "retail") {
            $order_type_api = "retail-orders";
        } else {
            $order_type_api = "outbound-orders";
        }

        // Retrieve Flourish Order ID and data
        $flourish_order_id = $order->get_meta('flourish_order_id', true);
        $order_status = $order->get_status();
        if (!empty($flourish_order_id))
        {
            // Get order data from Flourish API
            $order_data = $flourish_api->get_order_by_id($flourish_order_id, $order_type_api); 
            // Check if order status is 'Created' from Flourish API response
            $order_status = isset($order_data['order_status']) ? $order_data['order_status'] : null;
            if ($order_status === 'Created')
            {               
                    $actions['cancel'] = array(
                        'url'  => wp_nonce_url(
                            add_query_arg('cancel_order', $order->get_id()),
                            'woocommerce-cancel_order'
                        ),
                        'name' => __('Cancel', 'woocommerce'),
                    );  
                
            }
        }
        else
        {
            if ($order_type !== "retail" && $order_status !== 'cancelled') 
            { 
                $actions['cancel'] = array(
                    'url'  => wp_nonce_url(
                        add_query_arg('cancel_order', $order->get_id()),
                        'woocommerce-cancel_order'
                    ),
                    'name' => __('Cancel', 'woocommerce'),
                );  

            }

        }
        // Add the "View" button in any case
        $actions['view'] = array(
        'url'  => $order->get_view_order_url(),
        'name' => __('View', 'woocommerce'),
        );
        // Check if the order status is 'draft' and remove the 'Pay' action
        if (($order_status === 'wc-draft') || ($order_status === 'checkout-draft')) {
            unset($actions['pay']); // Remove the Pay button if the order is in 'draft' status
        }
        return $actions;
    }   

    public function process_cancel_order()
    {
        // Check if the cancel order URL parameter is set
        if (isset($_GET['cancel_order']) && is_user_logged_in()) {
            $order_id = absint($_GET['cancel_order']);
            $order = wc_get_order($order_id);
    
            // Ensure the order exists and belongs to the current user
            if ($order && $order->get_user_id() === get_current_user_id()) {
                // Verify nonce
                if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'woocommerce-cancel_order')) {
                    // Update the order status to 'cancelled'
                    $order->update_status('cancelled', __('Order cancelled by customer.', 'woocommerce'));
    
                    // Optionally add a note to the order
                    $order->add_order_note(__('Order cancelled by customer.', 'woocommerce'));
                    
                    
                }
            }
        }
    }
    /*update retail order and outbound order - order status will updated cancelled*/
    public function send_order_cancel_request_to_flourish($post_id) 
    { 
        $wc_order = wc_get_order($post_id);
         
        if (!$wc_order) {
            error_log('Order not found or invalid for post ID ' . $post_id);
            return;
        }
        
        // Retrieve the Flourish Order ID from order meta
        $flourish_order_id = $wc_order->get_meta('flourish_order_id');

        if (empty($flourish_order_id)) {
            $order_status = $wc_order->get_status();
            if ($order_status === 'cancelled') {  
                 
                //if ($order instanceof WC_Order) {
                   
                    // Check if stock has already been adjusted
                    if (!$wc_order->get_meta('_stock_adjusted')) {
                        HandlerOrdersSyncNow::adjust_variation_stock($wc_order, 'increase');
                        // Mark stock as adjusted
                        $wc_order->update_meta_data('_stock_adjusted', true);
                        $wc_order->save();
                    } else {
                        error_log("Stock already adjusted cancelled: {$post_id}");
                    }
                //}
                //$sync_outboundorder =  $this->reduce_variation_stock($post_id);
            }
            error_log('Flourish Order ID not found for WooCommerce Order ID ' . $wc_order->get_id());
            //return;
        }

        // Retrieve settings
        // Check for an existing destination in Flourish
        $flourish_api = $this->initializeFlourishAPI();
        $facility_id = $flourish_api->facility_id;
        $order_type = $this->existing_settings['flourish_order_type'] ?? '';
        // Ensure proper comparison
        if ($order_type == "retail") {
            $order_type_api = "retail-orders";
        } else {
            $order_type_api = "outbound-orders";
        }

        // Retrieve any additional data or settings needed
        $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;
        
        // Fetch the Flourish order data
        $order_data = $flourish_api->get_order_by_id($flourish_order_id,$order_type_api);

        // Check the Flourish order status
        $order_status = $order_data['order_status'] ?? null;

        if ($order_status === 'Created') {
            
            if ($order_type !== 'retail') {
            // Allow the order to be moved to trash
            // Build destination and billing address.
            $billing_address = HandlerOrdersOutbound::create_address_object($wc_order, 'billing');
            $destination = HandlerOrdersOutbound::create_destination_object($wc_order, $billing_address);

            // Check for an existing destination in Flourish.
            //$existing_destination = $flourish_api->fetch_destination_by_license($destination['license_number']);
           // if ($existing_destination) {
              //  $destination['id'] = $existing_destination['id'];
           // }
            
            $order = [
                'original_order_id' => (string)$wc_order->get_id(),
                'destination' => $destination,
                'order_timestamp' => gmdate("Y-m-d\TH:i:s.v\Z"),
                'order_status' => "Cancelled",
            ];
           
                // Update outbound order in Flourish.
                $flourish_order = $flourish_api->update_outbound_order($order,$flourish_order_id);
        }
            else
            {
             
            // Prepare billing and delivery addresses
            $billing_address = HandlerOrdersRetail::prepare_address($wc_order, 'billing');
            $delivery_address =HandlerOrdersRetail::prepare_address($wc_order, 'delivery');
            // Retrieve and format the date of birth (DOB)
            $dateOfBirth = HandlerOrdersRetail::get_formatted_dob($wc_order, $wc_order->get_billing_email(),$this->existing_settings);

            // Create customer data
            $customer_data = [
                'first_name' => $wc_order->get_billing_first_name(),
                'last_name' => $wc_order->get_billing_last_name(),
                'email' => $wc_order->get_billing_email(),
                'phone' => $wc_order->get_billing_phone(),
                'dob' => $dateOfBirth,
                'address' => [$billing_address],
            ];
            // Fetch or create the customer in Flourish
            $customer = $flourish_api->get_or_create_customer_by_email($customer_data);
            // Build order lines from WooCommerce order items
            $order_lines = HandlerOrdersRetail::prepare_order_lines($wc_order);


            // Prepare order data for Flourish
            $order = [
                'original_order_id' => (string)$wc_order->get_id(),
                'customer_id' => $customer['flourish_customer_id'],
                'delivery_address' => $delivery_address,
                'fulfillment_type' => 'delivery',
                'order_lines' => $order_lines, 
                'notes' => $wc_order->get_customer_note(),
                'order_status' => "Cancelled",
            ];
                //update retail order in flourish
                $flourish_order = $flourish_api->update_retail_order($order,$flourish_order_id);
            }
            $order_items = HandlerOrdersSyncNow::get_flourish_item_ids_from_order($post_id);
            $this->order_stock_update_retail($order_items);
            wp_redirect(get_permalink(get_option('woocommerce_myaccount_page_id')) . 'orders');
            exit; 

        } else {
            // Cancel the trashing of the order if the status does not match
            error_log('Order not trashed due to Flourish Order Status: ' . $order_status); 
        }   
    
    }
    protected function initializeFlourishAPI()
{
    $settingsHandler = new SettingsHandler($this->existing_settings);
    $api_key = $settingsHandler->getSetting('api_key');
    // Remove username line - no longer needed
    $url = $settingsHandler->getSetting('url');
    $facility_id = $settingsHandler->getSetting('facility_id'); 
 
    // Return a new FlourishAPI instance - UPDATED: New constructor without username
    return new FlourishAPI($api_key, $url, $facility_id);
}
    public function order_stock_update_retail($order_items)
{
    foreach ($order_items as $item) {
        $flourish_item_id = $item['flourish_item_id'];
        $product_id = $item['parent_id'] ?? $item['product_id'];
    
        if ($flourish_item_id && $product_id) {
            try {
                // Fetch sellable quantity from Flourish API - UPDATED: Use new API
                $flourish_api = $this->initializeFlourishAPI();
                $inventory_data = $flourish_api->fetch_inventory($flourish_item_id);
        
                foreach ($inventory_data as $items) {
                    if (!empty($items['sellable_qty'])) {
                        $sellable_quantity = $items['sellable_qty'];
                        
                        $wc_product = wc_get_product($product_id);
                        $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
                        
                        if ($sellable_quantity >= 0) {
                            $reserved_with_sellable = $sellable_quantity - $reserved_stock;
                        } else {
                            // Skip calculation or set a default value
                            $reserved_with_sellable = 0; // or null if you want to ignore
                        }  
                        
                        if ($wc_product) {
                            // Update stock and clear cache
                            $wc_product->set_manage_stock(true);
                            wc_update_product_stock($wc_product, $reserved_with_sellable, 'set');
                            wc_delete_product_transients($product_id);
                            wc_delete_shop_order_transients();
                            $wc_product->save();
                            // Optionally log success
                            // error_log("Updated stock for product ID: $product_id | Stock: $sellable_quantity");
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Error updating stock for product ' . $product_id . ': ' . $e->getMessage());
                continue; // Continue with next item even if this one fails
            }
        }
    }
    return true;
}
}
