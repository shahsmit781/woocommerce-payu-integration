/**
 * PayU Create Payment Link – Admin form
 * Toggles partial payment and communication fields; client-side validation matching server.
 *
 * @package PayU_Payment_Links
 */

(function ($) {
	'use strict';

	var i18n = (window.payuCreatePaymentLink && window.payuCreatePaymentLink.i18n) ? window.payuCreatePaymentLink.i18n : {};
	var orderTotal = (window.payuCreatePaymentLink && window.payuCreatePaymentLink.orderTotal != null) ? parseFloat(window.payuCreatePaymentLink.orderTotal, 10) : 0;
	var remainingPayable = (window.payuCreatePaymentLink && window.payuCreatePaymentLink.remainingPayable != null) ? parseFloat(window.payuCreatePaymentLink.remainingPayable, 10) : orderTotal;
	var allowedCurrencies = (window.payuCreatePaymentLink && window.payuCreatePaymentLink.allowedCurrencies) ? window.payuCreatePaymentLink.allowedCurrencies : [];

	function togglePartialFields(show) {
		var $rows = $('#payu-row-partial-fields, #payu-row-num-instalments');
		if (show) {
			$rows.show();
		} else {
			$rows.hide();
		}
	}

	function isValidEmail(str) {
		if (typeof str !== 'string' || !str.length) return false;
		var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		return re.test(str.trim());
	}

	function validateForm() {
		var errors = [];
		var amount = parseFloat($('#payu_amount').val(), 10) || 0;
		var currency = ($('#payu_currency').val() || '').trim();
		var customerEmail = ($('#payu_customer_email').val() || '').trim();
		var customerPhone = ($('#payu_customer_phone').val() || '').trim();
		var expiry = ($('#payu_expiry_date').val() || '').trim();
		var notifyEmail = $('#payu_notify_email').is(':checked');
		var notifySms = $('#payu_notify_sms').is(':checked');
		var partial = $('#payu_partial_payment').is(':checked');
		var minRaw = ($('#payu_min_initial_payment').val() || '').toString().trim();
		var numRaw = ($('#payu_num_instalments').val() || '').toString().trim();
		var min = minRaw !== '' ? parseFloat(minRaw, 10) : NaN;
		var num = numRaw !== '' ? parseInt(numRaw, 10) : NaN;

		if (amount <= 0) {
			errors.push(i18n.amountGreaterThanZero || 'Payment amount must be greater than zero.');
		}
		if (amount > remainingPayable) {
			errors.push(i18n.amountExceedRemaining || 'Payment link amount cannot exceed the remaining order amount.');
		}
		if (expiry !== '') {
			var expiryTs = Date.parse(expiry);
			if (isNaN(expiryTs) || expiryTs <= Date.now()) {
				errors.push(i18n.expiryMustBeFuture || 'Expiry date must be in the future.');
			}
		}
		if (currency === '' || (allowedCurrencies.length > 0 && allowedCurrencies.indexOf(currency) === -1)) {
			errors.push(i18n.selectValidCurrency || 'Please select a valid currency from PayU configurations.');
		}
		if (notifyEmail) {
			if (customerEmail === '' || !isValidEmail(customerEmail)) {
				errors.push(i18n.customerEmailRequired || i18n.emailRequired || 'A valid customer email is required.');
			}
		}
		if (notifySms) {
			if (customerPhone === '') {
				errors.push(i18n.smsRequired || 'Please enter a mobile number when SMS is selected.');
			}
		}
		if (partial) {
			if (minRaw === '') {
				errors.push(i18n.minInitialRequired || 'Minimum initial payment is required when partial payment is enabled.');
			}
			if (numRaw === '') {
				errors.push(i18n.numInstalmentsRequired || 'Number of instalments is required when partial payment is enabled.');
			}
			if (minRaw !== '' && !isNaN(min) && min > amount) {
				errors.push(i18n.minAmountError || 'Minimum initial payment cannot be more than the payment amount.');
			}
		}

		return errors;
	}

	function init() {
		var $form = $('#payu-create-payment-link-form');
		if (!$form.length) {
			return;
		}

		// Disable Create Payment Link button when no remaining amount (UI aligns with server-side; server is source of truth).
		var $submitBtn = $('#payu-submit-create-link');
		if ($submitBtn.length && remainingPayable <= 0) {
			$submitBtn.prop('disabled', true).attr('aria-disabled', 'true');
			var $notice = $('<p class="notice notice-warning" style="margin: 0.5em 0 0 0;"></p>').text(i18n.noRemainingAmount || 'No remaining amount to collect for this order.');
			$submitBtn.closest('.submit').prepend($notice);
		}

		// Partial payment: show/hide min initial payment and number of instalments
		$('#payu_partial_payment').on('change', function () {
			togglePartialFields($(this).is(':checked'));
		});

		// Submit: full client-side validation matching server
		$form.on('submit', function () {
			var errors = validateForm();
			if (errors.length > 0) {
				alert(errors[0]);
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
