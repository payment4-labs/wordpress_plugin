<?php
/*
 * Plugin Name: Payment4 Gateway Pro (Woo, Gravity, RCP, EDD)
 * Description: Crypto Payment Gateway integration with WooCommerce, RCP, EDD, and Gravity Forms.
 * Version: 1.0.0
 * Author: Payment4, Amirhossein Taghizadeh
 * Author URI: https://payment4.com
 * Text Domain: payment4-gateway-pro
 * Domain Path: /languages
 * Requires at least: 6.0.0
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Stable tag: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Plugin Folder URL.
if ( ! defined( 'PAYMENT4_PRO_URL' ) ) {
    define( 'PAYMENT4_PRO_URL', plugin_dir_url( __FILE__ ) );
}

add_action('plugins_loaded', function () {
    load_plugin_textdomain('payment4-gateway-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Load main files
require_once plugin_dir_path(__FILE__) . 'includes/class-gateway-pro-loader.php';

(new Payment4_Gateway_Pro_Loader())->run();