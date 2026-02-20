/**
 * PayU Payment Link Status – Fetch status on load, then display result (no page reload).
 */
(function ($) {
	'use strict';

	var config = window.payuStatusPage;
	if (!config || !config.ajaxUrl || !config.invoice) {
		return;
	}
	
	var $loader = $('#payu-status-loader');
	var $error = $('#payu-status-error');
	var $errorTitle = $('#payu-status-error-title');
	var $errorMessage = $('#payu-status-error-message');
	var $errorActions = $('#payu-status-error-actions');
	var $result = $('#payu-status-result');
	var $header = $('#payu-status-header');
	var $icon = $('#payu-status-icon');
	var $title = $('#payu-status-title');
	var $details = $('#payu-status-details');
	var $actions = $('#payu-status-actions');
	var i18n = config.i18n || {};

	function hideLoader() {
		$loader.addClass('is-hidden');
	}

	function showResult() {
		$result.addClass('is-visible');
	}

	function getErrorContent(code) {
		var title = i18n.errorGeneric || 'Something went wrong';
		var msg = i18n.errorGenericMsg || 'We couldn\'t load the payment status. Please try again or contact the store.';
		if (code === 'no_link') {
			title = i18n.errorNoLink || 'Payment link not found';
			msg = i18n.errorNoLinkMsg || 'This link may be invalid or expired. Please check your link or contact the store.';
		} else if (code === 'no_config') {
			title = i18n.errorNoConfig || 'Payment setup incomplete';
			msg = i18n.errorNoConfigMsg || 'The store has not completed PayU setup. Please contact the store.';
		} else if (code === 'invalid_invoice') {
			title = i18n.errorInvalid || 'Invalid link';
			msg = i18n.errorInvalidMsg || 'The invoice or link is invalid. Please check and try again.';
		}
		return { title: title, message: msg };
	}

	function showError(serverMessage, code) {
		hideLoader();
		var content = getErrorContent(code || '');
		$errorTitle.text(content.title);
		$errorMessage.text(content.message);
		var shopUrl = (typeof wc_get_page_permalink === 'function' && wc_get_page_permalink('shop')) ? wc_get_page_permalink('shop') : (window.location.origin || '/');
		var html = '<a href="' + shopUrl + '" class="payu-status-btn payu-status-btn-primary">' + (i18n.backToShop || 'Back to shop') + '</a>';
		html += '<button type="button" class="payu-status-btn payu-status-btn-secondary" id="payu-status-try-again">' + (i18n.tryAgain || 'Try again') + '</button>';
		$errorActions.html(html);
		$error.addClass('is-visible');
		$result.removeClass('is-visible');
		$('#payu-status-try-again').on('click', function () {
			$error.removeClass('is-visible');
			$errorActions.empty();
			$loader.removeClass('is-hidden');
			$result.removeClass('is-visible');
			fetchStatus();
		});
	}

	function statusLabel(displayStatus) {
		var s = (displayStatus || '').toUpperCase();
		if (s === 'PAID') return i18n.paid || 'Full payment completed';
		if (s === 'PARTIALLY_PAID') return i18n.partial || 'Partial payment received';
		if (s === 'FAILED') return i18n.failed || 'Payment failed';
		if (s === 'ACTIVE') return i18n.active || 'Payment pending';
		if (s === 'EXPIRED') return i18n.expired || 'Payment link expired';
		return i18n.active || 'Payment pending';
	}

	function statusClass(displayStatus) {
		var s = (displayStatus || '').toUpperCase();
		if (s === 'PAID') return 'payu-status--success';
		if (s === 'PARTIALLY_PAID') return 'payu-status--partial';
		if (s === 'FAILED') return 'payu-status--failure';
		if (s === 'ACTIVE') return 'payu-status--active';
		if (s === 'EXPIRED') return 'payu-status--expired';
		return 'payu-status--pending';
	}

	function statusIcon(displayStatus) {
		var s = (displayStatus || '').toUpperCase();
		if (s === 'PAID') return '✓';
		if (s === 'FAILED' || s === 'EXPIRED') return '✕';
		return '⋯';
	}

	function formatAmount(amount, currency) {
		if (amount === null || amount === undefined || amount === '') return '—';
		var num = parseFloat(amount, 10);
		if (isNaN(num)) return '—';
		if (currency && typeof wc_price === 'function') {
			return wc_price(num, { currency: currency });
		}
		return (currency || '') + ' ' + num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function renderDetails(data) {
		var currency = data.currency || '';
		var html = '';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.invoice || 'Invoice ID') + '</span><span class="payu-status-value">' + (data.invoice ? $('<div>').text(data.invoice).html() : '—') + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.orderRef || 'Order reference') + '</span><span class="payu-status-value">' + (data.order_ref ? $('<div>').text(data.order_ref).html() : '—') + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.status || 'Payment status') + '</span><span class="payu-status-badge">' + $('<div>').text(statusLabel(data.display_status)).html() + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.total || 'Total amount') + '</span><span class="payu-status-value">' + formatAmount(data.total, currency) + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.amountPaid || 'Amount paid') + '</span><span class="payu-status-value highlight">' + formatAmount(data.amount_paid, currency) + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.remaining || 'Remaining') + '</span><span class="payu-status-value">' + formatAmount(data.remaining, currency) + '</span></div>';
		$details.html(html);
	}

	function renderActions(data) {
		var shopUrl = (typeof wc_get_page_permalink === 'function' && wc_get_page_permalink('shop')) ? wc_get_page_permalink('shop') : (window.location.origin || '/');
		var orderUrl = '';
		if (data.order_ref && data.order_ref !== '—' && typeof wc_get_endpoint_url === 'function' && typeof wc_get_page_permalink === 'function') {
			var myaccount = wc_get_page_permalink('myaccount');
			if (myaccount) orderUrl = wc_get_endpoint_url('view-order', data.order_ref, myaccount);
		}
		var html = '';
		if (orderUrl && (data.display_status || '').toUpperCase() === 'PAID') {
			html += '<a href="' + orderUrl + '" class="payu-status-btn payu-status-btn-primary">' + (i18n.viewOrder || 'View order') + '</a>';
		}
		html += '<a href="' + shopUrl + '" class="payu-status-btn payu-status-btn-secondary">' + (i18n.backToShop || 'Back to shop') + '</a>';
		$actions.html(html);
	}

	function applyResult(data) {
		$header.attr('class', 'payu-status-header ' + statusClass(data.display_status));
		$result.attr('class', 'payu-status-result is-visible ' + statusClass(data.display_status));
		$icon.text(statusIcon(data.display_status));
		$title.text(statusLabel(data.display_status));
		renderDetails(data);
		renderActions(data);
		hideLoader();
		showResult();
	}

	function fetchStatus() {
		$.ajax({
			url: config.ajaxUrl,
			type: 'POST',
			data: {
				action: config.action,
				invoice: config.invoice
			},
			dataType: 'json',
			timeout: 30000
		}).done(function (response) {
			if (response && response.success && response.data) {
				applyResult(response.data);
			} else {
				var data = response && response.data ? response.data : {};
				showError(data.message || null, data.code || '');
			}
		}).fail(function (jqXHR, textStatus, errorThrown) {
			var data = (jqXHR.responseJSON && jqXHR.responseJSON.data) ? jqXHR.responseJSON.data : {};
			var msg = data.message || (typeof errorThrown === 'string' ? errorThrown : '') || (typeof textStatus === 'string' ? textStatus : '') || (i18n.error || '');
			showError(msg, data.code || 'error');
		});
	}

	fetchStatus();
})(jQuery);
