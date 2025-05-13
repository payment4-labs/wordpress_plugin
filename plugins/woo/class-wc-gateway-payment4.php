<?php

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Gateway class
 */
class WC_Payment4 extends WC_Payment_Gateway
{

    /**
     * Order ID.
     *
     * @var int
     */
    protected $order_id = 0;

    /**
     * Verification params.
     *
     * @var string
     */
    protected $verification_params = '';

    /**
     * Api base url.
     *
     * @var string
     */
    protected $api_base_url = "https://service.payment4.com/api/v1";

    /**
     * Construct.
     */
    public function __construct()
    {
        $this->id                 = 'WC_Payment4';
        $this->method_title       = __('Payment4', 'payment4-woocommerce');
        $this->method_description = __('Gateway settings for WooCommerce', 'payment4-woocommerce');
        $this->icon               = trailingslashit(WP_PLUGIN_URL) . plugin_basename(
                dirname(__FILE__)
            ) . '/assets/logo.png';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->settings['title'] . " " . __('( Pay with Crypto )', 'payment4-woocommerce');
        $img         = ' <img src="' . $this->icon . '" style="width: 90px;" >';


        $this->method_description .= $img;

        $this->has_fields = false;

        $this->description = $this->settings['description'];
        if ($this->is_checkout_block()) {
            $this->settings['discount_percent'] = 0;
        }

        if ($this->have_discount() && ! $this->is_checkout_block()) {
            $this->description .= "<br>";
            $this->description .= sprintf(
            /* translators: %s is replaced with "string" */
                __('Get %s Percent Discount for paying by Payment4 Crypto', 'payment4-woocommerce'),
                $this->option('discount_percent')
            );
        }

        if (version_compare(WC()->version, '2.0.0', '>=')) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [
                $this,
                'process_admin_options',
            ]);
        } else {
            add_action('woocommerce_update_options_payment_gateways', [$this, 'process_admin_options']);
        }

        add_action('woocommerce_receipt_' . $this->id, [$this, 'process_payment_request']);
        add_action('woocommerce_api_' . strtolower(get_class($this)) . "_callback", [
            $this,
            'process_payment_verify',
        ]);
        add_action('woocommerce_api_' . strtolower(get_class($this)) . "_webhook", [
            $this,
            'process_payment_webhook',
        ]);
        if ( ! $this->is_checkout_block()) {
            require_once 'fee_handle.php';
            add_action('wp_enqueue_scripts', array($this, 'enqueue_payment4_refresh_script'));
            // add_action("woocommerce_before_checkout_form", [$this, "default_payment"]);
        }
    }

    public function is_checkout_block()
    {
        return WC_Blocks_Utils::has_block_in_page(wc_get_page_id('checkout'), 'woocommerce/checkout');
    }

    function enqueue_payment4_refresh_script()
    {
        // Enqueue script only on the WooCommerce checkout page
        if (is_checkout() && ! is_order_received_page()) {
            wp_enqueue_script(
                'payment4_custom',
                plugin_dir_url(__FILE__) . 'assets/payment4_custom.js',
                array('jquery'),
                '1.0',
                true
            );
        }
    }

    /**
     * Option fields.
     */
    public function init_form_fields()
    {
        $general_options = get_option('payment4_gateway_pro_settings', []);

        $shortcodes = [];
        foreach ($this->fields_shortcodes() as $shortcode => $title) {
            $shortcode    = '{' . trim($shortcode, '\{\}') . '}';
            $shortcodes[] = "$shortcode:$title";
        }

        $shortcodes = '<br>' . implode(' - ', $shortcodes);
        $fields     = [
            'enabled'     => [
                'title'       => __('Enable/Disable', 'payment4-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Activation of the payment gateway', 'payment4-woocommerce'),
                'description' => __('Tick the checkbox to activate', 'payment4-woocommerce'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],
            // Optionally display API Key and Sandbox Mode as read-only
            'api_key' => [
                'title' => __('API Key', 'payment4-woocommerce'),
                'type' => 'text',
                'description' => __('Managed in Payment4 General Settings', 'payment4-woocommerce'),
                'default' => isset($general_options['api_key']) ? esc_attr($general_options['api_key']) : '',
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip' => true,
            ],
            'sandbox' => [
                'title' => __('Sandbox Mode', 'payment4-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Managed in Payment4 General Settings', 'payment4-woocommerce'),
                'description' => __('Enable/Disable Sandbox mode', 'payment4-woocommerce'),
                'default' => !empty($general_options['sandbox_mode']) ? 'yes' : 'no',
                'custom_attributes' => ['disabled' => 'disabled'],
                'desc_tip' => true,
            ],
            'title'       => [
                'title'       => __('Title', 'payment4-woocommerce'),
                'type'        => 'text',
                'description' => __('Gateway title', 'payment4-woocommerce'),
                'default'     => __('Payment4 (Pay with Crypto)', 'payment4-woocommerce'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'payment4-woocommerce'),
                'type'        => 'text',
                'description' => __('Gateway description', 'payment4-woocommerce'),
                'default'     => __('Accepting Crypto Payments', 'payment4-woocommerce'),
                'desc_tip'    => true,
            ],

        ];
        if ($this->is_checkout_block()) {
            $fields['discount_percent'] = [
                'title'             => __('Discount Percent', 'payment4-woocommerce'),
                'type'              => 'text',
                'description'       => __(
                    'for using discount use [woocommerce_checkout] shortcode in checkout page',
                    'payment4-woocommerce'
                ),
                'default'           => '0',
                'custom_attributes' => [
                    'readonly' => 'readonly',
                    'disabled' => 'disabled', // Prevent editing or submission
                ],
            ];
        } else {
            $fields['discount_percent'] = [
                'title'       => __('Discount Percent', 'payment4-woocommerce'),
                'type'        => 'text',
                'description' => __('Set 0 for no Discount', 'payment4-woocommerce'),
                'default'     => '0',
                'custom_html' => '<input type="text" name="discount_percent" value="0" readonly />',
                // Input field is set to readonly
            ];
        }

        $fields_extend     = [
            'completed_massage'  => [
                'title'       => __('Success payment message', 'payment4-woocommerce'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Successful payment.', 'payment4-woocommerce'),
            ],
            'failed_massage'     => [
                'title'       => __('Failed payment message', 'payment4-woocommerce'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Transaction Failed.', 'payment4-woocommerce'),
            ],
            'accepted_massage'   => [
                'title'       => __('Acceptable payment message', 'payment4-woocommerce'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Payment accepted.', 'payment4-woocommerce'),
            ],
            'mismatched_massage' => [
                'title'       => __('Mismatched payment message', 'payment4-woocommerce'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Payment Mismatched.', 'payment4-woocommerce'),
            ],
        ];
        $fields            = array_merge($fields, $fields_extend);
        $this->form_fields = apply_filters('WC_Payment4_Config', $fields);
    }

    /**
     * Check if the gateway is available for use.
     *
     * @return bool
     */
    public function is_available()
    {
        $is_available = parent::is_available();

        // Check if WooCommerce is enabled in plugin settings
        $plugin_options = get_option('payment4_gateway_pro_plugins', []);
        if (empty($plugin_options['woo'])) {
            $is_available = false;
        }

        if ($this->getCurrency() === false) {
            $is_available = false;
        }

        return $is_available;
    }

    /**
     * Process Payment.
     *
     * Process the payment. Override this in your gateway. When implemented, this should.
     * return the success and redirect in an array. e.g:
     *
     *        return array(
     *            'result'   => 'success',
     *            'redirect' => $this->get_return_url( $order )
     *        );
     *
     * @param  int  $order_id  Order ID.
     *
     * @return array
     */
    public function process_payment($order)
    {
        $order = $this->get_order($order);

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    /**
     * Get current currency.
     *
     * @return string|bool
     */
    protected function getCurrency()
    {
        $allowed              = ["USD", "IRT", "IRR"];
        $woocommerce_currency = $this->get_woo_currency();

        $response = wp_remote_get("https://storage.payment4.com/wp/currencies.json");

        if ( ! is_wp_error($response) && $response['response']['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (is_array($data) && count($data) > 1) {
                $allowed = $data;
                // if(in_array("IRT",$allowed)  && $woocommerce_currency ==)
                // 	$allowed["irr"] = "IRR";
            }
        }


        if ($woocommerce_currency === "IRR") {
            $woocommerce_currency = "IRT";
        }

        if (in_array($woocommerce_currency, $allowed)) {
            return $woocommerce_currency;
        }

        return false;
    }

    /**
     * Get current language.
     *
     * @return string|bool
     */
    protected function getLanguage()
    {
        $language = "EN";

        $wordpress_language = get_locale();
        $wordpress_language = explode("_", $wordpress_language);
        $wordpress_language = strtoupper($wordpress_language[0]);

        $response = wp_remote_get("https://storage.payment4.com/wp/languages.json");

        if ( ! is_wp_error($response) && $response['response']['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (is_array($data) && count($data) > 1) {
                if (in_array($wordpress_language, $data)) {
                    $language = $wordpress_language;
                }
            }
        }

        return $language;
    }

    /**
     * Redirect to payment gateway.
     */
    public function process_payment_request($order_id)
    {
        global $woocommerce;

        $this->order_id = $order_id;
        $this->session('set', 'order_id', $order_id);
        $order = $this->get_order($order_id);

        $result = $this->createPayment($order);

        $message = "";
        if ( ! empty($result["message"])) {
            $message = '<ul class="woocommerce-error" role="alert"><li>' . wp_kses_post($result["message"]) . '</li></ul><br/>';
        }

        if ( ! empty($result["redirect"])) {
            wp_redirect($result['redirect']);
            exit;
        }

        $form = '<form action="" method="POST" class="p4-payment-form" id="p4-payment-form">';
        $form .= '<a class="button cancel" href="' . esc_url($this->get_checkout_url()) . '">' . esc_html__(
                'Back',
                'payment4-woocommerce'
            ) . '</a>';
        $form .= '</form><br/>';

        echo wp_kses_post($message . $form);
    }

    /**
     * Create payment.
     */
    public function createPayment($order)
    {
        $currency = $this->getCurrency();
        if ($currency === false) {
            return __("The selected currency is not supported", 'payment4-woocommerce');
        }

        $amount       = $this->get_total();
        $order_number = $this->get_order_props('order_number');
        $description  = '#' . $order_number;
        $sandbox      = $this->option('sandbox') == '1';

        $request_data = array(
            "sandBox"        => $sandbox,
            "currency"       => $currency,
            "amount"         => $amount,
            "callbackUrl"    => $this->get_callback_url(),
            "callbackParams" => array(
                "wc-api"   => "WC_Payment4_callback",
                "wc_order" => $this->order_id,
            ),
            "webhookUrl"     => $this->get_webhook_url(),
            "webhookParams"  => array(
                "wc-api"   => "WC_Payment4_webhook",
                "wc_order" => $this->order_id,
            ),
            "language"       => $this->getLanguage(),
        );

        $headers = array(
            'Content-Type' => 'application/json',
            'x-api-key'    => $this->option('api_key'),
        );

        $args = array(
            'body'    => json_encode($request_data),
            'headers' => $headers,
            'timeout' => 15,
        );

        $url = $this->api_base_url . "/payment";

        $response = wp_remote_post($url, $args);

        $order = new WC_Order($this->order_id);

        if (is_wp_error($response)) {
            return ["message" => $response->get_error_message(), "redirect" => null];
        } else {
            $body = wp_remote_retrieve_body($response);
            $body = json_decode($body, true);
            if (isset($body["status"]) && $body['status'] === false) {
                return [
                    "message"  => $body['errorCode'] == 1001 ?
                        $body['message'] . ' ' . $body['errorCode'] :
                        $this->payment4_response_errors($body["errorCode"]) . ' ' . $body['errorCode'],
                    "redirect" => null,
                ];
            }

            $this->order_note($order, "created", ["uid" => $body["paymentUid"], "url" => $body["paymentUrl"]]);

            //$order->add_order_note("Payment UID :  " . $body["paymentUid"]. "<br/>Payment Url: ". $body["paymentUrl"], 1);

            return ["message" => null, "redirect" => $body["paymentUrl"]];
        }
    }

    /**
     * Process payment callback.
     */
    public function process_payment_verify()
    {
        $redirect = $this->get_checkout_url();

        $order_id = ! empty($this->get('wc_order')) ? intval($this->get('wc_order')) : $this->session(
            'get',
            'order_id'
        );

        if (empty($order_id)) {
            $this->set_message('failed', __('Order number not found.', 'payment4-woocommerce'), $redirect);
        }

        $order = $this->get_order($order_id);

        if ( ! $this->needs_payment($order)) {
            $this->set_message(
                'failed',
                __('The status of the transaction has already been determined.', 'payment4-woocommerce'),
                $redirect
            );
        }

        $this->order_id = $order_id;

        $result = $this->verifyPayment($order);


        $error            = '';
        $status           = ! empty($result['orderStatus']) ? $result['orderStatus'] : '';
        $paymentStatus    = ! empty($result['paymentStatus']) ? $result['paymentStatus'] : 'failed';
        $amountDifference = ! empty($result['amountDifference']) ? $result['amountDifference'] : '';
        $transaction_id   = ! empty($result['transaction_id']) ? $result['transaction_id'] : '';;


        if ($status == 'completed') {
            $redirect = $this->get_return_url($order);

            //            echo "<pre>" . print_r($order, true) . "</pre>";

            $order->payment_complete($transaction_id);
            //            echo "<hr>";

            $this->empty_cart();

            $shortcodes = $this->get_shortcodes_values();

            // $note       = [__('The transaction was successful. <br/> Payment UID : '. $transaction_id, 'payment4-woocommerce')];

            // foreach ($this->fields_shortcodes() as $key => $value) {
            // 	$key    = trim($key, '\{\}');
            // 	$note[] = "$value : {$shortcodes[$key]}";
            // }
            //$order->add_order_note(implode("<br>", $note), 1);

            $this->order_note(
                $order,
                "success",
                [
                    "uid"              => $transaction_id,
                    "url"              => "",
                    "status"           => $paymentStatus,
                    "amountDifference" => $amountDifference,
                ]
            );
        } else {
            $order->update_status('wc-failed');

            $error = ! empty($result['error']) ? $result['error'] : __(
                'An error occurred during payment.',
                'payment4-woocommerce'
            );
            $this->order_note(
                $order,
                "failed",
                [
                    "uid"              => $transaction_id,
                    "url"              => "",
                    "status"           => $paymentStatus,
                    "amountDifference" => $amountDifference,
                ]
            );
            //$order->add_order_note($error . "<br/>Payment UID :  " . $transaction_id, 1);
        }

        $this->set_message($paymentStatus, $error . "<br/>Payment UID  :  " . $transaction_id, $redirect);
        exit;
    }

    /**
     * Process payment webhook.
     */
    public function process_payment_webhook()
    {
        $this->process_payment_verify();
    }

    /**
     * Payment verify.
     */
    public function verifyPayment($order)
    {
        $sandbox        = $this->option('sandbox') == '1';
        $api_key        = $this->option('api_key');
        $transaction_id = $this->get('paymentUid');
        $amount         = $this->get_total();

        $error            = '';
        $orderStatus      = 'failed';
        $paymentStatus    = 'failed';
        $amountDifference = '';

        if ( ! empty($transaction_id)) {
            $request_data = array(
                "sandBox"    => $sandbox,
                "paymentUid" => $transaction_id,
                "amount"     => $amount,
                "currency"   => $this->getCurrency(),
            );


            $headers = array(
                'Content-Type' => 'application/json',
                'x-api-key'    => $this->option('api_key'),
            );

            $args = array(
                'body'    => json_encode($request_data),
                'method'  => 'PUT',
                'headers' => $headers,
                'timeout' => 15,
            );

            $url = $this->api_base_url . "/payment/verify";

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } else {
                $body             = wp_remote_retrieve_body($response);
                $body             = json_decode($body, true);
                $amountDifference = $body["amountDifference"] ?? "";

                if (isset($body["message"])) {
                    $error = $this->payment4_response_errors($body['errorCode']);
                }

                if (isset($body["verified"])) {
                    $payment_status = isset($body["paymentStatus"]) ? strtolower($body["paymentStatus"]) : 'failed';
                    if ($body["verified"]) {
                        $orderStatus   = 'completed';
                        $paymentStatus = $payment_status;
                        if (empty($error)) {
                            $error = $payment_status === "acceptable" ? __(
                                "Payment acceptable.",
                                'payment4-woocommerce'
                            ) : __("Payment successfull.", 'payment4-woocommerce');
                        }
                    } else {
                        $paymentStatus = $payment_status;
                        if (empty($error)) {
                            $error = $payment_status === "mismatched" ? __(
                                "Payment mismatched.",
                                'payment4-woocommerce'
                            ) : __("Payment failed.", 'payment4-woocommerce');
                        }
                    }
                }
            }
        } else {
            $error = __("Payment ID not found", 'payment4-woocommerce');
        }

        $this->set_shortcodes(['transaction_id' => $transaction_id]);

        return compact('orderStatus', 'paymentStatus', 'amountDifference', 'transaction_id', 'error');
    }


    /**
     * Get order id.
     *
     * @return int
     */
    protected function order_id($order)
    {
        if (is_numeric($order)) {
            $order_id = $order;
        } elseif (method_exists($order, 'get_id')) {
            $order_id = $order->get_id();
        } elseif ( ! ($order_id = absint(get_query_var('order-pay')))) {
            $order_id = $order->id;
        }

        if ( ! empty($order_id)) {
            $this->order_id = $order_id;
        }

        return $order_id;
    }

    /**
     * Get order by id.
     *
     * @return int
     */
    protected function get_order($order = 0)
    {
        if (empty($order)) {
            $order = $this->order_id;
        }

        if (empty($order)) {
            return (object)[];
        }

        if (is_numeric($order)) {
            $this->order_id = $order;

            $order = new WC_Order($order);
        }

        return $order;
    }

    /**
     * Get order proprties.
     *
     * @return string
     */
    protected function get_order_props($prop, $default = '')
    {
        if (empty($this->order_id)) {
            return '';
        }

        $order = $this->get_order();

        $method = 'get_' . $prop;

        if (method_exists($order, $method)) {
            $prop = $order->$method();
        } elseif ( ! empty($order->{$prop})) {
            $prop = $order->{$prop};
        } else {
            $prop = '';
        }

        return ! empty($prop) ? $prop : $default;
    }

    /**
     * Get order items.
     *
     * @return array
     */
    protected function get_order_items($product = false)
    {
        if (empty($this->order_id)) {
            return [];
        }

        $order = $this->get_order();
        $items = $order->get_items();

        if ($product) {
            $products = [];
            foreach ((array)$items as $item) {
                $products[] = $item['name'] . ' (' . $item['qty'] . ') ';
            }

            return implode(' - ', $products);
        }

        return $items;
    }

    /**
     * Get Woocommerce currency.
     *
     * @return string
     */
    protected function get_woo_currency()
    {
        $currency = get_woocommerce_currency();

        if ( ! empty($this->order_id)) {
            $order    = $this->get_order();
            $currency = method_exists($order, 'get_currency') ? $order->get_currency() : $order->get_order_currency();
        }

        $irt = ['irt', 'toman', 'tomaan', 'iran toman', 'iranian toman', 'تومان', 'تومان ایران'];
        if (in_array(strtolower($currency), $irt)) {
            $currency = 'IRT';
        }

        $irr = ['irr', 'rial', 'iran rial', 'iranian rial', 'ریال', 'ریال ایران'];
        if (in_array(strtolower($currency), $irr)) {
            $currency = 'IRR';
        }

        return $currency;
    }

    /**
     * Get total amount.
     *
     * @return int
     */
    protected function get_total($currency = 'IRR')
    {
        $currency = $this->get_woo_currency();

        if (empty($this->order_id)) {
            return 0;
        }

        $order = $this->get_order();

        if (method_exists($order, 'get_total')) {
            $price = floatval($order->get_total());
        } else {
            $price = floatval($order->order_total);
        }


        $currency = strtoupper($currency);

        if (in_array($currency, ['IRHR', 'IRHT'])) {
            $currency = str_ireplace('H', '', $currency);
        }

        if ($currency == 'IRR') {
            $price /= 10;
        }

        return $price;
    }

    /**
     * Check this order needs payment.
     *
     * @return bool
     */
    protected function needs_payment($order = 0)
    {
        if (empty($order) && empty($this->order_id)) {
            return true;
        }

        $order = $this->get_order($order);

        if (method_exists($order, 'needs_payment')) {
            return $order->needs_payment();
        }

        if (empty($this->order_id) && ! empty($order)) {
            $this->order_id = $this->order_id($order);
        }

        return ! in_array($this->get_order_props('status'), ['completed', 'processing']);
    }

    /**
     * Get callback url.
     *
     * @return string
     */
    protected function get_callback_url()
    {
        //        return WC()->api_request_url(get_class($this) . "_callback");
        return site_url();
    }

    /**
     * Get webhook url.
     *
     * @return string
     */
    protected function get_webhook_url()
    {
        //        return WC()->api_request_url(get_class($this) . "_webhook");
        return site_url();
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    protected function get_checkout_url()
    {
        if (function_exists('wc_get_checkout_url')) {
            return wc_get_checkout_url();
        } else {
            global $woocommerce;

            return $woocommerce->cart->get_checkout_url();
        }
    }

    /**
     * Empty cart
     */
    protected function empty_cart()
    {
        if (function_exists('wc_empty_cart')) {
            wc_empty_cart();
        } elseif (function_exists('WC') && ! empty(WC()->cart) && method_exists(WC()->cart, 'empty_cart')) {
            WC()->cart->empty_cart();
        } else {
            global $woocommerce;
            $woocommerce->cart->empty_cart();
        }
    }

    /**
     * Get shortcodes fields.
     *
     * @return array
     */
    protected function fields_shortcodes($fields = [])
    {
        return ! empty($fields['shortcodes']) && is_array($fields['shortcodes']) ? $fields['shortcodes'] : [];
    }

    /**
     * Get shortcodes values.
     *
     * @return array
     */
    protected function get_shortcodes_values()
    {
        $shortcodes = [];
        foreach ($this->fields_shortcodes() as $key => $value) {
            $key              = trim($key, '\{\}');
            $shortcodes[$key] = get_post_meta($this->order_id, '_' . $key, true);
        }

        return $shortcodes;
    }

    /**
     * Set shortcode.
     */
    protected function set_shortcodes($shortcodes)
    {
        $fields_shortcodes = $this->fields_shortcodes();

        foreach ($shortcodes as $key => $value) {
            if (is_numeric($key)) {
                $key = $fields_shortcodes[$key];
            }

            if ( ! empty($key) && ! is_array($key)) {
                $key = trim($key, '\{\}');
                update_post_meta($this->order_id, '_' . $key, $value);
            }
        }
    }

    /**
     * Set message.
     */
    protected function set_message($status, $error = '', $redirect = false)
    {
        if ( ! in_array($status, ['completed', 'success', 'accepted', 'mismatched', 'failed'])) {
            $status = 'failed';
        }

        wc_add_notice(
            $error,
            $status == 'completed' || $status == 'accepted' || $status == 'success' ? 'success' : 'error'
        );

        if ($redirect !== false) {
            wp_redirect($redirect);
        }

        return $message;
    }

    /*
    * Helpers
    * */

    protected function order_note($order, $type, $data)
    {
        $sandbox = $this->option('sandbox') == '1';

        $message = "";
        if ($type === "created") {
            $message .= "- Payment created <br/>";
        }
        if ($type === "success") {
            $message .= "- Payment success <br/>";
        }
        if ($type === "failed") {
            $message .= "- Payment failed <br/>";
        }

        $message .= "- Sandbox mode : " . ($sandbox ? "true" : "false") . "<br/>";

        if ( ! empty($data['status'])) {
            $message .= "- Status : {$data['status']} <br/>";
        }
        if ( ! empty($data['amountDifference'])) {
            $message .= "- Amount difference : {$data['amountDifference']} <br/>";
        }
        if ( ! empty($data['uid'])) {
            $message .= "- Payment UID : {$data['uid']} <br/>";
        }
        if ( ! empty($data['url'])) {
            $message .= "- Payment Url : {$data['url']} <br/>";
        }

        if ( ! empty($message)) {
            $order->add_order_note($message, 1);
        }
    }

    protected function option($name)
    {
        $general_options = get_option('payment4_gateway_pro_settings', []);

        // Prioritize settings from payment4_gateway_pro_settings
        if ($name === 'api_key' && isset($general_options['api_key'])) {
            return $general_options['api_key'];
        }
        if ($name === 'sandbox' && isset($general_options['sandbox_mode'])) {
            return !empty($general_options['sandbox_mode']) ? '1' : '0';
        }

        $option = '';
        if (method_exists($this, 'get_option')) {
            $option = $this->get_option($name);
        } elseif ( ! empty($this->settings[$name])) {
            $option = $this->settings[$name];
        }

        if (in_array(strtolower($option), ['yes', 'on', 'true'])) {
            $option = '1';
        }
        if (in_array(strtolower($option), ['no', 'off', 'false'])) {
            $option = false;
        }

        return $option;
    }

    protected function get($name, $default = '')
    {
        return !empty($_GET[$name]) ? sanitize_text_field(wp_unslash($_GET[$name])) : $default;
    }

    protected function post($name, $default = '')
    {
        return ! empty($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : $default;
    }

    protected function store_date($key, $value)
    {
        $this->session('set', $key, $value);
        update_post_meta($this->order_id, '_' . $this->id . '_' . $key, $value);
    }

    protected function get_stored($key)
    {
        $value = get_post_meta($this->order_id, '_' . $this->id . '_' . $key, true);

        return ! empty($value) ? $value : $this->session('get', $key);
    }

    protected function session($action, $name, $value = '')
    {
        global $woocommerce;

        $name = $this->id . '_' . $name;

        $wc_session = function_exists('WC') && ! empty(WC()->session);

        if ($action == 'set') {
            if ($wc_session && method_exists(WC()->session, 'set')) {
                WC()->session->set($name, $value);
            } else {
                $woocommerce->session->{$name} = $value;
            }
        } elseif ($action == 'get') {
            if ($wc_session && method_exists(WC()->session, 'get')) {
                $value = WC()->session->get($name);
                unset(WC()->session->{$name});
            } else {
                $value = $woocommerce->session->{$name};
                unset($woocommerce->session->{$name});
            }

            return $value;
        }

        return '';
    }

    protected function redirect($url)
    {
        if ( ! headers_sent()) {
            // Use esc_url_raw for URLs in header redirects
            header('Location: ' . esc_url_raw(trim($url)));
        } else {
            // Use esc_js and esc_url for safe JavaScript output
            $RedirectforPay = "<script type='text/javascript'>window.onload = function () { top.location.href = '" . esc_js(esc_url($url)) . "'; };</script>";
            // Output the script safely
            echo wp_kses($RedirectforPay, ['script' => ['type' => []]]);
        }
        exit;
    }

    protected function payment4_response_errors($errorCode)
    {
        $errors = [
            1001 => __('callbackUrl must be a URL address in production mode', 'payment4-woocommerce'),
            1002 => __('api key not send', 'payment4-woocommerce'),
            1003 => __('api key not found', 'payment4-woocommerce'),
            1004 => __('gateway not approved', 'payment4-woocommerce'),
            1006 => __('payment not found', 'payment4-woocommerce'),
            1010 => __('invalid amount', 'payment4-woocommerce'),
            1012 => __('invalid currency', 'payment4-woocommerce'),
            1005 => __('assets not found', 'payment4-woocommerce'),
            1011 => __('payment amount lower than minimum', 'payment4-woocommerce'),
            1013 => __('invalid language', 'payment4-woocommerce'),
        ];

        if (array_key_exists($errorCode, $errors)) {
            return $errors[$errorCode];
        }

        return __('An error occurred during payment.', 'payment4-woocommerce');
    }

    public function have_discount()
    {
        if (intval($this->option('discount_percent')) >= 1) {
            return true;
        }

        return false;
    }
}
