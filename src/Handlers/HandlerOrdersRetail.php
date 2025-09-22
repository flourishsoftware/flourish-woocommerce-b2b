<?php

namespace FlourishWooCommercePlugin\Handlers;

defined( 'ABSPATH' ) || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersSyncNow;

class HandlerOrdersRetail
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_filter('woocommerce_can_reduce_order_stock', '__return_false');
        add_action('woocommerce_order_status_pending', [$this, 'handle_order_retail']);
        add_action('woocommerce_order_status_processing', [$this, 'handle_order_retail']);
        
        // Make the company name mandatory
        add_filter('woocommerce_default_address_fields', [$this, 'require_company_field']);
    }
    /**
     * Handle retail-specific processing for a WooCommerce order.
     *
     * This method is triggered for retail orders to perform specific operations,
     * such as updating inventory, calculating additional charges, sending notifications,
     * or processing custom order metadata.
     *
     * @param int $order_id The ID of the WooCommerce order to process.
     */

    public function handle_order_retail($order_id)
    {
    try {
    $wc_order = wc_get_order($order_id);

    // Check if the order has already been created in Flourish
    if ($wc_order->get_meta('flourish_order_id')) {
    return;
    }

    // Retrieve settings with default values - UPDATED: Removed username
    $api_key = $this->existing_settings['api_key'] ?? '';
    $url = $this->existing_settings['url'] ?? '';
    $facility_id = $this->existing_settings['facility_id'] ?? '';
    $order_status_value = $this->existing_settings['flourish_order_status'] ?? '';

    // Validate required settings
    if (empty($api_key) || empty($url) || empty($facility_id)) {
    throw new \Exception('Missing required API settings (api_key, url, or facility_id)');
    }

    // Initialize Flourish API - UPDATED: New constructor without username
    $flourish_api = new FlourishAPI($api_key, $url, $facility_id);

    // Prepare billing and delivery addresses
    $billing_address = $this->prepare_address($wc_order, 'billing');
    $delivery_address = $this->prepare_address($wc_order, 'delivery');

    // Retrieve and format the date of birth (DOB)
    $dateOfBirth = $this->get_formatted_dob($wc_order, $wc_order->get_billing_email(), $this->existing_settings);

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
    $order_lines = $this->prepare_order_lines($wc_order);

    // If no order lines are available, log and exit
    if (empty($order_lines)) {
    wc_get_logger()->error("No order lines found for order " . $wc_order->get_id(), ['source' => 'flourish-woocommerce-plugin']);
    return;
    }

    // Prepare order data for Flourish
    $order_data = [
    'original_order_id' => (string)$wc_order->get_id(),
    'customer_id' => $customer['flourish_customer_id'],
    'delivery_address' => $delivery_address,
    'fulfillment_type' => 'delivery',
    'order_lines' => $order_lines,
    'notes' => $wc_order->get_customer_note(),
    'order_status' => $order_status_value,
    ];

    // Create the retail order in Flourish
    $flourish_order_id = $flourish_api->create_retail_order($order_data);
    $order_items = HandlerOrdersSyncNow::get_flourish_item_ids_from_order($order_id);

    // Update stock for each order item
    foreach ($order_items as $item) {
    $flourish_item_id = $item['flourish_item_id'];
    $product_id = $item['parent_id'] ?? $item['product_id'];

    if ($flourish_item_id && $product_id) {
    try {
        // Fetch sellable quantity from Flourish API
        $inventory_data = $flourish_api->fetch_inventory($flourish_item_id);

        foreach ($inventory_data as $items) {
            if (!empty($items['sellable_qty'])) {
                $sellable_quantity = $items['sellable_qty'];
                $wc_product = wc_get_product($product_id);

                if ($wc_product) {
                    // Update stock and clear cache
                    $wc_product->set_manage_stock(true);
                    wc_update_product_stock($wc_product, $sellable_quantity, 'set');
                    $wc_product->set_stock_quantity($sellable_quantity);
                    $wc_product->save();
                    wc_delete_product_transients($product_id);
                    wc_delete_shop_order_transients();

                    error_log("Updated stock for product ID: $product_id | Stock: $sellable_quantity");
                }
            }
        }
    } catch (\Exception $e) {
        error_log("Error updating stock for product $product_id: " . $e->getMessage());
        // Continue with other items even if one fails
    }
    }
    }

    // Update WooCommerce order with Flourish ID
    $wc_order->update_meta_data('flourish_order_id', $flourish_order_id);
    $wc_order->save();

    do_action('flourish_retail_order_created', $wc_order, $flourish_order_id);

    } catch (\Exception $e) {
    wc_get_logger()->error("Error creating retail order: " . $e->getMessage(), ['source' => 'flourish-woocommerce-plugin']);

    // Send failure email if HttpRequestHelper is available
    if (class_exists('FlourishWooCommercePlugin\Helpers\HttpRequestHelper')) {
    $email_send = \FlourishWooCommercePlugin\Helpers\HttpRequestHelper::send_order_failure_email_to_admin($e->getMessage(), $order_id);
    }
    }
    }
    /**
     * Helper function to prepare address data.
     */
    public static function prepare_address($wc_order, $type)
    {
    return (object)[
    'address_line_1' => $type === 'billing' ? $wc_order->get_billing_address_1() : $wc_order->get_shipping_address_1(),
    'address_line_2' => $type === 'billing' ? $wc_order->get_billing_address_2() : $wc_order->get_shipping_address_2(),
    'city' => $type === 'billing' ? $wc_order->get_billing_city() : $wc_order->get_shipping_city(),
    'state' => $type === 'billing' ? $wc_order->get_billing_state() : $wc_order->get_shipping_state(),
    'postcode' => $type === 'billing' ? $wc_order->get_billing_postcode() : $wc_order->get_shipping_postcode(),
    'country' => $type === 'billing' ? $wc_order->get_billing_country() : $wc_order->get_shipping_country(),
    'type' => $type,
    ];
    }

    /**
     * Helper function to prepare order lines.
     */
    public static function prepare_order_lines($wc_order)
    {
    $order_lines = [];

    foreach ($wc_order->get_items() as $item) {
    $variation_id = $item->get_variation_id();
    $product = wc_get_product($variation_id ? $variation_id : $item->get_product_id());
    $case_quantity = 0; // Default case quantity
    $unit_price_from_variation  = 0; 
    // Check if the product is a variation
    if ($variation_id) {
    // Get variation attributes
    $attributes = $product->get_attributes();

    foreach ($attributes as $attribute_slug => $attribute_value) {
        // Ensure attribute slug starts with "pa_"
        $taxonomy = $attribute_slug;
        
        //if (taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $attribute_value, $taxonomy);
            if ($term) {
                $case_quantity = get_term_meta($term->term_id, 'quantity', true) ?: 1; // Default to 1 if not set
                error_log("Term ID: {$term->term_id}, Case Quantity: {$case_quantity}");
            } else {
                error_log("No term found for attribute {$taxonomy} and value {$attribute_value}");
            }
        
    }
    $unit_price_from_variation = ((float)$item->get_total() / $item->get_quantity()) / $case_quantity; // Price per single item
    }

    if ($product && $product->get_sku()) {
    // Calculate total weight using case quantity
    $item_weight = (float)$case_quantity; 
    $item_quantity = $item->get_quantity();
    $total_item_quantity = $item_weight  > 0 ? $item_weight  * $item_quantity : $item_quantity;
    $unit_price_from_order = (float)$item->get_total() / $item->get_quantity();
    $unit_price = $unit_price_from_variation  > 0 ? $unit_price_from_variation  : $unit_price_from_order;
    $order_lines[] = (object)[
        'sku' => $product->get_sku(),
        'order_qty' => $total_item_quantity,
        'unit_price'=> $unit_price,
    ];
    }
    }

    return $order_lines;
    }



    public function require_company_field($fields)
    {
    // We don't need the company field for retail orders
    $fields['company']['required'] = false;
    return $fields;
    }

    // Get and format DOB, with exception handling
    public static function get_formatted_dob($wc_order,$email,$settings)
    {
    $raw_dob = filter_input(INPUT_POST, 'dob', FILTER_SANITIZE_STRING);
    $raw_dob = $raw_dob ?: get_user_meta($wc_order->get_user_id(), 'dob', true);
    $instance = new HandlerOrdersRetail($settings);
    try {
    if ($raw_dob) {
    return $instance->parse_dob($raw_dob, $wc_order->get_id());
    }
    else
    {
    $option_key = 'guest_dob_' . sanitize_title($email);
    $raw_dob = get_option($option_key);
    return $instance->parse_dob($raw_dob, $wc_order->get_id());
    }
    } catch (\Exception $e) {
    wc_get_logger()->error("Error parsing DOB for order {$wc_order->get_id()}: " . $e->getMessage(), ['source' => 'flourish-woocommerce-plugin']);
    }

    return null;
    }

    // Check the DOB format with improved exception handling and logging
    private function parse_dob($raw_dob, $order_id)
    {
    $formats = ['F d, Y', 'Y-m-d', 'm/d/Y'];

    foreach ($formats as $format) {
    $dob_datetime = \DateTime::createFromFormat($format, $raw_dob);
    if ($dob_datetime && $dob_datetime->format($format) === $raw_dob) {
    // Log successful parsing for debugging
    wc_get_logger()->info("Successfully parsed DOB for order $order_id: {$dob_datetime->format('Y-m-d')}", ['source' => 'flourish-woocommerce-plugin']);
    return $dob_datetime->format('Y-m-d'); // Format DOB as YYYY-MM-DD
    }
    }

    // If none of the formats matched, throw an exception
    throw new \Exception("Invalid DOB format: $raw_dob");
    }
    }
