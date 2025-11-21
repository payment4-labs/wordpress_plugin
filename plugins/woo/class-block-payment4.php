<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Payment4CPG_WC_Blocks extends AbstractPaymentMethodType
{

    private $gateway;
    protected $name = 'payment4cpg_wc';// your payment gateway name

    public function initialize()
    {
        $this->settings = get_option('woocommerce_payment4cpg_wc_settings', []);

        // you can also initialize your payment gateway here
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways[ $this->name ];
    }

    public function is_active() {
        return ! empty( $this->settings[ 'enabled' ] ) && 'yes' === $this->settings[ 'enabled' ];
    }

    public function get_payment_method_script_handles() {

        wp_register_script(
            'payment4cpg-wc-blocks-integration',
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

        // Localize script to pass Ajax URL and nonce
        wp_localize_script(
            'payment4cpg-wc-blocks-integration',
            'payment4Ajax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'payment4-checkout-nonce' ),
            )
        );

        return array( 'payment4cpg-wc-blocks-integration' );

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