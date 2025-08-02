<?php
use Automattic\WooCommerce\Utilities\FeaturesUtil;


/**
 * Payment4 Payment gateway plugin class.
 *
 * @class WC_Payment4_Payments
 */
class WC_Payment4_Payments
{

    /**
     * Plugin bootstrapping.
     */
    public static function init()
    {
        // Load plugin textdomain.
        add_action('plugins_loaded', array(__CLASS__, 'plugin_loaded'));

        self::includes();
        // Payments gateway class.
//        add_action('plugins_loaded', array(__CLASS__, 'includes'), 0);

        // Make the Payments gateway available to WC.
        add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));

        // Hook the custom function to 'before_woocommerce_init' the action for cart checkout blocks
        add_action('before_woocommerce_init', array(__CLASS__, 'declare_cart_checkout_blocks_compatibility'));

        // Hook the custom function to the 'woocommerce_blocks_loaded' action
        add_action('woocommerce_blocks_loaded', array(__CLASS__, 'oawoo_register_order_approval_payment_method_type'));


    }

    public static function plugin_loaded()
    {
        self::load_textdomain();
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
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        // Include the custom Blocks Checkout class
        require_once plugin_dir_path(__FILE__) . 'class-block-payment4.php';

        // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                // Register an instance of My_Custom_Gateway_Blocks
                $payment_method_registry->register(new WC_Payment4_Blocks);
            }
        );
    }

    /**
     * Load plugin textdomain.
     */
    public static function load_textdomain()
    {
        load_plugin_textdomain('payment4-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Add the Payment gateway to the list of available gateways.
     *
     * @param  array
     */
    public static function add_gateway($gateways)
    {
        $gateways[] = 'WC_Payment4';
        return $gateways;
    }

    /**
     * Plugin includes.
     */
    public static function includes()
    {
        // Make the WC_Payment4 class available.
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

WC_Payment4_Payments::init();
add_action('init', 'payment4_register_custom_order_status', 0);
add_filter('wc_order_statuses', 'payment4_add_custom_order_status');
function payment4_register_custom_order_status() {
    register_post_status('wc-payment4-mismatch', array(
        'label'                     => 'Payment4 Mismatch',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Payment4 Mismatch <span class="count">(%s)</span>', 'Payment4 Mismatch <span class="count">(%s)</span>'),
    ));
}

function payment4_add_custom_order_status($order_statuses) {
    $order_statuses['wc-payment4-mismatch'] = 'Payment4 Mismatch';
    return $order_statuses;
}