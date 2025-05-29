<?php
 
namespace FlourishWooCommercePlugin\CustomFields;
use WP_REST_Response;
defined( 'ABSPATH' ) || exit;
 
class DateOfBirth
{
    public function register_hooks()
    {
        // Needed to handle DOB in various locations
        add_action('woocommerce_register_form', [$this, 'add_dob_field_to_registration_form']);
        add_action('woocommerce_checkout_process', [$this, 'validate_dob_field'], 10, 3);
        add_action('woocommerce_created_customer', [$this, 'save_dob_field']);
        add_action('woocommerce_edit_account_form', [$this, 'add_dob_field_to_edit_account_form']);
        add_action('woocommerce_save_account_details', [$this, 'save_dob_field']);
        
        // Corrected to use $this->enqueue_custom_checkout_fields
        add_action('enqueue_block_assets', [$this, 'enqueue_custom_checkout_fields']);
       
        add_filter('woocommerce_checkout_fields', [$this, 'add_dob_field_to_checkout_form']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_dob_field']);
        add_action('show_user_profile', [$this, 'add_dob_field_to_user_edit']);
        add_action('edit_user_profile', [$this, 'add_dob_field_to_user_edit']);
        add_action('personal_options_update', [$this, 'save_dob_field_on_user_update']);
        add_action('edit_user_profile_update', [$this, 'save_dob_field_on_user_update']);
        
        // Register REST API endpoint for saving DOB
        add_action('rest_api_init', [$this, 'register_get_dob_endpoint']);
        add_action('rest_api_init', [$this, 'register_rest_api_endpoint']);
       
   }
   
   public function register_get_dob_endpoint() {
       register_rest_route('custom-endpoint', '/get-dob', [
           'methods' => 'GET',
           'callback' => [$this, 'get_dob_via_rest'],
           'permission_callback' => '__return_true',
           'args' => [
               'email' => [
                   'required' => true,
                   'validate_callback' => function ($param, $request, $key) {
                       return is_email($param); // Validate email format
                   }
               ]
           ]
       ]);
   }
   public function get_dob_via_rest(\WP_REST_Request $request) {
 
       $email = sanitize_email($request->get_param('email'));
   
       if ($email) {
           $option_key = 'guest_dob_' . sanitize_title($email);
           $dateOfBirth = get_option($option_key);
   
           if ($dateOfBirth) {
               return new WP_REST_Response([
                   'status' => 'success',
                   'dob'    => $dateOfBirth,
               ], 200);
           }
   
           return new WP_REST_Response([
               'status'  => 'error',
               'message' => 'No DOB found for the given email',
           ], 404);
       }
   
   }
   // Register REST API endpoint for saving DOB
   public function register_rest_api_endpoint()
   {        register_rest_route('custom-endpoint', '/save-dob', [
           'methods' => 'POST',
           'callback' => [$this, 'save_dob_via_rest'],
           'permission_callback' => '__return_true', // Adjust as needed
       ]);
   }
 
   // Save DOB via REST API for guests
   public function save_dob_via_rest(\WP_REST_Request $request) // Corrected type hint
   {
       $dateOfBirth = sanitize_text_field($request->get_param('dob'));
       $email = sanitize_email($request->get_param('email'));
 
       if ($dateOfBirth) {
           // Save the DOB as user meta
               // If the order is not found, you can save it temporarily using options or handle it another way
               $option_key = 'guest_dob_' . sanitize_title($email);
               update_option($option_key, $dateOfBirth);
   
               return new WP_REST_Response([
                   'status' => 'success',
                   'message' => 'DOB saved successfully as a temporary option for guest',
               ], 200);
       }
       return true;
 
   }
    public function enqueue_custom_checkout_fields()
    {
        // Ensure it only loads on WooCommerce checkout pages
        if (function_exists('is_checkout') && is_checkout()) {
            wp_enqueue_script(
                'custom-checkout-fields',
                plugin_dir_url(__DIR__) . '../assets/js/custom-checkout-dob.js', // Adjusted path
                array('jquery', 'wp-hooks'),
                '1.0.0',
                true
            );
            wp_enqueue_style(
                'custom-checkout-fields-style',
                plugin_dir_url(__DIR__) . '../assets/css/style.css', // Adjusted path
                array(),
                '1.0.0'
            );
        }
        wp_localize_script(
            'custom-checkout-fields',
            'dobData',
            [
                'getApiUrl' => esc_url_raw(rest_url('custom-endpoint/get-dob')),
                'postApiUrl' => esc_url_raw(rest_url('custom-endpoint/save-dob')),
            ]
        );
    }
   
    // Add DOB field to WooCommerce registration form
    public function add_dob_field_to_registration_form()
    {
        ?>
        <p class="form-row form-row-wide">
            <label for="dob"><?php _e('Date of Birth', 'woocommerce'); ?> <span class="required">*</span></label>
            <input type="date" class="input-text" name="dob" id="dob" value="<?php if (!empty($_POST['dob'])) echo esc_attr($_POST['dob']); ?>" />
        </p>
        <?php
    }

    // Validate DOB field during registration
    public function validate_dob_field($username, $email, $validation_errors)
    {
        if (empty($_POST['dob'])) {
            $validation_errors->add('dob_error', __('Date of Birth is required.', 'woocommerce'));
        }
        return $validation_errors;
    }

    // Save DOB field to user meta after registration
    public function save_dob_field($customer_id)
    {
        if (isset($_POST['dob'])) {
            update_user_meta($customer_id, 'dob', sanitize_text_field($_POST['dob']));
        }
    }

    // Add DOB field to WooCommerce edit account form
    public function add_dob_field_to_edit_account_form()
    {
        $user_id = get_current_user_id();
        $dateOfBirth = get_user_meta($user_id, 'dob', true);
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="dob"><?php _e('Date of Birth', 'woocommerce'); ?> <span class="required">*</span></label>
            <input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="dob" id="dob" value="<?php echo esc_attr($dateOfBirth); ?>" />
        </p>
        <?php
    }
    
    // Add DOB field to WooCommerce checkout form
    public function add_dob_field_to_checkout_form($fields)
    {
        $fields['billing']['dob'] = array(
            'label' => __('Date of Birth', 'woocommerce'),
            'placeholder' => _x('Date of Birth', 'placeholder', 'woocommerce'),
            'required' => true,
            'class' => array('form-row-wide'),
            'clear' => true,
            'type' => 'date',
        );
    
        return $fields;
    }

    // Add DOB field to user edit profile page
    public function add_dob_field_to_user_edit($user)
    {
        $dateOfBirth = get_user_meta($user->ID, 'dob', true);
        ?>
        <h3><?php _e('Date of Birth', 'woocommerce'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="dob"><?php _e('Date of Birth', 'woocommerce'); ?></label></th>
                <td>
                    <input type="date" name="dob" id="dob" value="<?php echo esc_attr($dateOfBirth); ?>" class="regular-text" />
                </td>
            </tr>
        </table>
        <?php
    }

    // Save DOB field when user profile is updated
    function save_dob_field_on_user_update($user_id)
    {
        if (current_user_can('edit_user', $user_id)) {
            update_user_meta($user_id, 'dob', sanitize_text_field($_POST['dob']));
        }
    }
}
 
 