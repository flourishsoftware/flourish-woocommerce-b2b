<?php
namespace FlourishWooCommercePlugin\Admin;
defined( 'ABSPATH' ) || exit;
use FlourishWooCommercePlugin\API\FlourishAPI;

class WoocommerceOutboundSettings
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
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_shipping_phone_license_in_admin']);
        // Hook to add license fields in the user profile
        add_action( 'show_user_profile', [ $this, 'add_license_field_to_user_edit' ] );
        add_action( 'edit_user_profile', [ $this, 'add_license_field_to_user_edit' ] ); 
        add_action('wp_ajax_update_user_destinations', [$this, 'update_user_destinations_callback']); 
        add_action('wp_ajax_update_destination_selection_toggle', [$this, 'update_destination_selection_toggle_callback']); 
        // Add this AJAX hook to your register_hooks() method:
        add_action('wp_ajax_toggle_sales_rep_management', [$this, 'toggle_sales_rep_management_callback']);
        // Sales Rep Management AJAX hooks
        add_action('wp_ajax_update_user_sales_reps', [$this, 'update_user_sales_reps_callback']);
        add_action('wp_ajax_update_sales_rep_selection_toggle', [$this, 'update_sales_rep_selection_toggle_callback']);
        add_action('wp_ajax_assign_destination_sales_reps', [$this, 'assign_destination_sales_reps_callback']);
        
        // Enqueue scripts for admin user edit pages
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }
    /**
 * Toggle sales rep management visibility
 */
public function toggle_sales_rep_management_callback() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
    }
    
    $user_id = intval($_POST['user_id']);
    $show_sales_rep = sanitize_text_field($_POST['show_sales_rep']);
    
    if (!$user_id) {
        wp_send_json_error(['message' => 'Invalid user']);
        return;
    }
    
    // Save the toggle state
    update_user_meta($user_id, 'show_sales_rep_management', $show_sales_rep); 
    
    wp_send_json_success([
        'message' => 'Sales rep management toggle updated successfully',
        'show_sales_rep' => $show_sales_rep,
    ]);
}
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on user edit pages
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') {
            return;
        }
        
              
        wp_localize_script('jquery', 'licenseData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('license_management_nonce')
        ]);
    }
    
    public function display_shipping_phone_license_in_admin( $order )
    { 
        $shipping_phone = get_post_meta( $order->get_id(), '_shipping_phone', true );
        $destination_text = get_post_meta( $order->get_id(), '_destination_text', true );
        $license = get_post_meta( $order->get_id(), 'license', true );
        $sales_rep_name = get_post_meta( $order->get_id(), '_sales_rep_name', true );

        if ( $shipping_phone ) {
            echo '<p><strong>' . __( 'Shipping Phone:', 'woocommerce' ) . '</strong> ' . esc_html( $shipping_phone ) . '</p>';
        }
        if ( $destination_text ) {
            echo '<p><strong>' . __( 'Destination:', 'woocommerce' ) . '</strong> ' . esc_html( $destination_text ) . '</p>';
        }
        if ( $license ) {
            echo '<p><strong>' . __( 'License Number:', 'woocommerce' ) . '</strong> ' . esc_html( $license ) . '</p>';
        }
        // Display sales rep information
        if ( $sales_rep_name ) {
        echo '<p><strong>' . __( 'Sales Rep:', 'woocommerce' ) . '</strong> ' . esc_html( $sales_rep_name ).'</p>';
        }
    }

    /**
     * Toggle for all Sales Reps or destination-specific sales reps
     */
    public function update_sales_rep_selection_toggle_callback() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        $user_id = intval($_POST['user_id']); 
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'Invalid user']);
            return;
        }
       update_user_meta($user_id, 'all_sales_rep', 'yes');
        
        wp_send_json_success([
            'message' => 'Sales rep settings updated successfully',
        ]);
    }

    /**
     * Assign sales reps to specific destinations
     */
    public function assign_destination_sales_reps_callback() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        $user_id = intval($_POST['user_id']);
        $destination_sales_reps = $_POST['destination_sales_reps'];
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'Invalid user']);
            return;
        }
        
        // Sanitize the nested array properly
        $sanitized_destination_sales_reps = [];
        if (is_array($destination_sales_reps)) {
            foreach ($destination_sales_reps as $destination_id => $sales_rep_ids) {
                $sanitized_destination_sales_reps[sanitize_text_field($destination_id)] = array_map('sanitize_text_field', (array)$sales_rep_ids);
            }
        }
        
        // Save destination-specific sales rep assignments
        update_user_meta($user_id, 'destination_sales_reps', serialize($sanitized_destination_sales_reps));
        update_user_meta($user_id, 'all_sales_rep', 'no');
        
        wp_send_json_success([
            'message' => 'Destination sales reps assigned successfully',
            'destination_sales_reps' => $sanitized_destination_sales_reps,
        ]);
    }

    /**
     * Update all sales reps for user
     */
    /**
 * Updated assign all sales reps with checkbox data
 */
public function update_user_sales_reps_callback() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
    }
    
    $user_id = intval($_POST['user_id']);
    $selected_sales_reps = isset($_POST['selected_sales_reps']) ? $_POST['selected_sales_reps'] : [];
    
    if (!$user_id) {
        wp_send_json_error(['message' => 'Invalid user']);
        return;
    }
    
    // Get all sales reps from API
    $sales_reps = $this->get_sales_reps();
    $sales_rep_data = [];
    
    if (!empty($selected_sales_reps) && is_array($selected_sales_reps)) {
        // Process selected sales reps
        foreach ($sales_reps as $rep) {
            $rep_id = isset($rep['sales_rep_id']) ? $rep['sales_rep_id'] : '';
            
            if (in_array($rep_id, $selected_sales_reps)) {
                $rep_name = (isset($rep['first_name'], $rep['last_name']) ? 
                    $rep['first_name'] . ' ' . $rep['last_name'] : 
                    'Sales Rep');
                $rep_name = trim($rep_name);
                
                if ($rep_id && $rep_name) {
                    $sales_rep_data[$rep_id] = $rep_name;
                }
            }
        }
        
        // Save the selected sales rep data (ID => Name format)
        update_user_meta($user_id, 'assigned_sales_rep_data', serialize($sales_rep_data));
        update_user_meta($user_id, 'all_sales_rep', 'yes');
        update_user_meta($user_id, 'destination_sales_reps', '');
        
        wp_send_json_success([
            'message' => 'Selected sales reps assigned successfully',
            'sales_reps_count' => count($sales_rep_data),
            'assigned_sales_reps' => $sales_rep_data
        ]);
    } else {
        wp_send_json_error(['message' => 'No sales representatives selected']);
    }
}

    /**
     * Toggle on for all Destinations or specific destinations  
     */
    public function update_destination_selection_toggle_callback() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        // Sanitize and validate
        $user_id = intval($_POST['user_id']);
        $all_destination = sanitize_text_field($_POST['all_destination']);
        
        if ($all_destination=="yes")
        {
        update_user_meta($user_id, 'destination_ids', '');
        update_user_meta($user_id, 'destination_texts', ''); 
        update_user_meta($user_id, 'all_destination', 'yes');
         // Save destination-specific sales rep assignments
        update_user_meta($user_id, 'destination_sales_reps', ''); 
        
        }
        else
        {
        update_user_meta($user_id, 'all_destination', 'no'); 
        
        }
        wp_send_json_success([
            'message' => 'Destination settings updated successfully', 
            'all_destination'=> $all_destination, 
        ]);
    }

    /**
     * Add/Update Destinations on user update.
     */
    public function update_user_destinations_callback() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    // Sanitize and validate
    $user_id = intval($_POST['user_id']);
    $selected_destination_ids = array_map('sanitize_text_field', (array)$_POST['destinations']);
    
    if (!$user_id) {
        wp_send_json_error(['message' => 'Invalid user']);
        return;
    }
    
    $destination_texts_array = [];

    // Get API credentials - UPDATED: Removed username
    $api_key = $this->existing_settings['api_key'] ?? '';
    $url = $this->existing_settings['url'] ?? '';
    $facility_id = $this->existing_settings['facility_id'] ?? '';

    try {
        // Initialize API - UPDATED: New constructor without username
        $flourish_api = new FlourishAPI($api_key, $url, $facility_id);  
        $destinations = $flourish_api->fetch_destination_by_facility_name(); 

        if ($destinations && is_array($destinations)) {
            foreach ($selected_destination_ids as $id) {
                foreach ($destinations as $destination) {
                    if ($destination['id'] == $id) {
                        // Check if alias is empty, use name as fallback
                        $display_name = !empty($destination['alias']) ? $destination['alias'] : $destination['name'];
                        $license_number = !empty($destination['license_number']) ? $destination['license_number'] : 'No License';
                        $destination_texts_array[] =  $display_name. ' (' . $license_number . ')';
                        break;
                    }
                }
            }
        }

        update_user_meta($user_id, 'destination_ids', serialize($selected_destination_ids));
        update_user_meta($user_id, 'destination_texts', serialize($destination_texts_array)); 
        update_user_meta($user_id, 'all_destination', 'no');   
        
        wp_send_json_success([
            'message' => 'Destinations updated successfully',
            'destination_ids' => $selected_destination_ids,
            'destination_texts' => $destination_texts_array,
        ]);
    } catch (Exception $e) {
        error_log('Error updating destinations: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Error updating destinations: ' . $e->getMessage()]);
    }
}
 
        /**
     * Get sales reps from Flourish API - sorted alphabetically
     */
    private function get_sales_reps()
{
    // Check if we have API settings - UPDATED: Removed username
    $api_key = $this->existing_settings['api_key'] ?? '';
    $url = $this->existing_settings['url'] ?? '';
    $facility_id = $this->existing_settings['facility_id'] ?? ''; 
    
    try {
        // Initialize API - UPDATED: New constructor without username
        $flourish_api = new FlourishAPI($api_key, $url, $facility_id);
        $sales_reps = $flourish_api->fetch_sales_reps(); 
        
        // Sort sales reps alphabetically by name
        if (is_array($sales_reps)) {
            usort($sales_reps, function($a, $b) {
                $name_a = isset($a['first_name'], $a['last_name']) ? 
                    $a['first_name'] . ' ' . $a['last_name'] : 
                    ($a['name'] ?? 'Sales Rep');
                
                $name_b = isset($b['first_name'], $b['last_name']) ? 
                    $b['first_name'] . ' ' . $b['last_name'] : 
                    ($b['name'] ?? 'Sales Rep');
                
                return strcasecmp(trim($name_a), trim($name_b));
            });
        }
        
        return $sales_reps;
    } catch (Exception $e) {
        error_log('Error fetching sales reps: ' . $e->getMessage());
        return [];
    }
}

    /**
     * Add destination and sales rep management fields to user edit screen
     * 
     * @param WP_User $user The user object being edited
     */
    function add_license_field_to_user_edit($user) { 
    // Fetch existing destinations from user meta
    $existing_destination_texts = maybe_unserialize(get_user_meta($user->ID, 'destination_texts', true));
    $existing_destination_ids = maybe_unserialize(get_user_meta($user->ID, 'destination_ids', true));
    $existing_destination_texts = is_array($existing_destination_texts) ? $existing_destination_texts : [];
    $existing_destination_ids = is_array($existing_destination_ids) ? $existing_destination_ids : [];
    $all_destination = get_user_meta($user->ID, 'all_destination', true);
    $all_sales_rep= get_user_meta($user->ID, 'all_sales_rep', true);
    $destination_sales_reps = maybe_unserialize(get_user_meta($user->ID, 'destination_sales_reps', true));
    $destination_sales_reps = is_array($destination_sales_reps) ? $destination_sales_reps : [];
    
    // Get API credentials - UPDATED: Removed username
    $api_key = $this->existing_settings['api_key'] ?? '';
    $url = $this->existing_settings['url'] ?? '';
    $facility_id = $this->existing_settings['facility_id'] ?? '';

    // Initialize variables
    $destination_options = [];
    $sales_reps = [];
    
    try {
        // Initialize API - UPDATED: New constructor without username
        $flourish_api = new FlourishAPI($api_key, $url, $facility_id); 
        $destination_options = $flourish_api->get_destination_options();
        $sales_reps = $this->get_sales_reps();
    } catch (Exception $e) {
        error_log('Error loading data for user edit: ' . $e->getMessage());
        // Continue with empty arrays - the UI will show appropriate messages
    }
        
        ?>
       

        <!-- Destination Management Section -->
        <h3><?php esc_html_e('Destination Management', 'woocommerce'); ?></h3>
        <table class="form-table">
            <tr>
                <th>Destination Settings</th>
                <td>  
                    <div class="destination-management-wrapper">
                        <div class="toggle-container">
                            <button type="button" id="specific_destinations_toggle" class="button <?php if ($all_destination=="no") { echo "active"; } ?>">
                                <?php if($all_destination=="no") {
                                    esc_html_e('Specific Destinations Assigned', 'woocommerce');
                                } else {
                                    esc_html_e('Choose Specific Destinations', 'woocommerce'); 
                                } ?>
                            </button>
                            <button type="button" id="all_destinations_toggle" class="button <?php if($all_destination=="yes") { echo "active"; } ?>"> 
                                <?php if($all_destination=="yes") {
                                    esc_html_e('All Destinations Assigned', 'woocommerce');
                                } else {
                                    esc_html_e('All Destinations', 'woocommerce'); 
                                } ?>
                            </button> 
                        </div> 
                        <p class="destination-info-note">
                            <?php 
                            echo sprintf(
                                '<strong>%1$s</strong> %2$s',
                                esc_html__('Note:', 'woocommerce'),
                                esc_html__('Please click "Specific Destinations" to assign specific shipping locations for the user. Click "All Destinations" to assign all shipping destinations to the user.', 'woocommerce')
                            ); 
                            ?>
                        </p>
                    </div> 
                </td>
            </tr>
        </table>
        
        <table class="form-table <?php if ($all_destination=="" || $all_destination=="yes") { echo "hidden-section" ;} ?>" id="destination_section">  
            <!-- Manage Destinations -->
            <tr id="license_selection_row">
                <th><label for="destination_select"><?php esc_html_e('Manage Destinations', 'woocommerce'); ?></label></th>
                <td>
                    <div class="destination-select-wrapper">
                        <select name="destination_select" id="destination_select" class="enhanced-select" style="width: 100%;">
                            <option value=""><?php esc_html_e('Select destinations...', 'woocommerce'); ?></option>
                            <?php foreach ($destination_options as $id => $display_text) : ?>
                                <option value="<?php echo esc_attr($id); ?>" data-text="<?php echo esc_attr($display_text); ?>">
                                    <?php echo esc_html($display_text); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="description">
                        <?php 
                        if (empty($destination_options)) {
                            esc_html_e('No destinations available from API.', 'woocommerce');
                        } else {
                            esc_html_e('Type to search and select one or more destinations.', 'woocommerce');
                        }
                        ?>
                    </p>
                </td>
            </tr>
            
            <!-- Display Selected Destinations -->
            <tr>
                <th><label for="selected_destinations"><?php esc_html_e('Selected Destinations', 'woocommerce'); ?></label></th>
                <td>
                    <div id="selected_destinations_container" class="selected-items-container">
                        <div id="selected_destinations_display">
                            <?php if (empty($existing_destination_texts)) : ?>
                                <em class="no-selections"><?php esc_html_e('No destinations selected', 'woocommerce'); ?></em>
                            <?php else : ?>
                                <?php foreach ($existing_destination_texts as $index => $destination) : ?>
                                    <span class="selected-item" data-id="<?php echo esc_attr($existing_destination_ids[$index]); ?>">
                                        <span class="item-text"><?php echo esc_html($destination); ?></span>
                                        <a href="#" class="remove-item" title="<?php esc_attr_e('Remove', 'woocommerce'); ?>">×</a>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Hidden fields -->
                    <input type="hidden" name="destination_ids" id="destination_ids" 
                           value="<?php echo esc_attr(implode(',', $existing_destination_ids)); ?>" />
                    <input type="hidden" name="destination_texts" id="destination_texts" 
                           value="<?php echo esc_attr(implode('||', $existing_destination_texts)); ?>" />
                    
                    <div class="destination-actions">
                        <button type="button" id="add-destination" class="button button-primary" 
                                <?php echo empty($existing_destination_texts) ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Assign/Remove Selected', 'woocommerce'); ?>
                        </button>
                    </div>
                </td>
            </tr>
        </table> 
        
        <table class="form-table">
            <tr>
                <th>Enable Sales Rep Management</th>
                <td>
                    <?php $show_sales_rep = get_user_meta($user->ID, 'show_sales_rep_management', true); ?>
                    <div class="sales-rep-toggle-wrapper">
                        <label>
                            <input type="checkbox" id="sales_rep_toggle" 
                                <?php checked($show_sales_rep, 'yes'); ?> />
                            <?php esc_html_e('Enable Sales Representative Management', 'woocommerce'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Check this to show/hide sales representative management options.', 'woocommerce'); ?>
                        </p>
                    </div>
                </td>
            </tr>
        </table>
        <!-- Sales Rep Management Section -->
          
<div id="sales_rep_management_section" style="<?php echo ($show_sales_rep !== 'yes') ? 'display: none;' : ''; ?>">

        <h3><?php esc_html_e('Sales Rep Management', 'woocommerce'); ?></h3>
        
        <table class="form-table">
            <tr>
                <th>Sales Rep Settings</th>
                <td>  
                    <div class="sales-rep-management-wrapper">
                        <div class="toggle-container">
                            <?php  if ($all_destination=="" || $all_destination=="no")
                            {?>
                            <button type="button" id="sales_rep_by_destination_toggle" class="button <?php if ( ($all_sales_rep=="no") ){ echo "active"; } ?>">
                                <?php if($all_sales_rep=="no") {
                                    esc_html_e('Sales Rep Assigned by Destination', 'woocommerce');
                                } else {
                                    esc_html_e('Assign Sales Rep by Destination', 'woocommerce'); 
                                }
                             } ?>
                            </button>
                             <?php  if ($all_destination=="yes")
                            {
                                ?> 
                            
                            <button type="button" id="all_sales_rep_toggle" class="button <?php if($all_sales_rep=="yes") { echo "active"; } ?>"> 
                                <?php if($all_sales_rep=="yes") {
                                    esc_html_e('All Sales Rep Assigned', 'woocommerce');
                                } else { 
                                    esc_html_e('All Sales Rep', 'woocommerce'); 
                                } ?>
                            </button> 
                            <?php
                            }
                            ?>
                        </div> 
                        <p class="sales-rep-info-note">
                            <?php 
                            echo sprintf(
                                '<strong>%1$s</strong> %2$s',
                                esc_html__('Note:', 'woocommerce'),
                                esc_html__('Choose "Sales Rep Assigned by Destination" to assign specific sales representatives to each destination. Choose "All Sales Rep" to assign all available sales representatives to the user.', 'woocommerce')
                            ); 
                            ?>
                        </p>
                    </div> 
                </td>
            </tr>
        </table>

        <!-- Sales Rep by Destination Section -->
        <table class="form-table" id="sales_rep_by_destination_section" style="<?php echo ($all_sales_rep == "yes") ? 'display: none;' : ''; ?>">
            <tr>
                <th><label><?php esc_html_e('Assign Sales Rep by Destination', 'woocommerce'); ?></label></th>
                <td>
                    <?php if (!empty($existing_destination_texts) && !empty($sales_reps)) : ?>
                        <table class="destination-sales-rep-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Destination Name', 'woocommerce'); ?></th>
                                    <th><?php esc_html_e('Select Sales Rep', 'woocommerce'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($existing_destination_texts as $index => $destination_text) : 
                                    $destination_id = $existing_destination_ids[$index];
                                    $assigned_reps = isset($destination_sales_reps[$destination_id]) ? $destination_sales_reps[$destination_id] : [];
                                ?>
                                <tr class="destination-sales-rep-row" data-destination-id="<?php echo esc_attr($destination_id); ?>">
                            <td><?php echo esc_html($destination_text); ?></td>
                            <td>
                                <div class="custom-multiselect-wrapper">
                                    <!-- Multiselect Display Box -->
                                    <div class="multiselect-display" data-destination="<?php echo esc_attr($destination_id); ?>">
                                        <div class="selected-items">
                                            <?php 
                                            $selected_count = count($assigned_reps);
                                            if ($selected_count == 0) {
                                                echo '<span class="placeholder">' . esc_html__('Select sales representatives...', 'woocommerce') . '</span>';
                                            } else {
                                                echo '<span class="selected-count">' . sprintf(esc_html__('%d selected', 'woocommerce'), $selected_count) . '</span>';
                                            }
                                            ?>
                                        </div>
                                        <div class="dropdown-arrow">
                                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                                <path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </div>
                                    </div>

                                    <!-- Dropdown Options -->
                                    <div class="multiselect-dropdown" data-destination="<?php echo esc_attr($destination_id); ?>" style="display: none;">
                                        <div class="dropdown-header">
                                            <label class="select-all-option">
                                                <input type="checkbox" class="select-all-checkbox" data-destination="<?php echo esc_attr($destination_id); ?>">
                                                <span class="checkmark"></span>
                                                <span class="option-text"><?php esc_html_e('Select All', 'woocommerce'); ?></span>
                                            </label>
                                        </div>
                                        
                                        <div class="dropdown-options">
                                            <?php foreach ($sales_reps as $rep) :
                                                $rep_name = (isset($rep['first_name'], $rep['last_name']) ? 
                                                    $rep['first_name'] . ' ' . $rep['last_name'] : 
                                                    'Sales Rep');
                                                $rep_id = isset($rep['sales_rep_id']) ? $rep['sales_rep_id'] : '';
                                                $is_checked = in_array($rep_id, $assigned_reps);
                                            ?>
                                                <label class="multiselect-option">
                                                    <input type="checkbox" 
                                                           name="destination_sales_rep_<?php echo esc_attr($destination_id); ?>[]" 
                                                           value="<?php echo esc_attr($rep_id); ?>"
                                                           class="option-checkbox"
                                                           data-destination="<?php echo esc_attr($destination_id); ?>"
                                                           data-name="<?php echo esc_attr($rep_name); ?>"
                                                           <?php echo $is_checked ? 'checked' : ''; ?>>
                                                    <span class="checkmark"></span>
                                                    <span class="option-text"><?php echo esc_html($rep_name); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="sales-rep-actions">
                    <button type="button" id="assign_destination_sales_reps" class="button button-primary">
                        <?php esc_html_e('Assign Selected Sales Reps', 'woocommerce'); ?>
                    </button>
                </div>
                 <?php elseif (empty($existing_destination_texts)) : ?>
                <p class="description">
                    <?php esc_html_e('Please assign destinations first to manage sales representatives by destination.', 'woocommerce'); ?>
                </p>
            <?php elseif (empty($sales_reps)) : ?>
                <p class="description">
                    <?php esc_html_e('No sales representatives available from API.', 'woocommerce'); ?>
                </p>
            <?php endif; ?>
        </td>
    </tr>
</table>
                

        <!-- All Sales Rep Section -->
        <table class="form-table" id="all_sales_rep_section" style="<?php if (($all_sales_rep == "no") || ($all_destination == "no")) { echo 'display: none;'; } ?>">
        <tr>
            <th><label><?php esc_html_e('All Sales Representatives', 'woocommerce'); ?></label></th>
            <td>
                <?php if (!empty($sales_reps)) : 
                    $assigned_sales_rep_data = maybe_unserialize(get_user_meta($user->ID, 'assigned_sales_rep_data', true));
                    $assigned_sales_rep_data = is_array($assigned_sales_rep_data) ? $assigned_sales_rep_data : [];
                ?>
                    <div class="all-sales-rep-info">
                        <p><?php echo sprintf(esc_html__('Total available sales representatives: %d', 'woocommerce'), count($sales_reps)); ?></p>
                        
                        <div class="sales-rep-checkbox-list">
                            <h4><?php esc_html_e('Select Sales Representatives:', 'woocommerce'); ?></h4>
                            <div class="sales-rep-selection" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                                <div style="margin-bottom: 10px;">
                                    <label>
                                        <input type="checkbox" id="select_all_sales_reps" />
                                        <strong><?php esc_html_e('Select All', 'woocommerce'); ?></strong>
                                    </label>
                                </div>
                                <hr style="margin: 10px 0;">
                                
                                <div style="columns: 2; column-gap: 20px;">
                                    <?php foreach ($sales_reps as $rep) :
                                        $rep_name = (isset($rep['first_name'], $rep['last_name']) ? 
                                            $rep['first_name'] . ' ' . $rep['last_name'] : 
                                            'Sales Rep');
                                        $rep_id = isset($rep['sales_rep_id']) ? $rep['sales_rep_id'] : '';
                                        $is_checked = array_key_exists($rep_id, $assigned_sales_rep_data);
                                        
                                        if (!empty($rep_id)) : ?>
                                            <div style="break-inside: avoid; margin-bottom: 8px;">
                                                <label>
                                                    <input type="checkbox" 
                                                           name="sales_rep_checkbox[]" 
                                                           value="<?php echo esc_attr($rep_id); ?>"
                                                           <?php checked($is_checked); ?> />
                                                    <?php echo esc_html($rep_name); ?>
                                                </label>
                                            </div>
                                        <?php endif;
                                    endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="sales-rep-actions">
                            <button type="button" id="assign_all_sales_reps" class="button button-primary">
                                <?php esc_html_e('Assign Selected Sales Representatives', 'woocommerce'); ?>
                            </button>
                            <span id="selected_count" style="margin-left: 15px; font-style: italic;">
                                <?php echo count($assigned_sales_rep_data) . ' selected'; ?>
                            </span>
                        </div>
                    </div>
                <?php else : ?>
                    <p class="description">
                        <?php esc_html_e('No sales representatives available from API.', 'woocommerce'); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>
        
        <?php
    }
    
}