<?php
namespace FlourishWooCommercePlugin\CustomFields;
use WP_REST_Response;
defined( 'ABSPATH' ) || exit;
use FlourishWooCommercePlugin\API\FlourishAPI;

class License
{
    public $existing_settings;
 
    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }
 
    /**
     * Registers all hooks for adding, validating, and saving the custom License field.
     */
    public function register_hooks()
    {
        add_action('enqueue_block_assets', [$this, 'enqueue_custom_checkout_fields']);
        add_filter('woocommerce_checkout_fields', [$this, 'modify_checkout_fields']);
        add_action('woocommerce_before_checkout_billing_form', [$this,'add_custom_destination_field']);
        add_action('woocommerce_checkout_process', [$this,'validate_checkout_fields']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_order_meta']);
        add_action('wp_ajax_ship_destination_from_flourish', [$this, 'ship_destination_from_flourish']);
        
        // Always display shipping fields
        add_filter('woocommerce_cart_needs_shipping_address', '__return_true');
        add_action('wp_head', [$this, 'add_checkout_styles']);
        add_action('woocommerce_before_checkout_shipping_form', [$this,'add_shipping_details_heading'], 10);
        add_filter('woocommerce_checkout_get_value', [$this, 'clear_field_values'], 10, 2);
        
        // Override WooCommerce address validation
        add_action('woocommerce_after_checkout_validation', [$this, 'override_address_validation'], 10, 2);
    }

    /**
     * Override WooCommerce's default address validation
     */
    public function override_address_validation($data, $errors) {
        // Clear any address-related errors if destination is provided
        if (!empty($_POST['destination'])) {
            $error_codes_to_remove = [
                'billing_address_1_required',
                'billing_city_required',
                'billing_postcode_required',
                'billing_country_required',
                'billing_state_required',
                'shipping_address_1_required',
                'shipping_city_required',
                'shipping_postcode_required',
                'shipping_country_required',
                'shipping_state_required'
            ];
            
            foreach ($error_codes_to_remove as $code) {
                $errors->remove($code);
            }
        }
    }

    /**
     * Validate checkout fields
     */
    public function validate_checkout_fields() {
        // Only validate destination field
        if (empty($_POST['destination'])) {
            wc_add_notice(__('Please select a destination', 'woocommerce'), 'error');
        }
         
        // Get the store's base country
        $store_base_country = WC()->countries->get_base_country();

        // Check if shipping country is empty
        // The $_POST array contains the submitted form data
        if (empty($_POST['shipping_country'])) {
            // If shipping country is empty, set it to the store's base country
            $_POST['shipping_country'] = $store_base_country;
          }
    }

    /**
     * Clear specific field values
     */
    public function clear_field_values($input, $key) {
        $fields_to_clear = [
            'billing_company','billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_phone', 'billing_country',
            'shipping_company','shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone'
        ];

        if (in_array($key, $fields_to_clear)) {
            return '';
        }
        return $input;
    }

    /**
     * Add shipping details heading
     */
    public function add_shipping_details_heading() {
        echo '<h4>' . __('Shipping Details', 'woocommerce') . '</h4>';
    }

    /**
     * Add checkout styles
     */
    public function add_checkout_styles() {
        if (!is_checkout()) return;
        
        echo '<style>
            .woocommerce-shipping-fields__field-wrapper,
            .shipping_address {
                display: block !important;
            }
            .woocommerce-shipping-fields__field-wrapper .woocommerce-shipping-fields h3,
            .woocommerce-shipping-fields .woocommerce-form__input-checkbox,
            #ship-to-different-address,
            .woocommerce-billing-fields__field-wrapper .optional,
            .woocommerce-shipping-fields__field-wrapper .optional {
                display: none !important;
            }
        </style>';
    }

    /**
     * AJAX handler for destination lookup
     */
    public function ship_destination_from_flourish()
    {
        if (empty($_POST['destination'])) {
            wp_send_json_error(['message' => 'Destination value is missing']);
            return;
        }

        $api_key = $this->existing_settings['api_key'] ?? '';
        $username = $this->existing_settings['username'] ?? '';
        $url = $this->existing_settings['url'] ?? '';
        $facility_id = $this->existing_settings['facility_id'] ?? '';

        $flourish_api = new FlourishAPI($username, $api_key, $url, $facility_id);

        try {
            $existing_destination = $flourish_api->fetch_destination_by_destination_id($_POST['destination']);
            if ($existing_destination && is_array($existing_destination)) {
                wp_send_json_success(['data' => $existing_destination]);
            } else {
                wp_send_json_error(['message' => 'No destination found: ' . $_POST['destination']]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error fetching destination: ' . $e->getMessage()]);
        }
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_custom_checkout_fields()
    {
        if (!function_exists('is_checkout') || !is_checkout()) return;
        
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'custom-checkout-license',
            plugin_dir_url(__DIR__) . '../assets/js/custom-checkout-license.js',
            ['wp-hooks', 'wc-blocks-checkout'],
            '1.0',
            true
        );
        
        wp_enqueue_style(
            'custom-checkout-fields-style',
            plugin_dir_url(__DIR__) . '../assets/css/style.css',
            array(),
            '1.0.0'
        );
        
        wp_localize_script(
            'custom-checkout-license',
            'licenseData',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('license_management_nonce'),
            ]
        );

        add_action('wp_footer', function() {
            if (is_checkout()) {
                echo '<div id="loading_overlay" style="display: none;">
                    <div class="spinner"></div>
                    <div class="loading-message">Please wait... Don\'t refresh the page.</div>
                  </div>';
            }
        });
    }

    /**
     * Save order meta data
     */
    public function save_order_meta($order_id)
    {
        // Save license
        if (isset($_POST['license'])) {
            update_post_meta($order_id, 'license', sanitize_text_field($_POST['license']));
        }
        
        // Save destination
        if (!empty($_POST['destination'])) {
            $destination_id = sanitize_text_field($_POST['destination']);
            update_post_meta($order_id, '_destination_id', $destination_id);

            // Get destination text
            $api_key = $this->existing_settings['api_key'] ?? '';
            $username = $this->existing_settings['username'] ?? '';
            $url = $this->existing_settings['url'] ?? '';
            $facility_id = $this->existing_settings['facility_id'] ?? '';

            $flourish_api = new FlourishAPI($username, $api_key, $url, $facility_id);
            try {
                $destination_options = $flourish_api->fetch_destination_by_destination_id($_POST['destination']);
                if (isset($destination_options['name'])) {
                    update_post_meta($order_id, '_destination_text', $destination_options['name']);
                }
            } catch (\Exception $e) {
                error_log('Error saving destination text: ' . $e->getMessage());
            }
        }
        
        // Save shipping phone
        if (!empty($_POST['shipping_phone'])) {
            update_post_meta($order_id, '_shipping_phone', sanitize_text_field($_POST['shipping_phone']));
        }
    }

    /**
     * Modify checkout fields
     */
    public function modify_checkout_fields($fields)
    {
        // Remove name fields
        unset($fields['billing']['billing_first_name']);
        unset($fields['billing']['billing_last_name']);
        unset($fields['shipping']['shipping_first_name']);
        unset($fields['shipping']['shipping_last_name']);

        // Add hidden license field
        $fields['billing']['license'] = array(
            'type'        => 'hidden',
            'required'    => false,
            'class'       => array('hidden-field'),
            'priority'    => 8,
        );

        // Modify billing fields
        $this->modify_billing_fields($fields);
        
        // Modify shipping fields
        $this->modify_shipping_fields($fields);

        return $fields;
    }

     /**
     * Modify billing fields
     */
    private function modify_billing_fields(&$fields)
    {
        if (!isset($fields['billing'])) return;

        $billing_modifications = [
            'billing_email'      => ['priority' => 9, 'required' => true],
            'billing_address_1'  => ['placeholder' => 'Address 1', 'required' => false],
            'billing_address_2'  => ['placeholder' => 'Address 2', 'required' => false],
            'billing_country'    => ['type' => 'text', 'required' => false],
            'billing_state'      => ['type' => 'text', 'required' => false],
            'billing_city'       => ['required' => false],
            'billing_postcode'   => ['required' => false],
            'billing_phone'      => ['required' => false],
            'billing_company'    => ['required' => true, 'custom_attributes' => ['readonly' => 'readonly']]
        ];

        foreach ($billing_modifications as $key => $modification) {
            if (isset($fields['billing'][$key])) {
                $fields['billing'][$key] = array_merge($fields['billing'][$key], $modification);
            }
        }
    }

    /**
     * Modify shipping fields
     */
    private function modify_shipping_fields(&$fields)
    {
        if (!isset($fields['shipping'])) return;

        $shipping_modifications = [
            'shipping_address_1' => ['placeholder' => 'Address 1', 'required' => false],
            'shipping_address_2' => ['placeholder' => 'Address 2', 'required' => false],
            'shipping_country'   => ['type' => 'text', 'required' => false],
            'shipping_state'     => ['type' => 'text', 'required' => false],
            'shipping_city'      => ['required' => false],
            'shipping_postcode'  => ['required' => false],
            'shipping_company'   => ['required' => true, 'custom_attributes' => ['readonly' => 'readonly']]
        ];

        foreach ($shipping_modifications as $key => $modification) {
            if (isset($fields['shipping'][$key])) {
                $fields['shipping'][$key] = array_merge($fields['shipping'][$key], $modification);
            }
        }

        // Add company phone field
        $fields['shipping']['shipping_phone'] = array(
            'type'        => 'tel',
            'label'       => __('Company Phone', 'woocommerce'),
            'placeholder' => __('Company phone', 'woocommerce'),
            'required'    => false,
            'priority'    => 91,
        );
    }

    /**
     * Add custom destination field
     */
    public function add_custom_destination_field() {
        if (!is_checkout()) return;
           
        $user_id = get_current_user_id();
        $destination_options = $this->get_destination_options_for_user($user_id);
        $is_destination_unavailable = empty($destination_options);
        
        ?>
        <div class="form-row form-row-wide destination-wrapper" id="destination_field">
            <label for="destination"><?php esc_html_e('Destination', 'woocommerce'); ?> <abbr class="required" title="required">*</abbr></label>
            
            <?php if ($is_destination_unavailable): ?>
                <div class="destination-unavailable-notice" style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 4px; margin-bottom: 15px;">
                    <strong><?php esc_html_e('Destinations Not Available', 'woocommerce'); ?></strong><br>
                    <?php esc_html_e('Please contact support to set up your delivery destinations before placing an order.', 'woocommerce'); ?>
                </div>
                <select name="destination" id="destination" class="destination-select" disabled>
                    <option value=""><?php esc_html_e('No destinations available', 'woocommerce'); ?></option>
                </select>
            <?php else: ?>
                <select name="destination" id="destination" class="destination-select" required>
                    <option value=""><?php esc_html_e('Select a destination...', 'woocommerce'); ?></option>
                    <?php foreach ($destination_options as $id => $name) : ?>
                        <?php if (!empty($id)) : ?>
                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div> 
        <?php 
    }

    /**
     * Get destination options for the current user
     */
    
    private function get_destination_options_for_user($user_id) {

     $all_destination =get_user_meta($user_id, 'all_destination', true);
    if ($all_destination=="no")
    {
    // First check if user has saved destinations
    $existing_destination_ids = get_user_meta($user_id, 'destination_ids', true);
    $existing_destination_texts = get_user_meta($user_id, 'destination_texts', true); 
        // If user has saved destinations, use those
        if (!empty($existing_destination_ids) && !empty($existing_destination_texts)) { 
            $ids = maybe_unserialize($existing_destination_ids);
            $texts = maybe_unserialize($existing_destination_texts);
            
            if (is_array($ids) && is_array($texts) && count($ids) === count($texts)) {
                for ($i = 0; $i < count($ids); $i++) {
                    $destination_options[$ids[$i]] = $texts[$i];
                }
                return $destination_options;
            }
        }
    }
    elseif ($all_destination=="yes") 
    { 
    $api_key = $this->existing_settings['api_key'] ?? '';
    $username = $this->existing_settings['username'] ?? '';
    $url = $this->existing_settings['url'] ?? '';
    $facility_id = $this->existing_settings['facility_id'] ?? ''; 
    // Initialize API
    $flourish_api = new FlourishAPI($username, $api_key, $url, $facility_id); 
    // Fetch destinations from API
    $destination_options = $flourish_api->get_destination_options();
     return $destination_options; 
    
}
}
 
     
}     
 