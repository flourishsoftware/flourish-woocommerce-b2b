<?php
namespace FlourishWooCommercePlugin\Admin;
use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Importer\FlourishItems;

class ProductCustomFields
{

    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
        $this->register_hooks();
    }
    public function register_hooks()
    {
        // Custom fields
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_custom_fields']);
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'add_custom_fields_inventory']);       
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_fields']);
        
        // Stock status auto-update
        add_action('woocommerce_admin_process_product_object', [$this, 'auto_update_stock_status'], 25, 1);
        
        // Backorder warnings
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'add_backorder_warning_script']);
        add_action('wp_ajax_check_product_open_orders', [$this, 'ajax_check_product_open_orders']);
        

        // Min/max quantity validation
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_min_max_order_quantity'], 10, 3);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_min_max_order_quantity']);
    }

    public function add_custom_fields_inventory()
    {
        global $post;

        $fields = [
            '_held_stock' => [
                'label' => __('Held Stock', 'woocommerce'),
                'description' => __('Stock temporarily set aside when a customer adds a product to their cart.', 'woocommerce')
            ],
            '_reserved_stock' => [
                'label' => __('Reserved Stock', 'woocommerce'),
                'description' => __('Stock allocated for confirmed orders but not yet fulfilled.', 'woocommerce')
            ]
        ];
        
        echo '<div class="options_group">';
        foreach ($fields as $id => $field) {
            woocommerce_wp_text_input([
                'id' => $id,
                'label' => $field['label'],
                'description' => $field['description'],
                'type' => 'number',
                'value' => get_post_meta($post->ID, $id, true) ?: 0,
                'desc_tip' => true,
                'custom_attributes' => ['readonly' => 'readonly']
            ]);
        }
        echo '</div>'; 
    }

    public function add_custom_fields()
    {
        global $post;
        
        $wc_product = wc_get_product($post->ID);
        if (!$wc_product) return;
        
        $regular_price = get_post_meta($post->ID, '_price', true);
        
        $fields = [
            'unit_weight' => [
                'label' => __('Unit Weight', 'flourish-woocommerce'),
                'description' => __('Enter the weight of the unit', 'flourish-woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($post->ID, 'unit_weight', true),
            ],
            '_case_size_base_uom' => [
                'label' => __('Case Size in Base UOM', 'flourish-woocommerce'),
                'description' => __('Enter the number of base units in a case', 'flourish-woocommerce'),
                'type' => 'number',
                'value' => get_post_meta($post->ID, '_case_size_base_uom', true) ?: 1,
            ],
            'uom' => [
                'label' => __('Case Alias', 'flourish-woocommerce'),
                'description' => __('Enter an alias for the case (e.g., Box, Carton)', 'flourish-woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($post->ID, 'uom', true),
            ],
            'uom_description' => [
                'label' => __('Case Alias Description', 'flourish-woocommerce'),
                'description' => __('Enter a description for the case alias (e.g., Each, Gram)', 'flourish-woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($post->ID, 'uom_description', true),
            ],
            '_case_price' => [
                'label' => __('Case Price', 'flourish-woocommerce'),
                'description' => __('Enter the price for the case', 'flourish-woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($post->ID, '_case_price', true),
            ],
            '_min_order_quantity' => [
                'label' => __('Minimum Order Quantity', 'woocommerce'),
                'description' => __('Set the minimum quantity customers can order.', 'woocommerce'),
                'type' => 'number',
                'value' => get_post_meta($post->ID, '_min_order_quantity', true),
                'custom_attributes' => ['step' => '1', 'min' => '1'],
            ],
            '_max_order_quantity' => [
                'label' => __('Maximum Order Quantity', 'woocommerce'),
                'description' => __('Set the maximum quantity customers can order.', 'woocommerce'),
                'type' => 'number',
                'value' => get_post_meta($post->ID, '_max_order_quantity', true),
                'custom_attributes' => ['step' => '1', 'min' => '1'],
            ]
        ];

        echo '<div class="options_group">';
        foreach ($fields as $id => $field) {
            woocommerce_wp_text_input([
                'id' => $id,
                'label' => $field['label'],
                'description' => $field['description'],
                'type' => $field['type'],
                'value' => $field['value'],
                'desc_tip' => true,
                'custom_attributes' => $field['custom_attributes'] ?? []
            ]);
        }
        echo '</div>';
    }
         
    public function save_custom_fields($post_id)
    {
         $price = isset($_POST['price']);
        $fields = [
            'unit_weight',
            '_case_size_base_uom',
            'uom',
            'uom_description',
            '_case_price',
            '_min_order_quantity',
            '_max_order_quantity',
        ];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                if ($field === '_case_price') {
                    $price = sanitize_text_field($_POST['_case_price']);
                    $wc_product = wc_get_product($post_id);
                    $wc_product->set_price($price);
                    $wc_product->set_regular_price($price);
                    update_post_meta($wc_product->get_id(), '_case_price', $price);
                   
                } else {
                    update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                }
            }
        }
    }
    /**
     * Auto update stock status when product is saved
     */
    public function auto_update_stock_status($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) return;

        // Get form data
        $manage_stock = filter_var($_POST['_manage_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $stock_status = sanitize_text_field($_POST['_stock_status'] ?? 'instock');

        // Handle backorders update
        $backorders = '';
        if (isset($_POST['_backorders']) && in_array($_POST['_backorders'], ['yes', 'notify'])) {
            $backorders = sanitize_text_field($_POST['_backorders']);
            update_post_meta($product_id, '_backorders', $backorders);
        } else {
            delete_post_meta($product_id, '_backorders');
        }

        // Update product-level settings
        $product->set_manage_stock($manage_stock);

        // Only set backorders if value is not empty
        if (!empty($backorders)) {
            $product->set_backorders($backorders);
        }

        // Auto-set stock status if stock management is disabled
        if (!$manage_stock) {
            if ($backorders === 'yes' || $backorders === 'notify') {
                $product->set_stock_status('onbackorder');
            } else {
                $product->set_stock_status($stock_status);
            }
        }

        // ✅ Always save product to persist changes
        $product->save();

        // ✅ If variable product, apply same logic to each variation
        if ($product->is_type('variable')) {
            $variation_ids = $product->get_children();

            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                if (!empty($backorders)) {
                    $variation->set_backorders($backorders);
                }

                if ($backorders === 'yes' || $backorders === 'notify') {
                    $variation->set_stock_status('onbackorder');
                } else {
                    $variation->set_stock_status($stock_status);
                }

                $variation->save();
            }
        }

        // Clear transients and sync with Flourish
        wc_delete_product_transients($product->get_id());

        $item_id = get_post_meta($product_id, 'flourish_item_id', true);
        $flourish_api = new FlourishItems($product);
        return $flourish_api->create_attributes_update($product);

        //$this->save_variation_price($item_id);
    }

    /**
     * Add backorder warning JavaScript
     */
   public function add_backorder_warning_script()
{
    global $post;
    if (!$post) return;
    
    $product_id = 0;
    if ($post && $post->ID) {
        $product_id = $post->ID;
    } elseif (isset($_GET['post']) && is_numeric($_GET['post'])) {
        $product_id = intval($_GET['post']);
    } elseif (isset($_REQUEST['post_ID']) && is_numeric($_REQUEST['post_ID'])) {
        $product_id = intval($_REQUEST['post_ID']);
    }
    
    // Ensure we have a valid product ID
    if (!$product_id) {
        error_log('ProductCustomFields: Unable to determine product ID for backorder warnings');
        return;
    }
    
    // Verify it's actually a product
    $product = wc_get_product($product_id);
    if (!$product) {
        error_log('ProductCustomFields: Invalid product ID ' . $product_id . ' for backorder warnings');
        return;
    }
    
    $nonce = wp_create_nonce('check_orders_nonce');
    ?>
    <style>
    /* Custom Modal Styles */
    .backorder-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .backorder-modal {
        background: white;
        border-radius: 8px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        max-width: 500px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
        animation: modalSlideIn 0.3s ease-out;
    }
    
    @keyframes modalSlideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .backorder-modal-header {
        background: #dc3545;
        color: white;
        padding: 20px;
        border-radius: 8px 8px 0 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .backorder-modal-icon {
        font-size: 24px;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    
    .backorder-modal-title {
        font-size: 18px;
        font-weight: 600;
        margin: 0;
    }
    
    .backorder-modal-body {
        padding: 25px;
    }
    
    .backorder-warning-text {
        font-size: 16px;
        line-height: 1.5;
        margin-bottom: 20px;
        color: #333;
    }
    
    .backorder-order-count {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 6px;
        padding: 12px;
        margin: 15px 0;
        font-weight: 600;
        color: #856404;
    }
    
    .backorder-order-list {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        padding: 15px;
        margin: 15px 0;
        max-height: 200px;
        overflow-y: auto;
    }
    
    .backorder-order-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #e9ecef;
        font-family: monospace;
        font-size: 14px;
    }
    
    .backorder-order-item:last-child {
        border-bottom: none;
    }
    
    .backorder-order-id {
        font-weight: 600;
        color: #0073aa;
    }
    
    .backorder-order-status {
        background: #e7f3ff;
        color: #0066cc;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        text-transform: capitalize;
    }
    
    .backorder-modal-footer {
        padding: 20px 25px;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
    
    .backorder-btn {
        padding: 12px 24px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        min-width: 100px;
    }
    
    .backorder-btn-cancel {
        background: #6c757d;
        color: white;
    }
    
    .backorder-btn-cancel:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .backorder-btn-ok {
        background: #dc3545;
        color: white;
    }
    
    .backorder-btn-ok:hover {
        background: #c82333;
        transform: translateY(-1px);
    }
    </style>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var productId = <?php echo $product_id; ?>;
        var lastBackorderValue = $('input[name="_backorders"]:checked').val() || 'no';
        var lastManageStockValue = $('#_manage_stock').is(':checked');
        var lastStockStatusValue = $('input[name="_stock_status"]:checked').val() || 'instock';
        
        function showCustomModal(orderCount, orderDetails) {
            // Remove existing modal if any
            $('.backorder-modal-overlay').remove();
            
            // Create order list HTML
            var orderListHtml = '';
            if (orderDetails && orderDetails.length > 0) {
                orderDetails.forEach(function(order) {
                    orderListHtml += `
                        <div class="backorder-order-item">
                            <a href="<?php echo admin_url('post.php?post='); ?>${order.order_id}&action=edit" 
                               target="_blank" 
                               class="backorder-order-id" 
                               title="Click to open order details">
                               Order #${order.order_id}
                            </a> 
                        </div>
                    `;
                });
            }
            
            // Create modal HTML
            var modalHtml = `
                <div class="backorder-modal-overlay">
                    <div class="backorder-modal">
                        <div class="backorder-modal-header">
                            <span class="backorder-modal-icon">⚠️</span>
                            <h3 class="backorder-modal-title">Active Orders Warning</h3>
                        </div>
                        <div class="backorder-modal-body">
                            <div class="backorder-warning-text">
                                There are active orders for this product. Please update the backorder setting only after processing the orders listed below.
                            </div>
                            <div class="backorder-order-count">
                                📋 Found ${orderCount} open order(s)
                            </div>
                            <div class="backorder-order-list">
                                <strong>Order Details:</strong>
                                ${orderListHtml}
                            </div>
                        </div>
                        <div class="backorder-modal-footer">
                            <button class="backorder-btn backorder-btn-cancel" id="backorder-cancel">
                                Cancel
                            </button> 
                        </div>
                    </div>
                </div>
            `;
            
            // Add modal to page
            $('body').append(modalHtml);
            
            // Handle button clicks
            $('#backorder-cancel').on('click', function() {
                // Revert all changes
                revertChanges();
                closeModal();
            });
            
            $('#backorder-proceed').on('click', function() {
                // Allow the changes to stay
                $('#publish').prop('disabled', false);
                updateLastValues();
                closeModal();
            });
            
            // Close modal when clicking overlay
            $('.backorder-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    // Revert changes when clicking outside
                    revertChanges();
                    closeModal();
                }
            });
            
            // Handle escape key
            $(document).on('keydown.backorder-modal', function(e) {
                if (e.keyCode === 27) { // Escape key
                    revertChanges();
                    closeModal();
                }
            });
        }
        
        function closeModal() {
            $('.backorder-modal-overlay').fadeOut(200, function() {
                $(this).remove();
            });
            $(document).off('keydown.backorder-modal');
        }
        
        function revertChanges() {
            // Revert all form elements to their previous values
            $('#_manage_stock').prop('checked', lastManageStockValue);
            $('input[name="_stock_status"][value="' + lastStockStatusValue + '"]').prop('checked', true);
            $('input[name="_backorders"][value="' + lastBackorderValue + '"]').prop('checked', true);
            $('#publish').prop('disabled', false);
            
            // Trigger change events to update UI
            $('#_manage_stock').trigger('change');
            $('input[name="_stock_status"]:checked').trigger('change');
            $('input[name="_backorders"]:checked').trigger('change');
        }
        
        function updateLastValues() {
            // Update stored values to current selections
            lastBackorderValue = $('input[name="_backorders"]:checked').val() || 'no';
            lastManageStockValue = $('#_manage_stock').is(':checked');
            lastStockStatusValue = $('input[name="_stock_status"]:checked').val() || 'instock';
        }
        
        function checkOpenOrders(changeType, newValue) {
            // Disable publish button immediately
            $('#publish').prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'check_product_open_orders',
                    product_id: productId,
                    backorderValue: $('input[name="_backorders"]:checked').val() || 'no',
                    changeType: changeType,
                    newValue: newValue,
                    nonce: '<?php echo $nonce; ?>'
                },
                success: function(response) {
                    if (response.success && response.data.has_orders) {
                        var orderCount = response.data.order_count;
                        var orderDetails = response.data.order_details;
                        
                        // Show custom modal instead of browser confirm
                        showCustomModal(orderCount, orderDetails);
                    } else {
                        // No orders found, allow the change
                        $('#publish').prop('disabled', false);
                        updateLastValues();
                    }
                },
                error: function(xhr, status, error) {
                    $('#publish').prop('disabled', false);
                    console.log('Error checking open orders for product ' + productId + ':', error);
                    
                    // Show error modal
                    var errorModalHtml = `
                        <div class="backorder-modal-overlay">
                            <div class="backorder-modal">
                                <div class="backorder-modal-header" style="background: #dc3545;">
                                    <span class="backorder-modal-icon">❌</span>
                                    <h3 class="backorder-modal-title">Error</h3>
                                </div>
                                <div class="backorder-modal-body">
                                    <div class="backorder-warning-text">
                                        An error occurred while checking for open orders. Please try again.
                                    </div>
                                </div>
                                <div class="backorder-modal-footer">
                                    <button class="backorder-btn backorder-btn-cancel" onclick="$('.backorder-modal-overlay').remove();">
                                        Close
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    $('body').append(errorModalHtml);
                }
            });
        }
        
        // Monitor manage stock checkbox changes
        $('#_manage_stock').on('change', function() {
            var newValue = $(this).is(':checked');
            if (newValue !== lastManageStockValue) {
                checkOpenOrders('manage_stock', newValue);
            }
        });
        
        // Monitor backorder radio button changes
        $('input[name="_backorders"]').on('change', function() {
            var newValue = $(this).val();
            if (newValue !== lastBackorderValue) {
                checkOpenOrders('backorders', newValue);
            }
        });
        
        // Monitor stock status radio button changes
        $('input[name="_stock_status"]').on('change', function() {
            var newValue = $(this).val();
            if (newValue !== lastStockStatusValue) {
                checkOpenOrders('stock_status', newValue);
            }
        });
    });
    </script>
    <?php
}

    /**
     * AJAX handler for checking open orders
     */
    public function ajax_check_product_open_orders()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'check_orders_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $product_id = intval($_POST['product_id']);
        $changeType = sanitize_text_field($_POST['changeType']);
        $newValue = $_POST['newValue'];
        $backorderValue = sanitize_text_field($_POST['backorderValue']);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
        }
        
        $open_orders = $this->get_open_orders_for_product($product_id);
        
        $response = [
            'has_orders' => !empty($open_orders),
            'order_count' => count($open_orders),
            'orders' => $open_orders,
            'order_details' => [],
            'changeType' => $changeType,
            'newValue' => $newValue,
            'backorderValue' => $backorderValue
        ];
        
        if (!empty($open_orders)) {
           
            // Add detailed order information
            $response['order_details'] = array_map(function($order) {
                return [
                    'order_id' => $order['order_id']
                ];
            }, $open_orders);
        }
        
        wp_send_json_success($response);
    }

    /**
     * Get open orders for a product
     */
    /**
     * Get open orders for a product
     */
    private function get_open_orders_for_product($product_id)
    {
        global $wpdb;
        
        $open_statuses = ['draft', 'checkout-draft','wc-checkout-draft'];
        $placeholders = implode(',', array_fill(0, count($open_statuses), '%s'));
        
        // Get the product to check if it's variable
        $product = wc_get_product($product_id);
        $product_ids_to_check = [$product_id];
        
        // If it's a variable product, get all variation IDs
        if ($product && $product->is_type('variable')) {
            $variation_ids = $product->get_children();
            $product_ids_to_check = array_merge($product_ids_to_check, $variation_ids);
        }
        // If it's a variation, also check the parent product
        elseif ($product && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $product_ids_to_check[] = $parent_id;
            }
        }
        
        // Remove duplicates and ensure all IDs are integers
        $product_ids_to_check = array_unique(array_map('intval', $product_ids_to_check));
        
        // Create placeholders for product IDs
        $product_placeholders = implode(',', array_fill(0, count($product_ids_to_check), '%s'));
        
        $query = $wpdb->prepare("
            SELECT DISTINCT p.ID as order_id, p.post_status as status, p.post_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ($placeholders)
            AND oim.meta_key IN ('_product_id', '_variation_id')
            AND oim.meta_value IN ($product_placeholders)
            ORDER BY p.post_date DESC
            LIMIT 50
        ", array_merge($open_statuses, $product_ids_to_check));
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        $orders = [];
        foreach ($results as $row) {
            $orders[] = [
                'order_id' => $row['order_id'],
                'status' => str_replace('wc-', '', $row['status']),
                'date' => $row['post_date']
            ];
        }
        
        // Debug logging
        if (WP_DEBUG) {
            error_log("ProductCustomFields: Checking orders for product IDs: " . implode(', ', $product_ids_to_check));
            error_log("ProductCustomFields: Found " . count($orders) . " open orders");
        }
        
        return $orders;
    }

     
    /**
     * Validate min/max quantity on add to cart
     */
    public function validate_min_max_order_quantity($passed, $product_id, $quantity)
    {
        $min_quantity = get_post_meta($product_id, '_min_order_quantity', true);
        $max_quantity = get_post_meta($product_id, '_max_order_quantity', true);

        if (!empty($min_quantity) && $quantity < $min_quantity) {
            wc_add_notice(
                sprintf(__('You must purchase at least %s of this product.', 'woocommerce'), $min_quantity), 
                'error'
            );
            $passed = false;
        }

        if (!empty($max_quantity) && $quantity > $max_quantity) {
            wc_add_notice(
                sprintf(__('You can only purchase a maximum of %s of this product.', 'woocommerce'), $max_quantity), 
                'error'
            );
            $passed = false;
        }
        
        return $passed;
    }

    /**
     * Validate min/max quantity in cart
     */
    public function validate_cart_min_max_order_quantity()
    {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $product_name = $cart_item['data']->get_name();

            $min_quantity = get_post_meta($product_id, '_min_order_quantity', true);
            $max_quantity = get_post_meta($product_id, '_max_order_quantity', true);

            if (!empty($min_quantity) && $quantity < $min_quantity) {
                wc_add_notice(
                    sprintf(__('Product "%s" requires a minimum quantity of %s.', 'woocommerce'), $product_name, $min_quantity), 
                    'error'
                );
            }

            if (!empty($max_quantity) && $quantity > $max_quantity) {
                wc_add_notice(
                    sprintf(__('Product "%s" allows a maximum quantity of %s.', 'woocommerce'), $product_name, $max_quantity), 
                    'error'
                );
            }
        }
    }
}