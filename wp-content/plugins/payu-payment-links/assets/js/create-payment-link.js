/**
 * PayU Create Payment Link – Admin form
 * Toggles partial payment and communication channel fields (no inline JS).
 *
 * @package PayU_Payment_Links
 */

(function ($) {
	'use strict';

	function togglePartialFields(show) {
		var $rows = $('#payu-row-partial-fields, #payu-row-num-instalments');
		if (show) {
			$rows.show();
		} else {
			$rows.hide();
		}
	}

	function validateMinAmount() {
		if (!$('#payu_partial_payment').is(':checked')) {
			return true;
		}
		var amount = parseFloat($('#payu_amount').val(), 10) || 0;
		var min = parseFloat($('#payu_min_initial_payment').val(), 10) || 0;
		return min <= amount;
	}

	function init() {
		var $form = $('#payu-create-payment-link-form');
		if (!$form.length) {
			return;
		}

		// Partial payment: show/hide min initial payment and number of instalments
		$('#payu_partial_payment').on('change', function () {
			togglePartialFields($(this).is(':checked'));
		});

		// Submit: minimum initial payment cannot exceed payment amount
		$form.on('submit', function () {
			if (!validateMinAmount()) {
				alert((window.payuCreatePaymentLink && window.payuCreatePaymentLink.i18n && window.payuCreatePaymentLink.i18n.minAmountError) || 'Minimum initial payment cannot be more than the payment amount.');
				return false;
			}
		});

		// Initial state
		togglePartialFields($('#payu_partial_payment').is(':checked'));
	}

	$(function () {
		init();
	});
})(jQuery);
