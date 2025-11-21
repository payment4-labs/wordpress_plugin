<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Utilities\FeaturesUtil;


/**
 * Payment4 Payment gateway plugin class.
 *
 * @class Payment4CPG_WC_Loader
 */
class Payment4CPG_WC_Loader
{

    /**
     * Plugin bootstrapping.
     */
    public static function init()
    {
        self::includes();

        // Make the Payments gateway available to WC.
        add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));

        // Hook the custom function to 'before_woocommerce_init' the action for cart checkout blocks
        add_action('before_woocommerce_init', array(__CLASS__, 'declare_cart_checkout_blocks_compatibility'));

        // Hook the custom function to the 'woocommerce_blocks_loaded' action
        add_action('woocommerce_blocks_loaded', array(__CLASS__, 'oawoo_register_order_approval_payment_method_type'));
    }

    /**
     * Custom function to declare compatibility with cart_checkout_blocks feature
     */
    public static function declare_cart_checkout_blocks_compatibility()
    {
        // check if the required class exists
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            // declare compatibility for 'cart_checkout_blocks'
            FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                dirname(__FILE__) . '/class-wc-gateway-payment4.php',
                true
            );
        }
    }


    /**
     * Custom function to register a payment method type
     */

    public static function oawoo_register_order_approval_payment_method_type()
    {
        // Check if the required class exists
        if ( ! class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        // Include the custom Blocks Checkout class
        require_once plugin_dir_path(__FILE__) . 'class-block-payment4.php';

        // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                // Register an instance of My_Custom_Gateway_Blocks
                $payment_method_registry->register(new Payment4CPG_WC_Blocks);
            }
        );
    }

    public static function init_discount_handler()
    {
        // WooCommerce creates an instance of the gateway for us, so we can retrieve it.
        $gateways = WC()->payment_gateways->payment_gateways();

        // Check if our gateway is active and in the list.
        if (isset($gateways['payment4cpg_wc'])) {
            $payment_gateway = $gateways['payment4cpg_wc'];

            // Now you can use the instance to check for the discount.
            if ($payment_gateway->have_discount()) {
                require_once 'fee_handle.php';
            }
        }
    }

    /**
     * Add the Payment gateway to the list of available gateways.
     *
     * @param  array
     */
    public static function add_gateway($gateways)
    {
        $gateways[] = 'Payment4CPG_WC_Gateway';

        return $gateways;
    }

    /**
     * Plugin includes.
     */
    public static function includes()
    {
        // Make the Payment4CPG_WC_Gateway class available.
        if (class_exists('WC_Payment_Gateway')) {
            require_once 'class-wc-gateway-payment4.php';
        }
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_url()
    {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_abspath()
    {
        return trailingslashit(plugin_dir_path(__FILE__));
    }
}

Payment4CPG_WC_Loader::init();

add_action('init', 'payment4cpg_woo_register_custom_order_status');

function payment4cpg_woo_register_custom_order_status()
{
    register_post_status('wc-p4-acceptable', array(
        'label'                     => _x('Payment4 Acceptable', 'Order status', 'payment4-crypto-payment-gateway'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %1$s: Number of orders */
        'label_count'               => _n_noop(
            'Payment4 Acceptable <span class="count">(%1$s)</span>',
            'Payment4 Acceptables <span class="count">(%1$s)</span>',
            'payment4-crypto-payment-gateway'
        ),
    ));

    register_post_status('wc-p4-mismatch', array(
        'label'                     => _x('Payment4 Mismatch', 'Order status', 'payment4-crypto-payment-gateway'),
        'public'                    => true,
        'show_in_admin_status_list' => true,
        /* translators: %1$s: Number of orders */
        'label_count'               => _n_noop(
            'Payment4 Mismatch <span class="count">(%1$s)</span>',
            'Payment4 Mismatches <span class="count">(%1$s)</span>',
            'payment4-crypto-payment-gateway'
        ),
    ));
}

add_filter('wc_order_statuses', 'payment4cpg_woo_add_custom_order_status');

function payment4cpg_woo_add_custom_order_status($order_statuses)
{
    $order_statuses['wc-p4-mismatch']   = _x('Payment4 Mismatch', 'Order status', 'payment4-crypto-payment-gateway');
    $order_statuses['wc-p4-acceptable'] = _x('Payment4 Acceptable', 'Order status', 'payment4-crypto-payment-gateway');

    return $order_statuses;
}

add_action('admin_enqueue_scripts', function () {
    $screen = get_current_screen();

    if (isset($screen->id) && strpos($screen->id, 'wc-orders') !== false) {
        payment4cpg_woo_enqueue_order_status_styles();
    }
});

function payment4cpg_woo_enqueue_order_status_styles() {
    if (!is_admin()) return;

    // Register a dummy handle to attach inline styles
    wp_register_style('payment4-order-status', false);
    wp_enqueue_style('payment4-order-status');

    $custom_css = '
        .order-status.status-p4-mismatch {
            background-color: #ff5e57 !important;
            color: #ffffff !important;
        }

        .order-status.status-p4-acceptable {
            background-color: #58abe8 !important;
            color: #000000 !important;
        }

        .status-p4-mismatch td, .status-p4-mismatch th {
            background-color: #fff5f5 !important;
        }

        .status-p4-acceptable td, .status-p4-acceptable th {
            background-color: #f4fff4 !important;
        }
    ';

    wp_add_inline_style('payment4-order-status', $custom_css);
}