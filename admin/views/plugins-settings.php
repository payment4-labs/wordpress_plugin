<div class="wrap">
    <h1><?php
        echo esc_html(get_admin_page_title()); ?></h1>
    <form method="post" action="options.php">
        <?php
        settings_fields('payment4_gateway_pro_plugins');
        do_settings_sections('payment4_plugins');
        submit_button(__('Save Modules', 'payment4-gateway-pro'));
        ?>
    </form>
</div>