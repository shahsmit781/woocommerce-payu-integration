/**
 * PayU Payment Link Status – Fetch status on load, then display result (no page reload).
 */
(function ($) {
	'use strict';

	var config = window.payuStatusPage;
	if (!config || !config.ajaxUrl) {
		return;
	}
	// Server-side rendered result: nothing to fetch.
	if (config.preloaded && config.data) {
		return;
	}
	// Invoice required for fetch (optional when only binding Try again for server-rendered error).
	if (!config.invoice && !config.error) {
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
			title = i18n.errorGeneric || 'Something went wrong';
			msg = i18n.errorVerifying || "We're verifying your payment. Please refresh in a moment.";
		} else if (code === 'payu_no_transactions') {
			title = i18n.errorNoTransactions || 'Payment details not available';
			msg = i18n.errorNoTransactionsMsg || 'Payment details are not available yet. Please try again in a moment or contact support with your invoice number.';
		} else if (code === 'no_data') {
			title = i18n.errorGeneric || 'Something went wrong';
			msg = i18n.errorVerifying || "We're verifying your payment. Please refresh in a moment.";
		}
		return { title: title, message: msg };
	}

	function showError(serverMessage, code) {
		hideLoader();
		var content = getErrorContent(code || '');
		$errorTitle.text(content.title);
		$errorMessage.text(serverMessage || content.message);
		var shopUrl = (typeof wc_get_page_permalink === 'function' && wc_get_page_permalink('shop')) ? wc_get_page_permalink('shop') : (window.location.origin || '/');
		var html = '<button type="button" class="payu-status-btn payu-status-btn-secondary" id="payu-status-try-again">' + (i18n.tryAgain || 'Try again') + '</button>';
		$errorActions.html(html);
		$error.addClass('is-visible');
		$result.removeClass('is-visible');
		$('#payu-status-try-again').on('click', function () {
			$error.removeClass('is-visible');
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
		// Normalize transactions: backend may send array, or object (PHP assoc array), or JSON string
		var rawTxns = data.transactions;
		var txns = [];
		if (rawTxns) {
			if (Array.isArray(rawTxns)) {
				txns = rawTxns;
			} else if (typeof rawTxns === 'string') {
				try {
					var parsed = JSON.parse(rawTxns);
					txns = Array.isArray(parsed) ? parsed : (parsed && typeof parsed === 'object' ? Object.values(parsed) : []);
				} catch (e) {
					txns = [];
				}
			} else if (typeof rawTxns === 'object' && rawTxns !== null) {
				txns = Object.keys(rawTxns).map(function (k) { return rawTxns[k]; });
			}
		}
		var html = '';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.invoice || 'Invoice ID') + '</span><span class="payu-status-value">' + (data.invoice ? $('<div>').text(data.invoice).html() : '—') + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.orderRef || 'Order reference') + '</span><span class="payu-status-value">' + (data.order_ref ? $('<div>').text(data.order_ref).html() : '—') + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.invoiceStatus || 'Invoice status') + '</span><span class="payu-status-badge">' + $('<div>').text(statusLabel(data.display_status)).html() + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.total || 'Total amount') + '</span><span class="payu-status-value">' + formatAmount(data.total, currency) + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.amountPaid || 'Amount paid') + '</span><span class="payu-status-value highlight">' + formatAmount(data.amount_paid, currency) + '</span></div>';
		html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.remaining || 'Remaining') + '</span><span class="payu-status-value">' + formatAmount(data.remaining, currency) + '</span></div>';
		// User-friendly status: Success, Failed, In Progress, User Cancelled.
		function txnStatusLabel(s) {
			var u = (s || '').toUpperCase();
			if (u === 'PAID' || u === 'SUCCESS') return i18n.txnSuccess || 'Success';
			if (u === 'PARTIALLY_PAID') return i18n.txnPartiallyPaid || 'Partially paid';
			if (u === 'PENDING' || u === 'INITIATED') return i18n.txnInProgress || 'In Progress';
			if (u === 'FAILED') return i18n.txnFailed || 'Failed';
			if (u === 'CANCELLED' || u === 'USERCANCELLED') return i18n.txnUserCancelled || 'User Cancelled';
			return s || '—';
		}
		function txnStatusBadgeClass(label) {
			var l = (label || '').toLowerCase().replace(/\s+/g, '-');
			if (l === 'failed') return 'payu-status-badge--failed';
			if (l === 'success') return 'payu-status-badge--success';
			if (l === 'in-progress') return 'payu-status-badge--in-progress';
			if (l === 'user-cancelled') return 'payu-status-badge--user-cancelled';
			return '';
		}
		function dateTimeHtml(t) {
			var d = t.date_display || '';
			var tm = t.time_display || '';
			if (!d && !tm) return $('<div>').text(t.date || '—').html();
			var h = '<span class="payu-status-datetime">';
			if (d) h += '<span class="payu-status-date-line">' + $('<div>').text(d).html() + '</span>';
			if (tm) h += '<span class="payu-status-time-line">' + $('<div>').text(tm).html() + '</span>';
			h += '</span>';
			return h;
		}
		var displayStatus = (data.display_status || '').toUpperCase();
		var isPartial = displayStatus === 'PARTIALLY_PAID';
		var isPaid = displayStatus === 'PAID';
		var showTable = (isPartial && txns.length >= 1) || (!isPaid && txns.length > 1);
		var showSingle = (isPaid && txns.length >= 1) || (txns.length === 1 && !isPartial);

		if (showTable) {
			html += '<div class="payu-status-txn-block"><div class="payu-status-txn-heading">' + (i18n.paymentTxns || 'Payment transactions') + '</div><table class="payu-status-txn-table" role="table" aria-label="' + (i18n.paymentTxns || 'Payment transactions') + '"><thead><tr><th scope="col">' + (i18n.dateTime || 'Date & time') + '</th><th scope="col">' + (i18n.transactionId || 'Transaction ID') + '</th><th scope="col">' + (i18n.amount || 'Amount') + '</th><th scope="col">' + (i18n.txnStatus || 'Transaction status') + '</th></tr></thead><tbody>';
			for (var i = 0; i < txns.length; i++) {
				var t = txns[i];
				var txnStatusText = txnStatusLabel(t.status);
				var badgeClass = txnStatusBadgeClass(txnStatusText);
				html += '<tr><td class="payu-status-datetime-cell">' + dateTimeHtml(t) + '</td><td><span class="payu-status-txn-id">' + $('<div>').text(t.transaction_id || '—').html() + '</span></td><td>' + formatAmount(t.amount, currency) + '</td><td><span class="payu-status-badge ' + badgeClass + '">' + $('<div>').text(txnStatusText).html() + '</span></td></tr>';
			}
			html += '</tbody></table></div>';
		} else if (showSingle) {
			var t0 = txns[0];
			html += '<div class="payu-status-row payu-status-row-txn"><span class="payu-status-label">' + (i18n.transactionNo || 'Transaction No.') + '</span><span class="payu-status-value"><span class="payu-status-txn-id">' + $('<div>').text(t0.transaction_id || '—').html() + '</span></span></div>';
			html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.dateTime || 'Date & time') + '</span><span class="payu-status-value payu-status-datetime">' + dateTimeHtml(t0) + '</span></div>';
		} else {
			html += '<div class="payu-status-row"><span class="payu-status-label">' + (i18n.transactionNo || 'Transaction No.') + '</span><span class="payu-status-value">—</span></div>';
		}
		$details.html(html);
	}

	function renderActions(data) {
		var shopUrl = (typeof wc_get_page_permalink === 'function' && wc_get_page_permalink('shop')) ? wc_get_page_permalink('shop') : (window.location.origin || '/');
		var orderUrl = '';
		var orderId = data.order_id || (data.order_ref && data.order_ref !== '—' && /^\d+$/.test(String(data.order_ref)) ? String(data.order_ref) : null);
		if (orderId && typeof wc_get_endpoint_url === 'function' && typeof wc_get_page_permalink === 'function') {
			var myaccount = wc_get_page_permalink('myaccount');
			if (myaccount) orderUrl = wc_get_endpoint_url('view-order', orderId, myaccount);
		}
		var html = '';
		html += '<button type="button" class="payu-status-btn payu-status-btn-primary" onclick="window.print(); return false;">' + (i18n.print || 'Print') + '</button>';
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
			var msg = data.message || (i18n.errorVerifying || "We're verifying your payment. Please refresh in a moment.");
			showError(msg, data.code || 'no_data');
		});
	}

	// Server-rendered error: bind Try again to fetch and show loader.
	if (config.error) {
		$('#payu-status-try-again').on('click', function () {
			$error.removeClass('is-visible');
			$loader.removeClass('is-hidden');
			$result.removeClass('is-visible');
			fetchStatus();
		});
		return;
	}
	fetchStatus();
})(jQuery);
