<div class="wrap">
    <h1><?php
        echo esc_html(get_admin_page_title()); ?></h1>
    <form action="options.php" method="post">
        <?php
        settings_fields('payment4_gateway_pro_options');
        do_settings_sections('payment4_gateway_pro');
        submit_button(__('Save Settings', 'payment4-crypto-payment-gateway'));
        ?>
    </form>
</div>