<?php
namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;


class HandlerOutboundUpdateCart
{
    public function __construct()
    {
        // Register the necessary WooCommerce hooks.
        $this->register_hooks();
    }

    public function register_hooks()
    {
        add_action('woocommerce_after_cart_item_quantity_update', [$this,'adjust_stock_on_cart_update'],10,3);
        // Store reservation time in session
        add_filter('woocommerce_loop_add_to_cart_link',[$this, 'replace_add_to_cart_with_view_cart'], 10, 2);
        add_filter('woocommerce_add_cart_item_data', [$this, 'store_reservation_time_in_cart'], 10, 2);
        //Handle stock adjustments on adding/removing cart items
        add_action('woocommerce_add_to_cart', [$this, 'reduce_stock_on_add_to_cart'], 10, 2);
        //Display remaining reservation time in the cart
        add_filter('woocommerce_get_item_data', [$this, 'display_remaining_reservation_time'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [$this,'change_variation_max_qty_in_cart'], 10, 3);
        //add_action('woocommerce_cart_loaded_from_session', [$this, 'change_variation_max_qty_in_cart'], 10, 3);
        add_action('wp_ajax_get_dynamic_attribute_data', [$this, 'ajax_get_dynamic_attribute_data']);
        add_action('wp_ajax_nopriv_get_dynamic_attribute_data', [$this,'ajax_get_dynamic_attribute_data']);
        add_action('woocommerce_single_product_summary', [$this,'customize_single_product_page']);
        add_action('woocommerce_before_cart', [$this,'remove_expired_cart_items']);
        //  cart_cleanup_item_reservation_timeout
        add_action('wp_ajax_restore_stock_on_remove_cart', [$this,'restore_stock_on_remove_cart']);
        add_action('wp_ajax_nopriv_restore_stock_on_remove_cart', [$this,'restore_stock_on_remove_cart']);
        add_action('wp_ajax_cart_cleanup_item_reservation_timeout', [$this,'cart_cleanup_item_reservation_timeout']);
        add_action('wp_ajax_nopriv_cart_cleanup_item_reservation_timeout', [$this,'cart_cleanup_item_reservation_timeout']);
        add_filter('woocommerce_cart_item_required_stock_is_not_enough', [$this, 'disable_stock_validation_on_cart_page'], 10, 3);
        add_action('woocommerce_before_checkout_process', [$this,'remove_stock_validation_on_proceed_to_checkout']);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this,'disable_pay_button_cod_orders'], 10, 2);
           }


     public function disable_pay_button_cod_orders($statuses, $order) {
        if ($order && $order->get_payment_method() == 'cod' && in_array($order->get_status(), ['draft','failed'])) {
            return []; // Removes payment options for COD orders in Draft status
        }
        return $statuses;
    }
    public function remove_stock_validation_on_proceed_to_checkout() {

        // Remove stock hold for checkout process
        remove_filter('woocommerce_hold_stock_for_checkout', '__return_true');
        // Remove stock validation filter
        remove_filter('woocommerce_cart_item_required_stock_is_not_enough', '__return_true');
        // Remove WooCommerce default cart item stock validation
        remove_action('woocommerce_check_cart_items', array(WC()->cart, 'check_cart_items'), 1);
        // Remove WooCommerce stock reservation function for orders
        remove_action('woocommerce_checkout_order_created', 'wc_reserve_stock_for_order', 10);
    }
    public function disable_stock_validation_on_cart_page($is_not_enough, $product, $values) {
           // Check if the current page is the cart page but not the checkout page
        if (is_cart() || is_checkout()) {
            return false; // Disable the stock validation only on the cart page
        }
        return $is_not_enough; // Default behavior for other pages
    }

    public function replace_add_to_cart_with_view_cart($button, $product) {
        // Ensure the product is a simple product (not a variation)
        if (!$product || !$product->is_type('simple')) {
            return $button; // Return the default button for non-simple products
        }

        // Check if the product is in stock
        if (!$product->is_in_stock()) {
            return $button; // Return the default button if the product is out of stock
        }

            // If stock management is enabled and backorders are enabled, skip stock checking
        if ($this->should_manage_stock($product)) {
            // Check if the simple product is already in the cart
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] == $product->get_id()) {
                    // Replace "Add to Cart" with "View Cart" button
                    $cart_url = wc_get_cart_url();
                    return '<a href="' . esc_url($cart_url) . '" class="button wc-forward">' . __('View Cart', 'woocommerce') . '</a>';
                }
            }
            return $button; // Return default "Add to Cart" button
        }

        // Calculate the available stock for simple product
        $product_id = $product->get_id();
        $total_stock = $product->get_stock_quantity();
        $held_stock = (int) get_post_meta($product_id, '_held_stock', true) ?: 0;
        $available_stock = max(0, $total_stock - $held_stock);

        // If stock is not available, show "Out of Stock"
        if ($available_stock <= 0) {
            return '<button class="button out-of-stock" disabled>' . __('Out of Stock', 'woocommerce') . '</button>';
        }

        // Check if the simple product is already in the cart
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                // Replace "Add to Cart" with "View Cart" button
                $cart_url = wc_get_cart_url();
                return '<a href="' . esc_url($cart_url) . '" class="button wc-forward">' . __('View Cart', 'woocommerce') . '</a>';
            }
        }

        // If stock is available and the product is not in the cart, return the "Add to Cart" button
        return $button;
    }
    /* Logic for handling "Add to Cart" button */

    public function customize_single_product_page() {
        if (!is_product()) return;

        global $product;
     // Check if stock management is enabled for this product


       if ($this->should_manage_stock($product)) {
        return; // Skip stock management
        }

        $stock_display_format = get_option('woocommerce_stock_format', 'always');
        $cart_items = $this->get_cart_items();
        ?>
        <script>
        jQuery(document).ready(function($) {
            const ProductCustomizer = {
                cartItems: <?php echo json_encode($cart_items); ?>,
                productId: <?php echo $product->get_id(); ?>,
                stockDisplayFormat: "<?php echo esc_js($stock_display_format); ?>",

                init: function() {
                    $('.stock.in-stock:first').hide();
                    <?php if ($product->is_type('variable')): ?>
                        this.handleVariableProduct();
                    <?php else: ?>
                        this.handleSimpleProduct();
                    <?php endif; ?>
                    this.bindEvents();
                },

                handleVariableProduct: function() {
                    $('form.variations_form').on('show_variation', (event, variation) => {
                        $('.stock.in-stock:first').hide();
                        this.fetchProductData({
                            variation_id: variation.variation_id,
                            product_id: $('input[name="product_id"]').val()
                        });
                    });
                },

                handleSimpleProduct: function() {
                    const productId = $('button.single_add_to_cart_button').val();
                    this.fetchProductData({ product_id: productId });
                },

                fetchProductData: function(data) {
                    data.action = 'get_dynamic_attribute_data';

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        method: 'POST',
                        data: data,
                        success: (response) => {
                            if (response.success) {
                                this.updateProductDisplay(response.data);
                            } else {
                                console.error(response.data.message);
                            }
                        },
                        error: (xhr, status, error) => console.error('Error:', error)
                    });
                },

                updateProductDisplay: function(data) {
                    const $quantityInput = $('input.qty');
                    $quantityInput.attr('max', data.maxQty).val(1);
                    if (data.maxQty === 0) {
                        this.showOutOfStock();
                    } else {
                        this.updateStockMessage(data);
                        this.checkCartStatus();
                    }
                },

                updateStockMessage: function(data) {
                    let displayMessage = this.formatStockMessage(data);

                    // Update stock message
                    $('.woocommerce-variation-availability p.stock').not(':first').remove();
                    const $stockContainer = $('.woocommerce-variation-availability p.stock:first');

                    if ($stockContainer.length) {
                        $stockContainer.text(displayMessage).show();
                    } else {
                        $('.woocommerce-variation-add-to-cart, form.cart').before(
                            '<p class="stock in-stock">' + displayMessage + '</p>'
                        );
                    }
                },

                formatStockMessage: function(data) {
                    const stockQty = data.stock_quantity;

                    switch (this.stockDisplayFormat) {
                        case 'no_amount':
                            return 'In stock';
                        case 'low_amount':
                            return stockQty <= 12 ? `Only ${stockQty} left in stock!` : `${stockQty} in stock`;
                        case 'always':
                        case '':
                        default:
                            return `${stockQty} in stock`;
                    }
                },

                showOutOfStock: function() {
                    this.hideAddToCartElements();
                    this.showMessage('out-of-stock-container',
                        '<?php _e("This product is currently out of stock.", "your-text-domain"); ?>',
                        'woocommerce-error'
                    );
                },

                checkCartStatus: function() {
                    setTimeout(() => {
                        const variationId = this.getVariationId();
                        const isInCart = this.isProductInCart(variationId);

                        if (isInCart) {
                            this.showAlreadyInCart();
                        } else {
                            this.showAddToCartElements();
                            this.removeMessages();
                        }
                    }, 500);
                },

                isProductInCart: function(variationId) {
                    return this.cartItems.some(item =>
                        (item.product_id == this.productId && item.variation_id == parseInt(variationId)) ||
                        (item.product_id == this.productId && item.variation_id == 0)
                    );
                },

                getVariationId: function() {
                    const $form = $('.woocommerce-variation-add-to-cart').length ?
                        $('.woocommerce-variation-add-to-cart') : $('.cart');
                    return $form.find('input.variation_id').val() || 0;
                },

                showAlreadyInCart: function() {
                    this.hideAddToCartElements();
                    const message = '<?php _e("Item already in cart", "your-text-domain"); ?>' +
                        '<a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="button wc-forward" style="float:right;margin-left:30px;">' +
                        '<?php _e("View Cart", "your-text-domain"); ?></a>';

                    this.showMessage('already-in-cart-container', message, 'woocommerce-message');
                },

                hideAddToCartElements: function() {
                    const $form = this.getFormElements();
                    $form.qty.hide();
                    $form.button.hide();
                },

                showAddToCartElements: function() {
                    const $form = this.getFormElements();
                    $form.qty.show();
                    $form.button.show();
                },

                getFormElements: function() {
                    const $form = $('.woocommerce-variation-add-to-cart').length ?
                        $('.woocommerce-variation-add-to-cart') : $('.cart');
                    return {
                        qty: $form.find('.quantity'),
                        button: $form.find('button.single_add_to_cart_button')
                    };
                },

                showMessage: function(containerId, message, messageClass) {
                    if ($('#' + containerId).length === 0) {
                        const $form = this.getFormElements();
                        $form.button.after(`
                            <div id="${containerId}">
                                <div class="woocommerce-notices-wrapper">
                                    <div class="${messageClass}" role="alert" tabindex="-1">
                                        ${message}
                                    </div>
                                </div>
                            </div>
                        `);
                        $('#' + containerId).fadeIn('fast');
                    }
                },

                removeMessages: function() {
                    $('#out-of-stock-container, #already-in-cart-container').fadeOut('fast', function() {
                        $(this).remove();
                    });
                },

                bindEvents: function() {
                    $(document.body).on('change', 'table.variations select', () => {
                        this.checkCartStatus();
                    });

                    $(document.body).on('updated_cart_totals', () => {
                        this.checkCartStatus();
                    });
                }
            };

            ProductCustomizer.init();
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for dynamic attribute data
     */
    public function ajax_get_dynamic_attribute_data() {
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : null;

        // Get product data
        $product_data = $this->get_product_stock_data($product_id, $variation_id);

        if (!$product_data) {
            wp_send_json_error(['message' => 'Invalid product or no stock available'], 400);
        }

        wp_send_json_success($product_data);
    }

    /**
     * Get product stock data
     */
    private function get_product_stock_data($product_id, $variation_id = null) {
    // Determine which product to use
    $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
    $product_parent = wc_get_product($product_id);
    if (!$product) {
        return false;
    }

    // If stock management is enabled and  backorders are enabled, return unlimited stock
    if ($this->should_manage_stock($product)) {
        return;
    }

    // Get stock quantities for managed products
    $total_stock = $product_parent->get_stock_quantity();
    $held_stock = get_post_meta($product_id, '_held_stock', true) ?: 0;
    $total_qty = $total_stock - $held_stock;

    // For variations, calculate pack-based quantity
    if ($variation_id && $product->is_type('variation')) {
        $pack_size = $this->get_pack_size($product);
        $max_qty = $total_qty > 0 ? floor($total_qty / $pack_size) : 0;
    } else {
        $max_qty = $total_qty;
    }

    // Generate stock message
    $stock_message = $this->generate_stock_message($total_qty);

    return [
        'stock_quantity' => $total_qty,
        'maxQty' => $max_qty,
        'stockMessage' => $stock_message,
    ];
}

    /**
     * Get pack size from variation attributes
     */
    private function get_pack_size($product) {
        $variation_attributes = $product->get_attributes();

        foreach ($variation_attributes as $attribute_key => $attribute_value) {
            $taxonomy = str_replace('attribute_', '', $attribute_key);
            $term = get_term_by('slug', $attribute_value, $taxonomy);

            if ($term) {
                $pack_size = get_term_meta($term->term_id, 'quantity', true);
                if ($pack_size) {
                    return intval($pack_size);
                }
            }
        }

        return 1; // Default pack size
    }

    /**
     * Generate stock message HTML
     */
    private function generate_stock_message($total_qty) {
        $status_class = $total_qty > 0 ? 'in-stock' : 'out-of-stock';
        $message = $total_qty > 0 ? "{$total_qty} in stock" : 'Out of stock';

        return sprintf(
            '<div class="woocommerce-variation-availability"><p class="stock %s">%s</p></div>',
            $status_class,
            $message
        );
    }

    /**
     * Get current cart items
     */
    private function get_cart_items() {
        $cart_items = [];

        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $cart_items[] = [
                    'product_id' => $cart_item['product_id'],
                    'variation_id' => $cart_item['variation_id'],
                ];
            }
        }

        return $cart_items;
    }

    /* If the reservation time is expires, Automatically clean the cart */
    public function cart_cleanup_item_reservation_timeout() {
        $this->check_cart_item_expiration();
        $save_cart_expire = new HandlerOutboundMultipleCart;
        $save_cart_expire->mc_remove_expired_saved_carts();
    }



    /* set the mix and max values in cart page */
    public function change_variation_max_qty_in_cart($product_quantity, $cart_item_key, $cart_item) {
    $product_id = $cart_item['product_id'];
    $product = wc_get_product($product_id);

    // If stock management is enabled and backorders are enabled, return unlimited quantity input
    if ($this->should_manage_stock($product)) {
        return sprintf(
            '<div class="quantity">
                <label class="screen-reader-text" for="quantity_%1$s">Quantity</label>
                <input type="button" value="-" class="qty_button minus">
                <input type="number" id="quantity_%1$s" name="cart[%2$s][qty]" value="%3$s" min="1" step="1" class="input-text qty text" size="4" pattern="[0-9]*" inputmode="numeric" aria-labelledby="quantity-label">
                <input type="button" value="+" class="qty_button plus">
            </div>',
            esc_attr($cart_item_key), // Unique ID for the input
            esc_attr($cart_item_key), // Name attribute
            esc_attr($cart_item['quantity']) // Current quantity (no max limit)
        );
    }

    // Continue with stock-managed products
    $total_qty = 0;
    $total_stock = $product->get_stock_quantity();
    $held_stock = (int)get_post_meta($product_id, '_held_stock', true) ?: 0;
    $total_qty = $total_stock - $held_stock;

    if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
        foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
            // Clean attribute key (remove "attribute_").
            $taxonomy = str_replace('attribute_', '', $attribute_key);
            // Get the term by its name in the corresponding taxonomy.
            $term = get_term_by('name', $attribute_value, $taxonomy);
            if ($term) {
                // Get the custom term quantity meta.
                $pack_size = get_term_meta($term->term_id, 'quantity', true) ?: 0;
                $total_cart_qty = ($cart_item['quantity'] * $pack_size) + $total_qty;
                $max_qty = floor($total_cart_qty / $pack_size);
            }
        }
    } else {
        $max_qty = $cart_item['quantity'] + $total_qty;
    }

    $product_quantity = sprintf(
        '<div class="quantity">
            <label class="screen-reader-text" for="quantity_%1$s">Quantity</label>
            <input type="button" value="-" class="qty_button minus">
            <input type="number" id="quantity_%1$s" name="cart[%2$s][qty]" value="%3$s" min="1" max="%4$s" step="1" class="input-text qty text" size="4" pattern="[0-9]*" inputmode="numeric" aria-labelledby="quantity-label">
            <input type="button" value="+" class="qty_button plus">
        </div>',
        esc_attr($cart_item_key), // Unique ID for the input
        esc_attr($cart_item_key), // Name attribute
        esc_attr($cart_item['quantity']), // Current quantity
        esc_attr($max_qty) // Max value
    );

    return $product_quantity;
    }

    /* adjust stock on cart update */
    public function adjust_stock_on_cart_update($cart_item_key, $new_quantity, $old_quantity)
    {
        // Get the updated cart item using the cart item key.
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        // Ensure the cart item exists and fetch the product ID.
        if (!$cart_item) {
            return;
        }
        $product_id = isset($cart_item['variation_id']) && $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
        $parent_id = $cart_item['product_id'];
        $product = wc_get_product($parent_id);
        if ($this->should_manage_stock($product)) {
        return; // Skip stock management
        }

        // Calculate the quantity difference.
        $quantity_difference = $new_quantity - $old_quantity;
        $held_stock = get_post_meta($parent_id, '_held_stock', true);
        $stock=get_post_meta($parent_id, '_stock', true);
        $parent_stock =  $stock-$held_stock;
        // --- Variation pack size validation logic --- Retrieve all variations of the parent product.
        $args = array(
            'post_type' => 'product_variation',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'post_parent' => $parent_id, // Parent product ID
        );

        //validation update cart
        $attributes = $product->get_attributes();
        $variations = get_posts($args);
        $total_variation_qty = 0;
        $variation_pack_sizes = []; // Store pack sizes for each variation.
        $total_variation_qty = 0;  // To store the total quantity of all variations in the cart.
        if ($product->is_type('simple'))
        {
            //simple product
        }
        else
        {
            $total_variation_qty = 0;
            $variation_pack_sizes = [];
            foreach ($variations as $variation) {
                $variation_id = $variation->ID;
                $variation_qty = 0;
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if ($cart_item['variation_id'] == $variation_id) {
                        $variation_qty += $cart_item['quantity'];
                    }
                }
                $total_variation_qty += $variation_qty;
            }
	            // Loop through each variation to validate its stock with the pack size.
            foreach ($variations as $variation) {
                $variation_id = $variation->ID;
                $post_excerpt = get_post_field('post_excerpt', $variation_id);
                // Extract the pack size value from the post excerpt.
                $output = strpos($post_excerpt, ':') !== false ? trim(explode(':', $post_excerpt, 2)[1]) : '';
                // Get the pack size for the current variation.
                $pack_size = 0;
                $attributes = wc_get_product_variation_attributes($variation_id);
                foreach ($attributes as $attribute_key => $attribute_value) {
                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                    if ($attribute_value === $output) {
                        $term = get_term_by('name', $attribute_value, $taxonomy);
                        if ($term) {
                            $pack_size = get_term_meta($term->term_id, 'quantity', true) ?: 0;
                        }
                    }
                }
                $variation_pack_sizes[$variation_id] = $pack_size;
                // Get the quantity of this variation in the cart.
                $variation_qty = 0;
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if ($cart_item['variation_id'] == $variation_id) {
                        $variation_qty += $cart_item['quantity'];
                        $variation_product = wc_get_product($cart_item['variation_id']);

                        if ($variation_product) {
                            // Get the variation name
                            $variation_name = $variation_product->get_name();
                        }
                    }
                }
                // Calculate the max allowed quantity for this variation.
                if ($pack_size > 0 && $variation_qty !== 0) {
                    $max_allowed_qty = floor($parent_stock / $pack_size);
                    $allowed_qty_variation = $max_allowed_qty + $old_quantity;
                    if ($quantity_difference > $max_allowed_qty) {
                        wc_add_notice(
                            sprintf(__('The maximum allowed quantity for "%s" is %d.', 'text-domain'),$variation_name,$allowed_qty_variation ),
                            'notice'
                        );
                        wp_safe_redirect(wc_get_cart_url());
                        //exit;
                    }
                }
            }

	    }
        // --- Stock adjustment logic --- and quantity update in update cart
        if ($quantity_difference !== 0) {
            $adjust_quantity = $quantity_difference*$pack_size;
            // Adjust stock for the parent product.
            $adjust_quantity = $quantity_difference;
            $cart_item = WC()->cart->get_cart_item($cart_item_key);
            // If the product is a variation, adjust based on term quantity.
            if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                    // Clean attribute key (remove "attribute_").
                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                    // Get the term by its name in the corresponding taxonomy.
                    $term = get_term_by('name', $attribute_value, $taxonomy);
                    if ($term) {
                        // Get the custom term quantity meta.
                        $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                        if ($term_quantity) {
                            // Adjust quantity based on the term quantity.
                            $adjust_quantity = $quantity_difference * (int)$term_quantity;
                        }
                    }
                }
            }
            // Adjust stock based on the calculated adjustment quantity.
            if ($adjust_quantity > 0) {
                $this->adjust_stock($parent_id, -$adjust_quantity);
            } else {
                $this->adjust_stock($parent_id, abs($adjust_quantity));
            }
	        // Update the saved quantity for the specific cart item.
            WC()->cart->cart_contents[$cart_item_key]['saved_cart_quantity'] = $new_quantity;
        }
        // Save the cart session after modifications.
        WC()->cart->set_session();
        WC()->cart->calculate_totals();
    }

    /*store the reservation time in based on cart items*/
    public function store_reservation_time_in_cart($cart_item_data, $product_id)
    {
        $reservation_time = get_option('stock_reservation_time', 20);
        if ($reservation_time) {
            $cart_item_data['reservation_expiration_time'] = time() + ($reservation_time * 60);
        }
        return $cart_item_data;
    }
    /* check the cart items expiration*/
    public function check_cart_item_expiration() {
        // Check if the cart exists and is not empty
        if (!WC()->cart || WC()->cart->is_empty()) {
            wp_send_json_success('Cart is empty or not available.');
            return;
        }
        $cart_items_to_remove = [];
        $items_removed = false;
        // Iterate through cart items
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            // Check expiration condition
            if (isset($cart_item['reservation_expiration_time']) && $cart_item['reservation_expiration_time'] < time()) {
                error_log('Processing expired item: ' . print_r($cart_item, true));
                // Process only unprocessed items
                if (empty($cart_item['processed_expired'])) {
                    $cart_item['processed_expired'] = true; // Mark as processed
                    if (WC()->cart->get_cart_item($cart_item_key)) {
                        error_log("Cart item exists for key: $cart_item_key");
                        $remaining_time = $cart_item['reservation_expiration_time'] - time();

                        // Remove item if reservation time has expired
                        if ($remaining_time <= 0) {
                            WC()->cart->remove_cart_item($cart_item_key);
                            $items_removed = true;
                            $this->adjust_stock_for_expired_cart_item($cart_item);
                        }

                    } else {
                        error_log("Cart item not found for key: $cart_item_key");
                    }
                    // Handle stock adjustment
                    $cart_items_to_remove[] = $cart_item_key; // Mark for removal
                }
            }
        }
         // Return response
        if ($items_removed) {
            WC()->cart->calculate_totals();
            WC()->cart->set_session();
            wp_send_json_success(['message' => __('Expired items have been removed from your cart.', 'woocommerce')]);
        } else {
            wp_send_json_success(['message' => __('No expired items found in your cart.', 'woocommerce')]);
        }
        // Refresh the cart to reflect changes
        WC()->cart->set_session(); // Save the cart session
        WC()->cart->calculate_totals(); // Recalculate totals

        wp_send_json_success('Cart processed successfully.');
    }

    /*removed the expired cart when cart page loads*/
    public function remove_expired_cart_items() {
        $cart = WC()->cart->get_cart();

        foreach ($cart as $cart_item_key => $cart_item) {
            if (isset($cart_item['reservation_expiration_time'])) {
                $remaining_time = $cart_item['reservation_expiration_time'] - time();
                // Remove item if reservation time has expired
                if ($remaining_time <= 0) {
                    WC()->cart->remove_cart_item($cart_item_key);
                    wc_add_notice(__('An item has been removed from your cart as its reservation time has expired.', 'woocommerce'), 'notice');
                }
            }
        }
    }

    // Centralized stock adjustment logic to avoid redundancy
    private function adjust_stock_for_expired_cart_item($cart_item)
    {
        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
            foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                $taxonomy = str_replace('attribute_', '', $attribute_key);
                $term = get_term_by('name', $attribute_value, $taxonomy);
                if ($term) {
                    $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                    $adjust_quantity = $cart_item['quantity'] * (int)$term_quantity;
                    $this->adjust_stock($cart_item['product_id'], $adjust_quantity);
                }
        }
        } else {
            $this->adjust_stock($cart_item['product_id'], $cart_item['quantity']);
        }
    }
    /* show reservation time in cart items */
    public function display_remaining_reservation_time($item_data, $cart_item)
    {
        if (isset($cart_item['reservation_expiration_time'])) {
            $remaining_time = $cart_item['reservation_expiration_time'] - time();

            if ($remaining_time > 0) {
                // Check if the "Reservation Time" key already exists
                $key_exists = false;
                foreach ($item_data as $data) {
                    if ($data['key'] === __('This item will be reserved shortly', 'woocommerce')) {
                        $key_exists = true;
                        break;
                    }
                }
                // Add "Reservation Time" only if it doesn't already exist
                if (!$key_exists) {
                    $item_data[] = [
                        'key'   => __('This item will be reserved shortly', 'woocommerce'),
                        'value' => sprintf('<span class="reservation-timer" data-remaining-time="%d"></span>', $remaining_time),
                    ];
                }
            }
        }

        return $item_data;
    }

    /*when the products is added in cart, add/update the held stock*/

    public function reduce_stock_on_add_to_cart($cart_item_key, $product_id) {
        // Get cart item details
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        if (!$cart_item) {
            return;
        }
        $product = wc_get_product($product_id);
        if ($this->should_manage_stock($product)) {
        return; // Skip stock management
        }

        // Check if stock has already been deducted for this cart item
        if (isset($cart_item['saved_cart_item'])) {
            return; // Prevent double stock deduction
        }

        // Get cart quantity
        $cart_quantity = $cart_item['quantity'];

        // Check if the product is a variation
        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
            foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                // Clean attribute key
                $taxonomy = str_replace('attribute_', '', $attribute_key);
                // Get the term by its name
                $term = get_term_by('name', $attribute_value, $taxonomy);
                if ($term) {
                    $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                    $adjust_quantity = $cart_quantity * (int)$term_quantity;
                    $this->adjust_stock($product_id, -$adjust_quantity);
                    WC()->cart->cart_contents[$cart_item_key]['saved_cart_item'] = true; // Mark as processed
                    return; // Exit
                }
            }
        } else {
            // Adjust stock based on cart quantity directly
            $this->adjust_stock($product_id, -$cart_quantity);
            WC()->cart->cart_contents[$cart_item_key]['saved_cart_item'] = true; // Mark as processed
        }
    }

    /*Restore stock on remove cart*/
    public function restore_stock_on_remove_cart()
    {
        // Validate nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woocommerce-cart')) {
        // wp_send_json_error(['error' => 'Invalid nonce.']);
        }
        // Get POST data
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
        if (!$product_id || !$cart_item_key) {
            wp_send_json_error(['error' => 'Invalid data provided.']);
        }
        // Access the cart instance
        $cart = WC()->cart;
        $cart_item = $cart->get_cart()[$cart_item_key] ?? null;
        if (!$cart_item) {
            wp_send_json_error(['error' => 'Cart item not found.']);
        }

        // Check if the product manages stock
        $product = wc_get_product($product_id);
        if ($this->should_manage_stock($product)) {
        return; // Skip stock management
        }

        if ($product && $product->managing_stock()) {
            // Determine restore quantity
            $restore_quantity = 0;
            // Handle variations if applicable
            if (isset($cart_item['variation_id']) && $cart_item['variation_id'] !== 0 && isset($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $attribute_key => $attribute_value) {
                    // Clean attribute key (remove "attribute_")
                    $taxonomy = str_replace('attribute_', '', $attribute_key);
                    // Get the term by its name in the corresponding taxonomy
                    $term = get_term_by('name', $attribute_value, $taxonomy);
                    if ($term) {
                        $term_quantity = get_term_meta($term->term_id, 'quantity', true);
                        $restore_quantity += $cart_item['quantity'] * (int) $term_quantity;
                    }
                }
            } else {
                // Use default quantity for simple products
                $restore_quantity = $cart_item['quantity'];
            }
            // Adjust stock
            $this->adjust_stock($product_id, $restore_quantity);
            // Remove the item from the cart
            $cart->remove_cart_item($cart_item_key);
            // Recalculate cart totals
            $cart->calculate_totals();
            do_action('woocommerce_cart_updated');
            // Return success message
            wp_send_json_success(['message' => 'Item removed and stock restored successfully.']);
        } else {
            wp_send_json_error(['error' => 'Product does not manage stock or is invalid.']);
        }

    }

    // Helper function to adjust stock
    public function adjust_stock($product_id, $quantity_change) {
        // Get the product object using the product ID
        $product = wc_get_product($product_id);
        if ($product && $product->managing_stock()) {
            // Get the current stock quantity of the product
            $current_stock= (int) get_post_meta($product_id, '_held_stock', true) ?: 0;
            // If stock is managed, adjust the stock based on the quantity change
            $new_stock = $current_stock + $quantity_change;
            // Only update the stock if the new stock is different from the current stock
            if ($current_stock !== $new_stock)
            {
                $new_held_stock = $current_stock - $quantity_change;
                // Ensure held stock doesn't go below zero
                $new_held_stock = max(0, $new_held_stock);
                // Update the held stock meta
                update_post_meta($product_id, '_held_stock', $new_held_stock);
                error_log("Stock updated for Product ID {$product_id}: New Stock = {$new_stock}, Change = {$quantity_change}");
            }
        } else {
            // Log if stock is not managed or product doesn't exist
            error_log("Stock not updated for Product ID {$product_id}: Product does not manage stock.");
        }
    }

    private function should_manage_stock($product) {
    if (!$product) return false;

    $manage_stock = $product->get_manage_stock();
    $backorders_allowed = $product->get_backorders();
    $stock_status=$product->get_stock_status();
    // Fix to allow backordering
    return $manage_stock === false;
}
}
