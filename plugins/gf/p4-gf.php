<?php

if ( ! class_exists('GFPayment4_Bootstrap')) {
    // Initialize the add-on when Gravity Forms is loaded
    add_action('gform_loaded', array('GFPayment4_Bootstrap', 'load'), 5);

    class GFPayment4_Bootstrap
    {
        public static function load()
        {
            if ( ! method_exists('GFForms', 'include_payment_addon_framework')) {
                return;
            }


            // Include the Payment Add-On Framework
            require_once 'p4-class.php';
            GFForms::include_payment_addon_framework();
            // Register the add-on class
            GFAddOn::register('GFPayment4');
        }
    }
}

/**
 * Returns the instance of the add-on (used internally by Gravity Forms).
 */
function gfpayment4()
{
    return GFPayment4::get_instance();
}

add_filter('the_content', 'your_plugin_display_callback_message');
function your_plugin_display_callback_message($content)
{
    // Check if we are on the front page (homepage)
    // This prevents the message from appearing on other pages or posts.

    // Try to retrieve the transient message
    $message = get_transient('gf_payment_success_message');

    if ($message) {
        // If a message exists, display it
        $output = '<div class="gf-payment-success-notice" style="
                background-color: #dff0d8;
                color: #3c763d;
                border: 1px solid #d6e9c6;
                padding: 10px;
                margin-bottom: 20px;
                border-radius: 4px;
                text-align: center;
                font-weight: bold;
            ">' . esc_html($message) . '</div>';

        // Delete the transient immediately after retrieving it
        // This ensures the message is shown only once and disappears on refresh.
        delete_transient('gf_payment_success_message');

        // Prepend the message to the existing content
        return $output . $content;
    }


    // Return the original content if no message or not on the front page
    return $content;
}