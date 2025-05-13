<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Payment4_Blocks extends AbstractPaymentMethodType
{

    private $gateway;
    protected $name = 'WC_Payment4';// your payment gateway name

    public function initialize()
    {
        $this->settings = get_option('woocommerce_WC_Payment4_settings', []);

        // you can also initialize your payment gateway here
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways[ $this->name ];
    }

    public function is_active() {
        return ! empty( $this->settings[ 'enabled' ] ) && 'yes' === $this->settings[ 'enabled' ];
    }

    public function get_payment_method_script_handles() {

        wp_register_script(
            'wc-payment4-blocks-integration',
            plugin_dir_url( __FILE__ ) . 'assets/checkout.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            null, // or time() or filemtime( ... ) to skip caching
            true
        );

        return array( 'wc-payment4-blocks-integration' );

    }

    public function get_payment_method_data()
    {
        return [
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon'        => plugin_dir_url(__FILE__) . 'assets/logo.png',
        ];
    }

}