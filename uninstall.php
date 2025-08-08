<?php
/**
 * Uninstall script for the Payment4 Gateway pro plugin
 * Removes plugin settings from the database.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// حذف تنظیمات از wp_options
delete_option('woocommerce_WC_Payment4_settings');
delete_option('payment4_gateway_pro_plugins');
delete_option('payment4_gateway_pro_settings');