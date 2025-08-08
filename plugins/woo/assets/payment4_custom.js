(function ($) {
	// Your gateway ID, matching the PHP class name
	const payment4Id = 'WC_Payment4';

	// This is the main function that runs when a payment method is changed
	function handlePaymentMethodChange() {
		$('body').trigger('update_checkout');
	}

	// Listen for changes on the classic checkout page
	$(document.body).on('change', 'input[name="payment_method"]', function() {
		handlePaymentMethodChange();
	});
})(jQuery);