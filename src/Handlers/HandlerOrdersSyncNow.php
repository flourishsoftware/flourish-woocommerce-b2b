<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersOutbound;
use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;
use FlourishWooCommercePlugin\Handlers\SettingsHandler;

class HandlerOrdersSyncNow
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_action('woocommerce_order_actions', [$this, 'add_sync_now_action']);
		add_action('woocommerce_order_action_sync_order_now', [$this, 'sync_order_now']);
        // Hook into the action when an order is moved to the trash from the order edit page
        add_action('wp_trash_post', [$this, 'custom_action_on_trash_order_from_edit_page'], 10, 1);
        // Hook into the WooCommerce order save action
        // Prevent stock reduction before order items are saved
        $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;

        if ($order_type !== 'retail') {
            add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', function( $prevent, $item, $item_quantity ) {
                $order = wc_get_order( $item->get_order_id() );
            
                // Prevent stock reduction for "on-hold" and "processing" order statuses
                if ( $order && in_array( $order->get_status(), ['on-hold', 'processing'] ) ) { 
                    return true;
                }
            
                return $prevent;
            }, 10, 3 );
            
            add_filter('woocommerce_can_reduce_order_stock', function ($can_reduce_stock, $order) {
                if (is_a($order, 'WC_Order') && in_array($order->get_status(), ['draft', 'on-hold'])) {
                    error_log("Stock reduction disabled for Order ID: " . $order->get_id());
                    return false;
                }
                return $can_reduce_stock;
            }, 10, 2);
        }
        add_action('woocommerce_process_shop_order_meta', [$this, 'handle_order_cancel_update'], 10, 3);    
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_custom_bulk_status_action'], 10, 3);
        
    }
      

    
    /**
     * Adds a custom "Sync Now" action to the available actions list.
     * @param array $actions An array of existing actions.
     * @return array Modified array of actions with the "Sync Now" action added.
     */
    public function add_sync_now_action($actions)
    {
        $actions['sync_order_now'] = 'Sync order to Flourish';

        return $actions;
    }
    /**
     * Synchronizes the given WooCommerce order with the Flourish system.
     *
     * This function checks whether an order has already been synced with Flourish 
     * and, if not, handles synchronization based on the specified order type 
     * (e.g., retail or outbound). The synchronization process is delegated to 
     * the appropriate handler class.
     *
     * @param WC_Order $order The WooCommerce order object to be synchronized.
     * @return void
     */
  public function sync_order_now($order)
{
    $order_id = $order->get_id();

    if ($order->get_meta('flourish_order_id')) {
        // We've already created this order in Flourish, so we don't need to do anything.
        return;
    }

    if (!$this->existing_settings) {
        // We don't have any Flourish settings
        return;
    }

    $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;
    if (!$order_type) {
        // We don't have an order type set
        return;
    }

    try {
        if ($order_type === 'retail') {
            $handler_orders_retail = new HandlerOrdersRetail($this->existing_settings);
            $handler_orders_retail->handle_order_retail($order_id);
        } else {
            $this->handle_order_outbound($order_id);
        }
    } catch (Exception $e) {
        error_log('Error syncing order ' . $order_id . ': ' . $e->getMessage());
        // Add order note about the failure
        $order->add_order_note('Failed to sync with Flourish: ' . $e->getMessage());
        $order->save();
    }

    return;
}

    /**
     * syncing products with Flourish when order status is processing.
     */
   public function sync_products_with_flourish($order_id, $order_status_value)
{
    if ($order_id) {
        $wc_order = wc_get_order($order_id);

        if ($wc_order) {
            $flourish_order_id = $wc_order->get_meta('flourish_order_id');

            try {
                // Check for an existing destination in Flourish - UPDATED: Use new API
                $flourish_api = $this->initializeFlourishAPI();
                $facility_id = $flourish_api->facility_id;

                // Create outbound order in Flourish.
                $order_data = $flourish_api->get_order_by_id($flourish_order_id, "outbound-orders");

                $order_status = isset($order_data['order_status']) ? $order_data['order_status'] : null;

                if ($order_status === 'Created') {
                    // Build destination and billing address.
                    $billing_address = HandlerOrdersOutbound::create_address_object($wc_order, 'billing');
                    $destination = HandlerOrdersOutbound::create_destination_object($wc_order, $billing_address);
                    // Loop through order items and sync them with Flourish
                    $order_lines = HandlerOrdersOutbound::get_order_lines($wc_order, "update");
                     
                    $order = [
                        'original_order_id' => (string) $wc_order->get_id(),
                        'order_lines' => $order_lines,
                        'destination' => $destination,
                        'order_timestamp' => gmdate("Y-m-d\TH:i:s.v\Z"),
                    ];
                    
                    // Add `order_status` only if `$order_status_value` is "completed"
                    if ($order_status_value === 'shipped') {
                        $order['order_status'] = "Shipped";
                    }
                    
                    $order_sales_rep_id = get_post_meta($wc_order->get_id(), '_sales_rep_id', true);

                    if (!empty($order_sales_rep_id)) {
                        $sale_rep_id = $order_sales_rep_id;
                    } else {
                        $sale_rep_id = "";
                    } 
                    
                    $default_sales_rep_id = $this->existing_settings['sales_rep_id'];
                          
                    // Validate facility configuration.
                    HandlerOrdersOutbound::validate_facility_config($flourish_api, $facility_id, $sale_rep_id, $order, $default_sales_rep_id);

                    // Update outbound order in Flourish.
                    $flourish_order = $flourish_api->update_outbound_order($order, $flourish_order_id);
                    $order_items = $this->get_flourish_item_ids_from_order($order_id);
                    $this->order_stock_update($order_items);
                    
                    $wc_order->add_order_note("Products updated with Flourish successfully");
                    $wc_order->save();
                } else {
                    $logger = wc_get_logger();
                    $context = ['source' => 'flourish-sync'];
                    $logger->error("Order ID $order_id sync failed. Order status: " . ($order_status ?? 'Unknown'), $context);
                    
                    $wc_order->add_order_note("Order cannot be synced. Status: " . $order_status);
                    $wc_order->save();
                }
            } catch (Exception $e) {
                error_log('Error syncing products with Flourish for order ' . $order_id . ': ' . $e->getMessage());
                $wc_order->add_order_note("Error syncing with Flourish: " . $e->getMessage());
                $wc_order->save();
            }
        }
    }
}
    public function custom_action_on_trash_order_from_edit_page($post_id)
    {
        $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;

        if ($order_type !== 'retail') {
            // Check if the post being trashed is a WooCommerce order
            if ('shop_order' !== get_post_type($post_id)) {
                //return;
            }

            // Ensure the action is coming from the WooCommerce admin order edit page
            if ($_GET['action'] === 'trash') {
                // Retrieve the WooCommerce order
                $wc_order = wc_get_order($post_id);
                $this->sync_cancel_update($wc_order, $post_id);
            }
        }
    }

    public static function get_flourish_item_ids_from_order($order_id)
    {
        $wc_order = wc_get_order($order_id);

        $flourish_items = [];

        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product(); // Get the product object
            if ($product) {
                // Check if the product is a variation
                if ($product->is_type('variation')) {
                    // Get the parent (variable) product
                    $parent_id = $product->get_parent_id();
                    $parent_product = wc_get_product($parent_id);

                    if ($parent_product) {
                        // Retrieve Flourish item ID from the parent product
                        $flourish_item_id = $parent_product->get_meta('flourish_item_id');
                    }
                } else {
                    // If it's not a variation, get the Flourish item ID directly
                    $flourish_item_id = $product->get_meta('flourish_item_id');
                }

                // Add product details to the array
                $flourish_items[] = [
                    'product_id' => $product->get_id(),
                    'parent_id' => isset($parent_id) ? $parent_id : null, // Add parent ID for variations
                    'flourish_item_id' => $flourish_item_id,
                    'quantity' => $item->get_quantity(),
                ];
            }
        }

        return $flourish_items;
    }

 public function order_stock_update($order_items)
{
    foreach ($order_items as $item) {
        $flourish_item_id = $item['flourish_item_id'];
        $product_id = $item['parent_id'] ?? $item['product_id'];
        $wc_product = wc_get_product($product_id);
        
        if ($this->should_manage_stock($wc_product)) {
            continue;
        } 

        if ($flourish_item_id && $product_id) {
            try {
                // Fetch sellable quantity from Flourish API - UPDATED: Use new API
                $flourish_api = $this->initializeFlourishAPI();
                $inventory_data = $flourish_api->fetch_inventory($flourish_item_id);

                foreach ($inventory_data as $items) {
                    if (!empty($items['sellable_qty'])) {
                        $sellable_quantity = $items['sellable_qty']; 
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
    public function handle_custom_bulk_status_action($redirect_to, $action, $post_ids) {
       
        error_log('Bulk sync triggered for orders: ' . print_r($post_ids, true));

        foreach ($post_ids as $order_id) {
            $this->handle_order_cancel_update_bulk($order_id,$action);
        }

        return add_query_arg('bulk_sync_to_flourish_done', count($post_ids), $redirect_to);
    }

   
    public function handle_order_cancel_update_bulk($post_id, $status)
{
    // Ensure this is a WooCommerce order
    if ('shop_order' !== get_post_type($post_id)) {
        return;
    }

    // Get the updated order
    $wc_order = wc_get_order($post_id);

    if (!$wc_order) {
        error_log('Order not found for post ID ' . $post_id);
        return;
    }

    // Check if the order status is being updated to "Cancelled"
    $selected_status = $status;

    // Retrieve any additional data or settings needed
    $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;

    if ($order_type !== 'retail') {
        $flourish_order_id = $wc_order->get_meta('flourish_order_id');

        if (!empty($flourish_order_id)) {
            try {
                $flourish_api = $this->initializeFlourishAPI();
                $order_data = $flourish_api->get_order_by_id($flourish_order_id, "outbound-orders");

                $order_status = isset($order_data['order_status']) ? $order_data['order_status'] : null;
                if ($order_status === "Allocated") {
                    // Your desired logic here
                    $wc_order->add_order_note("The order is allocated in Flourish.");
                    $wc_order->save();
                }
            } catch (Exception $e) {
                error_log('Error checking order status in Flourish: ' . $e->getMessage());
            }
        }
        
        if ($selected_status === 'mark_cancelled') {
            $flourish_order_id = $wc_order->get_meta('flourish_order_id');

            if (empty($flourish_order_id)) {
                $order = wc_get_order($post_id);

                // Check if stock has already been adjusted
                if (!$order->get_meta('_stock_adjusted')) {
                    $this->adjust_variation_stock($order, 'increase');
                    // Mark stock as adjusted
                    $order->update_meta_data('_stock_adjusted', true);
                    $order->save();
                } else {
                    error_log("Stock already adjusted cancelled: {$post_id}");
                }
            } else {
                // Retrieve the WooCommerce order
                $wc_order = wc_get_order($post_id);
                $this->sync_cancel_update($wc_order, $post_id);
            }
        }
        
        if ($selected_status === 'mark_processing') {
            $sync_outboundorder = $this->handle_order_outbound($post_id);
        }
        
        if ($selected_status === 'mark_completed') {
            $sync_outboundorder = $this->sync_products_with_flourish($post_id, "shipped");
        }
    }
}
    public function handle_order_cancel_update($post_id)
    {
        // Ensure this is a WooCommerce order
        if ('shop_order' !== get_post_type($post_id)) {
            //return;
        }

        // Get the updated order
        $wc_order = wc_get_order($post_id);

        if (!$wc_order) {
            error_log('Order not found for post ID ' . $post_id);
            return;
        }

        // Check if the order status is being updated to "Cancelled"
        $selected_status = isset($_POST['order_status']) && !empty($_POST['order_status'])
            ? sanitize_text_field($_POST['order_status'])
            : null;

        // Retrieve any additional data or settings needed
        $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;

        if ($order_type !== 'retail') {
            $flourish_order_id = $wc_order->get_meta('flourish_order_id');

            if (!empty($flourish_order_id)) {
                $flourish_api = $this->initializeFlourishAPI();
                $order_data = $flourish_api->get_order_by_id($flourish_order_id, "outbound-orders");

                $order_status = isset($order_data['order_status']) ? $order_data['order_status'] : null;
                if ($order_status === "Allocated") {
                    // Your desired logic here
                    // For example: Disable items or update order meta
                    $wc_order->add_order_note("The order is allocated in Flourish.");
                    $wc_order->save();
                }
            }
            if ($selected_status === 'wc-cancelled') {
                // Check if the post being trashed is a WooCommerce order
                $flourish_order_id = $wc_order->get_meta('flourish_order_id');

                if (empty($flourish_order_id)) {
                    // Create outbound order in Flourish.
                    if ($selected_status === 'wc-cancelled') {
                        $order = wc_get_order($post_id);
                        //if ($order instanceof WC_Order) {

                        // Check if stock has already been adjusted
                        if (!$order->get_meta('_stock_adjusted')) {
                            $this->adjust_variation_stock($order, 'increase');
                            // Mark stock as adjusted
                            $order->update_meta_data('_stock_adjusted', true);
                            $order->save();
                        } else {
                            error_log("Stock already adjusted cancelled: {$post_id}");
                        }
                        //}
                        //$sync_outboundorder =  $this->reduce_variation_stock($post_id); 
                    }
                }
                else
                {
                    if ('shop_order' !== get_post_type($post_id)) {
                        //return;
                    }
                    // Retrieve the WooCommerce order
                    $wc_order = wc_get_order($post_id);
                    $this->sync_cancel_update($wc_order, $post_id);
                }
            }
            if ($selected_status === 'wc-processing') {
                $sync_outboundorder = $this->handle_order_outbound($post_id);
            }
            if ($selected_status === 'wc-completed') {
                $sync_outboundorder =  $this->sync_products_with_flourish($post_id, "shipped");
            }
            if ($selected_status === 'wc-checkout-draft' || $selected_status === 'wc-on-hold') {
                remove_action('woocommerce_reduce_order_stock', 'wc_maybe_reduce_stock_levels');
                add_filter('woocommerce_can_reduce_order_stock', '__return_false');
                return false; // Prevent stock reduction
            }
        }
    }
    /**
     * Adjust variation stock based on the action (increase or decrease).
     *
     * @param WC_Order $order The WooCommerce order object.
     * @param string $action The action to perform: 'increase' or 'decrease'.
     */
    public static function adjust_variation_stock($order, $action = 'increase')
    {
        foreach ($order->get_items() as $item) {
            $line_item_id = $item->get_id(); // Get the line item ID
            $variation_id = $item->get_variation_id();
            $product = wc_get_product($variation_id ? $variation_id : $item->get_product_id());
            $parent_product = wc_get_product($item->get_product_id());
            $product_id = $item->get_product_id();
            $case_quantity = 0; // Default case quantity

            if ($variation_id) {
                // Get variation attributes
                $attributes = $product->get_attributes();

                foreach ($attributes as $attribute_slug => $attribute_value) {
                    $taxonomy = $attribute_slug;

                    // Fetch term details
                    $term = get_term_by('slug', $attribute_value, $taxonomy);
                    if ($term) {
                        $case_quantity = get_term_meta($term->term_id, 'quantity', true) ?: 1; // Default to 1 if not set
                        $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
                        // Calculate new stock based on the action
                        $woo_current_stock = $product->get_stock_quantity();
                        if ($woo_current_stock >= 0) {
                            $current_stock = $woo_current_stock;
                        } else {
                            // Skip calculation or set a default value
                            $current_stock = 0; // or null if you want to ignore
                        }
                        $adjustment = ($action === 'increase') ? $case_quantity : -$case_quantity;
                        $add_qty = $adjustment * $item->get_quantity();
                        $new_stock = max(0, $current_stock + ($adjustment * $item->get_quantity()));
                        
                    }
                }
            }
            else
            {
                $simple_qty = $item->get_quantity();
                $adjustment = ($action === 'increase') ? $simple_qty : -$simple_qty;
                $add_qty = $adjustment;
                $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
                $woo_current_stock = $product->get_stock_quantity();
                if ($woo_current_stock >= 0) {
                    $current_stock = $woo_current_stock;
                } else {
                    // Skip calculation or set a default value
                    $current_stock = 0; // or null if you want to ignore
                }
                $new_stock =max(0, $current_stock + $adjustment);
            }
            
            // Update stock
            if($action === 'decrease')
            {
                $add_reserved_stock = -($add_qty);
                $reversed_stock_decrease = $reserved_stock + $add_reserved_stock;
                update_post_meta($product_id, '_reserved_stock', $reversed_stock_decrease); 
                update_post_meta($line_item_id, '_reserved_stock', $add_reserved_stock); 
            }
            else
            {
                //when order is cancelled reversed stock will decrease
                $reversed_stock_increase = abs($reserved_stock - $add_qty);
                update_post_meta($product_id, '_reserved_stock', $reversed_stock_increase); 
            }
            // FIXED: Replace $this->should_manage_stock() with static method call
        if (self::should_manage_stock($parent_product))
        {
           continue;
        }
            // Skip if product doesn't exist (deleted product)
            if (!$product || !$parent_product) {
            error_log("Product not found for order item. Product ID: $product_id, Variation ID: $variation_id");
            continue;
            } 
            $parent_product->set_stock_quantity($new_stock);
            $parent_product->save();
        }
    }
   public function handle_order_outbound($order_id)
{
    try {
        $wc_order = wc_get_order($order_id);

        if ($wc_order->get_meta('flourish_order_id')) {
            $this->sync_products_with_flourish($order_id, "Updated");
            // Order already exists in Flourish, skip processing.
            return;
        }
        
        // Check for an existing destination in Flourish - UPDATED: Use new API
        $flourish_api = $this->initializeFlourishAPI();
        $facility_id = $flourish_api->facility_id;

        // Build destination and billing address.
        $billing_address = HandlerOrdersOutbound::create_address_object($wc_order, 'billing');
        $destination = HandlerOrdersOutbound::create_destination_object($wc_order, $billing_address);

        // Generate order lines.
        $order_lines = HandlerOrdersOutbound::get_order_lines($wc_order, "create");
        if (empty($order_lines)) {
            throw new \Exception("No order lines found for order " . $wc_order->get_id());
        }

        // Collect customer notes.
        $notes = HandlerOrdersOutbound::get_customer_notes($wc_order);

        // Build the order payload.
        $order = [
            'original_order_id' => (string) $wc_order->get_id(),
            'order_lines' => $order_lines,
            'destination' => $destination,
            'order_timestamp' => gmdate("Y-m-d\TH:i:s.v\Z"),
            'notes' => $notes,
        ];

        $order_sales_rep_id = get_post_meta($wc_order->get_id(), '_sales_rep_id', true);

        if (!empty($order_sales_rep_id)) {
            $sale_rep_id = $order_sales_rep_id;
        } else {
            $sale_rep_id = "";
        }
        
        $default_sales_rep_id = $this->existing_settings['sales_rep_id'];
                  
        // Validate facility configuration.
        HandlerOrdersOutbound::validate_facility_config($flourish_api, $facility_id, $sale_rep_id, $order, $default_sales_rep_id);    

        // Create outbound order in Flourish.
        $flourish_order_id = $flourish_api->create_outbound_order($order);

        $order_items = HandlerOrdersSyncNow::get_flourish_item_ids_from_order($order_id);

        // Update WooCommerce order metadata.
        $this->order_stock_update($order_items);
        $wc_order->update_meta_data('flourish_order_id', $flourish_order_id);
        $wc_order->add_order_note("Products synced with Flourish successfully");
        $wc_order->save();

        do_action('flourish_order_outbound_created', $wc_order, $flourish_order_id);
    } catch (\Exception $e) {
        // Log errors.
        wc_get_logger()->error(
            "Error creating outbound order: " . $e->getMessage(),
            ['source' => 'flourish-woocommerce-plugin']
        );

        // Send mail 
        if (class_exists('FlourishWooCommercePlugin\Helpers\HttpRequestHelper')) {
            $email_send = HttpRequestHelper::send_order_failure_email_to_admin($e->getMessage(), $order_id);
        }
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
    public function sync_cancel_update($wc_order, $post_id)
    {
    if (!$wc_order) {
        error_log('Order not found or invalid for post ID ' . $post_id);
        return;
    }

    // Retrieve the Flourish Order ID from order meta
    $flourish_order_id = $wc_order->get_meta('flourish_order_id');

    if (empty($flourish_order_id)) {
        $this->adjust_variation_stock($wc_order, 'increase');
        error_log('Flourish Order ID not found for WooCommerce Order ID ' . $wc_order->get_id());
        return;
    }
    
    try {
        // Check for an existing destination in Flourish - UPDATED: Use new API
        $flourish_api = $this->initializeFlourishAPI();

        // Fetch the Flourish order data
        $order_data = $flourish_api->get_order_by_id($flourish_order_id, "outbound-orders");

        // Check the Flourish order status
        $order_status = $order_data['order_status'] ?? null;

        if ($order_status === 'Created') {
            // Allow the order to be moved to trash
            // Build destination and billing address.
            $billing_address = HandlerOrdersOutbound::create_address_object($wc_order, 'billing');
            $destination = HandlerOrdersOutbound::create_destination_object($wc_order, $billing_address);

            $order = [
                'original_order_id' => (string) $wc_order->get_id(),
                'destination' => $destination,
                'order_timestamp' => gmdate("Y-m-d\TH:i:s.v\Z"),
                'order_status' => "Cancelled",
            ];
            
            $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;
            if ($order_type !== 'retail') {
                // Update outbound order in Flourish.
                $flourish_order = $flourish_api->update_outbound_order($order, $flourish_order_id);
            } else {
                // Update retail order in Flourish
                $flourish_order = $flourish_api->update_retail_order($order, $flourish_order_id);
            }

            $order_items = $this->get_flourish_item_ids_from_order($post_id);
            $this->order_stock_update($order_items);
        } else {
            // Cancel the trashing of the order if the status does not match
            error_log('Order not trashed due to Flourish Order Status: ' . $order_status);
        }
    } catch (Exception $e) {
        error_log('Error cancelling order in Flourish: ' . $e->getMessage());
    }
}

   private static function should_manage_stock($product) {
    if (!$product) return false;
    
    $manage_stock = $product->get_manage_stock();
    $backorders_allowed = $product->get_backorders();  
    $stock_status=$product->get_stock_status(); 
    // Return true only if stock management is enabled AND backorders are allowed
    return $manage_stock===false && ($backorders_allowed === 'notify' || $backorders_allowed === 'yes' || $stock_status=="onbackorder" || $stock_status=="instock");
}
}
