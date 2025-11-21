const Pay4_settings = window.wc.wcSettings.getSetting( 'payment4cpg_wc_data', {} );
const Pay4_label = window.wp.htmlEntities.decodeEntities( Pay4_settings.title ) || window.wp.i18n.__( '( Pay with Crypto )', 'payment4-gateway-pro' );
const Pay4_Content = () => {
    return window.wp.htmlEntities.decodeEntities( Pay4_settings.description || window.wp.i18n.__('Accepting Crypto Payments', 'payment4-gateway-pro') );
};

const Pay4_Icon = () => {
    return Pay4_settings.icon
        ? React.createElement('img', { src: Pay4_settings.icon })
        : null;
}

const Pay4_Label = () => {
    return React.createElement(
        'span',
        { style: { width: '97%', display: 'flex', justifyContent: 'space-between' } },
        Pay4_label,
        React.createElement(Pay4_Icon)
    );
}

const Pay4_Block_Gateway = {
    name: 'payment4cpg_wc',
    label: React.createElement(Pay4_Label),
    content: React.createElement(Pay4_Content),
    edit: React.createElement(Pay4_Content),
    canMakePayment: () => true,
    ariaLabel: Pay4_label,
    supports: {
        features: Pay4_settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Pay4_Block_Gateway );

jQuery(document).ready(function($) {
    var isCheckoutPageP4 = $('body').hasClass('woocommerce-checkout') ||
        $('.wc-block-checkout').length > 0 ||
        window.location.pathname.includes('/checkout') ||
        $('.woocommerce-checkout-review-order-table').length > 0;

    if (!isCheckoutPageP4) {
        return;
    }

    function triggerBlocksRefresh() {
        try {
            $(document).trigger('wc_cart_fragments_loaded');
            $(window).trigger('wc_cart_hash_changed');
        } catch (e) {
        }
    }

    function manipulateCartDisplay(selectedMethod) {
        var discountFound = false;
        var discountValue = 0;
        var discountName = 'Payment4 Crypto Discount'; // New discount name

        $('.wc-block-components-totals-item').each(function() {
            var $item = $(this);
            var text = $item.text();

            // Look for the discount name instead of the fee
            if (text.includes(discountName)) {
                discountFound = true;

                // If a discount is found but another method is selected, store its value
                if (selectedMethod !== 'payment4cpg_wc') {
                    var $valueElement = $item.find('.wc-block-components-totals-item__value');
                    discountValue = extractNumericValue($valueElement.text());
                }

                // Show or hide the discount row based on the selected payment method
                if (selectedMethod === 'payment4cpg_wc') {
                    $item.show();
                } else {
                    $item.hide();
                }

                return false;
            }
        });

        // If a discount was found and we're switching to another method,
        // we manually adjust the total back.
        if (discountFound && selectedMethod !== 'payment4cpg_wc' && discountValue > 0) {
            updateTotalDisplay(-discountValue);
        }
    }

    function extractNumericValue(priceText) {
        if (!priceText) return 0;

        var numericStr = priceText.replace(/[۰-۹]/g, function(w) {
            var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            return persian.indexOf(w).toString();
        });

        numericStr = numericStr.replace(/[^\d.,]/g, '');
        numericStr = numericStr.replace(/,/g, '');

        return parseFloat(numericStr) || 0;
    }

    function updateTotalDisplay(adjustment) {
        var totalSelectors = [
            '.wc-block-components-totals-item--total .wc-block-components-totals-item__value',
            '.wc-block-checkout__totals .wc-block-components-totals-item--total .wc-block-components-totals-item__value'
        ];

        totalSelectors.forEach(function(selector) {
            var $totalElement = $(selector);
            if ($totalElement.length) {
                var currentText = $totalElement.text();
                var currentValue = extractNumericValue(currentText);
                var newValue = currentValue + adjustment;

                var formattedValue = newValue.toLocaleString('fa-IR');
                var newText = currentText.replace(/[\d,۰-۹.]+/g, formattedValue);
                $totalElement.text(newText);
            }
        });
    }

    function useClientSideFallback(selectedMethod) {
        if (typeof wc_checkout_params === 'undefined') {
            manipulateCartDisplay(selectedMethod);
            triggerBlocksRefresh();
        } else {
            $('body').trigger('update_checkout');
        }

        isUpdating = false;
    }

    var updateTimeout;
    var lastSelectedMethod = '';
    var isUpdating = false;

    function updatePaymentMethod(selectedMethod) {
        if (isUpdating || selectedMethod === lastSelectedMethod) {
            return;
        }

        if (updateTimeout) {
            clearTimeout(updateTimeout);
        }

        lastSelectedMethod = selectedMethod;
        isUpdating = true;

        var ajaxUrl = '';
        var nonce = '';

        if (typeof wc_checkout_params !== 'undefined') {
            ajaxUrl = wc_checkout_params.ajax_url;
            nonce = wc_checkout_params.update_order_review_nonce;
        } else if (typeof payment4Ajax !== 'undefined') {
            // Use localized Payment4 Ajax URL and nonce
            ajaxUrl = payment4Ajax.ajax_url;
            nonce = payment4Ajax.nonce;
        } else {
            // Fallback for other cases
            return;
        }

        $.post(ajaxUrl, {
            // Updated AJAX action to your new one
            action: 'payment4_update_payment_method',
            payment_method: selectedMethod,
            nonce: nonce
        }).done(function(response) {
            updateTimeout = setTimeout(function() {
                if (typeof wc_checkout_params !== 'undefined') {
                    $('body').trigger('update_checkout');
                    isUpdating = false;
                } else {
                    if (window.wp && window.wp.data) {
                        try {
                            var cartDispatch = window.wp.data.dispatch('wc/store/cart');
                            if (cartDispatch && typeof cartDispatch.invalidateResolution === 'function') {
                                cartDispatch.invalidateResolution('getCartData');
                                cartDispatch.invalidateResolution('getCartTotals');
                            }
                        } catch (e) {
                        }
                    }

                    setTimeout(function() {
                        manipulateCartDisplay(selectedMethod);
                        triggerBlocksRefresh();
                        isUpdating = false;
                    }, 150);
                }
            }, 50);
        }).fail(function(xhr, status, error) {
            isUpdating = false;

            updateTimeout = setTimeout(function() {
                useClientSideFallback(selectedMethod);
            }, 50);
        });
    }

    var classicCheckoutTimeout;
    $(document.body).on('change', 'input[name="payment_method"]', function() {
        var selectedMethod = $(this).val();

        if (classicCheckoutTimeout) {
            clearTimeout(classicCheckoutTimeout);
        }

        classicCheckoutTimeout = setTimeout(function() {
            updatePaymentMethod(selectedMethod);
        }, 100);
    });

    setTimeout(function() {
        var selectedMethod = $('input[name="payment_method"]:checked').val();
        if (selectedMethod) {
            updatePaymentMethod(selectedMethod);
        }
    }, 500);

    if (window.MutationObserver) {
        var blocksLastSelectedMethod = '';
        var blocksObserverTimeout;

        var observer = new MutationObserver(function(mutations) {
            if (blocksObserverTimeout) {
                clearTimeout(blocksObserverTimeout);
            }

            blocksObserverTimeout = setTimeout(function() {
                var selectedRadio = document.querySelector('input[name="radio-control-wc-payment-method-options"]:checked');
                if (selectedRadio) {
                    var selectedMethod = selectedRadio.value;
                    if (selectedMethod !== blocksLastSelectedMethod) {
                        blocksLastSelectedMethod = selectedMethod;
                        updatePaymentMethod(selectedMethod);
                    }
                }
            }, 150);
        });

        setTimeout(function() {
            var checkoutContainer = document.querySelector('.wc-block-checkout');
            if (checkoutContainer) {
                observer.observe(checkoutContainer, {
                    childList: true,
                    subtree: true
                });

                var initialRadio = document.querySelector('input[name="radio-control-wc-payment-method-options"]:checked');
                if (initialRadio) {
                    blocksLastSelectedMethod = initialRadio.value;
                    updatePaymentMethod(initialRadio.value);
                }
            }
        }, 1000);
    }
});