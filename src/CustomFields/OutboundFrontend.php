<?php

namespace FlourishWooCommercePlugin\CustomFields;

defined( 'ABSPATH' ) || exit;

class OutboundFrontend
{
    public function register_hooks()
    {
        // Hook to Add custom fields to the registration form
        add_action('woocommerce_register_form_start', [$this, 'custom_woocommerce_register_fields']);
        
        // Use a higher priority (lower number) to validate all fields before WooCommerce's default validation
        add_filter('woocommerce_process_registration_errors', [$this, 'validate_all_fields'], 5, 4);
        
        // Hook to save custom fields during registration and send admin notification
        add_action('woocommerce_created_customer', [$this, 'save_custom_fields_and_notify_admin']);
        
        // Hook to prevent login for unapproved users
        add_filter('wp_authenticate_user', [$this, 'check_user_approval'], 10, 2);
        
        // Add custom message for pending users
        add_filter('woocommerce_login_form', [$this, 'pending_approval_message']);
        
        // Modify registration confirmation message
        add_filter('woocommerce_registration_auth_new_customer', '__return_false');
		// For custom registration success message
		add_filter('woocommerce_registration_redirect', [$this, 'redirect_after_registration']);
		add_action('woocommerce_before_customer_login_form', [$this, 'display_custom_message']);
        add_filter('woocommerce_registration_error_email_exists', [$this, 'custom_registration_error']);
		// Admin column to show approval status
        add_filter('manage_users_columns', [$this, 'add_approval_column']);
        add_filter('manage_users_custom_column', [$this, 'show_approval_column_content'], 10, 3);

        // User profile field for approval status
        add_action('show_user_profile', [$this, 'add_approval_field']);
        add_action('edit_user_profile', [$this, 'add_approval_field']);

        // Save approval status changes
        add_action('personal_options_update', [$this, 'save_approval_field']);
        add_action('edit_user_profile_update', [$this, 'save_approval_field']); 
        
        // Make sure WooCommerce validation messages are displayed
        add_filter('woocommerce_register_form', [$this, 'add_form_validation_attributes']);

    }
    /**
     * Add HTML5 validation attributes to WooCommerce registration form
     */
    public function add_form_validation_attributes() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Add required attribute to email field
                $('#reg_email').attr('required', 'required');
                
                // Add required and maxlength attributes to password field
                $('#reg_password').attr('required', 'required').attr('maxlength', '15');
            });
        </script>
        <?php
    }
    public function custom_woocommerce_register_fields()
    {
        ?>
        <p class="form-row">
            <label for="first_name"><?php _e('First Name', 'woocommerce'); ?> <span class="required">*</span></label>
            <input type="text" class="input-text" name="first_name" id="first_name" value="<?php if (!empty($_POST['first_name'])) echo esc_attr($_POST['first_name']); ?>" />
        </p>

        <p class="form-row">
            <label for="last_name"><?php _e('Last Name', 'woocommerce'); ?> <span class="required">*</span></label>
            <input type="text" class="input-text" name="last_name" id="last_name" value="<?php if (!empty($_POST['last_name'])) echo esc_attr($_POST['last_name']); ?>" />
        </p>

        <p class="form-row">
            <label for="job_title"><?php _e('Job Title', 'woocommerce'); ?></label>
            <input type="text" class="input-text" name="job_title" id="job_title" value="<?php if (!empty($_POST['job_title'])) echo esc_attr($_POST['job_title']); ?>" />
        </p>

        <p class="form-row">
            <label for="company_name"><?php _e('Company Name', 'woocommerce'); ?></label>
            <input type="text" class="input-text" name="company_name" id="company_name" value="<?php if (!empty($_POST['company_name'])) echo esc_attr($_POST['company_name']); ?>" />
        </p>

        <p class="form-row">
            <label for="phone"><?php _e('Phone Number', 'woocommerce'); ?> <span class="required">*</span></label>
            <input type="tel" class="input-text" name="phone" id="phone" value="<?php if (!empty($_POST['phone'])) echo esc_attr($_POST['phone']); ?>" />
        </p>

        <p class="form-row">
            <label for="license"><?php _e('License Number(s)', 'woocommerce'); ?> <span class="required">*</span></label>
            <textarea name="license" id="license" rows="2" style="width: 100%;" placeholder="Enter your License Number(s)..."><?php if (!empty($_POST['license'])) echo esc_textarea($_POST['license']); ?></textarea>
			<b>Note: </b>You can enter multiple license numbers separated by commas. Example: ABC-12345,DEF-67890
If you don't have any license number, please type <b>no license number</b> in the box. 

        </p>
         <?php
    }

    /**
     * Validates all fields at once and collects all errors
     * 
     * @param WP_Error $errors
     * @param string $username
     * @param string $email
     * @param array $data
     * @return WP_Error
     */
    public function validate_all_fields($errors, $username, $email, $data = [])
    {
        // Create an array to hold all field validations
        $fields_to_validate = [
            'first_name' => [
                'required' => true,
                'regex' => "/^[a-zA-Z\s]+$/",
                'error_empty' => __('First name is required.', 'woocommerce'),
                'error_invalid' => __('First name should not contain special characters or numbers.', 'woocommerce')
            ],
            'last_name' => [
                'required' => true,
                'regex' => "/^[a-zA-Z\s]+$/",
                'error_empty' => __('Last name is required.', 'woocommerce'),
                'error_invalid' => __('Last name should not contain special characters or numbers.', 'woocommerce')
            ],
            'job_title' => [
                'required' => false,
                'regex' => "/^[a-zA-Z\s]+$/",
                'error_invalid' => __('Job title should not contain special characters or numbers.', 'woocommerce')
            ],
            'company_name' => [
                'required' => false,
                'regex' => "/^[a-zA-Z0-9\s]+$/",
                'error_invalid' => __('Company name should not contain special characters (only letters, numbers, and spaces).', 'woocommerce')
            ],
            'phone' => [
                'required' => true,
                'regex' => '/^\+?[0-9]{7,15}$/',
                'error_empty' => __('Phone number is required.', 'woocommerce'),
                'error_invalid' => __('Enter a valid phone number (only digits, optionally starting with +).', 'woocommerce')
            ],
            'license' => [
                'required' => true,
                'regex' => '/^([a-zA-Z0-9\-_.]+)(,\s*[a-zA-Z0-9\-_.]+)*$/',
                'error_empty' => __('License number is required.', 'woocommerce'),
                'error_invalid' => __('Enter a valid license number (Only letters, numbers, dashes (-), underscores (_), and periods (.) are allowed.).', 'woocommerce')
            ]
        ];

        // Validate email separately using PHP filter
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
           // $errors->add('email_invalid', __('Please enter a valid email address.', 'woocommerce'));
        }

        // Batch validate all fields
        foreach ($fields_to_validate as $field => $rules) {
            $value = isset($_POST[$field]) ? trim($_POST[$field]) : '';
            
            // Check if required field is empty
            if ($rules['required'] && empty($value)) {
                $errors->add($field . '_error', $rules['error_empty']);
            } 
            // Validate field format if not empty
            elseif (!empty($value) && isset($rules['regex']) && !preg_match($rules['regex'], $value)) {
                $errors->add($field . '_invalid', $rules['error_invalid']);
            }
        }

        return $errors;
    }

    /**
     * Save customer data and set as pending approval
     * Send notification email to admin
     */
    public function save_custom_fields_and_notify_admin($customer_id) 
    {


        // Save all custom fields to user meta
        $fields_to_save = ['first_name', 'last_name', 'job_title', 'company_name', 'phone', 'license'];
        
        foreach ($fields_to_save as $field) {
			if ($field === 'license') {
				$licenses = explode(',', $_POST[$field]); // Split by comma
                
				update_user_meta($customer_id, $field, array_map('trim', $licenses));
			} else {
				update_user_meta($customer_id, $field, sanitize_text_field($_POST[$field]));
			}
            
        }
        
        // Set user as pending approval
        update_user_meta($customer_id, 'account_status', 'pending');
        
        // Get user details for the email
        $user = get_user_by('id', $customer_id);
        $user_email = $user->user_email;
        $first_name = get_user_meta($customer_id, 'first_name', true);
        $last_name = get_user_meta($customer_id, 'last_name', true);
        $phone = get_user_meta($customer_id, 'phone', true);
		$licenses = get_user_meta($customer_id, 'license', true); 
		$licenses = !empty($licenses) && is_array($licenses) ? $licenses : []; // Ensure it's an array
		// Format the licenses array into a string
		$licenses_string = implode(', ', $licenses);
        $job_title = get_user_meta($customer_id, 'job_title', true);
        $company_name = get_user_meta($customer_id, 'company_name', true);
        
        // Admin email content
        $admin_email = get_option('admin_email');
        $subject = 'New Customer Registration Awaiting Approval';  
		$message = "Hi,\n";
        $message = "A new customer has signed up and is pending your approval.\n\n";
        $message .= "Name: $first_name $last_name\n";
        $message .= "Email: $user_email\n";
        $message .= "Phone: $phone\n";
        $message .= "License Number(s): $licenses_string\n";
        
        if (!empty($job_title)) {
            $message .= "Job Title: $job_title\n";
        }
        
        if (!empty($company_name)) {
            $message .= "Company: $company_name\n";
        }
        
                // Create direct link to edit the specific user
        $edit_user_url = admin_url("user-edit.php?user_id={$customer_id}");

        $message .= "\nTo approve , please go to: " . $edit_user_url . "\n";
        $message .= "If all good, Edit the user and change the 'Account Status' from 'pending' to 'approved' in the dropdown.";
        
        // Send email to admin
        wp_mail($admin_email, $subject, $message);
         
    }
    
    /**
     * Check if user is approved before allowing login
     */
    public function check_user_approval($user, $password) 
    {
        // Skip check for admins
        if (user_can($user, 'manage_options')) {
            return $user;
        }
        
        $account_status = get_user_meta($user->ID, 'account_status', true);
        
        if ($account_status === 'pending') {
            return new \WP_Error('account_pending', __('Your account is still pending approval. You will be notified by email when your account is approved.', 'woocommerce'));
        }
        
        return $user;
    }
    
    /**
     * Add notice to login form
     */
    public function pending_approval_message() 
    {
        if (isset($_GET['account_pending']) && $_GET['account_pending'] === '1') {
            wc_print_notice(__('Your account is still pending approval. You will be notified by email when your account is approved.', 'woocommerce'), 'notice');
        }
    }
    /**
     *   to check  email already exists
     * */
	public function custom_registration_error($error) 
    {
    return new WP_Error('registration-error-email-exists', __('This email address is already registered. Please login or use a different email address.', 'woocommerce'));
} 
    /**
     * Custom registration message
     */
    
public function redirect_after_registration($redirect) {
    // Set a session or cookie to indicate successful registration
    WC()->session->set('registration_success', true);
    return $redirect;
}

public function display_custom_message() {
    if (WC()->session && WC()->session->get('registration_success')) {
        wc_add_notice(__('Registration complete. Your account is pending approval. You will be notified by email when your account is approved.', 'woocommerce'), 'success');
        // Clear the flag after displaying
        WC()->session->set('registration_success', false);
    }
}
    
    /**
     * Add user admin column to show approval status
     */
    public function add_approval_column($columns) 
    {
        $columns['account_status'] = 'Account Status';
        return $columns;
    }
    
    /**
     * Show approval status in user admin column
     */
    public function show_approval_column_content($value, $column_name, $user_id) 
    {
        if ('account_status' === $column_name) {
            $status = get_user_meta($user_id, 'account_status', true);
            if (empty($status)) {
                return 'Approved';
            }
            return ucfirst($status);
        }
        return $value;
    }
    
    /**
     * Add user profile field for approval status
     */
    public function add_approval_field($user) 
    {
        // Skip for admins
        if (user_can($user, 'manage_options')) {
            return;
        }
        
        $status = get_user_meta($user->ID, 'account_status', true);
        if (empty($status)) {
            $status = 'approved';
        }
        ?>
        <h3><?php _e('Account Approval', 'woocommerce'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="account_status"><?php _e('Account Status', 'woocommerce'); ?></label></th>
                <td>
                    <select name="account_status" id="account_status">
                        <option value="pending" <?php selected($status, 'pending'); ?>><?php _e('Pending', 'woocommerce'); ?></option>
                        <option value="approved" <?php selected($status, 'approved'); ?>><?php _e('Approved', 'woocommerce'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }
    
   /**
 * Save approval status and notify user if changed
 */
public function save_approval_field($user_id) 
{
    // Skip for admins
    if (user_can($user_id, 'manage_options')) {
        return;
    }
    
    if (isset($_POST['account_status'])) {
        $old_status = get_user_meta($user_id, 'account_status', true);
        $new_status = sanitize_text_field($_POST['account_status']);
        
        update_user_meta($user_id, 'account_status', $new_status);
        
        // Send notification if status changed from pending to approved
        if ($old_status === 'pending' && $new_status === 'approved') {
            $user = get_userdata($user_id);
            $subject = 'Your Account Has Been Approved';
            $message = "Dear " . $user->first_name . ",\n\n";
            $message .= "Congratulations! Your account has been approved. You can now log in to our website.\n\n";
            
            // Get WooCommerce my-account page URL instead of wp-admin
            $login_url = wc_get_page_permalink('myaccount');
            
            $message .= "Login URL: " . $login_url . "\n\n";
            $message .= "Best regards,\n";
            $message .= get_bloginfo('name');
            
            wp_mail($user->user_email, $subject, $message);
        }
    }
}
}