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
    }
    
    public function display_shipping_phone_license_in_admin( $order )
    { 
        $shipping_phone = get_post_meta( $order->get_id(), '_shipping_phone', true );
        $destination_text = get_post_meta( $order->get_id(), '_destination_text', true );
        $license = get_post_meta( $order->get_id(), 'license', true );
       

    
        if ( $shipping_phone ) {
            echo '<p><strong>' . __( 'Shipping Phone:', 'woocommerce' ) . '</strong> ' . esc_html( $shipping_phone ) . '</p>';
        }
        if ( $destination_text ) {
            echo '<p><strong>' . __( 'Destination:', 'woocommerce' ) . '</strong> ' . esc_html( $destination_text ) . '</p>';
        }
        if ( $license ) {
            echo '<p><strong>' . __( 'License Number:', 'woocommerce' ) . '</strong> ' . esc_html( $license ) . '</p>';
        }
         
   }


    /**
     * Toggle on for all Destinations or specific destinations  
     */

   public function update_destination_selection_toggle_callback() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'license_management_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
       // return;
    }
    // Sanitize and validate
    $user_id = intval($_POST['user_id']);
    // Sanitize and validate
    $all_destination =$_POST['all_destination'];
    // Update user meta
    // Store both as serialized arrays in the database 
    $facility_id = $this->existing_settings['facility_id'] ?? ''; 
     // Update user meta 
    update_user_meta($user_id, 'facility_id', $facility_id); 
   update_user_meta($user_id, 'all_destination', $all_destination);  
   wp_send_json_success([
        'message' => 'Destination settings successfully', 
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
    
    $existing_destination_ids = [];
    $destination_texts_array = [];

    // Get API credentials - adjust this to match your application structure
        $api_key = $this->existing_settings['api_key'] ?? '';
        $username = $this->existing_settings['username'] ?? '';
        $url = $this->existing_settings['url'] ?? '';
        $facility_id = $this->existing_settings['facility_id'] ?? '';

        // Initialize API
        $flourish_api = new FlourishAPI($username, $api_key, $url, $facility_id);  
    
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
        'destination_ids' => $existing_destination_ids,
        'destination_texts' => $destination_texts_array,
    ]);
}
 

    /**
 * Add destination management fields to user edit screen
 * 
 * @param WP_User $user The user object being edited
 */
  function add_license_field_to_user_edit($user) { 
    // Fetch existing destinations from user meta
$existing_destination_texts = maybe_unserialize(get_user_meta($user->ID, 'destination_texts', true));
$existing_destination_ids = maybe_unserialize(get_user_meta($user->ID, 'destination_ids', true));

$existing_destination_texts = is_array($existing_destination_texts) ? $existing_destination_texts : [];
$existing_destination_ids = is_array($existing_destination_ids) ? $existing_destination_ids : [];
$all_destination =get_user_meta($user->ID, 'all_destination', true);
    
    
        // Get API credentials - adjust this to match your application structure
        $api_key = $this->existing_settings['api_key'] ?? '';
        $username = $this->existing_settings['username'] ?? '';
        $url = $this->existing_settings['url'] ?? '';
        $facility_id = $this->existing_settings['facility_id'] ?? '';

        // Initialize API
        $flourish_api = new FlourishAPI($username, $api_key, $url, $facility_id); 
        $destination_options = $flourish_api->get_destination_options();        
        // Create an array of formatted license options
         
    
    ?>
    <h3><?php esc_html_e('Destination Management', 'woocommerce'); ?></h3>
<table class="form-table"> <tr><th>  Destination Settings</th>
<td>  
    <div class="destination-management-wrapper">
        <div class="toggle-container">
                <button type="button" id="specific_destinations_toggle" class="button <?php if($all_destination=="no")
         { echo "active"; } ?>">
         <?php if($all_destination=="no")
                    {
                    esc_html_e('Specific Destinations Assigned', 'woocommerce');
                    }
                    else
                    {
                       esc_html_e('Choose Specific Destinations', 'woocommerce'); 
                    } ?>
                                    </button>
                 <button type="button" id="all_destinations_toggle" class="button <?php if($all_destination=="yes")
         { echo "active"; } ?>"> 
                    <?php if($all_destination=="yes")
                    {
                    esc_html_e('All Destinations Assigned', 'woocommerce');
                    }
                    else
                    {
                       esc_html_e('All Destinations', 'woocommerce'); 
                    } ?>
                </button> 

               
            </div> 
           <p class="destination-info-note">
    <?php 
// A more secure approach that allows HTML formatting for "Note:" only
echo sprintf(
    '<strong>%1$s</strong> %2$s',
    esc_html__('Note:', 'woocommerce'),
    esc_html__('Please click "Specific Destinations" to assign specific shipping locations for the user. Click "All Destinations" to assign all shipping destinations in the user.', 'woocommerce')
); 
?>
</p>
    </div> 
</td></tr></table>
 
    <table class="form-table <?php if ($all_destination=="" || $all_destination=="yes") { echo "hidden-section" ;} ?>" id="destination_section" >  
        
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
                        <?php if (empty($existing_destination_texts) ) : ?>
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

                <!-- Hidden field to store selected destination IDs -->
                <input type="hidden" name="destination_ids" id="destination_ids" 
                       value="<?php echo esc_attr(implode(',', $existing_destination_ids)); ?>" />
                
                <!-- Hidden field to store selected destination texts -->
                <input type="hidden" name="destination_texts" id="destination_texts" 
                       value="<?php echo esc_attr(implode('||', $existing_destination_texts)); ?>" />
                
 
            <div class="destination-actions" style="margin-top: 10px; ">
                    <button type="button" id="add-destination" class="button button-primary" 
                            <?php echo empty($existing_destination_texts) ? 'disabled' : ''; ?>>
                        <?php esc_html_e('Assign/Remove Selected', 'woocommerce'); ?>
                    </button>
                   
                     
                </div>
           
            </td>
        </tr>
       
    </table> 
    
    
    
    <?php
}
}       