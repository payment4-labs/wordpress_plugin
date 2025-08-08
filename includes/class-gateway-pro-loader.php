<?php

if ( ! defined('ABSPATH')) {
    exit;
}
class Payment4_Gateway_Pro_Loader
{

    public function run()
    {
        // Load Admin Panel
        $this->load_admin_panel();

        // Load modules conditionally
        add_action('plugins_loaded', function () {
            $enabled_plugins = get_option('payment4_gateway_pro_plugins');

            // WooCommerce
            if (!empty($enabled_plugins['woo']) && class_exists('WooCommerce')) {
                require_once plugin_dir_path(__FILE__) . '../plugins/woo/index.php';
            }

            // RCP
            if (!empty($enabled_plugins['rcp']) && class_exists('RCP_Payment_Gateway')) {
                require_once plugin_dir_path(__FILE__) . '../plugins/rcp/rcp-pg.php';
            }

            // EDD
            if (!empty($enabled_plugins['edd']) && class_exists( 'Easy_Digital_Downloads' )) {
                require_once plugin_dir_path(__FILE__) . '../plugins/edd/edd-pg.php';
            }
        });
        // Gravity Forms
        add_action('gform_loaded', function () {
            $enabled_plugins = get_option('payment4_gateway_pro_plugins', []);
            if (!empty($enabled_plugins['gf']) && class_exists('GFForms')) {
                require_once plugin_dir_path(__FILE__) . '../plugins/gf/p4-gf.php';
            }
        }, 1);
    }

    private function load_admin_panel()
    {
        add_action('admin_menu', [$this, 'payment4_gateway_pro_options_page']);
        add_action('admin_enqueue_scripts', [$this, 'payment4_admin_styles']);
        // Register settings
        require_once plugin_dir_path(__FILE__) . '../includes/register-settings.php';
        add_action('admin_init', 'payment4_register_settings');
        add_action('admin_notices', 'payment4_admin_settings_notice');
    }

    public function payment4_gateway_pro_options_page()
    {
        // Main menu
        add_menu_page(
            'Payment4 Gateway Pro Settings',
            __('Payment4', 'payment4-gateway-pro'),
            'manage_options',
            'payment4-gateway-pro',
            [$this, 'render_general_settings_page'],
            plugin_dir_url(__FILE__) . '../assets/img/small-square-logo.png',

        );
        // Submenu: General Settings
        add_submenu_page(
            'payment4-gateway-pro',
            __('General Settings', 'payment4-gateway-pro'),
            __('General Settings', 'payment4-gateway-pro'),
            'manage_options',
            'payment4-gateway-pro',
            [$this, 'render_general_settings_page'],
        );
        // Submenu: Plugins
        add_submenu_page(
            'payment4-gateway-pro',
            __('Active Plugins', 'payment4-gateway-pro'),
            __('Plugins', 'payment4-gateway-pro'),
            'manage_options',
            'payment4-gateway-pro-plugins',
            [$this, 'render_plugins_settings_page'],
        );
    }

    public function render_general_settings_page()
    {
        include plugin_dir_path(__FILE__) . '../admin/views/general-settings.php';
    }

    public function render_plugins_settings_page()
    {
        include plugin_dir_path(__FILE__) . '../admin/views/plugins-settings.php';
    }

    public function payment4_admin_styles() {
        // Check if we are on your specific admin page
        if ( isset($_GET['page']) && ( $_GET['page'] == 'payment4-gateway-pro' || $_GET['page'] == 'payment4-gateway-pro-plugins') ) {
            wp_enqueue_style(
                'payment4-admin-css', // Handle name for your stylesheet
                PAYMENT4_PRO_URL . 'assets/css/payment4.css', // Path to your CSS file
                array(), // Dependencies (if any)
                '1.0.0' // Version number
            );
        }
    }
}