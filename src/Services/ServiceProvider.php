<?php

namespace FlourishWooCommercePlugin\Services;
use FlourishWooCommercePlugin\API\FlourishWebhook;
use FlourishWooCommercePlugin\Admin\SettingsPage;
use FlourishWooCommercePlugin\Admin\ProductCustomfields;
use FlourishWooCommercePlugin\Admin\WoocommerceSettingsCustomFields;
use FlourishWooCommercePlugin\Admin\WoocommerceOutboundSettings;
use FlourishWooCommercePlugin\CustomFields\FlourishOrderID;
use FlourishWooCommercePlugin\CustomFields\License;
use FlourishWooCommercePlugin\CustomFields\OutboundFrontend;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersOutbound;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersRetail;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersSyncNow;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersCancel;
use FlourishWooCommercePlugin\Handlers\HandlerOutboundUpdateCart;
use FlourishWooCommercePlugin\Handlers\HandlerOutboundMultipleCart;
 
 

class ServiceProvider
{
    private $settings;
    private $plugin_basename;

    public function __construct(array $settings, string $plugin_basename)
    {
        $this->settings = $settings;
        $this->plugin_basename = $plugin_basename;
    }

    public function register_services()
    {
        // Register the settings page
        (new SettingsPage($this->settings, $this->plugin_basename))->register_hooks();

        // Register common fields and handlers
        (new FlourishOrderID())->register_hooks();
        (new FlourishWebhook($this->settings))->register_hooks();
        (new HandlerOrdersSyncNow($this->settings))->register_hooks();
        (new ProductCustomfields($this->settings))->register_hooks();
        (new HandlerOrdersCancel($this->settings))->register_hooks();
        
        // Conditional registration based on order type
        $this->register_order_type_handlers();
    }

    private function register_order_type_handlers()
    {
        $order_type = $this->settings['flourish_order_type'] ?? 'retail';

        if ($order_type === 'retail') {
            
            $dob = new \FlourishWooCommercePlugin\CustomFields\DateOfBirth();
            $dob->register_hooks();
            (new HandlerOrdersRetail($this->settings))->register_hooks();
        } else {
            (new License($this->settings))->register_hooks();
            (new WoocommerceOutboundSettings($this->settings))->register_hooks();
            (new OutboundFrontend())->register_hooks(); 
            (new WoocommerceSettingsCustomFields())->register_hooks(); 
            (new HandlerOrdersOutbound($this->settings))->register_hooks();            
            (new HandlerOutboundUpdateCart())->register_hooks(); 
            (new HandlerOutboundMultipleCart())->register_hooks();
            
        }
    }
    
}
