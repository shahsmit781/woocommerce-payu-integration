/**
 * PayU Payment Links – Admin actions (expiry modal, resend modal, copy).
 */
(function () {
	'use strict';

	// Do not run if jQuery failed to load (e.g. 502 on jquery-core)
	if (typeof window.jQuery === 'undefined') return;

	var jQuery = window.jQuery;

	// Three dots to open action - Mobile screen
	jQuery(document).on('click', '.payu-actions-toggle', function (e) {
		e.preventDefault();
		jQuery(this).closest('.payu-actions').toggleClass('open');
	});

	// Copy Button click
	jQuery(document).on('click', '.payu-copy-btn', function () {
		var btn = jQuery(this);
		var url = btn.data('url');
		if (!url) return;
		if (typeof navigator.clipboard === 'undefined' || !navigator.clipboard.writeText) return;
		navigator.clipboard.writeText(url).then(function () {
			btn.addClass('copied');
			btn.find('.dashicons').removeClass('dashicons-admin-links').addClass('dashicons-yes');
			setTimeout(function () {
				btn.removeClass('copied');
				btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-admin-links');
			}, 1200);
		}).catch(function () {});
	});
})();

(function () {
	'use strict';

	if (typeof window.jQuery === 'undefined') return;
	var $ = window.jQuery;
	var cfg = window.payuPaymentLinks || {};
	const expiryModal = document.getElementById('payu-expiry-modal');
	const expiryDatetime = document.getElementById('payu-expiry-datetime');
	const expiryConfirm = document.getElementById('payu-expiry-confirm');
	const expiryCancel = document.getElementById('payu-expiry-cancel');
	const expiryError = document.querySelector('.payu-expiry-modal-error');
	const expirySpinner = document.querySelector('.payu-expiry-spinner');

	if (!expiryModal || !expiryDatetime || !expiryConfirm || !expiryCancel) {
		return;
	}

	let currentLinkId = null;
	let currentInvoice = null;
	let $currentRow = null;
	var expiryRequestInFlight = false;

	function openExpiryModal(linkId, invoice, currentExpiry) {
		currentLinkId = linkId;
		currentInvoice = invoice;
		const btn = document.querySelector('.payu-expire-btn[data-link-id="' + linkId + '"]');
		$currentRow = btn ? $(btn).closest('tr') : null;

		const now = new Date();
		const nextMinute = new Date(now.getTime() + 60 * 1000);
		const minStr = nextMinute.getFullYear() + '-' + String(nextMinute.getMonth() + 1).padStart(2, '0') + '-' + String(nextMinute.getDate()).padStart(2, '0') + 'T' + String(nextMinute.getHours()).padStart(2, '0') + ':' + String(nextMinute.getMinutes()).padStart(2, '0');
		expiryDatetime.setAttribute('min', minStr);

		if (currentExpiry && currentExpiry.length >= 16) {
			expiryDatetime.value = currentExpiry.substring(0, 16).replace(' ', 'T');
		} else {
			const defaultDate = new Date(now.getTime() + 24 * 60 * 60 * 1000);
			expiryDatetime.value = defaultDate.getFullYear() + '-' + String(defaultDate.getMonth() + 1).padStart(2, '0') + '-' + String(defaultDate.getDate()).padStart(2, '0') + 'T' + String(defaultDate.getHours()).padStart(2, '0') + ':' + String(defaultDate.getMinutes()).padStart(2, '0');
		}

		if (expiryError) {
			expiryError.style.display = 'none';
			expiryError.textContent = '';
		}
		expiryModal.removeAttribute('hidden');
		document.body.classList.add('payu-modal-open');
		document.addEventListener('keydown', handleExpiryModalEscape);
	}

	function handleExpiryModalEscape(e) {
		if (e.key === 'Escape' && expiryModal && !expiryModal.hasAttribute('hidden')) {
			closeExpiryModal();
		}
	}

	function closeExpiryModal() {
		document.removeEventListener('keydown', handleExpiryModalEscape);
		expiryModal.setAttribute('hidden', '');
		document.body.classList.remove('payu-modal-open');
		currentLinkId = null;
		currentInvoice = null;
		$currentRow = null;
	}

	function setExpiryLoading(loading) {
		expiryConfirm.disabled = loading;
		if (expirySpinner) {
			expirySpinner.classList.toggle('is-hidden', !loading);
		}
		if (loading && expiryError) {
			expiryError.style.display = 'none';
			expiryError.textContent = '';
		}
	}

	function showExpiryError(msg) {
		if (expiryError) {
			expiryError.textContent = msg || (cfg.i18n && cfg.i18n.errorGeneric) || 'Something went wrong.';
			expiryError.style.display = 'block';
			expiryError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	function formatExpiryDisplay(dtStr) {
		if (!dtStr) return '—';
		var d = new Date(dtStr.replace(' ', 'T'));
		if (isNaN(d.getTime())) return dtStr;
		var y = d.getFullYear();
		var m = String(d.getMonth() + 1).padStart(2, '0');
		var day = String(d.getDate()).padStart(2, '0');
		var h = String(d.getHours()).padStart(2, '0');
		var min = String(d.getMinutes()).padStart(2, '0');
		return y + '-' + m + '-' + day + ' ' + h + ':' + min;
	}

	// Expiry Button Action call
	$(document).on('click', '.payu-expire-btn', function (e) {
		e.preventDefault();
		const btn = e.currentTarget;
		const linkId = btn.getAttribute('data-link-id');
		const invoice = btn.getAttribute('data-invoice');
		const expiry = btn.getAttribute('data-expiry-date');
		if (!linkId || !invoice) return;
		openExpiryModal(linkId, invoice, expiry || '');
	});

	expiryCancel.addEventListener('click', function () {
		closeExpiryModal();
	});

	$(expiryModal).on('click', '.payu-modal-backdrop', function () {
		closeExpiryModal();
	});

	expiryConfirm.addEventListener('click', function () {	
		const raw = expiryDatetime.value.trim();
		if (!raw) {
			showExpiryError(cfg.i18n && cfg.i18n.expiryRequired ? cfg.i18n.expiryRequired : 'Please select an expiry date and time.');
			return;
		}
		const selected = new Date(raw.replace('T', ' '));
		const now = Date.now();
		const oneMinuteMs = 60 * 1000;
		if (isNaN(selected.getTime()) || selected.getTime() <= now) {
			showExpiryError(cfg.i18n && cfg.i18n.expiryFuture ? cfg.i18n.expiryFuture : 'Expiry date and time must be after the current time.');
			return;
		}
		if (selected.getTime() < now + oneMinuteMs) {
			showExpiryError(cfg.i18n && cfg.i18n.expiryMinuteAhead ? cfg.i18n.expiryMinuteAhead : 'Expiry must be at least 1 minute from now.');
			return;
		}
		if (expiryRequestInFlight) return;
		expiryRequestInFlight = true;

		setExpiryLoading(true);

		const payload = {
			action: cfg.expiryUpdateAction || 'payu_update_payment_link_expiry',
			nonce: cfg.expiryUpdateNonce || '',
			link_id: currentLinkId,
			payu_invoice_number: currentInvoice,
			new_expiry_datetime: raw.replace('T', ' ')
		};

		$.post(cfg.ajaxUrl, payload)
			.done(function (res) {
				if (res.success && res.data && res.data.expiry_date) {
					var $rowToUpdate = $currentRow;
					var linkIdToUpdate = currentLinkId;
					closeExpiryModal();
					// Update row in place when in DOM; then show success notice (no page reload)
					var updated = false;
					if ($rowToUpdate && $rowToUpdate.length) {
						var $cell = $rowToUpdate.find('.column-expiry_date');
						if ($cell.length) {
							var formatted = formatExpiryDisplay(res.data.expiry_date);
							var $display = $cell.find('.payu-expiry-display');
							if ($display.length) {
								$display.text(formatted);
							} else {
								$cell.text(formatted);
							}
							var status = (res.data.status || '').toUpperCase();
							if (status === 'EXPIRED') {
								var $statusCell = $rowToUpdate.find('.column-status');
								if ($statusCell.length) {
									var statusLabel = (cfg.statusLabels && cfg.statusLabels.payment && cfg.statusLabels.payment[status]) ? cfg.statusLabels.payment[status] : status;
									$statusCell.text(statusLabel);
								}
							}
							var paymentLinkStatus = (res.data.payment_link_status || '').toString().trim().toLowerCase();
							if (paymentLinkStatus) {
								var $linkStatusCell = $rowToUpdate.find('.column-payment_link_status');
								if ($linkStatusCell.length) {
									var linkLabel = (cfg.statusLabels && cfg.statusLabels.link && cfg.statusLabels.link[paymentLinkStatus]) ? cfg.statusLabels.link[paymentLinkStatus] : res.data.payment_link_status;
									$linkStatusCell.text(linkLabel);
								}
							}
							// Remove edit expiry icon only when same condition as refresh: link expired or payment not pending/partially_paid
							var shouldHideExpiryBtn = paymentLinkStatus === 'expired' || (status !== 'PENDING' && status !== 'PARTIALLY_PAID');
							if (shouldHideExpiryBtn && linkIdToUpdate) {
								$rowToUpdate.find('button.payu-expire-btn[data-link-id="' + linkIdToUpdate + '"], button.payu-expiry-edit-btn[data-link-id="' + linkIdToUpdate + '"]').remove();
							}
							$cell.addClass('payu-cell-updated');
							setTimeout(function () { $cell.removeClass('payu-cell-updated'); }, 2000);
							updated = true;
						}
					}
					// Always show success notice (data is saved on server; row update is best-effort)
					var notice = $('<div class="notice notice-success is-dismissible"><p></p></div>');
					notice.find('p').text(res.data.message || (cfg.i18n && cfg.i18n.success) || 'Expiry date updated successfully.');
					$('.wrap.woocommerce h1').after(notice);
					setTimeout(function () { notice.fadeOut(function () { $(this).remove(); }); }, 4000);
				} else {
					var errMsg = (res && res.data && res.data.message) ? res.data.message : (res && res.message) ? res.message : (cfg.i18n && cfg.i18n.errorGeneric) || 'Something went wrong. Please try again.';
					showExpiryError(errMsg);
				}
			})
			.fail(function (xhr, status, err) {
				var msg = (cfg.i18n && cfg.i18n.errorGeneric) || 'Something went wrong. Please try again.';
				if (xhr.responseJSON) {
					var d = xhr.responseJSON.data || xhr.responseJSON;
					if (d && d.message) msg = d.message;
					else if (xhr.responseJSON.message) msg = xhr.responseJSON.message;
				} else if (xhr.responseText) {
					try {
						var j = JSON.parse(xhr.responseText);
						if (j.data && j.data.message) msg = j.data.message;
						else if (j.message) msg = j.message;
					} catch (e) {
						if (xhr.responseText.length > 0 && xhr.responseText.length < 300) msg = xhr.responseText.replace(/<[^>]+>/g, '').trim();
					}
				}
				showExpiryError(msg);
			})
			.always(function () {
				expiryRequestInFlight = false;
				setExpiryLoading(false);
			});
	});
})();

/* ========== Resend Payment Link Modal ========== */
(function () {
	'use strict';

	if (typeof window.jQuery === 'undefined') return;
	var $ = window.jQuery;
	var cfg = window.payuPaymentLinks || {};
	const resendModal = document.getElementById('payu-resend-modal');
	const resendViaEmail = document.getElementById('payu-resend-via-email');
	const resendViaSms = document.getElementById('payu-resend-via-sms');
	const resendEmail = document.getElementById('payu-resend-email');
	const resendPhone = document.getElementById('payu-resend-phone');
	const resendSubmit = document.getElementById('payu-resend-submit');
	const resendCancel = document.getElementById('payu-resend-cancel');
	const resendError = document.querySelector('.payu-resend-modal-error');
	const resendSpinner = document.querySelector('.payu-resend-spinner');
	const fieldEmail = document.querySelector('.payu-resend-field-email');
	const fieldPhone = document.querySelector('.payu-resend-field-phone');
	const resendLoadingOverlay = document.getElementById('payu-resend-loading-overlay');

	if (!resendModal || !resendSubmit || !resendCancel) {
		return;
	}

	let currentPaymentLinkId = null;
	let currentPayuInvoice = null;
	var resendRequestInFlight = false;

	function openResendModal(paymentLinkId, payuInvoice, prefilledEmail, prefilledPhone) {
		currentPaymentLinkId = paymentLinkId;
		currentPayuInvoice = payuInvoice || '';
		if (resendViaEmail) resendViaEmail.checked = false;
		if (resendViaSms) resendViaSms.checked = false;
		if (resendEmail) resendEmail.value = prefilledEmail || '';
		if (resendPhone) resendPhone.value = prefilledPhone || '';
		if (fieldEmail) fieldEmail.style.display = 'none';
		if (fieldPhone) fieldPhone.style.display = 'none';
		if (resendError) {
			resendError.style.display = 'none';
			resendError.textContent = '';
		}
		resendModal.removeAttribute('hidden');
		document.body.classList.add('payu-modal-open');
	}

	function closeResendModal() {
		resendModal.setAttribute('hidden', '');
		document.body.classList.remove('payu-modal-open');
		currentPaymentLinkId = null;
		currentPayuInvoice = null;
	}

	function setResendLoading(loading) {
		resendRequestInFlight = loading;
		resendSubmit.disabled = loading;
		if (resendCancel) resendCancel.disabled = loading;
		if (resendSpinner) {
			resendSpinner.classList.toggle('is-hidden', !loading);
		}
		if (resendLoadingOverlay) {
			if (loading) {
				resendLoadingOverlay.removeAttribute('hidden');
				resendLoadingOverlay.setAttribute('aria-busy', 'true');
			} else {
				resendLoadingOverlay.setAttribute('hidden', '');
				resendLoadingOverlay.removeAttribute('aria-busy');
			}
		}
		if (resendError) {
			resendError.style.display = 'none';
			resendError.textContent = '';
		}
	}

	function showResendError(msg) {
		if (resendError) {
			resendError.textContent = msg || (cfg.i18n && cfg.i18n.errorGeneric) || 'Something went wrong.';
			resendError.style.display = 'block';
		}
	}

	$(document).on('click', '.payu-resend-btn', function (e) {
		e.preventDefault();
		var btn = e.currentTarget;
		var id = btn.getAttribute('data-id');
		var invoice = btn.getAttribute('data-invoice');
		if (!id || !invoice) return;

		var payload = {
			action: cfg.getLinkDetailsAction || 'payu_get_payment_link_details',
			nonce: cfg.getLinkDetailsNonce || '',
			payment_link_id: id
		};
		$.get(cfg.ajaxUrl, payload)
			.done(function (res) {
				var email = '';
				var phone = '';
				if (res.success && res.data) {
					email = (res.data.customerEmail || '').trim();
					phone = (res.data.customerPhone || '').trim();
					if (res.data.payu_invoice_number) invoice = res.data.payu_invoice_number;
				}
				openResendModal(id, invoice, email, phone);
			})
			.fail(function () {
				// Fallback: open with empty or data attributes if fetch fails
				var email = (btn.getAttribute('data-email') || '').trim();
				var phone = (btn.getAttribute('data-phone') || '').trim();
				openResendModal(id, invoice, email, phone);
			});
	});

	if (resendViaEmail) {
		resendViaEmail.addEventListener('change', function () {
			if (fieldEmail) fieldEmail.style.display = this.checked ? 'block' : 'none';
			if (resendError) { resendError.style.display = 'none'; resendError.textContent = ''; }
		});
	}
	if (resendViaSms) {
		resendViaSms.addEventListener('change', function () {
			if (fieldPhone) fieldPhone.style.display = this.checked ? 'block' : 'none';
			if (resendError) { resendError.style.display = 'none'; resendError.textContent = ''; }
		});
	}

	resendCancel.addEventListener('click', function () {
		closeResendModal();
	});

	$(resendModal).on('click', '.payu-modal-backdrop', function () {
		if (resendRequestInFlight) return;
		closeResendModal();
	});

	resendSubmit.addEventListener('click', function () {
		if (resendRequestInFlight) return;
		var sendEmail = resendViaEmail && resendViaEmail.checked;
		var sendSms = resendViaSms && resendViaSms.checked;
		if (!sendEmail && !sendSms) {
			showResendError(cfg.i18n && cfg.i18n.resendSelectChannel ? cfg.i18n.resendSelectChannel : 'Please select at least one channel (Email and/or SMS).');
			return;
		}
		var email = '';
		var phone = '';
		if (sendEmail) {
			email = resendEmail ? resendEmail.value.trim() : '';
			if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				showResendError(cfg.i18n && cfg.i18n.resendEmailRequired ? cfg.i18n.resendEmailRequired : 'Please enter a valid email address.');
				return;
			}
		}
		if (sendSms) {
			phone = resendPhone ? resendPhone.value.replace(/\D/g, '') : '';
			// We don't know user's country; minimal check to avoid empty/typo. PayU validates format.
			if (phone.length < 6) {
				showResendError(cfg.i18n && cfg.i18n.resendPhoneRequired ? cfg.i18n.resendPhoneRequired : 'Please enter a phone number with country code (e.g. +91 9876543210).');
				return;
			}
			if (phone.charAt(0) !== '+') {
				phone = '+' + phone;
			}
		}

		setResendLoading(true);

		var payload = {
			action: cfg.resendAction || 'payu_resend_payment_link',
			nonce: cfg.resendNonce || '',
			payment_link_id: currentPaymentLinkId,
			payu_invoice_number: currentPayuInvoice,
			send_email: sendEmail ? '1' : '0',
			send_sms: sendSms ? '1' : '0',
			email: email,
			phone: phone
		};

		// Use $.ajax with timeout; Share API can be slow (60s server timeout)
		$.ajax({
			url: cfg.ajaxUrl,
			type: 'POST',
			data: payload,
			timeout: 90000,
			dataType: 'json'
		})
			.done(function (res) {
				var isSuccess = res && res.success && res.data;
				var message = (res.data && res.data.message) ? res.data.message : (cfg.i18n && cfg.i18n.resendSuccess) || 'Done! The payment link has been sent.';

				if (isSuccess) {
					closeResendModal();
					// Show success notice below page title (no DB update = no row changes)
					var $wrap = $('.wrap.woocommerce');
					var $anchor = $wrap.length ? $wrap.find('h1').first() : $('.wrap h1').first();
					if ($anchor.length) {
						var notice = $('<div class="notice notice-success is-dismissible"><p></p></div>');
						notice.find('p').text(message);
						$anchor.after(notice);
						setTimeout(function () { notice.fadeOut(function () { $(this).remove(); }); }, 6000);
					} else {
						showResendError(message);
					}
				} else {
					// Error: show server message in modal (do not close modal)
					var errMsg = (res && res.data && res.data.message) ? res.data.message : (res && res.message) ? res.message : (cfg.i18n && cfg.i18n.resendErrorFormat) || (cfg.i18n && cfg.i18n.errorGeneric) || 'Couldn’t send the link. Please check the email and phone number and try again.';
					showResendError(errMsg);
				}
			})
			.fail(function (xhr, status, err) {
				var msg = (cfg.i18n && cfg.i18n.errorGeneric) || 'Something went wrong. Please try again.';
				if (status === 'timeout') {
					msg = (cfg.i18n && cfg.i18n.resendTimeout) ? cfg.i18n.resendTimeout : 'This is taking longer than usual. Please try again.';
				} else if (xhr && xhr.responseJSON) {
					var j = xhr.responseJSON;
					if (j.data && j.data.message) {
						msg = j.data.message;
					} else if (j.message) {
						msg = j.message;
					}
				} else if (xhr && xhr.responseText) {
					try {
						var j = JSON.parse(xhr.responseText);
						if (j.data && j.data.message) msg = j.data.message;
						else if (j.message) msg = j.message;
					} catch (e) {}
				}
				showResendError(msg);
			})
			.always(function () {
				setResendLoading(false);
			});
	});
})();

/* ========== Refresh Payment Link from PayU ========== */
(function () {
	'use strict';

	if (typeof window.jQuery === 'undefined') return;
	var $ = window.jQuery;
	var cfg = window.payuPaymentLinks || {};

	$(document).on('click', '.payu-refresh-btn', function (e) {
		e.preventDefault();
		var btn = $(e.currentTarget);
		if (btn.hasClass('payu-refresh-loading') || btn.hasClass('payu-refresh-disabled') || btn.prop('disabled')) return;
		var id = btn.attr('data-id');
		if (!id) return;

		var $row = btn.closest('tr');
		btn.addClass('payu-refresh-loading').prop('disabled', true);
		var $label = btn.find('.label');
		var labelText = $label.text();
		$label.text('…');

		var payload = {
			action: cfg.refreshAction || 'payu_refresh_payment_link',
			nonce: cfg.refreshNonce || '',
			payment_link_id: id
		};

		$.ajax({
			url: cfg.ajaxUrl,
			type: 'POST',
			data: payload,
			timeout: 60000,
			dataType: 'json'
		})
			.done(function (res) {
				if (res.success && res.data) {
					var d = res.data;
					btn.removeClass('payu-refresh-loading');
					$label.text(labelText);
					if ($row.length) {
						var status = (d.status || '').toUpperCase();
						var paymentLinkStatus = (d.payment_link_status || '').toString().trim().toLowerCase();
						var amount = (d.amount !== undefined && d.amount !== null) ? String(d.amount) : '';
						var currency = (d.currency || '').trim();
						var paidAmount = (d.paid_amount !== undefined && d.paid_amount !== null) ? String(d.paid_amount) : '';
						var expiryDate = d.expiry_date || '';

						var statusLabel = (cfg.statusLabels && cfg.statusLabels.payment && cfg.statusLabels.payment[status]) ? cfg.statusLabels.payment[status] : status || '—';
						var linkStatusLabel = (cfg.statusLabels && cfg.statusLabels.link && cfg.statusLabels.link[paymentLinkStatus]) ? cfg.statusLabels.link[paymentLinkStatus] : (paymentLinkStatus ? paymentLinkStatus : '—');

						var $statusCell = $row.find('.column-status');
						if ($statusCell.length) {
							$statusCell.text(statusLabel);
						}
						var $linkStatusCell = $row.find('.column-payment_link_status');
						if ($linkStatusCell.length) {
							$linkStatusCell.text(linkStatusLabel);
						}
						var $amountCell = $row.find('.column_amount');
						if (!$amountCell.length) {
							$amountCell = $row.find('.column-amount');
						}
						if ($amountCell.length) {
							var amountDisplay = currency ? (currency + ' ' + (amount || paidAmount)) : (amount || paidAmount);
							$amountCell.text(amountDisplay);
						}
						var $expiryCell = $row.find('.column-expiry_date');
						if ($expiryCell.length && expiryDate) {
							var formatted = expiryDate.replace(' ', ' ').substring(0, 16);
							if (formatted.length >= 16) {
								var parts = formatted.split(' ');
								if (parts[0]) {
									formatted = parts[0] + ' ' + (parts[1] || '').substring(0, 5);
								}
							}
							var $display = $expiryCell.find('.payu-expiry-display');
							if ($display.length) {
								$display.text(formatted || '—');
							} else {
								$expiryCell.append('<span class="payu-expiry-display">' + (formatted || '—') + '</span>');
							}
						}
						// Remove edit expiry button from DOM when it should not show (so icon never displays).
						// Use data-link-id to target only the expiry edit button in this row (id = payment_link_id from refresh button).
						var shouldHideExpiryBtn = paymentLinkStatus === 'expired' || (status !== 'PENDING' && status !== 'PARTIALLY_PAID');
						if (shouldHideExpiryBtn) {
							$row.find('button.payu-expire-btn[data-link-id="' + id + '"]').remove();
							$row.find('button.payu-expiry-edit-btn[data-link-id="' + id + '"]').remove();
							$row.find('button[data-link-id="' + id + '"][data-invoice]').remove();
						}
						// Remove any expiry edit button that already has hidden class (e.g. from expiry modal)
						$row.find('button.payu-expire-btn.payu-expire-btn-hidden, button.payu-expiry-edit-btn.payu-expire-btn-hidden').remove();
						if (status === 'PAID' || status === 'EXPIRED') {
							btn.addClass('payu-refresh-disabled').prop('disabled', true);
						} else {
							btn.removeClass('payu-refresh-disabled').prop('disabled', false);
						}
					} else {
						btn.prop('disabled', false);
					}
					var notice = $('<div class="notice notice-success is-dismissible"><p></p></div>');
					notice.find('p').text((cfg.i18n && cfg.i18n.refreshSuccess) || 'Payment link data refreshed successfully.');
					$('.wrap.woocommerce h1').first().after(notice);
					setTimeout(function () { notice.fadeOut(function () { $(this).remove(); }); }, 4000);
				} else {
					var errMsg = (res && res.data && res.data.message) ? res.data.message : (res && res.message) ? res.message : (cfg.i18n && cfg.i18n.refreshError) || 'Could not refresh. Please try again.';
					btn.removeClass('payu-refresh-loading').prop('disabled', false);
					$label.text(labelText);
					var notice = $('<div class="notice notice-error is-dismissible"><p></p></div>');
					notice.find('p').text(errMsg);
					$('.wrap.woocommerce h1').first().after(notice);
				}
			})
			.fail(function (xhr, statusCode, err) {
				btn.removeClass('payu-refresh-loading').prop('disabled', false);
				$label.text(labelText);
				var msg = (cfg.i18n && cfg.i18n.refreshError) || 'Could not refresh. Please try again.';
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				} else if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
					msg = xhr.responseJSON.message;
				} else if (statusCode === 'timeout') {
					msg = (cfg.i18n && cfg.i18n.resendTimeout) || 'This is taking longer than usual. Please try again.';
				}
				var notice = $('<div class="notice notice-error is-dismissible"><p></p></div>');
				notice.find('p').text(msg);
				$('.wrap.woocommerce h1').first().after(notice);
			});
	});
})();