<?php

if (!defined('ABSPATH')) {
    exit;
}

function payment4cpg_register_settings()
{
    // Register General Settings
    register_setting('payment4_gateway_pro_options', 'payment4_gateway_pro_settings', array(
        'sanitize_callback' => 'payment4cpg_sanitize_general_settings'
    ));

    add_settings_section(
        'payment4_main_section',
        __('General Settings', 'payment4-crypto-payment-gateway'),
        null,
        'payment4_gateway_pro'
    );

    add_settings_field(
        'api_key',
        __('API Key', 'payment4-crypto-payment-gateway'),
        function () {
            $options = get_option('payment4_gateway_pro_settings');
            ?>
            <input type="text" name="payment4_gateway_pro_settings[api_key]" value="<?php
            echo esc_attr($options['api_key'] ?? ''); ?>" class="regular-text"/>
            <?php
        },
        'payment4_gateway_pro',
        'payment4_main_section'
    );

    add_settings_field(
        'sandbox_mode',
        __('SandBox Mode', 'payment4-crypto-payment-gateway'),
        function () {
            $options = get_option('payment4_gateway_pro_settings');
            $checked = !empty($options['sandbox_mode']) ? 'checked' : '';
            ?>
            <label>
                <input type='checkbox' name='payment4_gateway_pro_settings[sandbox_mode]' value='1' <?php echo esc_attr($checked); ?>>
                <?php echo esc_html__('SandBox', 'payment4-crypto-payment-gateway'); ?>
            </label><br>
            <?php
        },
        'payment4_gateway_pro',
        'payment4_main_section'
    );

    // Register plugins setting
    register_setting('payment4_gateway_pro_plugins', 'payment4_gateway_pro_plugins', array(
        'sanitize_callback' => 'payment4cpg_sanitize_plugin_settings'
    ));

    add_settings_section(
        'payment4_plugins_section',
        __('Select Active Plugins', 'payment4-crypto-payment-gateway'),
        null,
        'payment4_plugins'
    );

    add_settings_field(
        'enabled_plugins',
        'Plugins',
        function () {
            $options = get_option('payment4_gateway_pro_plugins');
            $plugins = [
                'woo' => 'WooCommerce',
                'rcp' => 'Restrict Content Pro',
                'edd' => 'Easy Digital Downloads',
                'gf'  => 'Gravity Forms',
            ];

            foreach ($plugins as $key => $label) {
                $checked = !empty($options[$key]) ? 'checked' : '';
                ?>
                <label>
                    <input type='checkbox' name='payment4_gateway_pro_plugins[<?php echo esc_attr($key); ?>]' value='1' <?php echo esc_attr($checked); ?>>
                    <?php echo esc_html($label); ?>
                </label><br>
                <?php
            }
        },
        'payment4_plugins',
        'payment4_plugins_section'
    );
}

/**
 * Sanitize general settings.
 */
function payment4cpg_sanitize_general_settings($input)
{
    $sanitized = array();
    
    if (isset($input['api_key'])) {
        $sanitized['api_key'] = sanitize_text_field($input['api_key']);
    }
    
    if (isset($input['sandbox_mode'])) {
        $sanitized['sandbox_mode'] = absint($input['sandbox_mode']);
    }
    
    return $sanitized;
}

/**
 * Sanitize plugin settings.
 */
function payment4cpg_sanitize_plugin_settings($input)
{
    $sanitized = array();
    
    $allowed_plugins = array('woo', 'rcp', 'edd', 'gf');
    
    foreach ($allowed_plugins as $plugin) {
        if (isset($input[$plugin])) {
            $sanitized[$plugin] = absint($input[$plugin]);
        }
    }
    
    return $sanitized;
}

function payment4cpg_admin_settings_notice()
{
    if (
        isset($_GET['settings-updated']) &&
        $_GET['settings-updated'] == 'true' &&
        isset($_GET['page']) &&
        ($_GET['page'] === 'payment4-crypto-payment-gateway' || $_GET['page'] === 'payment4-gateway-pro-plugins')
    ) {
        echo '<div class="notice notice-success is-dismissible">
            <p>' . esc_html__('Settings saved successfully.', 'payment4-crypto-payment-gateway') . '</p>
        </div>';
    }
}