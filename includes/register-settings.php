<?php

if (!defined('ABSPATH')) {
    exit;
}

function payment4_register_settings()
{
    // Register General Settings
    register_setting('payment4_gateway_pro_options', 'payment4_gateway_pro_settings');

    add_settings_section(
        'payment4_main_section',
        'General Settings',
        null,
        'payment4_gateway_pro'
    );

    add_settings_field(
        'api_key',
        'API Key',
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
        'SandBox Mode',
        function () {
            $options = get_option('payment4_gateway_pro_settings');
            $checked = !empty($options['sandbox_mode']) ? 'checked' : '';
            echo "<label><input type='checkbox' name='payment4_gateway_pro_settings[sandbox_mode]' value='1' $checked> SandBox</label><br>";
        },
        'payment4_gateway_pro',
        'payment4_main_section'
    );

    // Register plugins setting
    register_setting('payment4_gateway_pro_plugins', 'payment4_gateway_pro_plugins');

    add_settings_section(
        'payment4_plugins_section',
        'Select Active Plugins',
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
                echo "<label><input type='checkbox' name='payment4_gateway_pro_plugins[$key]' value='1' $checked> $label</label><br>";
            }
        },
        'payment4_plugins',
        'payment4_plugins_section'
    );
}