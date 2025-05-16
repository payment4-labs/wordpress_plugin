<?php

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

// Register the Payment4 gateway
add_action('rcp_payment_gateways', 'payment4_rcp_register_gateway');
function payment4_rcp_register_gateway($gateways)
{
    // Check if RCP is enabled in plugin settings
    $plugin_options = get_option('payment4_gateway_pro_plugins', []);
    if ( ! empty($plugin_options['rcp'])) {
        $gateways['payment4'] = [
            'label'       => __('Payment4 (Pay with Crypto)', 'rcp-payment4'),
            'admin_label' => __('Payment4', 'rcp-payment4'),
            'class'       => 'RCP_Payment_Gateway_Payment4',
        ];
    }

    return $gateways;
}

// Load translations
add_action('plugins_loaded', 'payment4_rcp_load_textdomain');
function payment4_rcp_load_textdomain()
{
    load_plugin_textdomain('rcp-payment4', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

/**
 * Payment4 Payment Gateway for Restrict Content Pro
 */
class RCP_Payment_Gateway_Payment4 extends RCP_Payment_Gateway
{
    private $api_base_url = 'https://service.payment4.com/api/v1';
    private $api_key;
    private $sandbox_mode;

    public function __construct($subscription_data = array())
    {
        parent::__construct($subscription_data);
    }
    /**
     * Initialize gateway settings (optional, for future use)
     */
    public function init()
    {
        // Declare supported features
        $this->supports[] = 'one-time';

        // Load settings
        $general_options = get_option('payment4_gateway_pro_settings', []);
        $this->api_key = isset($general_options['api_key']) ? sanitize_text_field($general_options['api_key']) : '';
        $this->sandbox_mode = !empty($general_options['sandbox_mode']);

        // Set test mode
        $this->test_mode = $this->sandbox_mode || rcp_is_sandbox();
    }

    /**
     * Process registration (create payment)
     */
    public function process_signup()
    {
        global $rcp_payments_db;

        if (empty($this->api_key)) {
            $this->add_error('missing_api_key', __('Payment4 API Key is missing.', 'rcp-payment4'));

            return;
        }

        $currency = $this->get_currency();
        if ($currency === false) {
            $this->add_error(
                'invalid_currency',
                __('The selected currency is not supported by Payment4.', 'rcp-payment4')
            );

            return;
        }

        // Ensure payment object exists
        if (empty($this->payment) || empty($this->payment->id)) {
            $this->add_error('payment_missing', __('Payment record not found.', 'rcp-payment4'));

            return;
        }

        $amount          = $this->initial_amount; // Includes signup fees, discounts, etc.
        $subscription_id = $this->subscription_key ?: 'rcp_' . $this->membership->get_id();
        $description     = sprintf(__('Membership #%s', 'rcp-payment4'), $this->membership->get_id());

        // Process return_url to extract base URL and query parameters
        $parsed_url        = parse_url($this->return_url);
        $base_callback_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if ( ! empty($parsed_url['path'])) {
            $base_callback_url .= $parsed_url['path'];
        }

        $callback_params = [
            'rcp_gateway'   => 'payment4',
            'membership_id' => $this->membership->get_id(),
            'payment_id'    => $this->payment->id,
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
            'rcp_gateway'   => 'payment4',
            'membership_id' => $this->membership->get_id(),
            'payment_id'    => $this->payment->id,
        ];

        // If webhook_url has query parameters, move them to webhook_params
        if ( ! empty($parsed_webhook_url['query'])) {
            parse_str($parsed_webhook_url['query'], $webhook_query_params);
            $webhook_params = array_merge($webhook_params, $webhook_query_params);
        }

        $request_data = [
            'sandBox'        => $this->test_mode,
            'currency'       => $currency,
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

        if (is_wp_error($response)) {
            $this->add_error('api_error', $response->get_error_message());

            return;
        }

        $body = wp_remote_retrieve_body($response);
        $body = json_decode($body, true);

        if (isset($body['status']) && $body['status'] === false) {
            $error_message = isset($body['errorCode']) ? $this->get_error_message($body['errorCode']) : __(
                'Payment creation failed.',
                'rcp-payment4'
            );
            $this->add_error('payment_failed', $error_message);

            return;
        }

        if ( ! empty($body['paymentUid'])) {
            // Update payment with transaction ID using $rcp_payments_db
            $rcp_payments_db->update($this->payment->id, [
                'transaction_id' => sanitize_text_field($body['paymentUid']),
                'status'         => 'pending',
            ]);

            // Add note to membership
            $this->membership->add_note(
                sprintf(
                    __('Payment created. Payment UID: %s, Payment URL: %s', 'rcp-payment4'),
                    $body['paymentUid'],
                    $body['paymentUrl']
                )
            );

            // Trigger RCP action for manual signup processing
            $member = new RCP_Member($this->membership->get_user_id());
            do_action('rcp_process_manual_signup', $member, $this->payment->id, $this);

            // Redirect to Payment4 payment page
            wp_redirect(esc_url_raw($body['paymentUrl']));
            exit;
        }

        $this->add_error('no_redirect', __('Unable to redirect to Payment4 payment page.', 'rcp-payment4'));
    }

    /**
     * Process webhook
     */
    public function process_webhook()
    {
        global $rcp_payments_db;

        if (empty($_GET['rcp_gateway']) || $_GET['rcp_gateway'] !== 'payment4' || empty($_GET['membership_id'])) {
            wp_die('Invalid webhook request.', 'Payment4', ['response' => 400]);
        }

        $membership_id = intval($_GET['membership_id']);
        $payment_id    = intval($_GET['payment_id']);
        $membership    = rcp_get_membership($membership_id);

        if ( ! $membership || ! $membership->get_id()) {
            wp_die('Membership not found.', 'Payment4', ['response' => 404]);
        }

        $amount = $rcp_payments_db->get_payment($payment_id);

        $result = $this->verify_payment($amount->amount);

        $status            = ! empty($result['orderStatus']) ? $result['orderStatus'] : 'failed';
        $payment_status    = ! empty($result['paymentStatus']) ? $result['paymentStatus'] : 'failed';
        $amount_difference = ! empty($result['amountDifference']) ? $result['amountDifference'] : '';
        $transaction_id    = ! empty($result['transaction_id']) ? $result['transaction_id'] : '';

        if ($status === 'completed') {
            $rcp_payments_db->update($payment_id, [
                'status'         => 'complete',
                'transaction_id' => $transaction_id,
            ]);

            $membership->renew(true, 'active');

            $membership->add_note(
                sprintf(
                    __('Payment successful. Payment UID: %s, Status: %s, Amount Difference: %s', 'rcp-payment4'),
                    $transaction_id,
                    $payment_status,
                    $amount_difference
                )
            );

            // Send email if configured
//            rcp_email_user($membership->get_user_id(), 'active_membership', $membership->get_id());
        } else {
            $rcp_payments_db->update($payment_id, [
                'status' => 'failed',
            ]);
            $membership->set_status('failed');

            $membership->add_note(
                sprintf(
                    __('Payment failed. Payment UID: %s, Status: %s, Amount Difference: %s, Error: %s', 'rcp-payment4'),
                    $transaction_id,
                    $payment_status,
                    $amount_difference,
                    $result['error']
                )
            );

            // Send email if configured
//            rcp_email_user($membership->get_user_id(), 'failed_membership', $membership->get_id());
        }

//        wp_die('Webhook processed.', 'Payment4', ['response' => 200]);
    }

    /**
     * Verify payment
     */
    private function verify_payment($amount)
    {
        $transaction_id = ! empty($_GET['paymentUid']) ? sanitize_text_field($_GET['paymentUid']) : '';
        $currency       = $this->get_currency();

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

                $body              = wp_remote_retrieve_body($response);
                $body              = json_decode($body, true);

                $amountDifference = $body['amountDifference'] ?? '';

                if (isset($body['message'])) {
                    $error = $body['message'];
                }

                $paymentStatus = isset($body['paymentStatus']) ? strtolower($body['paymentStatus']) : 'failed';
                if (isset($body['verified'])) {
                    if ($body['verified']) {
                        $orderStatus   = 'completed';
                        $error          = $paymentStatus === 'acceptable' ? __('Payment acceptable.', 'rcp-payment4') : __(
                            'Payment successful.',
                            'rcp-payment4'
                        );
                    } else {
                        $error          = $paymentStatus === 'mismatched' ? __('Payment mismatched.', 'rcp-payment4') : __(
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
    private function get_currency()
    {
        $allowed      = ['USD', 'IRT', 'IRR'];
        $rcp_currency = rcp_get_currency();

        $response = wp_remote_get('https://storage.payment4.com/wp/currencies.json');
        if ( ! is_wp_error($response) && $response['response']['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (is_array($data) && count($data) > 1) {
                $allowed = $data;
            }
        }

        if ($rcp_currency === 'IRR') {
            $rcp_currency = 'IRT';
        }

        return in_array($rcp_currency, $allowed) ? $rcp_currency : false;
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

        return isset($errors[$error_code]) ? $errors[$error_code] : __(
            'An error occurred during payment.',
            'rcp-payment4'
        );
    }

    /**
     * Validate fields (optional, not needed for Payment4)
     */
    public function validate_fields()
    {
        // No additional fields to validate
    }

    /**
     * Add fields to registration form (optional, not needed for Payment4)
     */
    public function fields()
    {
        // No additional fields needed, as Payment4 redirects to an external page
        return '';
    }
}

// Register webhook handler
add_action('init', 'payment4_rcp_handle_webhook');
function payment4_rcp_handle_webhook()
{
    if ( ! empty($_GET['rcp_gateway']) && $_GET['rcp_gateway'] === 'payment4') {
        $gateway = new RCP_Payment_Gateway_Payment4();
        $gateway->process_webhook();
    }
}