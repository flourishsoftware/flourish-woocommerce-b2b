<?php

namespace FlourishWooCommercePlugin\Admin;

defined( 'ABSPATH' ) || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Importer\FlourishItems;
use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;

class SettingsPage
{
    public $plugin_basename;
    public $existing_settings;

    public function __construct($existing_settings, $plugin_basename)
    {
        $this->existing_settings = $existing_settings ? $existing_settings : [];
        $this->plugin_basename =  $plugin_basename;
    }

    public function register_hooks()
    {
        $order_type = isset($this->existing_settings['flourish_order_type']) ? $this->existing_settings['flourish_order_type'] : false;
        add_action('add_meta_boxes', [$this, 'add_refresh_inventory_button_meta_box']);
        add_action('wp_ajax_fetch_inventory', [$this, 'fetch_inventory_callback']);
        add_action('wp_ajax_nopriv_fetch_inventory', [$this, 'fetch_inventory_callback']); // Non-logged-in users
        if ($order_type !== 'retail') // check flourish order type
        { 
            add_action('plugins_loaded', function () {
                 //Draft Orders Email class in WooCommerce
                add_filter('woocommerce_email_classes', [$this, 'register_draft_order_email']);
                 
            });
            //To completely disable woocommerce_cleanup_draft_orders in WooCommerce
            add_action('init', function() {
                $timestamp = wp_next_scheduled('woocommerce_cleanup_draft_orders');
                if ($timestamp) {
                    wp_unschedule_event($timestamp, 'woocommerce_cleanup_draft_orders');
                }
                remove_action('woocommerce_cleanup_draft_orders', 'wc_cleanup_draft_orders');
                remove_action( 'wp_scheduled_auto_draft_delete', 'wp_delete_auto_drafts' );
                add_action( 'before_delete_post',[$this, 'prevent_draft_order_deletion'], 10, 2 );
                // Disable re-scheduling by blocking the function call
                add_filter('woocommerce_cleanup_draft_orders_interval', '__return_zero');
                add_filter('woocommerce_delete_order_items', function($delete, $order_id) {
                    // Check if order_id is provided
                    if ($order_id === null) {
                        return $delete;
                    }
                    
                    $order = wc_get_order($order_id);
                    if ($order && ($order->get_status() === 'wc-checkout-draft' || $order->get_status() === 'auto-draft')) {
                        return false; // Prevent deletion of order items for draft orders
                    }
                    return $delete;
                }, 10, 2);
                
                
            });  
            add_action('wp_enqueue_scripts', function($hook) {
                // Enqueue custom styles (for front-end)
                wp_enqueue_style(
                    'save-cart-style',  // Make sure you are using a unique handle
                    plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/css/save-cart-style.css', 
                    [], // No dependencies
                    '1.0.0', // Version
                    'all' // Media type
                );
                wp_enqueue_script(
                    'flourish-cart-js',  
                    plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/js/flourish-cart.js', 
                    ['jquery'], 
                    '1.0.0', 
                     true
                );
                // In PHP (add nonce to localize script)
                wp_localize_script('flourish-cart-js', 'stockAvailability', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('flourish_cart_nonce')
                ]);
               
            });
        }
        
        add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_settings_link']);
        // Get the settings page to show up in the admin menu
        add_action('admin_menu', function() {
            $page_hook = add_options_page(
                'Flourish WooCommerce Plugin Settings',
                '🌱 Flourish',
                'manage_options',
                'flourish-woocommerce-plugin-settings',
                [$this, 'render_settings_page']
            );

            add_action('load-' . $page_hook, [$this, 'register_settings']);
        });

        add_action('admin_init', function() {
            register_setting('flourish-woocommerce-plugin-settings-group', 'flourish_woocommerce_plugin_settings', [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => [],
                'show_in_rest' => false,
            ]);
        });
        //calling custom css
        add_action('admin_enqueue_scripts', [$this, 'flourish_woocommerce_plugin_enqueue_styles']);
        // Handling importing products button being pushed
        if (isset($_POST['action']) && $_POST['action'] === 'import_products') {
            add_action('admin_init', [$this, 'handle_import_products_form_submission']);
        }
        // Unified add_action to handle Add/Edit operations for case sizes.
        add_action('wp_ajax_add_edit_case_size',    [$this, 'handle_ajax_add_edit_case_size']);
        // to handle Add/Edit operations for case sizes.
        add_action('wp_ajax_delete_case_size', [$this, 'handle_ajax_delete_case_size']);
        // Handle AJAX request to fetch UOM options
        add_action('wp_ajax_get_uom_options', 'get_uom_options_ajax_handler');
        // Handle the AJAX request to edit the case size and retrieve the available Unit of Measurement (UOM) options.
        add_action('wp_ajax_get_uom_dropdown_html_handler', [$this, 'get_uom_dropdown_html_handler']);
    
    }
    public function prevent_draft_order_deletion($post_id, $post) {
        if (($post->post_type === 'shop_order' && get_post_status($post_id) === 'draft') || ($post->post_type === 'shop_order' && get_post_status($post_id) === 'wc-checkout-draft') || ($post->post_type === 'shop_order' && get_post_status($post_id) === 'auto-raft')){
               wp_die(__('Draft orders cannot be deleted.', 'your-textdomain'));
           }
       }
    function add_refresh_inventory_button_meta_box() {
        add_meta_box(
            'refresh_inventory_meta_box', // Unique ID for the meta box
            'Refresh Inventory', // Title of the meta box
            [$this, 'refresh_inventory_button_callback'], 
            'product', // Post type (WooCommerce Product)
            'side', // Display location (side panel)
            'high' // Priority
        );
    }
    
    // Callback function to display the button
    function refresh_inventory_button_callback($post) {
        // Ensure the function is accessible
        if (!isset($post->post_status)) {
            echo '<p>Error: Invalid product data.</p>';
            return;
        }

        $product_id = $post->ID;
        ?>
        <button id="refresh-inventory-btn" data-product-id="<?php echo esc_attr($product_id); ?>" class="button button-primary">
            Refresh Inventory
        </button>
        <p id="inventory-message" style="margin-top: 10px; color: green;"></p> 
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#refresh-inventory-btn').on('click', function(event) {
                    //event.preventDefault(); // Prevent default form action
                    var productId = $(this).data('product-id');
                    console.log("Product ID:", productId); // Debugging

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fetch_inventory',
                            product_id: productId
                        },
                        success: function(response) {
                            if (response.success && response.data && response.data.message) {
                                $('#inventory-message').text(response.data.message).css("color", "green"); 
                            } else {
                                $('#inventory-message').text("Inventory updated, but no message found.").css("color", "orange");
                            }
                        },
                        error: function() {
                            $('#inventory-message').text("Error updating inventory.").css("color", "red"); 
                        }
                    });
                });
            });
        </script>
        <?php
    }
    function fetch_inventory_callback() {
        error_log("POST Data: " . print_r($_POST, true)); // Log all POST data

        if (!isset($_POST['product_id'])) {
            wp_send_json_error(['message' => 'Invalid product ID']);
        }
        $product_id = sanitize_text_field($_POST['product_id']);
        $product = wc_get_product($product_id);

        if ($product) {
            // Retrieve Flourish item ID from the parent product
            $item_id = $product->get_meta('flourish_item_id');
        }
        
        // Updated to use new API key authentication
        $flourish_api = new FlourishAPI(
            $this->existing_settings['api_key'] ?? '',
            $this->existing_settings['url'] ?? '',
            $this->existing_settings['facility_id'] ?? ''
        );
        
        $url = $flourish_api->url;
        $api_url = $url . "/external/api/v1/items?item_id={$item_id}";
        $auth_header = $flourish_api->auth_header;
        $headers = [ 'x-api-key: ' . $auth_header];

        try {
            // Make API request
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            error_log("Error fetching product (API call): " . $e->getMessage());
            return false;
        }

        if (!isset($response_data['data'][0])) {
            error_log("API returned empty data for item ID: " . $item_id);
            return false;
        }

        // Extract product data
        $data = $response_data['data'][0];
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
        
        $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
        
        if ($inventory_quantity >= 0) {
            $woocommerce_stock = abs($inventory_quantity - $reserved_stock);
        } else {
            // Skip calculation or set a default value
            $woocommerce_stock = 0; // or null if you want to ignore
        }
        $product->set_stock_quantity($woocommerce_stock); 
        $product->save();
       // $flourish_items = new FlourishItems($items);
        
        //$flourish_items->save_as_woocommerce_products($item_sync_options);
        //update reserve stock
        $stock_info = $this->update_product_reserved_stock($product_id);
        $available_reserved_stock_info = (int)$stock_info->total_reserved_stock;
        update_post_meta($product_id, '_reserved_stock', $available_reserved_stock_info);
        if (is_wp_error($data)) {
            wp_send_json_error(['message' => 'Error fetching inventory']);
        }
    
        //$body = wp_remote_retrieve_body($response);
        wp_send_json_success(['message' => 'Inventory refreshed']);
    }
    function update_product_reserved_stock($product_id) {
        global $wpdb;
    
        $query = $wpdb->prepare(
            "SELECT 
                oim.meta_value AS product_id,
                SUM(CAST(COALESCE(pm.meta_value, '0') AS DECIMAL(10,2))) AS total_reserved_stock
            FROM 
                {$wpdb->posts} p 
                JOIN {$wpdb->prefix}woocommerce_order_items oli ON p.ID = oli.order_id 
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oli.order_item_id = oim.order_item_id 
                LEFT JOIN {$wpdb->postmeta} pm ON oli.order_item_id = pm.post_id AND pm.meta_key = '_reserved_stock'
            WHERE 
                p.post_type = 'shop_order' 
                AND p.post_status = 'wc-checkout-draft' 
                AND oim.meta_key = '_product_id' 
                AND oim.meta_value = %d
            GROUP BY 
                oim.meta_value",
            $product_id
        );
    
       return $result = $wpdb->get_row($query);
    }
    
    //enqueue the style css page
    function flourish_woocommerce_plugin_enqueue_styles($hook_suffix) {
        // Check if we are on the correct settings page
       wp_enqueue_style('flourish-woocommerce-plugin-styles', plugin_dir_url(__FILE__) . '../../assets/css/style.css');
    }
    /**
     * Register plugin settings for the Flourish WooCommerce plugin.
     */
    public function register_settings()
    {
        // Add the main settings section
          add_settings_section(
            'flourish_woocommerce_plugin_section',
            '⚙️ Settings from Flourish',
            null,
            'flourish-woocommerce-plugin-settings'
        );

        //  Define and add basic settings fields 
        $basic_settings = [
            'api_key' => 'External API Key',        // ✅ Correct!
            'url' => 'API URL',                     // ✅ Correct!
            'webhook_key' => 'Webhook Signing Key', // ✅ Correct!
        ];

        foreach ($basic_settings as $key => $label) {
            $value = $this->existing_settings[$key] ?? '';
            add_settings_field(
                $key,
                $label,
                function () use ($key, $value) {
                    $this->render_setting_field($key, $value);
                },
                'flourish-woocommerce-plugin-settings',
                'flourish_woocommerce_plugin_section'
            );
        }

        // Handle facility settings
        $facilities = $this->get_facilities_safe();
        $facility_id = $this->existing_settings['facility_id'] ?? '';
        add_settings_field(
            'facility_id',
            'Facility',
            function () use ($facility_id, $facilities) {
                $this->render_facility_id($facility_id, $facilities);
            },
            'flourish-woocommerce-plugin-settings',
            'flourish_woocommerce_plugin_section'
        );

        // Handle sales rep settings if required
        if (!empty($facility_id) && $this->is_sales_rep_required($facility_id)) {
            $sales_reps = $this->get_sales_reps_safe();
            if (!empty($sales_reps)) {
                $sales_rep_id = $this->existing_settings['sales_rep_id'] ?? '';
                add_settings_field(
                    'sales_rep_id',
                    'Sales Rep',
                    function () use ($sales_rep_id, $sales_reps) {
                        $this->render_sales_rep_id($sales_rep_id, $sales_reps);
                    },
                    'flourish-woocommerce-plugin-settings',
                    'flourish_woocommerce_plugin_section'
                );
            }
        }

        // Add order type settings
        $order_type = $this->existing_settings['flourish_order_type'] ?? '';
        $order_status = $this->existing_settings['flourish_order_status'] ?? '';
        $woocommerce_order_status = $this->existing_settings['woocommerce_order_status'] ?? ''; 
        add_settings_field(
            'flourish_order_type',
            'Order Type', 
            function () use ($order_type, $order_status,$woocommerce_order_status) {
                $this->render_flourish_order_type($order_type, $order_status,$woocommerce_order_status);
            },
            'flourish-woocommerce-plugin-settings',
            'flourish_woocommerce_plugin_section' 
        ); 
        
       // Add item sync options settings
        $item_sync_options = $this->existing_settings['item_sync_options'] ?? [];
        add_settings_field(
            'item_sync_options',
            'Item Sync Options',
            function () use ($item_sync_options) {
                $this->render_item_sync_options($item_sync_options);
            },
            'flourish-woocommerce-plugin-settings',
            'flourish_woocommerce_plugin_section'
        );

         // Handle brand filter settings
        $brands = $this->get_brands_safe();
        $saved_brands = $this->existing_settings['brands'] ?? [];
        $filter_brands = $this->existing_settings['filter_brands'] ?? false;
        add_settings_field(
            'brands',
            'Filter Brands',
            function () use ($filter_brands, $saved_brands, $brands) {
                $this->render_brands($filter_brands, $saved_brands, $brands);
            },
            'flourish-woocommerce-plugin-settings',
            'flourish_woocommerce_plugin_section'
        );
    }

    /**
     * Safely retrieve facilities with error handling.
     */
    private function get_facilities_safe()
    {
        try {
            return $this->get_facilities();
        } catch (\Exception $e) {
            $this->add_admin_notice($e->getMessage());
            return [];
        }
    }

    /**
     * Check if a facility requires a sales representative.
     */
    private function is_sales_rep_required($facility_id)
    {
        $facility_config = $this->get_facility_config($facility_id);
        return $facility_config['sales_rep_required_for_outbound'] ?? false;
    }

    /**
     * Safely retrieve sales representatives with error handling.
     */
    private function get_sales_reps_safe()
    {
        try {
            return $this->get_sales_reps();
        } catch (\Exception $e) {
            $this->add_admin_notice($e->getMessage());
            return [];
        }
    }

    /**
     * Safely retrieve brands with error handling.
     */
    private function get_brands_safe()
    {
        try {
            return $this->get_brands();
        } catch (\Exception $e) {
            $this->add_admin_notice($e->getMessage());
            return [];
        }
    }

    /**
     * Add an admin notice for displaying error messages.
     */
    private function add_admin_notice($message)
    {
        add_action('admin_notices', function () use ($message) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <?php
        });
    }
    public function sanitize_settings($settings)
    {
        $sanitized_settings = [];
        $sanitized_settings['api_key'] = !empty($settings['api_key']) ? sanitize_text_field($settings['api_key']) : '';
        // Removed username sanitization as it's no longer needed
        $sanitized_settings['facility_id'] = !empty($settings['facility_id']) ? sanitize_text_field($settings['facility_id']) : '';
        $sanitized_settings['sales_rep_id'] = !empty($settings['sales_rep_id']) ? sanitize_text_field($settings['sales_rep_id']) : '';
        $sanitized_settings['webhook_key'] = !empty($settings['webhook_key']) ? sanitize_text_field($settings['webhook_key']) : '';

        // Default to retail
        $sanitized_settings['flourish_order_type'] = !empty($settings['flourish_order_type']) ? sanitize_text_field($settings['flourish_order_type']) : 'retail';
        // Default to created in retail order status
        $sanitized_settings['flourish_order_status'] = !empty($settings['flourish_order_status']) ? sanitize_text_field($settings['flourish_order_status']) : 'created';
       // Handle WooCommerce order status for outbound orders
        $sanitized_settings['woocommerce_order_status'] = !empty($settings['woocommerce_order_status']) ? sanitize_text_field($settings['woocommerce_order_status']) : 'draft';
  
        // Default to production API
        $sanitized_settings['url'] = !empty($settings['url']) ? esc_url_raw($settings['url']) : 'https://app.flourishsoftware.com';

        // Sanitize the brands
        $sanitized_settings['brands'] = !empty($settings['brands']) ? array_map('sanitize_text_field', $settings['brands']) : [];

        // Sanitize the filter brands
        $sanitized_settings['filter_brands'] = !empty($settings['filter_brands']) ? sanitize_text_field($settings['filter_brands']) : false;

        // Sanitize the item sync options
        if (!isset($settings['item_sync_options'])) {
            $sanitized_settings['item_sync_options'] = [
                'name' => 0,
                'description' => 0,
                'price' => 0,
                'categories' => 0,
            ];
        } else {
            $sanitized_settings['item_sync_options'] = array_map('sanitize_text_field', $settings['item_sync_options']);
        }

        return $sanitized_settings;
    }
    /// This function renders a setting field based on the provided key and value.
    /// It dynamically sets the input type (text, password, url) and handles special cases
    /// for the 'webhook_key' field, generating a hashed value if the API key exists.
    public function render_setting_field($key, $setting_value)
    {
        $input_type = 'text'; // Default input type
        $readonly = ''; // Default readonly attribute

        // Determine input type and value based on the key
        switch ($key) {
            case 'url':
                $input_type = 'url';
                $setting_value = $setting_value ?: 'https://app.flourishsoftware.com';
                break;

            case 'api_key':
                if (!empty($setting_value)) {
                    $input_type = 'password';
                }
                break;
            
            case 'webhook_key':
                $readonly = 'readonly';

                // Updated webhook key generation to use only API key instead of username + API key
                if (empty($this->existing_settings['api_key'])) {
                    $setting_value = 'Provide your API key';
                } else {
                    $setting_value = sha1($this->existing_settings['api_key']);
                }
                break;
        }

        // Render the input field
        ?>
        <input 
            type="<?php echo esc_attr($input_type); ?>" 
            id="<?php echo esc_attr($key); ?>" 
            name="flourish_woocommerce_plugin_settings[<?php echo esc_attr($key); ?>]" 
            value="<?php echo esc_attr($setting_value); ?>" 
            size="42" 
            <?php echo $readonly ? 'readonly' : ''; ?> 
        />
        <?php
    }


    //Render flourish order type retail and outbound in setting page
    public function render_flourish_order_type($setting_value,$order_status_value,$woocommerce_order_status)
    {
        ?>
        <input type="radio" id="flourish_order_type_retail" name="flourish_woocommerce_plugin_settings[flourish_order_type]" value="retail" <?php checked($setting_value, 'retail'); ?> <?php checked($setting_value, ''); ?> />
        <label for="flourish_order_type_retail">Retail</label>
        <div class="toggle-container">
        <h4>Retail Order status:</h4>
        <input type="radio" id="flourish_order_type_created" name="flourish_woocommerce_plugin_settings[flourish_order_status]" value="created" <?php checked($order_status_value, 'created'); ?> <?php checked($order_status_value, ''); ?> />
        <label for="flourish_order_type_created">Created</label>
        <input type="radio" id="flourish_order_type_submitted" name="flourish_woocommerce_plugin_settings[flourish_order_status]" value="submitted" <?php checked($order_status_value, 'submitted'); ?> />
        <label for="flourish_order_type_submitted">Submitted</label>
        </div>
        <br>
        <p class="description">Orders will be created in Flourish as retail orders from customers.</p>
        <ul>
            <li>• Facility must be of type "retail"</li>
            <li>• Date of birth will be required and collected from the customer</li>
        </ul>
        <br>
        <input type="radio" id="flourish_order_type_outbound" name="flourish_woocommerce_plugin_settings[flourish_order_type]" value="outbound" <?php checked($setting_value, 'outbound'); ?> />
        <label for="flourish_order_type_outbound">Outbound</label>
        <div class="toggle-container">
        <h4>Outbound Order status:</h4>
        <input type="radio" id="flourish_order_type_draft" name="flourish_woocommerce_plugin_settings[woocommerce_order_status]" value="draft" <?php checked($woocommerce_order_status, 'draft'); ?> <?php checked($woocommerce_order_status, ''); ?> />
        <label for="flourish_order_type_draft">Draft</label>
        <input type="radio" id="flourish_order_type_processing" name="flourish_woocommerce_plugin_settings[woocommerce_order_status]" value="processing" <?php checked($woocommerce_order_status, 'processing'); ?> />
        <label for="flourish_order_type_processing">Processing</label>
        </div>
        <p class="description">Orders will be created in Flourish as outbound orders from destinations.</p>
        <ul>
            <li>• License will be required and collected from the destination</li>
            <li>• Used in support of the <a href="https://docs.flourishsoftware.com/article/1ceu43yo4p-before-setting-up-woo-commerce-integration">Flourish Wholesale Portal</a> experience for orders and workflow</li>
        </ul>
        <?php
    }
    
    
    public function render_settings_page()
    {
        $import_products_button_active = true;
        // Updated to check API key instead of username and API key
        foreach (['api_key', 'facility_id', 'url'] as $required_setting) {
            if (!isset($this->existing_settings[$required_setting]) || !strlen($this->existing_settings[$required_setting])) {
                $import_products_button_active = false;
                break;
            }
        }

        if (isset($this->existing_settings['filter_brands']) && $this->existing_settings['filter_brands'] && !count($this->existing_settings['brands'])) {
            $import_products_button_active = false;
        }

        if (isset($_SERVER['HTTPS']) &&
            ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
            isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
            $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
            $protocol = 'https://';
        } else {
            $protocol = 'http://';
        }

        $site_url = $protocol . $_SERVER['HTTP_HOST'];
        ?>
        <div class="wrap">
            <h1>Flourish WooCommerce Plugin Settings</h1>
            <p>
                Retrieve your External API Key from Flourish. <a href="https://docs.flourishsoftware.com/article/u70dr055pp-flourish-software-external-api-key-management" target="_blank">Learn about the new API Key Management</a>
            </p>
            
            <h2>🪝 Webhooks</h2>
            <p class="description">
                More information about webhooks in Flourish can be found here: <a href="https://docs.flourishsoftware.com/article/am15rjpmvg-flourish-webhooks">Flourish Webhooks</a>.
            </p>
            <p class="description">
                More information about securing webhooks with a signing key in Flourish can be found here: <a href="https://docs.flourishsoftware.com/article/gr4ipg7jcv-securing-your-webhooks">Securing your webhooks</a>.
            </p>
            <p>
                1. Configure Wordpress so that your Webhook endpoints are available by using "Post name" permalinks. 
                <ul>
                    <li>• Settings → Permalinks → Post name.</li>
                </ul>
            </p>
            <p>
                2. Configure your Flourish webhooks so that Flourish can communicate with your shop. You need to create them for:
            </p>
            <ul>
                <li>• Item</li>
                <li>• Retail Order</li>
                <li>• Outbound Order</li>
                <li>• Inventory Summary</li>
            </ul>
            <p>
                <strong>🔗 Endpoint URL:</strong> <span style="font-family: 'Courier New', Courier, monospace; white-space: nowrap;"><?php echo $site_url; ?>/wp-json/flourish-woocommerce-plugin/v1/webhook</span>
            </p>
            <p>
                <strong>🔑 Signing Key:</strong> Copy the key generated from "Webhook Signing Key" below as your "Signing Key" in Flourish. "Save Changes" here when complete.
            </p>
            <form method="post" action="options.php">
                <?php
                settings_fields('flourish-woocommerce-plugin-settings-group');
                do_settings_sections('flourish-woocommerce-plugin-settings');
                submit_button();
                ?>
            </form>

            <hr>

            <!-- Notification Alert Container -->
            <div id="import-alert-container" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgb(0 0 0 / 80%); z-index: 9999; text-align: center; color: #fff;">
                <div style="margin-top: 20%; font-size: 18px;">
                    <p>Please do not refresh the page or go back until the import process is complete.</p>
                    <p><strong>Importing Products...Please wait!</strong></p>
                </div>
            </div>

            <div class="wrap">
                <h2>↔️ Import Flourish Items to WooCommerce Products</h2>
                <p class="description">Once you have provided your API key above, use this button to import items from the Flourish API into WooCommerce products.</p>
                <br>
                <form id="case-size-form">
                <?php wp_nonce_field('case_size_nonce', 'case_size_nonce'); ?>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                            <th>Case Name</th>
                            <th>Quantity</th>
                            <th>Base UOM</th>
                            <th>Actions</th>
                            </tr>
                        </thead>
                            <tbody id="case-size-rows">
                            <?php echo $this->fetch_existing_case_sizes(); ?>
                            </tbody>
                    </table>
                    <hr>
                    <div class="case-size-container">
                        <input type="text" id="case-name" placeholder="Case Name" required />
                        <input type="number" id="quantity" placeholder="Quantity" min="1" required />
                        <?php  echo $this->display_uom_dropdown();?>
                        <button type="button" id="add-case-row" class="button button-primary">+ Add Case Size</button>
                    </div>
                </form>
                <hr>
                <!-- Form for Import Products -->
                <form method="post" id="handle-import-product-form">
                <?php wp_nonce_field('flourish-woocommerce-plugin-import-products', 'import_nonce'); ?>
                <input type="hidden" name="action" value="import_products">
                <input type="submit" id="import-products" class="button button-primary" value="Initial Sync" <?php echo $import_products_button_active ? '' : 'disabled'; ?>>
                </form>
                <div class="flourish_setting_warn">
                ⚠️ <strong style="font-size: 17px; text-transform: uppercase;">Warning:</strong> 
                This will reset all product information and re-import items from Flourish. We recommend avoid clicking this after the initial import unless you expect to reset data.
                </div> 
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const importForm = document.getElementById('handle-import-product-form');
                const alertContainer = document.getElementById('import-alert-container');
                if (importForm) {
                    importForm.addEventListener('submit', function () {
                        // Prevent scrolling
                        document.body.style.overflow = 'hidden';
                        // Show the alert container
                        alertContainer.style.display = 'block';
                    });
                }
            });
        </script>
        <?php
    }
    function get_uom_options_ajax_handler()
    {
    // Assuming the 'display_uom_dropdown' method is part of the class
    $uom_options = $this->display_uom_dropdown(); // Fetch UOM options
    
    // Return the options as the AJAX response (without the <select> element)
    echo $uom_options;
    
    // Always call wp_die() to end the AJAX request
    wp_die();
    }
   
    public function display_uom_dropdown()
    {
        // Initialize the API object
        $flourish_api = $this->get_flourish_api();

        // Check if the API object is initialized
        if (!$flourish_api) {
            error_log('Flourish API object is not initialized.');
            return '<option value="">Error initializing Flourish API.</option>';
        }

        // Fetch UOMs from the API
        $uoms = $flourish_api->fetch_uoms();

        // Log the response for debugging
        error_log('UOMs response: ' . print_r($uoms, true));

        // Check if UOMs are returned and are in the correct format
        if ($uoms && is_array($uoms)) {
            $options = '';
            $options = '<select id="uom" required>';   // Start the dropdown
            // Loop through the UOM data and create <option> elements
            foreach ($uoms as $uom) {
                $value = htmlspecialchars($uom['uom']); // UOM value
                $description = htmlspecialchars($uom['description']); // UOM display text
                $options .= "<option value=\"$value\">$description</option>";
            }
            // End the dropdown
            $options .= '</select>'; 
            return $options;  
        } else {
            error_log('No UOMs available.');
            return '<option value="" style="color:red;padding:10px;font-size:14px;">No UOMs available.' . $uoms . '</option>'; // Return an error message if no UOM data is found
        }
    }

    public function render_facility_id($setting_value, $facilities)
    {
        $disabled = '';
        $message = 'Select a Flourish facility to sync with';
        // Updated to check only API key instead of username and API key
        if (!isset($this->existing_settings['api_key']) || !strlen($this->existing_settings['api_key'])) {
            $disabled = 'disabled';
            $message = 'Provide your API key to select a facility.';
        } 
        ?>
        <select id="facility_id" name="flourish_woocommerce_plugin_settings[facility_id]" <?php echo $disabled; ?> width="50px">
            <option value=""><?php echo $message; ?></option>
            <?php foreach ($facilities as $facility) : ?>
                <option value="<?php echo $facility['id']; ?>" <?php selected($setting_value, $facility['id']); ?>><?php echo sprintf('%s - %s', $facility['facility_name'], $facility['facility_type']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_sales_rep_id($setting_value, $sales_reps)
    {
        $disabled = '';
        $message = 'Select a sales rep for your orders';
        // Updated to check only API key instead of username and API key
        if (!isset($this->existing_settings['api_key']) || !strlen($this->existing_settings['api_key'])) {
            $disabled = 'disabled';
            $message = 'Provide your API key to select a facility and see sales reps.';
        } 
        ?>
        <select id="facility_id" name="flourish_woocommerce_plugin_settings[sales_rep_id]" <?php echo $disabled; ?> width="50px">
            <option value=""><?php echo $message; ?></option>
            <?php foreach ($sales_reps as $sales_rep) : ?>
                <option value="<?php echo $sales_rep['sales_rep_id']; ?>" <?php selected($setting_value, $sales_rep['sales_rep_id']); ?>><?php echo $sales_rep['first_name'] . ' ' . $sales_rep['last_name']; ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_brands($filter_brands, $saved_brands, $brands) 
    {
        ?>
        <label>
            <input id="flourish-woocommerce-plugin-filter-brands" type="checkbox" name="flourish_woocommerce_plugin_settings[filter_brands]" value="1" <?php checked($filter_brands); ?>>
            Filter brands to sync with WooCommerce
        </label>
        <br>
        <br>
        <div id="flourish-woocommerce-plugin-brand-selection"<?php echo $filter_brands ? '' : ' style="display: none;"'; ?>>
            <p class="description">Select the brands you would like to sync with WooCommerce.</p>
            <br>
            <?php
            // For each brand, render a checkbox.
            foreach ($brands as $brand) {
                $brand_name = $brand['brand_name'];
                ?>
                <label>
                    <input type="checkbox" name="flourish_woocommerce_plugin_settings[brands][]" value="<?php echo esc_attr($brand_name); ?>" <?php checked(empty($saved_brands) || in_array($brand_name, $saved_brands)); ?>>
                    <?php echo esc_html($brand_name); ?>
                </label><br>
                <?php
            }
        ?>
        </div>
        <?php
    }

    // Render item sync options for: name, description, price, and categories
    public function render_item_sync_options($item_sync_options)
    {
        if (empty($item_sync_options)) {
            $item_sync_options = [
                'name' => 1,
                'description' => 1,
                'price' => 1,
                'categories' => 1
            ];
        }
        ?>
        <p class="description">Select the item data you would like to sync from Flourish to WooCommerce.<br><em>This will overwrite WooCommerce data on product import or update.</em></p>
        <br>
        <label>
            <input type="checkbox" name="flourish_woocommerce_plugin_settings[item_sync_options][name]" value="1" <?php checked($item_sync_options['name']); ?>>
            Names
        </label>
        <br>
        <label>
            <input type="checkbox" name="flourish_woocommerce_plugin_settings[item_sync_options][description]" value="1" <?php checked($item_sync_options['description']); ?>>  
            Descriptions
        </label>
        <br>
        <label>
            <input type="checkbox" name="flourish_woocommerce_plugin_settings[item_sync_options][price]" value="1" <?php checked($item_sync_options['price']); ?>>
            Prices
        </label>
        <br>
        <label>
            <input type="checkbox" name="flourish_woocommerce_plugin_settings[item_sync_options][categories]" value="1" <?php checked($item_sync_options['categories']); ?>>
            Categories
        </label>
        <?php
    }

    public function add_settings_link($links)
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=flourish-woocommerce-plugin-settings'),
            __('Settings', 'flourish-woocommerce-plugin')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    public function handle_import_products_form_submission()
    {
        // Check the nonce for security.
        check_admin_referer('flourish-woocommerce-plugin-import-products', 'import_nonce');

        // Check if the user has the necessary capability.
        if (!current_user_can('manage_options')) {
            wp_die('You do not have the necessary permissions to import products.');
        }

        // Call the import_products method.
        try {
            $imported_count = $this->import_products();

            add_action('admin_notices', function () use ($imported_count) {
                ?>
                <div class="notice notice-success is-dismissible" style="display:block !important;">
                    <p><span style="color: #00a32a;"><strong>Success!</strong></span> <?php _e($imported_count . ' Flourish items successfully synced with WooCommerce products.'); ?></p>
                </div>
                <?php
            });
        } catch (\Exception $e) {
            add_action('admin_notices', function () use ($e) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php _e('An error has occurred attempting to import the items: ' . $e->getMessage()); ?></p>
                </div>
                <?php
            });
        }
    }
    
    // Helper method to initialize the FlourishAPI object - Updated for new authentication
    private function get_flourish_api()
    {
        $api_key = isset($this->existing_settings['api_key']) ? $this->existing_settings['api_key'] : '';
        $url = isset($this->existing_settings['url']) ? $this->existing_settings['url'] : '';
        $facility_id = isset($this->existing_settings['facility_id']) ? $this->existing_settings['facility_id'] : '';

        // Updated constructor call - removed username parameter
        return new FlourishAPI($api_key, $url, $facility_id);
    }
    
    public function import_products() {
        $flourish_api = $this->get_flourish_api();
        return $flourish_api->fetch_products($this->existing_settings['filter_brands'] ?? false, $this->existing_settings['brands'] ?? []); // Call fetch_products, which now handles processing
    }
   
    public function get_facilities()
    {
        // Fetch the API object
        $flourish_api = $this->get_flourish_api();

        // Return the facilities data
        return $flourish_api->fetch_facilities();
    }

    public function get_brands()
    {
        // Fetch the API object
        $flourish_api = $this->get_flourish_api();

        // Return the brands data
        return $flourish_api->fetch_brands();
    }

    public function get_sales_reps()
    {
        // Fetch the API object
        $flourish_api = $this->get_flourish_api();

        // Return the sales reps data
        return $flourish_api->fetch_sales_reps();
    }

    public function get_facility_config($facility_id)
    {
        // Fetch the API object
        $flourish_api = $this->get_flourish_api();
        if(!empty($facility_id))
        {
            // Return the facility config
            return $flourish_api->fetch_facility_config($facility_id);
        }
        else
        {
            return true;
        }
    }
    
    /**
     * This function checks whether case sizes already exist.
     */
    public function fetch_existing_case_sizes()
    {
        $attributes = wc_get_attribute_taxonomies();
        $rows = '';
        $has_records = false; // Flag to check if any records exist    
        foreach ($attributes as $attribute)
        {
            $terms = get_terms('pa_' . $attribute->attribute_name, ['hide_empty' => false]);
            if (!empty($terms))
            {
                foreach ($terms as $term)
                {
                    $quantity = get_term_meta($term->term_id, 'quantity', true);
                    $base_uom = get_term_meta($term->term_id, 'base_uom', true);
                    // Get the taxonomy object to access taxonomy name
                    $taxonomy = 'pa_' . $attribute->attribute_name; // Correct taxonomy slug
                    $taxonomy_name = $taxonomy ? $taxonomy : ''; // Correct taxonomy slug
                     // Create the table rows with taxonomy name in the data-term-name attribute
                    $rows .= $this->render_case_size_row($term->term_id, $term->name, $quantity, $base_uom, $taxonomy);
                    $has_records = true; // Set flag to true as records are found
                }
            }
        }    
        // If no records are found, show "No records found" message
        if (!$has_records)
        {
            $rows .= '<tr><td colspan="4" style="text-align: center;" id="no-records-row">No records found</td></tr>';
        }
   
        return $rows;
    }
       
    /**
     * Unified function to handle Add/Edit operations for case sizes.
     */
 
    function handle_ajax_add_edit_case_size()
    {
        // Verify nonce for security
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'case_size_nonce'))
        {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }
        // Get POST data
        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : null;
        $case_name = sanitize_text_field($_POST['case_name']);
        $quantity = intval($_POST['quantity']);
        $base_uom = sanitize_text_field($_POST['base_uom']);
        if (empty($case_name) || empty($quantity) || empty($base_uom)) {
            wp_send_json_error(['message' => 'All fields are required.']);
        }
 
        $base_uom_slug = sanitize_title($base_uom);
        $taxonomy_name = 'pa_' . $base_uom_slug;
        // Ensure the attribute exists
        if (!$this->check_if_attribute_exists($base_uom_slug))
        {
            $this->create_base_uom_attribute($base_uom, $taxonomy_name);
        }
        // Handle Add or Edit operation
        if ($term_id)
        {
            $this->update_case_size($term_id, $case_name, $quantity, $base_uom, $taxonomy_name);
        }
        else
        {
            $this->add_case_size($case_name, $quantity, $base_uom, $taxonomy_name);
        }
    }
 
    /**
     * Update case size term.
     */
    private function update_case_size($term_id, $case_name, $quantity, $base_uom, $taxonomy_name)
    {
        $existing_uom = get_term_meta($term_id, 'base_uom', true);
        // Handle UOM change
        if ($existing_uom !== $base_uom)
        {
            wp_delete_term($term_id, 'pa_' . sanitize_title($existing_uom));
            $this->add_case_size($case_name, $quantity, $base_uom, $taxonomy_name);
        }
        else
        {
            // Update term details
            if (!term_exists($term_id, $taxonomy_name))
            {
                wp_send_json_error(['message' => 'The term does not exist.']);
            }
            $term_update = wp_update_term($term_id, $taxonomy_name, ['name' => $case_name]);
            if (is_wp_error($term_update))
            {
                wp_send_json_error(['message' => 'Failed to update the case name.']);
            }
            $this->update_term_meta($term_id, $quantity, $base_uom,$case_name);
            $row_html = $this->render_case_size_row($term_id, $case_name, $quantity, $base_uom, $taxonomy_name);
            wp_send_json_success(['row_html' => $row_html, 'message' => 'Case Name updated successfully.']);
        }
    }
 
    /**
     * Add new case size term.
     */
    private function add_case_size($case_name, $quantity, $base_uom, $taxonomy_name)
    {
        $term_slug = sanitize_title($case_name);
        $term = term_exists($term_slug, $taxonomy_name);
        if ($term)
        {
            wp_send_json_error(['message' => 'Case Name already exists.']);
        }
        $result = wp_insert_term($term_slug, $taxonomy_name, ['slug' => $term_slug]);
        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => 'Error creating term: ' . $result->get_error_message()]);
        }
        $term_id = $result['term_id'];
        $this->update_term_meta($term_id, $quantity, $base_uom,$case_name);
        $row_html = $this->render_case_size_row($term_id, $case_name, $quantity, $base_uom, $taxonomy_name);
        wp_send_json_success(['row_html' => $row_html, 'message' => 'Case Name added successfully.']);
    }
 
    /**
     * Update term meta for quantity and base UOM.
     */
    private function update_term_meta($term_id, $quantity, $base_uom,$case_name)
    {
        update_term_meta($term_id, 'case_name', $case_name);
        update_term_meta($term_id, 'quantity', $quantity);
        update_term_meta($term_id, 'base_uom', $base_uom);
    }
 
    /**
     * Helper function to create a base_uom attribute if it doesn't exist.
     */
    private function create_base_uom_attribute($base_uom, $taxonomy_name)
    {
        $attribute = wc_create_attribute([
            'name' => 'pa_' . sanitize_title($base_uom),
            'slug' => $taxonomy_name,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ]);
 
        if (is_wp_error($attribute))
        {
            wp_send_json_error(['message' => 'Error creating base_uom attribute: ' . $attribute->get_error_message()]);
        }
        register_taxonomy(
            $taxonomy_name,
            'product',
            [
                'labels' => [
                    'name' => __('Base UOM', 'woocommerce'),
                    'singular_name' => __('Base UOM', 'woocommerce'),
                ],
                'hierarchical' => true,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]
        );
        flush_rewrite_rules();
    }
   
    /**
     * Helper function to check if the attribute exists.
     *
     * @param string $slug
     * @return bool
     */
    private function check_if_attribute_exists($slug)
    {
       global $wpdb;
        // Check if the attribute exists in the WooCommerce attribute taxonomies table
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
                $slug
            )
        );
        return !empty($result);
    }
    /**
     * Helper function to generate HTML for a case size row.
     *
     * @param int $term_id
     * @param string $case_name
     * @param string $quantity
     * @param string $base_uom
     * @param string $taxonomy
     * @return string
     */
    private function render_case_size_row($term_id, $case_name, $quantity, $base_uom, $taxonomy)
    {
        $term_slug = $case_name; // Your input string
        $term_name = str_replace('-', ' ', $term_slug); // Replace underscores with spaces
        ob_start();
        echo '<tr>';
        echo '<td class="casename">' . esc_html($term_name) . '</td>';
        echo '<td class="quantity">' . esc_html($quantity) . '</td>';
        echo '<td class="base_uom">' . esc_html($base_uom) . '</td>';
        echo '<td class="actions">';
        echo '<a href="#" class="edit-case-size" data-term-name="' . esc_attr($taxonomy) . '" data-term-id="' . esc_attr($term_id) . '">Edit</a> | ';
        echo '<a href="#" class="delete-case-size" data-term-name="' . esc_attr($taxonomy) . '" data-term-id="' . esc_attr($term_id) . '">Delete</a>';
        echo '</td>';
        echo '</tr>';
        return ob_get_clean();
    }
 
    /**
     * Helper function to Delete attributes.
     */
    public function handle_ajax_delete_case_size()
    {
        // Verify nonce for security
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'delete_case_size_nonce'))
        {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }
        // Get the term ID and taxonomy (pa_{attribute_name})
        $term_id = intval($_POST['term_id']);
        $taxonomy = sanitize_text_field($_POST['taxonomy']); // Ensure correct taxonomy is passed
        // Check if the term exists
        if (!term_exists($term_id, $taxonomy))
        {
            wp_send_json_error(['message' => 'The term does not exist or has already been deleted.']);
        }    
        // Attempt to delete the term
        $result = wp_delete_term($term_id, $taxonomy);    
        if (is_wp_error($result))
        {
            wp_send_json_error(['message' => 'Error deleting term: ' . $result->get_error_message()]);
        }
        elseif ($result === false)
        {
            wp_send_json_error(['message' => 'The term could not be deleted.']);
        } 
         
              // Check if the taxonomy (attribute) still exists after deletion
    // Check if the taxonomy (attribute) still exists after deletion
    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
    ]);

    if (empty($terms)) {
        // If no terms exist, delete the attribute
        global $wpdb;
        $attribute_name = str_replace('pa_', '', $taxonomy);

        // Check if the attribute exists in WooCommerce's attribute table
        $attribute_id = $wpdb->get_var($wpdb->prepare("
            SELECT attribute_id
            FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
            WHERE attribute_name = %s
        ", $attribute_name));

        if ($attribute_id) {
            // Delete the attribute from WooCommerce's attribute table
            $wpdb->delete(
                $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                ['attribute_name' => $attribute_name],
                ['%s']
            );

            // Flush WooCommerce attributes cache
            delete_transient('wc_attribute_taxonomies');
        }
    }
        
        // Success
        wp_send_json_success(['message' => 'Case Name deleted successfully.']);
    }

    /**
     *  function to handle Edit operations for case sizes to retrieve the available Unit of Measurement (UOM) options. 
     */
    function get_uom_dropdown_html_handler() {
        // 'display_uom_dropdown' method is part of the class
        $uom_options = $this->display_uom_dropdown(); // Fetch UOM options
        // Return success response
        wp_send_json_success(['html' => $uom_options]);
    }
     
    public function register_draft_order_email($emails)
    {
         
        $email_class_file = plugin_dir_path(dirname(__DIR__)). 'classes/emails/class-wc-email-draft-order.php';

        
        if (file_exists($email_class_file)) {
            require_once $email_class_file;
            $emails['WC_Email_Draft_Order'] = new \WC_Email_Draft_Order();
        } else {
            error_log('Draft order email class file missing.');
        }
    
        return $emails;
    }
    
}