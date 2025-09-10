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

         // Add the sales rep field to checkout
        add_action('woocommerce_after_checkout_shipping_form', [$this, 'add_sales_rep_field_to_checkout']);
        
                // AJAX handler for getting sales reps by destination
        add_action('wp_ajax_get_sales_reps_by_destination', [$this, 'get_sales_reps_by_destination']);
        add_action('wp_ajax_nopriv_get_sales_reps_by_destination', [$this, 'get_sales_reps_by_destination']);


        
        // Always display shipping fields
        add_filter('woocommerce_cart_needs_shipping_address', '__return_true');
        add_action('wp_head', [$this, 'add_checkout_styles']);
        add_action('woocommerce_before_checkout_shipping_form', [$this,'add_shipping_details_heading'], 10);
        add_filter('woocommerce_checkout_get_value', [$this, 'clear_field_values'], 10, 2);
        
        // Override WooCommerce address validation
        add_action('woocommerce_after_checkout_validation', [$this, 'override_address_validation'], 10, 2);
    }
    
 /**
     * AJAX handler to get sales reps for selected destination
     */
    public function get_sales_reps_by_destination() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        $destination_id = sanitize_text_field($_POST['destination_id']);
        $user_id = get_current_user_id();
        
        if (!$user_id || !$destination_id) {
            wp_send_json_error(['message' => 'Missing user or destination ID']);
        }

        try {
            // Get user's sales rep assignments
            $all_sales_rep = get_user_meta($user_id, 'all_sales_rep', true);
            $sales_reps_options = [];

            if ($all_sales_rep === 'yes') {
                // User has all sales reps assigned
                $sales_reps_options = $this->get_all_sales_reps_formatted();
            } else {
                // Get destination-specific sales reps
                $destination_sales_reps = maybe_unserialize(get_user_meta($user_id, 'destination_sales_reps', true));
                
                if (is_array($destination_sales_reps) && isset($destination_sales_reps[$destination_id])) {
                    $assigned_rep_ids = $destination_sales_reps[$destination_id];
                    $sales_reps_options = $this->get_sales_reps_by_ids($assigned_rep_ids);
                }
            }

            wp_send_json_success([
                'sales_reps' => $sales_reps_options,
                'message' => 'Sales reps loaded successfully'
            ]);

        } catch (Exception $e) {
            error_log('Error getting sales reps: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error loading sales representatives']);
        }
    }

    /**
 * Get all sales reps formatted for dropdown - sorted alphabetically (Alternative)
 */
/**
 * Get all sales reps formatted for dropdown - sorted alphabetically
 */
private function get_all_sales_reps_formatted() {
    try {
        $api_key = $this->existing_settings['api_key'] ?? '';
        // Remove username line - no longer needed
        $url = $this->existing_settings['url'] ?? '';
        $facility_id = $this->existing_settings['facility_id'] ?? '';

        // Updated constructor call - removed username parameter
        $flourish_api = new FlourishAPI($api_key, $url, $facility_id);
        $sales_reps_raw = $flourish_api->fetch_sales_reps();
        
        $sales_reps_formatted = [];
        
        if (is_array($sales_reps_raw)) {
            // First, collect all sales reps with their names
            $temp_reps = [];
            
            foreach ($sales_reps_raw as $rep) {
                if (is_array($rep) && isset($rep['sales_rep_id'])) {
                    $rep_name = (isset($rep['first_name'], $rep['last_name']) ? 
                        $rep['first_name'] . ' ' . $rep['last_name'] : 
                        'Sales Rep');
                    $rep_id = isset($rep['sales_rep_id']) ? $rep['sales_rep_id'] : '';
                    $rep_name = trim($rep_name);
                    
                    if ($rep_id && $rep_name) {
                        $temp_reps[] = [
                            'id' => $rep_id,
                            'name' => $rep_name
                        ];
                    }
                }
            }
            
            // Sort by name alphabetically
            usort($temp_reps, function($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
            
            // Convert back to ID => Name format
            foreach ($temp_reps as $rep) {
                $sales_reps_formatted[$rep['id']] = $rep['name'];
            }
        }
        
        return $sales_reps_formatted;
    } catch (Exception $e) {
        error_log('Error fetching all sales reps: ' . $e->getMessage());
        return [];
    }
}

    /**
     * Get specific sales reps by their IDs
     */
    private function get_sales_reps_by_ids($rep_ids) {
        try {
            $all_sales_reps = $this->get_all_sales_reps_formatted();
            $filtered_reps = [];
            
            foreach ($rep_ids as $rep_id) {
                if (isset($all_sales_reps[$rep_id])) {
                    $filtered_reps[$rep_id] = $all_sales_reps[$rep_id];
                }
            }
            
            return $filtered_reps;
        } catch (Exception $e) {
            error_log('Error filtering sales reps by IDs: ' . $e->getMessage());
            return [];
        }
    }
   /**
 * Add sales rep field to checkout
 */
public function add_sales_rep_field_to_checkout() {
    $user_id = get_current_user_id();
    $destination_options = $this->get_destination_options_for_user($user_id);
    $is_destination_unavailable = empty($destination_options);
    
    try {
        // Check user's destination and sales rep settings
        $all_destination = get_user_meta($user_id, 'all_destination', true);
        $all_sales_rep = get_user_meta($user_id, 'all_sales_rep', true);
        
        $options = [];
        $field_required = false;
        $description = '';
        
        if ($is_destination_unavailable) {
            // No destinations available
            $options = ['' => __('No destinations available', 'flourish-woocommerce')];
            $field_required = false;
            $description = __('Please contact support to set up destinations first.', 'flourish-woocommerce');
            
        
            $options = ['' => __('No destinations available', 'flourish-woocommerce')];
            $field_required = false;
            $description = __('Please contact support to set up destinations first.', 'flourish-woocommerce');
            
        } elseif ($all_destination === 'yes' && $all_sales_rep === 'yes') {
            // User has all destinations AND all sales reps - load assigned sales reps
            $sales_rep_data_raw = get_user_meta($user_id, 'assigned_sales_rep_data', true);
            $sales_rep_data = maybe_unserialize($sales_rep_data_raw);
            if (!empty($sales_rep_data) && is_array($sales_rep_data)) {
                $options = ['' => __('Select a sales representative', 'flourish-woocommerce')];
                
                // Ensure all keys are strings for dropdown compatibility
                foreach ($sales_rep_data as $rep_id => $rep_name) {
                    $string_key = (string)$rep_id; // Convert to string
                    $options[$string_key] = $rep_name;
                }
                
                $description = sprintf(__('You have %d assigned sales representatives available.', 'flourish-woocommerce'), count($sales_rep_data));
                error_log("Processed options for dropdown: " . print_r($options, true));
            } 
            
        } elseif ($all_destination === 'yes' && $all_sales_rep !== 'yes') {
            // User has all destinations but destination-specific sales reps
            $options = ['' => __('Select destination first', 'flourish-woocommerce')];
            $description = __('Sales representatives will be loaded based on your destination selection.', 'flourish-woocommerce');
            
        } else {
            // User has specific destinations - sales reps will be loaded via AJAX
            $options = ['' => __('Select destination first', 'flourish-woocommerce')];
            $description = __('Please select a destination first to see available sales representatives.', 'flourish-woocommerce');
        }
        
        echo '<div id="sales_rep_field" class="sales-rep-checkout-field">';
        
        woocommerce_form_field('sales_rep_id', array(
            'type'        => 'select',
            'class'       => array('form-row-wide'),
            'label'       => __('Sales Rep', 'flourish-woocommerce'),
            'placeholder' => __('Select your sales rep', 'flourish-woocommerce'),
            'required'    => $field_required,
            'options'     => $options, 
        ), '');

        echo '</div>';
        
        // Add JavaScript data for dynamic loading
        ?>
        <script>
        if (typeof window.salesRepCheckoutData === 'undefined') {
            window.salesRepCheckoutData = {
                allDestination: '<?php echo esc_js($all_destination); ?>',
                allSalesRep: '<?php echo esc_js($all_sales_rep); ?>',
                userId: <?php echo intval($user_id); ?>,
                isPreloaded: <?php echo ($all_destination === 'yes' && $all_sales_rep === 'yes') ? 'true' : 'false'; ?>
            };
        }
        </script>
        <?php

    } catch (Exception $e) {
        error_log('Sales Rep Field Error: ' . $e->getMessage());
        
        // Fallback display
        echo '<div id="sales_rep_field" class="sales-rep-checkout-field">';
        woocommerce_form_field('sales_rep_id', array(
            'type'        => 'select',
            'class'       => array('form-row-wide'),
            'label'       => __('Sales Rep', 'flourish-woocommerce'),
            'required'    => false,
            'options'     => ['' => __('Error loading sales representatives', 'flourish-woocommerce')],
            'description' => __('Please contact support if this error persists.', 'flourish-woocommerce'),
        ), '');
        echo '</div>';
    }
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
    // Remove username line - no longer needed
    $url = $this->existing_settings['url'] ?? '';
    $facility_id = $this->existing_settings['facility_id'] ?? '';

    // Updated constructor call - removed username parameter
    $flourish_api = new FlourishAPI($api_key, $url, $facility_id);

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
        // Remove username line - no longer needed
        $url = $this->existing_settings['url'] ?? '';
        $facility_id = $this->existing_settings['facility_id'] ?? '';

        // Updated constructor call - removed username parameter
        $flourish_api = new FlourishAPI($api_key, $url, $facility_id);
        try {
            $destination_options = $flourish_api->fetch_destination_by_destination_id($_POST['destination']);
            if (isset($destination_options['name'])) {
                update_post_meta($order_id, '_destination_text', $destination_options['name']);
            }
        } catch (\Exception $e) {
            error_log('Error saving destination text: ' . $e->getMessage());
        }
    }
    // Save sales rep
    if (!empty($_POST['sales_rep_id'])) {
        $sales_rep_id = sanitize_text_field($_POST['sales_rep_id']);
        update_post_meta($order_id, '_sales_rep_id', $sales_rep_id);
        
        // Get and save sales rep name
        try {
            $sales_rep_name = $this->get_sales_rep_name_by_id($sales_rep_id);
            if ($sales_rep_name) {
                update_post_meta($order_id, '_sales_rep_name', $sales_rep_name);
            }
        } catch (Exception $e) {
            error_log('Error saving sales rep name: ' . $e->getMessage());
        }
    }
    // Save shipping phone
    if (!empty($_POST['shipping_phone'])) {
        update_post_meta($order_id, '_shipping_phone', sanitize_text_field($_POST['shipping_phone']));
    }
}
    /**
     * Get sales rep name by ID
     */
    private function get_sales_rep_name_by_id($sales_rep_id) {
        try {
            $all_sales_reps = $this->get_all_sales_reps_formatted();
            return isset($all_sales_reps[$sales_rep_id]) ? $all_sales_reps[$sales_rep_id] : null;
        } catch (Exception $e) {
            error_log('Error getting sales rep name: ' . $e->getMessage());
            return null;
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
    $all_destination = get_user_meta($user_id, 'all_destination', true);
    
    if ($all_destination == "no") {
        // First check if user has saved destinations
        $existing_destination_ids = get_user_meta($user_id, 'destination_ids', true);
        $existing_destination_texts = get_user_meta($user_id, 'destination_texts', true); 
        
        // If user has saved destinations, use those
        if (!empty($existing_destination_ids) && !empty($existing_destination_texts)) { 
            $ids = maybe_unserialize($existing_destination_ids);
            $texts = maybe_unserialize($existing_destination_texts);
            
            if (is_array($ids) && is_array($texts) && count($ids) === count($texts)) {
                $destination_options = [];
                for ($i = 0; $i < count($ids); $i++) {
                    $destination_options[$ids[$i]] = $texts[$i];
                }
                return $destination_options;
            }
        }
    } elseif ($all_destination == "yes") { 
        $api_key = $this->existing_settings['api_key'] ?? '';
        // Remove username line - no longer needed
        $url = $this->existing_settings['url'] ?? '';
        $facility_id = $this->existing_settings['facility_id'] ?? ''; 
        
        // Updated constructor call - removed username parameter
        $flourish_api = new FlourishAPI($api_key, $url, $facility_id); 
        
        // Fetch destinations from API
        $destination_options = $flourish_api->get_destination_options();
        return $destination_options; 
    }
    
    return []; // Return empty array if no destinations found
}
 
     
}     
 