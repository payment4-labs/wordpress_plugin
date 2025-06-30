<?php

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

class Payment4Edd
{
    private $api_base_url = 'https://service.payment4.com/api/v1';
    private $api_key;
    private $sandbox_mode;

    public function __construct()
    {
        // Load settings
        $general_options    = get_option('payment4_gateway_pro_settings', []);
        $this->api_key      = isset($general_options['api_key']) ? sanitize_text_field(
            $general_options['api_key']
        ) : '';
        $this->sandbox_mode = ! empty($general_options['sandbox_mode']);

        $this->hooks();
    }

    public function hooks()
    {
        add_filter('edd_payment_gateways', [$this, 'register_payment4_gateway']);
        add_action('edd_payment4_cc_form', '__return_false');
        add_action('edd_gateway_payment4', [$this, 'process_payment']);
        add_action('edd_pre_process_purchase', [$this, 'is_payment4_configured'], 1);
        add_action('init', [$this, 'process_redirect']);
        add_action('p4_edd_payment4_redirect_verify', [$this, 'process_redirect_payment']);
        add_filter('edd_currencies', [$this, 'add_currencies']);
        add_filter('edd_accepted_payment_icons', [$this, 'payment_icon']);
        add_filter('edd_currency_symbol', [$this, 'extra_currency_symbol'], 10, 2);
    }

    /**
     * @param $gateways
     *
     * @return mixed
     */
    public function register_payment4_gateway($gateways)
    {
        // Check if EDD is enabled in plugin settings
        $plugin_options = get_option('payment4_gateway_pro_plugins', []);
        if ( ! empty($plugin_options['edd'])) {
            $gateways['payment4'] = array(
                'admin_label'    => __('Payment4', 'payment4-gateway-pro'),
                'checkout_label' => __('Payment4 (Pay with Crypto)', 'payment4-gateway-pro'),
            );
        }

        return $gateways;
    }

    public function is_payment4_configured()
    {
        $is_enabled     = edd_is_gateway_active('payment4');
        $chosen_gateway = edd_get_chosen_gateway();

        if ('payment4' === $chosen_gateway && ! $is_enabled) {
            edd_set_error(
                'payment4_gateway_not_configured',
                __('Payment4 payment gateway is not setup.', 'payment4-gateway-pro')
            );
        }

        if ('payment4' === $chosen_gateway && ! $this->get_supported_currencies()) {
            edd_set_error(
                'payment4_gateway_invalid_currency',
                __(
                    'Currency not supported by Payment4.',
                    'payment4-gateway-pro'
                )
            );
        }
    }

    /**
     * @param $purchase_data
     */
    public function process_payment($purchase_data)
    {
        $payment_data = array(
            'price'        => $purchase_data['price'],
            'date'         => $purchase_data['date'],
            'user_email'   => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency'     => edd_get_currency(),
            'downloads'    => $purchase_data['downloads'],
            'cart_details' => $purchase_data['cart_details'],
            'user_info'    => $purchase_data['user_info'],
            'status'       => 'pending',
            'gateway'      => 'payment4',
        );

        $payment_id = edd_insert_payment($payment_data);

        if (false === $payment_id) {
            edd_record_gateway_error(
                'Payment Error',
                sprintf(
                    'Payment creation failed before sending buyer to Payment4. Payment data: %s',
                    wp_json_encode($payment_data)
                )
            );

            edd_send_back_to_checkout('?payment-mode=payment4');
        } else {
            $payment4_data  = [];
            $transaction_id = 'EDD-' . $payment_id . '-' . uniqid();

            $payment4_data['amount']    = $purchase_data['price'];
            $payment4_data['reference'] = $transaction_id;

            edd_set_payment_transaction_id($payment_id, $transaction_id);

            $body = $this->get_payment_link($payment4_data);

            if ( ! empty($body['paymentUid'])) {
                // Redirect to Payment4 payment page
                wp_redirect(esc_url_raw($body['paymentUrl']));
                exit;
            }

            if (isset($body['status']) && ! $body['status']) {
                $error_message = isset($body['errorCode']) ? $this->get_error_message($body['errorCode']) : __(
                    'Payment creation failed.',
                    'payment4-gateway-pro'
                );
                edd_set_error('payment4_error', $error_message);
            }
            edd_send_back_to_checkout('?payment-mode=payment4');
        }
    }

    /**
     * @param $payment4_data
     *
     * @return mixed
     */
    public function get_payment_link($payment4_data)
    {
        $amount = $payment4_data['amount'];
        if (edd_get_currency() == 'RIAL') {
            $amount /= 10;
        }

        // Process return_url to extract base URL and query parameters
        $callback_url      = add_query_arg('edd-listener', 'payment4', home_url('index.php'));
        $parsed_url        = parse_url($callback_url);
        $base_callback_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if ( ! empty($parsed_url['path'])) {
            $base_callback_url .= $parsed_url['path'];
        }

        $callback_params = [
            'trxref' => $payment4_data['reference'],
        ];

        // If return_url has query parameters, move them to callback_params
        if ( ! empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            $callback_params = array_merge($callback_params, $query_params);
        }

        // Use the same logic for webhook_url
        $base_webhook_url   = home_url('/index.php'); // Clean base URL
        $parsed_webhook_url = parse_url($base_webhook_url);
        $base_webhook_url   = $parsed_webhook_url['scheme'] . '://' . $parsed_webhook_url['host'] . $parsed_webhook_url['path'];

        $webhook_params = [
            'trxref' => $payment4_data['reference'],
        ];

        // If webhook_url has query parameters, move them to webhook_params
        if ( ! empty($parsed_webhook_url['query'])) {
            parse_str($parsed_webhook_url['query'], $webhook_query_params);
            $webhook_params = array_merge($webhook_params, $webhook_query_params);
        }

        $request_data = [
            'sandBox'        => $this->sandbox_mode,
            'currency'       => $this->get_supported_currencies(),
            'amount'         => $amount,
            'callbackUrl'    => $base_callback_url,
            'callbackParams' => $callback_params,
//            'webhookUrl' => $base_webhook_url,
//            'webhookParams' => $webhook_params,
            'language'       => $this->get_language(),
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key'    => $this->api_key,
        ];

        $args = [
            'body'    => json_encode($request_data),
            'headers' => $headers,
            'timeout' => 15,
        ];

        $url      = $this->api_base_url . '/payment';
        $response = wp_remote_post($url, $args);

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function process_redirect()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset($_GET['edd-listener'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ('payment4' === strtolower(trim(sanitize_text_field(wp_unslash($_GET['edd-listener']))))) {
            do_action('p4_edd_payment4_redirect_verify');
        }
    }

    public function process_redirect_payment()
    {
        if (isset($_REQUEST['trxref'])) { // phpcs:ignore

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $transaction_id = sanitize_text_field(wp_unslash($_REQUEST['trxref']));

            $the_payment_id = edd_get_purchase_id_by_transaction_id($transaction_id);

            $payment_status = edd_get_payment_status($the_payment_id);

            if ($the_payment_id && in_array($payment_status, ['publish', 'complete'], true)) {
                edd_empty_cart();

                edd_send_to_success_page();
            }

            $order_info = explode('-', $transaction_id);
            $payment_id = $order_info[1];
            $amount     = edd_get_payment_amount($payment_id);
            $currency   = edd_get_payment_currency_code($payment_id);

            if ($currency == "RIAL") {
                $currency = 'IRT';
                $amount   /= 10;
            }
            $result = $this->verify_transaction($amount, $currency);

            $status                  = ! empty($result['orderStatus']) ? $result['orderStatus'] : 'failed';
            $payment_status          = ! empty($result['paymentStatus']) ? $result['paymentStatus'] : 'failed';
            $amount_difference       = ! empty($result['amountDifference']) ? $result['amountDifference'] : '0';
            $payment4_transaction_id = ! empty($result['transaction_id']) ? $result['transaction_id'] : '';

            $payment          = new \EDD_Payment($payment_id);
            if ($payment_id && ($status === 'completed')) {
                $note = sprintf(
                    // translators: 1: Payment UID, 2: Payment status, 3: Amount difference
                    __('Payment successful. Payment UID: %1$s, Status: %2$s, Amount Difference: %3$s', 'payment4-gateway-pro'),
                    $payment4_transaction_id,
                    $payment_status,
                    $amount_difference
                );

                $payment->status = 'publish';


                $payment->add_note($note);

                $payment->save();

                edd_empty_cart();

                edd_send_to_success_page();
            } else {
                $note = sprintf(
                    // translators: 1: Payment UID, 2: Payment status, 3: Amount difference, 4: Error message
                    __('Payment failed. Payment UID: %1$s, Status: %2$s, Amount Difference: %3$s, Error: %4$s', 'payment4-gateway-pro'),
                    $payment4_transaction_id,
                    $payment_status,
                    $amount_difference,
                    $result['error']
                );
                $payment->status = 'failed';


                $payment->add_note($note);

                $payment->save();

                edd_set_error('failed_payment', $note);

                edd_send_back_to_checkout('?payment-mode=payment4');
            }
        }
    }

    /**
     * @param $amount
     * @param $currency
     *
     * @return array
     */
    public function verify_transaction($amount, $currency): array
    {
        $transaction_id = ! empty($_GET['paymentUid']) ? sanitize_text_field($_GET['paymentUid']) : '';

        $error            = '';
        $orderStatus      = 'failed';
        $paymentStatus    = 'failed';
        $amountDifference = '';

        if ( ! empty($transaction_id)) {
            $request_data = [
                'paymentUid' => $transaction_id,
                'amount'     => $amount,
                'currency'   => $currency,
            ];

            $headers = [
                'Content-Type' => 'application/json',
                'x-api-key'    => $this->api_key,
            ];

            $args = [
                'body'    => json_encode($request_data),
                'method'  => 'PUT',
                'headers' => $headers,
                'timeout' => 15,
            ];

            $url      = $this->api_base_url . '/payment/verify';
            $response = wp_remote_request($url, $args);


            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } else {
                $body = wp_remote_retrieve_body($response);
                $body = json_decode($body, true);

                $amountDifference = $body['amountDifference'] ?? '';

                if (isset($body['message'])) {
                    $error = $body['message'];
                }

                $paymentStatus = isset($body['paymentStatus']) ? strtolower($body['paymentStatus']) : 'failed';
                if (isset($body['verified'])) {
                    if ($body['verified']) {
                        $orderStatus = 'completed';
                        $error       = $paymentStatus === 'acceptable' ? __('Payment acceptable.', 'rcp-payment4') : __(
                            'Payment successful.',
                            'rcp-payment4'
                        );
                    } else {
                        $error = $paymentStatus === 'mismatched' ? __('Payment mismatched.', 'rcp-payment4') : __(
                            'Payment failed.',
                            'rcp-payment4'
                        );
                    }
                }
            }
        } else {
            $error = __('Payment ID not found', 'rcp-payment4');
        }

        return compact('orderStatus', 'paymentStatus', 'amountDifference', 'transaction_id', 'error');
    }

    /**
     * Get supported currency
     */
    private function get_supported_currencies(): bool|string
    {
        $allowed      = ['USD', 'IRT', 'RIAL'];
        $edd_currency = edd_get_currency();

        $response = wp_remote_get('https://storage.payment4.com/wp/currencies.json');
        if ( ! is_wp_error($response) && $response['response']['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (is_array($data) && count($data) > 1) {
                $allowed = $data;
            }
        }

        if ($edd_currency === 'RIAL') {
            $edd_currency = 'IRT';
        }

        return in_array($edd_currency, $allowed) ? $edd_currency : false;
    }

    /**
     * Get language
     */
    private function get_language()
    {
        $language           = 'EN';
        $wordpress_language = get_locale();
        $wordpress_language = explode('_', $wordpress_language);
        $wordpress_language = strtoupper($wordpress_language[0]);

        $response = wp_remote_get('https://storage.payment4.com/wp/languages.json');
        if ( ! is_wp_error($response) && $response['response']['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (is_array($data) && count($data) > 1 && in_array($wordpress_language, $data)) {
                $language = $wordpress_language;
            }
        }

        return $language;
    }

    /**
     * Get error message for API error codes
     */
    private function get_error_message($error_code)
    {
        $errors = [
            1001 => __('Callback or Webhook URL must be a valid URL in production mode.', 'rcp-payment4'),
            1002 => __('API key not provided.', 'rcp-payment4'),
            1003 => __('API key not found.', 'rcp-payment4'),
            1004 => __('Gateway not approved.', 'rcp-payment4'),
            1006 => __('Payment not found.', 'rcp-payment4'),
            1010 => __('Invalid amount.', 'rcp-payment4'),
            1012 => __('Invalid currency.', 'rcp-payment4'),
            1005 => __('Assets not found.', 'rcp-payment4'),
            1011 => __('Payment amount lower than minimum.', 'rcp-payment4'),
            1013 => __('Invalid language.', 'rcp-payment4'),
        ];

        return $errors[$error_code] ?? __(
            'An error occurred during payment.',
            'rcp-payment4'
        );
    }

    /**
     * @param $currencies
     *
     * @return array
     */
    public function add_currencies($currencies)
    {
        $currencies['IRT'] = __('Iranian Toman (IRT)', 'payment4-gateway-pro');

        return $currencies;
    }

    /**
     * @param $icons
     *
     * @return mixed
     */
    public function payment_icon($icons)
    {
        $icons[PAYMENT4_PRO_URL . 'assets/img/small-square-logo.png'] = __('Payment4', 'payment4-gateway-pro');

        return $icons;
    }

    /**
     * @param $symbol
     * @param $currency
     *
     * @return mixed|string
     */
    public function extra_currency_symbol($symbol, $currency)
    {
        switch ($currency) {
            case 'IRT':
                $symbol = 'تومان';
                break;

            case 'RIAL':
                $symbol = 'ریال';
                break;
        }

        return $symbol;
    }

}

new Payment4Edd();