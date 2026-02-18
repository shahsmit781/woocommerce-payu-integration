/**
 * PayU Order Payment Link – Admin order edit screen
 * Handles "Create Payment Link" button click via AJAX.
 *
 * @package PayU_Payment_Links
 */

(function ($) {
	'use strict';

	function init() {
		$(document).on('click', '.payu-create-payment-link-btn', function () {
			var $btn = $(this);
			var orderId = $btn.data('order-id');
			var nonce = $btn.data('nonce');
			var $result = $('#payu-payment-link-result');

			if (!orderId || !nonce) {
				return;
			}

			if (typeof payuOrderPaymentLink === 'undefined') {
				$result.show().addClass('notice notice-error').html('<p>' + ($btn.closest('.payu-order-payment-link-box').length ? 'Configuration error.' : '') + '</p>');
				return;
			}

			$result.hide().removeClass('notice notice-error notice-success').empty();
			$btn.prop('disabled', true).text(payuOrderPaymentLink.i18n.creating || 'Creating…');

			$.post(
				payuOrderPaymentLink.ajaxUrl,
				{
					action: payuOrderPaymentLink.action,
					order_id: orderId,
					nonce: nonce
				},
				function (response) {
					$btn.prop('disabled', false).text(payuOrderPaymentLink.i18n.buttonLabel || 'Create Payment Link');
					$result.show();
					if (response && response.success && response.data) {
						$result.addClass('notice notice-success');
						if (response.data.link) {
							$result.html(
								'<p><a href="' + response.data.link + '" target="_blank" rel="noopener noreferrer">' +
								(response.data.message || 'Payment link created') + '</a></p>'
							);
						} else {
							$result.html('<p>' + (response.data.message || 'Done.') + '</p>');
						}
					} else {
						$result.addClass('notice notice-error').html(
							'<p>' + (response && response.data && response.data.message ? response.data.message : payuOrderPaymentLink.i18n.error) + '</p>'
						);
					}
				}
			).fail(function () {
				$btn.prop('disabled', false).text(payuOrderPaymentLink.i18n.buttonLabel || 'Create Payment Link');
				$result.show().addClass('notice notice-error').html('<p>' + (payuOrderPaymentLink.i18n.error || 'Request failed.') + '</p>');
			});
		});
	}

	$(function () {
		init();
	});
})(jQuery);
