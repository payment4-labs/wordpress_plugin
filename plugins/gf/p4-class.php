<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Main Add-On Class for Payment4
 */
class Payment4CPG_GF_Gateway extends GFPaymentAddOn
{

    /** The version of the add-on. */
    protected $_version = '1.0';
    /** Minimum Gravity Forms version required. */
    protected $_min_gravityforms_version = '2.5';
    /** Unique slug used by Gravity Forms. */
    protected $_slug = 'payment4cpg_gf';
    /** Path to this plugin file, relative to the plugins directory. */
    protected $_path = 'payment4-gateway-pro/plugins/gf/p4-class.php';
    /** Full path to this plugin file. */
    protected $_full_path = __FILE__;
    /** The title of the plugin shown in the Gravity Forms Add-On list. */
    protected $_title = 'Gravity Forms Payment4 Add-On';
    /** The short title shown in form settings. */
    protected $_short_title = 'Payment4';
    /** Enable webhook/callback handling (IPN). */
    protected $_supports_callbacks = true;
    /** We do not require a credit card field (payment is external). */
    protected $_requires_credit_card = false;

    /** Singleton instance */
    private static $_instance = null;

    protected static $custom_error_message = null;

    protected string $api_key;
    private bool $sandbox_mode;

    /**
     * Get singleton instance.
     *
     * @return GFPayment4
     */
    public static function get_instance()
    {
        if (self::$_instance === null) {
            self::$_instance = new GFPayment4();
        }

        return self::$_instance;
    }

    /**
     * Runs before the payment add-on is initialized.
     *
     * @return void
     * @uses    GFAddOn::pre_init()
     * @uses    GFPaymentAddOn::payment_method_is_overridden()
     * @uses    GFPaymentAddOn::setup_cron()
     * @uses    GFPaymentAddOn::maybe_process_callback()
     *
     * @since   Unknown
     * @access  public
     *
     * @used-by GFAddOn::__construct()
     */
    public function pre_init()
    {
        parent::pre_init();

        $general_options    = get_option('payment4_gateway_pro_settings', []);
        $this->api_key      = isset($general_options['api_key']) ? sanitize_text_field(
            $general_options['api_key']
        ) : '';
        $this->sandbox_mode = ! empty($general_options['sandbox_mode']);
        // Intercepting callback requests.
        add_action('parse_request', array($this, 'maybe_process_callback'));
    }

    /**
     * Init function (called early by Gravity Forms).
     */
    public function init()
    {
        parent::init();
        add_action('gform_pre_handle_confirmation', [$this, 'payment4_pre_handle_confirmation'], 10, 2);
        add_filter('gform_currencies', [$this, 'supported_currencies']);
        add_filter('gform_validation_message', [$this, 'payment4_validation_message'], 10, 2);
        add_action("gform_post_payment_action", [$this, 'payment4_post_payment_action'], 10, 2);

        add_filter( 'gform_entry_list_columns', function ($table_columns, $form_id){
            $table_columns['field_id-payment_status'] = 'Payment4 Status';
            return $table_columns;
        }, 10, 2 );

        add_filter( 'gform_entries_column_filter', function( $value, $form_id, $field_id, $entry ) {
            if ( $field_id === 'payment_status' ) {
                switch ( $value ) {
                    case 'ACCEPTABLE':
                        $color = '#0000FF'; // آبی
                        break;
                    case 'MISMATCH':
                        $color = '#dc3545'; // قرمز
                        break;
                    case 'SUCCESS':
                        $color = '#28a745'; // سبز
                        break;
                    default:
                        $color = '#6c757d'; // خاکستری
                        break;
                }
                $label = ucfirst( strtolower($value) );

                return '<span style="font-weight:bold; color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</span>';
            }

            return $value;
        }, 10, 4 );


    }

    public function plugin_settings()
    {
        $settings_url = admin_url('admin.php?page=payment4-gateway-pro');
        echo '<h2>' . esc_html__('To change Payment4 options (such as API key & Sandbox mode) visit', 'payment4-crypto-payment-gateway') . ' <a href="' . esc_url(
                $settings_url
            ) . '" target="_blank">' . esc_html__('Payment4 Settings', 'payment4-crypto-payment-gateway') . '</a></h2>';
    }

    /**
     * Feed settings fields (select email and amount fields).
     * Adds custom feed settings for Email and Amount mapping.
     */
    public function feed_settings_fields()
    {
        return array(
            array(
                'title'       => 'Payment4 Feed Settings',
                'description' => 'Select the form fields to use for payment amount and payer email.',
                'fields'      => array(
                    array(
                        'name'          => 'paymentAmount',
                        'label'         => esc_html__('Payment Amount', 'gravityforms'),
                        'type'          => 'select',
                        'choices'       => $this->product_amount_choices(),
                        'required'      => true,
                        'default_value' => 'form_total',
                        'tooltip'       => '<h6>' . esc_html__('Payment Amount', 'gravityforms') . '</h6>' . esc_html__(
                                "Select which field determines the payment amount, or select 'Form Total' to use the total of all pricing fields as the payment amount.",
                                'gravityforms'
                            ),
                    ),
                    array(
                        'name'     => 'transactionType',
                        'label'    => esc_html__('Transaction Type', 'gravityforms'),
                        'type'     => 'select',
                        'onchange' => "jQuery(this).parents('form').submit();",
                        'choices'  => array(
                            array(
                                'label' => esc_html__('Products and Services', 'gravityforms'),
                                'value' => 'product',
                            ),
                        ),
                        'tooltip'  => '<h6>' . esc_html__('Transaction Type', 'gravityforms') . '</h6>' . esc_html__(
                                'Select a transaction type.',
                                'gravityforms'
                            ),
                    ),
                ),
            ),
        );
    }

    public function payment4_pre_handle_confirmation($entry, $form)
    {
        $feed            = $this->get_payment_feed($entry, $form);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        $this->add_pending_payment($entry, array(
            'type'           => 'add_pending_payment',
            'amount'         => $submission_data['payment_amount'],
            'transaction_id' => $entry['transaction_id'],
            'payment_method' => $this->_short_title,
        ));
    }

    public function authorize($feed, $submission_data, $form, $entry)
    {
        $payment4Result = $this->payment4_api_call($entry, $submission_data);
        if ($payment4Result['is_authorized']) {
            $this->redirect_url = $payment4Result['redirect_url'];

            return array(
                'is_authorized'  => true,
                'transaction_id' => $payment4Result['transaction_id'],
                'payment_status' => 'Pending',
                'payment_gateway' => $this->_short_title,
            );
        }
        self::$custom_error_message = $payment4Result['error_message'];

        return array(
            'is_authorized' => false,
            'error_message' => $payment4Result['error_message'],
        );
    }

    public function payment4_api_call($entry, $submission_data)
    {
        $api_key     = $this->api_key;
        $use_sandbox = $this->sandbox_mode;

        // استخراج آدرس بازگشت از entry
        $callback_url      = $entry['source_url'];
        $parsed_url        = parse_url($callback_url);
        $base_callback_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if ( ! empty($parsed_url['path'])) {
            $base_callback_url .= $parsed_url['path'];
        }

        $callback_params = array(
            'formId'   => $entry['form_id'],
            'callback' => $this->get_slug(),
        );

        if ( ! empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            $callback_params = array_merge($callback_params, $query_params);
        }

        // ساخت بادی برای API
        $body = array(
            'amount'         => $submission_data['payment_amount'],
            'callbackUrl'    => $base_callback_url,
            'callbackParams' => $callback_params,
            'currency'       => $entry['currency'],
            'sandBox'        => $use_sandbox,
            'language'       => $this->get_language(),
        );

        // ساخت هدرها
        $headers = array(
            'Content-Type' => 'application/json',
            'x-api-key'    => $api_key,
        );

        // ارسال درخواست به API پیمنت ۴
        $response = wp_remote_post('https://service.payment4.com/api/v1/payment', array(
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ));

        // بررسی خطای WordPress
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error(__METHOD__ . "(): WP Error: $error_message");
            GFFormsModel::add_note($entry['id'], 0, $this->_short_title, "Payment4 API request failed: $error_message");
            self::$custom_error_message = $error_message;

            return array(
                'is_authorized'  => false,
                'error_message'  => $error_message,
                'transaction_id' => null,
                'redirect_url'   => null,
            );
        }

        // بررسی کد وضعیت پاسخ
        $code      = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        $result    = json_decode($resp_body, true);

        if ($code != 200 && $code != 201) {
            $msg = $result['message'] ?? $resp_body;
            $this->log_error(__METHOD__ . "(): API Error ($code): $msg");
            GFFormsModel::add_note($entry['id'], 0, $this->_short_title, "Payment4 API error: $msg");
            self::$custom_error_message = $msg;

            return array(
                'is_authorized'  => false,
                'error_message'  => esc_html($msg),
                'transaction_id' => null,
                'redirect_url'   => null,
            );
        }

        // بررسی وجود paymentUrl
        if (empty($result['paymentUrl'])) {
            $msg = $result['message'] ?? $resp_body;
            $this->log_error(__METHOD__ . "(): No paymentUrl returned. Response: $msg");
            GFFormsModel::add_note($entry['id'], 0, $this->_short_title, "Payment4 API returned no payment URL.");
            self::$custom_error_message = $msg;

            return array(
                'is_authorized'  => false,
                'error_message'  => $msg,
                'transaction_id' => null,
                'redirect_url'   => null,
            );
        }

        return array(
            'is_authorized'  => true,
            'error_message'  => null,
            'transaction_id' => $result['paymentUid'],
            'redirect_url'   => $result['paymentUrl'],
        );
    }

    public function payment4_validation_message($message, $form)
    {
        if (self::$custom_error_message) {
            return '<div class="gform_validation_errors" id="gform_' . $form['id'] . '_validation_container" tabindex="-1">
            <h2 class="gform_submission_error">
                <span class="gform-icon gform-icon--circle-error"></span>
                ' . esc_html(self::$custom_error_message) . '
            </h2>
        </div>';
        }

        return $message;
    }

    public function callback()
    {
        $paymentUid = rgget('paymentUid');
        $formId     = rgget('formId');

        if ( ! $paymentUid || ! $formId) {
            return new WP_Error('invalid_data', esc_html__('paymentUid or formId missing', 'payment4-crypto-payment-gateway'));
        }

        $search_criteria['field_filters'][] = [
            'key'   => 'transaction_id',
            'value' => $paymentUid,
        ];
        $entry                              = GFAPI::get_entries($formId, $search_criteria)[0];
        if ( ! $paymentUid || ! $formId) {
            return new WP_Error('invalid_data', esc_html__('Entry missing', 'payment4-crypto-payment-gateway'));
        }

        $api_key = $this->api_key;
        $headers = array(
            'Content-Type' => 'application/json',
            'x-api-key'    => $api_key,
        );

        // Call verify endpoint
        $verify_url  = 'https://service.payment4.com/api/v1/payment/verify';
        $verify_body = array(
            'paymentUid' => $paymentUid,
            'amount'     => $entry['payment_amount'],
            'currency'   => $entry['currency'],
        );

        $response = wp_remote_request($verify_url, array(
            'method'  => 'PUT',
            'headers' => $headers,
            'body'    => wp_json_encode($verify_body),
            'timeout' => 30,
        ));

        $code      = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        $result    = json_decode($resp_body, true);

        if ($code == 200 && rgar($result, 'verified', false)) {
            $type    = 'complete_payment';
            $message = rgar($result, 'paymentStatus') . '; Amount Difference: ' . rgar($result, 'amountDifference', 0);
        } else {
            $type    = 'fail_payment';
            $message = rgar($result, 'message', rgar($result, 'paymentStatus')) . '; Amount Difference: ' . rgar($result, 'amountDifference', 0);
        }

        return [
            'id'             => $paymentUid,
            'type'           => $type,
            'entry_id'       => $entry['id'],
            'transaction_id' => $paymentUid,
            'amount'         => rgar($entry, 'payment_amount'),
            'note'           => $message,
            'payment_method' => $this->_short_title,
            'payment_status' => rgar($result, 'paymentStatus'),
            'source_id'      => rgar($entry, 'source_id'),
        ];
    }

    public function post_callback($callback_action, $result)
    {

        // Define your message
        if (rgar($callback_action, 'type', 'fail_payment') == 'complete_payment') {
            $message = 'Your Payment Has Been Confirmed: ' . rgar($callback_action, 'note'); // Your success message
        } else {
            $message = 'Payment Failed: ' . rgar($callback_action, 'note');
        }

        // Set a transient with the message
        // 'gf_payment_success_message' is a unique name for your transient.
        // Use a specific name to avoid conflicts with other plugins.
        // $message is the content of your message.
        // 30 is the expiration time in seconds (e.g., 30 seconds).
        // The message will automatically disappear after this time.
        set_transient('gf_payment_success_message', $message, 10);

        gform_update_meta(rgar($callback_action, 'entry_id'), 'payment_gateway', $this->_short_title);

        // Redirect the user to the form page or homepage
        $redirect_url = home_url();
        $redirect     = rgar($callback_action, 'source_id');
        if ($redirect) {
            $redirect_url = get_permalink($redirect);
        }
        wp_redirect($redirect_url);
        die;
    }

    public function payment4_post_payment_action($entry, $action)
    {
        $this->log_debug(
            __METHOD__ . "() Payment action {$action['type']} for entry {$entry['id']}. Status: {$action['payment_status']}"
        );

        if (rgar($action, 'type') === 'add_pending_payment') {
            $entry_full = GFAPI::get_entry($entry['id']);

            if (is_wp_error($entry_full)) {
                $this->log_error(__METHOD__ . '(): Entry not found or invalid.');

                return;
            }

            // بروزرسانی فقط فیلدهای مربوط به پرداخت
            $entry_full['payment_method'] = $action['payment_method'] ?? $entry_full['payment_method'];
            $entry_full['transaction_id'] = $action['transaction_id'] ?? $entry_full['transaction_id'];
            $entry_full['payment_date']   = $action['payment_date'] ?? $entry_full['payment_date'];
            $entry_full['payment_amount'] = $action['amount'] ?? $entry_full['payment_amount'];

            $result = GFAPI::update_entry($entry_full);

            if (is_wp_error($result)) {
                $this->log_error(__METHOD__ . '(): Failed to update entry: ' . $result->get_error_message());
            } else {
                $this->log_debug(__METHOD__ . '(): Entry updated with Pending payment status.');
            }
        }
    }

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

    public function supported_currencies($currencies)
    {
        $currencies['IRT']['name']               = esc_html__('تومان ایران', 'payment4-crypto-payment-gateway');
        $currencies['IRT']['symbol_right']       = '';
        $currencies['IRT']['symbol_left']        = 'تومان';
        $currencies['IRT']['symbol_padding']     = ' ';
        $currencies['IRT']['thousand_separator'] = ',';
        $currencies['IRT']['decimal_separator']  = '.';
        $currencies['IRT']['decimals']           = 0;
        $currencies['IRT']['code']               = 'IRT';

        return $currencies;
    }
}