<?php

if ( ! defined('ABSPATH')) {
    exit;
}

class fee_handle
{
    public $discount_percent = 0;

    public function __construct()
    {
        add_action('woocommerce_cart_calculate_fees', [$this, 'payment4_add_discount']);
    }

    public
    function payment4_add_discount(
        $cart_object
    ) {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }

        $payment_method = 'WC_Payment4';

        // accessing discount_percent from WC_Payment4
        $payment_gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if (key_exists($payment_method, WC()->payment_gateways()->get_available_payment_gateways())) {
            $discount               = $payment_gateways[$payment_method]->settings['discount_percent'];
            $this->discount_percent = $discount;
        }
        // The percentage to apply
        $percent = $this->discount_percent;

        // no discount
        if ($this->discount_percent == 0) {
            return;
        }

        $cart_total = WC()->cart->subtotal_ex_tax;

        $chosen_payment_method = WC()->session->get('chosen_payment_method');
        if ($payment_method == $chosen_payment_method) {
            $label_text = __("Payment4 Discount", 'payment4-woocommerce');

            // Calculating percentage
            $discount = floatval(($cart_total / 100) * $percent);

            // Adding the discount
            $cart_object->add_fee($label_text, -$discount);
        }
    }
}

new fee_handle();