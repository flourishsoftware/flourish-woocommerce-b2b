<?php
namespace FlourishWooCommercePlugin\Admin;


class WoocommerceSettingsCustomFields
{
    public function __construct()
    {
        // Register the necessary WooCommerce hooks.
        $this->register_hooks();
    }
 
    public function register_hooks()
    {
        // Add custom Stock Reservation Time field to WooCommerce Settings (Inventory Tab)
        add_filter('woocommerce_get_settings_products', [$this, 'add_stock_reservation_time_setting']);
        //Save the Stock Reservation Time setting
        add_action('woocommerce_update_options_products', [$this, 'save_stock_reservation_time_setting']);
    } 
 
    /*Add reservation time in add to cart */
    public function add_stock_reservation_time_setting($settings)
    {
        foreach ($settings as $index => $setting) {
            if (isset($setting['id']) && $setting['id'] === 'woocommerce_hold_stock_minutes') {
                $settings = array_merge(
                    array_slice($settings, 0, $index + 1),
                    [
                        [
                            'name'     => __('Stock Reservation Time in the cart (minutes)', 'woocommerce'),
                            'desc'     => __('Set the time (in minutes) to reserve stock in the cart.', 'woocommerce'),
                            'id'       => 'stock_reservation_time',
                            'type'     => 'number',
                            'desc_tip' => true,
                            'default'  => 20,
                            'custom_attributes' => ['min' => 1],
                            'css'      => 'width: 100px;',
                        ],
                    ],
                    array_slice($settings, $index + 1)
                );
                break;
            }
        }
        return $settings;
    }
    /*save stock reservation time*/
    public function save_stock_reservation_time_setting()
    {
        if (isset($_POST['stock_reservation_time'])) {
            update_option('stock_reservation_time', sanitize_text_field($_POST['stock_reservation_time']));
        }
    }
}    