<?php

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Gateway class
 */
class Payment4CPG_WC_Gateway extends WC_Payment_Gateway
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
        $this->id                 = 'payment4cpg_wc';
        $this->method_title       = __('Payment4', 'payment4-crypto-payment-gateway');
        $this->method_description = __('Gateway settings for WooCommerce', 'payment4-crypto-payment-gateway');
        $this->icon               = trailingslashit(WP_PLUGIN_URL) . plugin_basename(
                dirname(__FILE__)
            ) . '/assets/logo.png';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->settings['title'] . " " . __('( Pay with Crypto )', 'payment4-crypto-payment-gateway');
        $img         = ' <img src="' . $this->icon . '" style="width: 90px;" >';


        $this->method_description .= $img;

        $this->has_fields = false;

        $this->description = $this->settings['description'];

        // discount
        if ($this->have_discount()) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_payment4_refresh_script'));
            add_action('woocommerce_cart_calculate_fees', array($this, 'add_payment4_discount_to_cart'), 10, 1);
            add_action('woocommerce_checkout_update_order_review', array($this, 'update_checkout_discounts'));
            $discount_text     = sprintf(
            /* translators: %s is replaced with "string" */
                __('Get %s Percent Discount for paying by Payment4', 'payment4-crypto-payment-gateway'),
                $this->option('discount_percent')
            );
            $this->description .= ". " . $discount_text;
        }

        if (version_compare(WC()->version, '2.0.0', '>=')) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [
                $this,
                'process_admin_options',
            ]);
        } else {
            add_action('woocommerce_update_options_payment_gateways', [$this, 'process_admin_options']);
        }


        add_action('woocommerce_receipt_' . $this->id, [$this, 'process_p4_payment_request']);
        add_action('woocommerce_api_' . strtolower(get_class($this)) . "_callback", [
            $this,
            'process_payment_verify',
        ]);
        add_action('woocommerce_api_' . strtolower(get_class($this)) . "_webhook", [
            $this,
            'process_payment_webhook',
        ]);

        add_filter('woocommerce_thankyou_order_received_text', [$this, 'payment4_custom_order_received_text'], 10, 2);
        add_action('woocommerce_before_thankyou', [$this, 'payment4_custom_thankyou_message'], 5);
    }

    function payment4_custom_order_received_text($text, $order)
    {
        if ( ! $order || $this->id !== $order->get_payment_method()) {
            return $text;
        }

        $status = $order->get_status();

        if ($status === 'p4-mismatch') {
            return __(
                '⛔ Your order was not completed due to a payment mismatch. Please contact support.',
                'payment4-crypto-payment-gateway'
            );
        }

        if ($status === 'p4-acceptable') {
            return __(
                '⚠️ Your order was received, but the payment amount was not exact. Your order is under review.',
                'payment4-crypto-payment-gateway'
            );
        }

        return $text;
    }

    function payment4_custom_thankyou_message($order_id)
    {
        $order = wc_get_order($order_id);
        if ( ! $order) {
            return;
        }

        $status = $order->get_status();

        if ($status === 'p4-mismatch') {
            echo '<div class="woocommerce-message">';
            echo wp_kses_post(
                __(
                    '⛔ Your order was not completed due to a payment mismatch.<br/>Please contact support.',
                    'payment4-crypto-payment-gateway'
                )
            );
            echo '</div>';
        }

        if ($status === 'p4-acceptable') {
            echo '<div class="woocommerce-message" >';
            echo wp_kses_post(
                __(
                    '⚠️ Your order was received, but the payment amount was not exact.<br/>Please wait while we review it.',
                    'payment4-crypto-payment-gateway'
                )
            );
            echo '</div>';
        }
    }

    public function add_payment4_discount_to_cart($cart)
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }

        if (is_cart() && ! defined('DOING_AJAX')) {
            return;
        }

        $is_payment4_selected  = false;
        $chosen_payment_method = WC()->session->get('chosen_payment_method');

        if ($chosen_payment_method === $this->id) {
            $is_payment4_selected = true;
        }

        if (isset($_POST['payment_method']) && $_POST['payment_method'] === $this->id) {
            $is_payment4_selected = true;
        }

        if (defined('WC_DOING_AJAX') && WC_DOING_AJAX) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($request_uri, '/wc/store/') !== false) {
                global $wp;
                if (isset($wp->query_vars['rest_route']) && strpos(
                        $wp->query_vars['rest_route'],
                        '/wc/store/'
                    ) !== false
                ) {
                    $input = file_get_contents('php://input');
                    if ($input) {
                        $data = json_decode($input, true);
                        if (isset($data['payment_method']) && $data['payment_method'] === $this->id) {
                            $is_payment4_selected = true;
                        }
                    }
                }
            }
        }

        if ( ! $is_payment4_selected && empty($chosen_payment_method) && is_checkout()) {
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            $gateway_keys       = array_keys($available_gateways);
            if ( ! empty($gateway_keys) && $gateway_keys[0] === $this->id) {
                $is_payment4_selected = true;
            }
        }

        if ($is_payment4_selected) {
            $discount_percent = floatval($this->get_option('discount_percent'));

            if ($discount_percent <= 0) {
                return;
            }

            $fees            = $cart->get_fees();
            $discount_exists = false;
            $discount_name   = sprintf(
                /* translators: %1$s: Discount percentage */
                __('Payment4 Crypto Discount (%1$s%%)', 'payment4-crypto-payment-gateway'),
                $discount_percent
            );
            foreach ($fees as $fee) {
                if ($fee->name === $discount_name) {
                    $discount_exists = true;
                    break;
                }
            }

            if ( ! $discount_exists) {
                $cart_total      = $cart->get_subtotal(); // Use subtotal to avoid double counting other fees
                $discount_amount = ($cart_total * $discount_percent) / 100;

                if ($discount_amount > 0) {
                    $cart->add_fee($discount_name, -$discount_amount, false);
                }
            }
        } else {
            $this->remove_payment4_discounts($cart);
        }
    }

    public function remove_payment4_discounts($cart)
    {
        $fees                 = $cart->get_fees();
        $discount_removed     = false;
        $discount_percent     = floatval($this->get_option('discount_percent'));
        $discount_name_prefix = sprintf(
            /* translators: %s: Discount percentage */
            __('Payment4 Crypto Discount (%s%%)', 'payment4-crypto-payment-gateway'),
            $discount_percent
        );

        foreach ($fees as $fee_key => $fee) {
            if ($fee->name === $discount_name_prefix) {
                unset($cart->fees[$fee_key]);
                $discount_removed = true;
            }
        }

        if ($discount_removed) {
            $cart->fees = array_values($cart->fees);
        }
    }

    public function update_checkout_discounts()
    {
        $chosen_payment_method = WC()->session->get('chosen_payment_method');

        if ($chosen_payment_method !== $this->id && WC()->cart) {
            $this->remove_payment4_discounts(WC()->cart);
        }

        WC()->cart->calculate_fees();
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

            // Localize script to pass Ajax URL and nonce
            wp_localize_script(
                'payment4_custom',
                'payment4Ajax',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'payment4-checkout-nonce' ),
                )
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

        $shortcodes        = '<br>' . implode(' - ', $shortcodes);
        $fields            = [
            'enabled'            => [
                'title'       => __('Enable/Disable', 'payment4-crypto-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Activation of the payment gateway', 'payment4-crypto-payment-gateway'),
                'description' => __('Tick the checkbox to activate', 'payment4-crypto-payment-gateway'),
                'default'     => 'no',
                'desc_tip'    => true,
            ],
            // Optionally display API Key and Sandbox Mode as read-only
            'api_key'            => [
                'title'             => __('API Key', 'payment4-crypto-payment-gateway'),
                'type'              => 'text',
                'description'       => __('Managed in Payment4 General Settings', 'payment4-crypto-payment-gateway'),
                'default'           => isset($general_options['api_key']) ? esc_attr($general_options['api_key']) : '',
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip'          => true,
            ],
            'sandbox'            => [
                'title'             => __('Sandbox Mode', 'payment4-crypto-payment-gateway'),
                'type'              => 'checkbox',
                'label'             => __('Managed in Payment4 General Settings', 'payment4-crypto-payment-gateway'),
                'description'       => __('Enable/Disable Sandbox mode', 'payment4-crypto-payment-gateway'),
                'default'           => ! empty($general_options['sandbox_mode']) ? 'yes' : 'no',
                'custom_attributes' => ['disabled' => 'disabled'],
                'desc_tip'          => true,
            ],
            'title'              => [
                'title'       => __('Title', 'payment4-crypto-payment-gateway'),
                'type'        => 'text',
                'description' => __('Gateway title', 'payment4-crypto-payment-gateway'),
                'default'     => __('Payment4', 'payment4-crypto-payment-gateway'),
                'desc_tip'    => true,
            ],
            'description'        => [
                'title'       => __('Description', 'payment4-crypto-payment-gateway'),
                'type'        => 'text',
                'description' => __('Gateway description', 'payment4-crypto-payment-gateway'),
                'default'     => __('Accepting Crypto Payments', 'payment4-crypto-payment-gateway'),
                'desc_tip'    => true,
            ],
            'discount_percent'   => [
                'title'       => __('Discount Percent', 'payment4-crypto-payment-gateway'),
                'type'        => 'text',
                'description' => __('Set 0 for no Discount', 'payment4-crypto-payment-gateway'),
                'default'     => '0.0',
            ],
            'completed_massage'  => [
                'title'       => __('Success payment message', 'payment4-crypto-payment-gateway'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Successful payment.', 'payment4-crypto-payment-gateway'),
            ],
            'failed_massage'     => [
                'title'       => __('Failed payment message', 'payment4-crypto-payment-gateway'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Transaction Failed.', 'payment4-crypto-payment-gateway'),
            ],
            'accepted_massage'   => [
                'title'       => __('Acceptable payment message', 'payment4-crypto-payment-gateway'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Payment accepted.', 'payment4-crypto-payment-gateway'),
            ],
            'mismatched_massage' => [
                'title'       => __('Mismatched payment message', 'payment4-crypto-payment-gateway'),
                'type'        => 'textarea',
                'description' => $shortcodes,
                'default'     => __('Payment Mismatched.', 'payment4-crypto-payment-gateway'),
            ],
        ];
        $this->form_fields = apply_filters('payment4cpg_wc_gateway_config', $fields);
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
    public function process_p4_payment_request($order_id)
    {
        $this->order_id = $order_id;

        /** @var WC_Order $order */
        $order = wc_get_order($order_id);

        $result = $this->createPayment($order);

        $message = "";
        if ( ! empty($result["message"])) {
            $message = '<ul class="woocommerce-error" role="alert"><li>' . wp_kses_post(
                    $result["message"]
                ) . '</li></ul><br/>';
        }

        if ( ! empty($result["redirect"])) {
            wp_redirect($result['redirect']);
            exit;
        }

        $form = '<form action="" method="POST" class="p4-payment-form" id="p4-payment-form">';
        $form .= '<a class="button cancel" href="' . esc_url($this->get_checkout_url()) . '">' . esc_html__(
                'Back',
                'payment4-crypto-payment-gateway'
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
            return __("The selected currency is not supported", 'payment4-crypto-payment-gateway');
        }

        $amount   = $this->get_total();
        $sandbox  = $this->option('sandbox') == '1';
        $callback = $this->get_callback_url_params();
        $webHook  = $this->get_callback_url_params(false);

        $request_data = array(
            "sandBox"        => $sandbox,
            "currency"       => $currency,
            "amount"         => $amount,
            "callbackUrl"    => $callback[0],
            "callbackParams" => $callback[1],
            "webhookUrl"     => $webHook[0],
            "webhookParams"  => $webHook[1],
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

            return ["message" => null, "redirect" => $body["paymentUrl"]];
        }
    }


    public function process_payment_verify()
    {
        $redirect = $this->get_checkout_url();

        $order_id = ! empty($this->get('wc_order')) ? intval($this->get('wc_order')) : $this->session(
            'get',
            'order_id'
        );

        if (empty($order_id)) {
            $this->set_message('failed', __('Order number not found.', 'payment4-crypto-payment-gateway'), $redirect);
        }

        $order = $this->get_order($order_id);

        if ( ! $this->needs_payment($order)) {
            $this->set_message(
                'failed',
                __('The status of the transaction has already been determined.', 'payment4-crypto-payment-gateway'),
                $redirect
            );
        }

        $this->order_id = $order_id;

        $result = $this->verifyPayment($order);

        $error            = '';
        $status           = $result['orderStatus'] ?? '';
        $paymentStatus    = $result['paymentStatus'] ?? 'failed';
        $amountDifference = $result['amountDifference'] ?? '';
        $transaction_id   = $result['transaction_id'] ?? '';

        // پیش‌فرض
        $redirect = $this->get_return_url($order);

        // هندل کردن وضعیت‌ها
        switch ($paymentStatus) {
            case 'success':
                if ($order->get_status() !== 'completed') {
                    $order->payment_complete($transaction_id); // خودش هوک رو اجرا می‌کنه
                }
                $this->empty_cart(); // فقط در حالت موفق
                break;

            case 'acceptable':
                if ($order->get_status() !== 'p4-acceptable') {
                    $this->handle_payment4_status($order, 'acceptable', $transaction_id);
                }
                break;

            case 'mismatch':
                if ($order->get_status() !== 'p4-mismatch') {
                    $this->handle_payment4_status($order, 'mismatch', $transaction_id);
                }
                break;

            default:
                // failed یا هرچیزی که غیر از موارد بالا باشه
                if ($order->get_status() !== 'failed') {
                    $order->update_status('wc-failed', __('Payment failed.', 'payment4-crypto-payment-gateway'));
                }

                $this->order_note($order, 'failed', [
                    "uid"              => $transaction_id,
                    "status"           => $paymentStatus,
                    "amountDifference" => $amountDifference,
                ]);
                break;
        }

        $this->order_note($order, $paymentStatus, [
            "uid"              => $transaction_id,
            "status"           => $paymentStatus,
            "amountDifference" => $amountDifference,
        ]);
        $this->set_message($paymentStatus, $result['error'] . "<br/>Payment UID  :  " . $transaction_id, $redirect);
    }

    public function handle_payment4_status(WC_Order $order, string $type, string $transaction_id = '')
    {
        if ( ! $order->get_id()) {
            return false;
        }

        try {
            if (WC()->session) {
                WC()->session->set('order_awaiting_payment', false);
            }

            if ( ! empty($transaction_id)) {
                $order->set_transaction_id($transaction_id);
            }

            switch ($type) {
                case 'acceptable':
                    do_action('woocommerce_pre_payment_complete', $order->get_id(), $transaction_id);

                    if ( ! $order->get_date_paid('edit')) {
                        $order->set_date_paid(time());
                    }

                    $order->set_status('p4-acceptable');
                    wc_reduce_stock_levels($order->get_id());

                    do_action('woocommerce_order_status_payment4-acceptable', $order->get_id(), $order);
                    do_action('woocommerce_payment_complete', $order->get_id(), $transaction_id);

                    break;

                case 'mismatch':
                    $order->add_order_note(__('Payment mismatch detected by Payment4 plugin.', 'payment4-crypto-payment-gateway'));
                    $order->set_status('p4-mismatch');
                    break;

                default:
                    $order->add_order_note(__('Unknown payment4 status handled.', 'payment4-crypto-payment-gateway'));

                    return false;
            }

            $order->save();
        } catch (Exception $e) {
            $logger = wc_get_logger();
            $logger->error(
                sprintf('Error handling payment4 status "%s" for order #%d', $type, $order->get_id()),
                array(
                    'order' => $order,
                    'error' => $e,
                )
            );

            $order->add_order_note(
                /* translators: %s: Payment status type */
                sprintf(__('Handling payment4 status "%s" failed.', 'payment4-crypto-payment-gateway'), $type) . ' ' . $e->getMessage()
            );

            return false;
        }

        return true;
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
        $transaction_id = $this->get('paymentUid');
        $amount         = $this->get_total();

        $error            = '';
        $orderStatus      = 'failed';
        $paymentStatus    = 'failed';
        $amountDifference = '';

        if ( ! empty($transaction_id)) {
            $request_data = array(
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
                                'payment4-crypto-payment-gateway'
                            ) : __("Payment successfull.", 'payment4-crypto-payment-gateway');
                        }
                    } else {
                        $paymentStatus = $payment_status;
                        if (empty($error)) {
                            $error = $payment_status === "mismatch" ? __(
                                "Payment mismatched.",
                                'payment4-crypto-payment-gateway'
                            ) : __("Payment failed.", 'payment4-crypto-payment-gateway');
                        }
                    }
                }
            }
        } else {
            $error = __("Payment ID not found", 'payment4-crypto-payment-gateway');
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
     * Get callback or webhook url & params.
     *
     * @return array
     */
    protected function get_callback_url_params($isCallback = true)
    {
        $callbackOrWebhook = $isCallback ? "_callback" : "_webhook";
        // Process return_url to extract base URL and query parameters
        $parsed_url        = wp_parse_url(
            WC()->api_request_url(
                get_class($this)
                . $callbackOrWebhook
            )
        );
        $base_callback_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if ( ! empty($parsed_url['path'])) {
            $base_callback_url .= $parsed_url['path'];
        }

        $callback_params = [
            "wc_order" => $this->order_id,
        ];

        // If return_url has query parameters, move them to callback_params
        if ( ! empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            $callback_params = array_merge($callback_params, $query_params);
        }

        return [$base_callback_url, $callback_params];
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
        if ( ! in_array($status, ['success', 'acceptable', 'mismatch', 'failed'])) {
            $status = 'failed';
        }

        wc_add_notice(
            $error,
            $status == 'success' || $status == 'acceptable' ? 'success' : 'error'
        );

        if ($redirect !== false) {
            wp_redirect($redirect);
        }

        return;
    }

    /*
    * Helpers
    * */

    protected function order_note($order, $type, $data)
    {
        $sandbox = $this->option('sandbox') == '1';

        $message = "Payment {$type}" . "<br/>";

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
            $existing_notes = wc_get_order_notes(['order_id' => $order->get_id()]);
            foreach ($existing_notes as $note) {
                if (str_contains($note->content, $data['uid']) && str_contains($note->content, $message)) {
                    return; // already added
                }
            }
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
            return ! empty($general_options['sandbox_mode']) ? '1' : '0';
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
        return ! empty($_GET[$name]) ? sanitize_text_field(wp_unslash($_GET[$name])) : $default;
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
            // Use wp_safe_redirect for proper WordPress redirect
            wp_safe_redirect(trim($url));
        } else {
            // Use wp_print_inline_script_tag for safe JavaScript output (WordPress 5.7+)
            $redirect_script = "window.onload = function () { top.location.href = '" . esc_js(
                    esc_url($url)
                ) . "'; };";
            wp_print_inline_script_tag($redirect_script);
        }
        exit;
    }

    protected function payment4_response_errors($errorCode)
    {
        $errors = [
            1001 => __('callbackUrl must be a URL address in production mode', 'payment4-crypto-payment-gateway'),
            1002 => __('api key not send', 'payment4-crypto-payment-gateway'),
            1003 => __('api key not found', 'payment4-crypto-payment-gateway'),
            1004 => __('gateway not approved', 'payment4-crypto-payment-gateway'),
            1006 => __('payment not found', 'payment4-crypto-payment-gateway'),
            1010 => __('invalid amount', 'payment4-crypto-payment-gateway'),
            1012 => __('invalid currency', 'payment4-crypto-payment-gateway'),
            1005 => __('assets not found', 'payment4-crypto-payment-gateway'),
            1011 => __('payment amount lower than minimum', 'payment4-crypto-payment-gateway'),
            1013 => __('invalid language', 'payment4-crypto-payment-gateway'),
        ];

        if (array_key_exists($errorCode, $errors)) {
            return $errors[$errorCode];
        }

        return __('An error occurred during payment.', 'payment4-crypto-payment-gateway');
    }

    public function have_discount()
    {
        if (intval($this->option('discount_percent')) >= 1) {
            return true;
        }

        return false;
    }
}