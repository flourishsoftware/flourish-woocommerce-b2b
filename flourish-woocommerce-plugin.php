<?php
 /**
 * Plugin Name: Flourish WooCommerce Plugin
 * Plugin URI: https://docs.flourishsoftware.com/article/yow6wworay-flourish-woocommerce-plugin-for-wordpress
 * Description: A WooCommerce plugin for your Flourish data.
 * Version: 1.4.0
 * Author: Flourish Software
 * Author URI: https://www.flourishsoftware.com/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * WC requires at least: 2.2
 * WC tested up to: 2.3
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/flourishsoftware/flourish-woocommerce/',
	__FILE__,
	'flourish-woocommerce-plugin'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');

FlourishWooCommercePlugin\FlourishWooCommercePlugin::get_instance()->init(plugin_basename(__FILE__));
