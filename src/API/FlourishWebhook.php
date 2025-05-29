<?php

namespace FlourishWooCommercePlugin\API;

defined( 'ABSPATH' ) || exit;

use FlourishWooCommercePlugin\Importer\FlourishItems;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersOutbound;
use WP_REST_Request;
use WP_REST_Server;
use WP_REST_Response;

class FlourishWebhook
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }
     /**
     * Authenticates the webhook request using HMAC SHA-256.
     *
     * @param string $request_body The raw request body.
     * @param string $signature The signature from the request headers.
     * @return bool True if authenticated, false otherwise.
     */
    public function authenticate($request_body, $signature)
    {
        // Check if the request is coming from Flourish
        return $signature === hash_hmac('sha256', $request_body, $this->existing_settings['webhook_key']);
    }
    /**
     * Registers the REST API route for handling Flourish webhooks.
     */
    public function register_hooks()
    {
        add_action('rest_api_init', function() {
            register_rest_route('flourish-woocommerce-plugin/v1', '/webhook', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle_webhook'],
            ]);
        });

    }

    
    /**
     * Handles incoming webhook requests.
     *
     * @param WP_REST_Request $request The incoming REST API request.
     * @return WP_REST_Response The response object.
     */
    public function handle_webhook(WP_REST_Request $request)
    {
         // Get body and headers
        $body = $request->get_body();
        $headers = $request->get_headers();

        // Check if 'auth_signature' exists in headers
        if (empty($headers['auth_signature'][0])) {
            wc_get_logger()->error(
                "Missing authentication signature in webhooks",
                ['source' => 'flourish-woocommerce-plugin']
            );
            return new WP_REST_Response(['message' => 'Missing authentication signature.'], 400);
        }

        // Authentication check
        if (!$this->authenticate($body, $headers['auth_signature'][0])) {
            wc_get_logger()->error(
                "Invalid authentication signature in webhooks",
                ['source' => 'flourish-woocommerce-plugin']
            );
            return new WP_REST_Response(['message' => 'Invalid authentication signature.'], 403);
        }

        // Decode the JSON body
        $decode_data = json_decode($body, true);

        // Ensure the decoded data is valid
        if (!is_array($decode_data) || empty($decode_data['resource_type']) || empty($decode_data['data'])) {
            $this->log_webhook_result('failure', $body, 'Invalid payload structure');
            return new WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }

        // Handle different resource types
        switch ($decode_data['resource_type']) {
            case 'item':
                $response = $this->handle_item($decode_data['data']);
                break;
            case 'retail_order':
                $response = $this->handle_retail_order($decode_data['data']);
                break;
            case 'order':
                $response = $this->handle_order($decode_data['data']);
                break;
            case 'inventory_summary':
                $response = $this->handle_inventory_summary($decode_data['data']);
                break;
            default:
                $this->log_webhook_result('failure', $body, 'Unknown resource type');
                return new WP_REST_Response(['message' => 'Unknown resource type.'], 400);
        }

        // Check if the resource handling was successful
        if ($response instanceof WP_REST_Response && $response->get_status() !== 200) {
            $this->log_webhook_result('failure', $decode_data, 'Resource handling failed');
            return $response;
        }

        // Log success
        //$this->log_webhook_result('success', $body);
        return new WP_REST_Response(['message' => 'Webhook processed successfully.'], 200);
        
    }
    private function log_webhook_result($status, $payload, $error = null)
    {
        $log_data = [
            'timestamp' => current_time('mysql'),
            'status'    => $status,
            'payload'   => $payload,
            'error'     => $error,
        ];

        // Create an instance of WC_Logger
        $logger = wc_get_logger();

        // Convert log data to a readable string format
        $log_message = print_r($log_data, true);

        // Define a context for the log (optional, useful for filtering logs)
        $context = ['source' => 'flourish-webhook'];
        
        if($payload['resource_type'] == "item" )
		{
		   $this->handle_item($payload['data']);	
		}
        // Write to WooCommerce logger
        if ($status === 'success') {
            $logger->info($log_message, $context);
        } else {
            $logger->error($log_message, $context);
        }

    }

    /**
     * Handles the 'item' resource type.
     *
     * @param array $data The item data from the webhook.
     * @return WP_REST_Response The response object.
     */
    private function handle_item($data)
    {
        // Check if item is eCommerce active
        if (!$data['ecommerce_active']) {
            return new WP_REST_Response(['message' => 'Item is not eCommerce active. Not handling.'], 200);
        }

        // Check if item has a SKU
        if (!$data['sku']) {
            return new WP_REST_Response(['message' => 'Item does not have a SKU. Not handling.'], 200);
        }

        // Brand filtering
        $brands = $this->existing_settings['brands'] ?? [];
        $filter_brands = $this->existing_settings['filter_brands'] ?? false;
        if ($filter_brands && !in_array($data['brand'], $brands)) {
            return new WP_REST_Response(['message' => 'Item does not match brand filter. Not handling.'], 200);
        }

        // Retrieve current inventory for the item
        $flourish_api = new FlourishAPI(
            $this->existing_settings['username'] ?? '',
            $this->existing_settings['api_key'] ?? '',
            $this->existing_settings['url'] ?? '',
            $this->existing_settings['facility_id'] ?? ''
        );

        $inventory_records = $flourish_api->fetch_inventory($data['id']);
        $inventory_quantity = 0;

        // Match inventory record with the item's SKU
        foreach ($inventory_records as $inventory) {
            if ($inventory['sku'] === $data['sku']) {
                $inventory_quantity = $inventory['sellable_qty'];
                break;
            }
        }

        // Save item data including inventory quantity
        $data['inventory_quantity'] = $inventory_quantity;
        $items = [$data];
        $item_sync_options = $this->existing_settings['item_sync_options'] ?? [];

        $flourish_items = new FlourishItems($items);
        $flourish_items->save_as_woocommerce_products($item_sync_options);

        return new WP_REST_Response(['message' => 'Item handled successfully.'], 200);
    }
    /**
     * Handles the 'retail_order' resource type.
     *
     * @param array $data The retail order data from the webhook.
     * @return WP_REST_Response The response object.
     */
    private function handle_retail_order($data)
    {
        $wc_order = wc_get_order($data['original_order_id']);
        $post_id = $data['original_order_id'];
        if (!$wc_order) {
            return new WP_REST_Response(['message' => 'Retail order not found.'], 404);
        }

        // Determine WooCommerce order status based on Flourish retail order status
        $new_status = 'created';
        switch ($data['order_status']) {
            case 'Packed':
            case 'Out for Delivery':
            case 'Completed':
                $new_status = 'completed';
                break;
            case 'Cancelled':
                $new_status = 'cancelled';
                break;
            default:
                $new_status = 'created';
                break;
        }
        $this->sync_cancel_update($wc_order,$post_id);
        $wc_order->update_status($new_status, 'Flourish retail order has been ' . $data['order_status'] . '. Updated by API webhook.');

        return new WP_REST_Response(['message' => 'Retail order handled successfully.'], 200);
    }

    /**
     * Handles the 'order' resource type.
     *
     * @param array $data The order data from the webhook.
     * @return WP_REST_Response The response object.
     */
    private function handle_order($data)
    {
        $wc_order = wc_get_order($data['original_order_id']);
        $post_id = $data['original_order_id'];
        if (!$wc_order) {
            return new WP_REST_Response(['message' => 'Order not found.'], 404);
        }

        // Determine WooCommerce order status based on Flourish order status
        $new_status = 'created';
        switch ($data['order_status']) {
            case 'Shipped':
            case 'Completed':
                $new_status = 'completed';
                break;
            case 'Cancelled':
                $new_status = 'cancelled';
                break;
            default:
                $new_status = 'created';
                break;
        }
        $this->sync_cancel_update($wc_order,$post_id);
        $wc_order->update_status($new_status, 'Flourish order has been ' . $data['order_status'] . '. Updated by API webhook.');
        
        return new WP_REST_Response(['message' => 'Order handled successfully.'], 200);
    }

    /**
     * Handles the 'inventory_summary' resource type.
     *
     * @param array $data The inventory summary data from the webhook.
     * @return WP_REST_Response The response object.
     */
    private function handle_inventory_summary($data)
    {
        // Brand filtering
        $brands = $this->existing_settings['brands'] ?? [];
        $filter_brands = $this->existing_settings['filter_brands'] ?? false;
        if ($filter_brands && !in_array($data['brand'], $brands)) {
            return new WP_REST_Response(['message' => 'Item does not match brand filter. Not handling.'], 200);
        }

        // Update WooCommerce product stock based on SKU
        $wc_product = wc_get_products([
            'sku' => $data['sku'],
            'limit' => 1,
        ]);

        if (empty($wc_product)) {
            return new WP_REST_Response(['message' => 'Product not found.'], 404);
        }

        $wc_product = $wc_product[0];
        $product_id = $wc_product->get_id();
        //$held_stock = get_post_meta($product_id, '_held_stock', true) ?: 0;
        //$flourish_stock = $data['sellable_qty'];
        //$woocommerce_stock = $flourish_stock + $held_stock;
        $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
        $flourish_stock = $data['sellable_qty'];
        if ($flourish_stock >= 0) {
            $woocommerce_stock = $flourish_stock - $reserved_stock;
        } else {
            // Skip calculation or set a default value
            $woocommerce_stock = 0; // or null if you want to ignore
        }
        $wc_product->set_stock_quantity($woocommerce_stock); 
        
        //$wc_product->set_stock_quantity($data['sellable_qty']);
        $wc_product->save();

        return new WP_REST_Response(['message' => 'Inventory summary handled successfully.'], 200);
    }

    public function sync_cancel_update($wc_order,$post_id)
    {
        if (!$wc_order) {
            error_log('Order not found or invalid for post ID ' . $post_id);
            return;
        }

        // Retrieve the Flourish Order ID from order meta
        $flourish_order_id = $wc_order->get_meta('flourish_order_id');

        if (empty($flourish_order_id)) {
            error_log('Flourish Order ID not found for WooCommerce Order ID ' . $wc_order->get_id());
            return;
        }

        $order_items = $this->get_flourish_item_ids_from_order($post_id);
        $this->order_stock_update($order_items);
        return true;
        
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
        
            if ($flourish_item_id && $product_id) {
                // Fetch sellable quantity from Flourish API
                // Retrieve settings
                $settings = $this->existing_settings;
                $api_key = $settings['api_key'] ?? '';
                $username = $settings['username'] ?? '';
                $url = $settings['url'] ?? '';
                $facility_id = $settings['facility_id'] ?? '';
        
                // Initialize the Flourish API
                $flourish_api = new FlourishAPI($username, $api_key, $url, $facility_id);
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
							$wc_product->set_stock_quantity($reserved_with_sellable);
							$wc_product->save();
							wc_delete_product_transients($product_id);
							wc_delete_shop_order_transients();
                            //error_log("Updated stock for product ID: $product_id | Stock: $sellable_quantity");
                            
                        }
                    }
                }
            }
        }
        return true;
    }
}