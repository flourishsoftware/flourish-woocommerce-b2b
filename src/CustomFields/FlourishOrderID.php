<?php

namespace FlourishWooCommercePlugin\CustomFields;

defined( 'ABSPATH' ) || exit;

class FlourishOrderID
{
    public function register_hooks()
    {
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'display_flourish_order_id_on_admin_order_view'], 10, 1);
    }

    function display_flourish_order_id_on_admin_order_view($order)
    {
        $flourish_order_id = $order->get_meta('flourish_order_id', true);

        if (!empty($flourish_order_id)) {
            ?>
            <p class="form-field form-field-wide">
                Flourish Order ID:<br>
                <?php echo $flourish_order_id; ?>
            </p>
            <?php
        }
    }
}
