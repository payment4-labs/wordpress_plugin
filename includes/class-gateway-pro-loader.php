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
        });
    }

    private function load_admin_panel()
    {
        add_action('admin_menu', [$this, 'payment4_gateway_pro_options_page']);
        // Register settings
        require_once plugin_dir_path(__FILE__) . '../includes/register-settings.php';
        add_action('admin_init', 'payment4_register_settings');
    }

    public function payment4_gateway_pro_options_page()
    {
        // Main menu
        add_menu_page(
            'Payment4 Gateway Pro Settings',
            'Payment4',
            'manage_options',
            'payment4-gateway-pro',
            [$this, 'render_general_settings_page'],
            plugin_dir_url(__FILE__) . '../assets/img/small-square-logo.png',

        );
        // Submenu: General Settings
        add_submenu_page(
            'payment4-gateway-pro',
            'General Settings',
            'General Settings',
            'manage_options',
            'payment4-gateway-pro',
            [$this, 'render_general_settings_page'],
        );
        // Submenu: Plugins
        add_submenu_page(
            'payment4-gateway-pro',
            'Active Plugins',
            'Plugins',
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
}