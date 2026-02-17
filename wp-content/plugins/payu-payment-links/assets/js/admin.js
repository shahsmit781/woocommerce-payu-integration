/**
 * PayU Payment Links Admin JavaScript
 *
 * @package PayU_Payment_Links
 */

(function($) {
	'use strict';

	// Form toggle
	window.PayUInlineFormToggle = function(toggleButtonId, formId, cancelButtonId) {
		var $toggleButton = $('#' + toggleButtonId);
		var $form = $('#' + formId);
		var $cancelBtn = cancelButtonId ? $('#' + cancelButtonId) : null;
		var $formElement = $form.find('form');
		var animationDuration = 250;

		if (!$toggleButton.length || !$form.length) {
			return;
		}

		function closeForm() {
			$form.find('.payu-field-invalid').removeClass('payu-field-invalid');
			$form.find('.payu-field-error-container').empty();
			$form.slideUp(animationDuration, function() {
				$form.removeClass('is-visible');
				$toggleButton.attr('aria-expanded', 'false');
			});
			if ($formElement.length) {
				$formElement[0].reset();
			}
		}

		function openForm() {
			$form.find('.payu-field-invalid').removeClass('payu-field-invalid');
			$form.find('.payu-field-error-container').empty();
			$form.addClass('is-visible');
			$form.slideDown(animationDuration, function() {
				$toggleButton.attr('aria-expanded', 'true');
				$form.find('input, select').first().focus();
			});
		}

		$toggleButton.on('click', function(e) {
			e.preventDefault();
			var isVisible = $form.hasClass('is-visible') && $form.is(':visible');
			if (isVisible) {
				closeForm();
			} else {
				openForm();
			}
		});

		if ($cancelBtn && $cancelBtn.length) {
			$cancelBtn.on('click', function(e) {
				e.preventDefault();
				closeForm();
			});
		}
	};


	jQuery(document).on('click', '.notice.is-dismissible', function (e) {
		if (jQuery(e.target).hasClass('notice-dismiss') || jQuery(e.target).closest('.notice-dismiss').length) {
			jQuery(this).fadeOut();
		}
	});

	// Field-wise validation functions
	// These functions mirror the server-side validation in payu-form-handler.php
	
	function validateCurrency() {
		var $field = $('#payu_config_currency');
		var $errorContainer = $('#payu_config_currency_error');
		var value = $field.val() || '';
		
		$errorContainer.empty();
		$field.removeClass('payu-field-invalid');
		
		// Server-side: empty() check
		if (!value || value.trim() === '') {
			$field.addClass('payu-field-invalid');
			$errorContainer.html('<span class="payu-field-error" role="alert">Currency is required.</span>');
			return false;
		}
		
		return true;
	}

	function validateMerchantId() {
		var $field = $('#payu_config_merchant_id');
		var $errorContainer = $('#payu_config_merchant_id_error');
		// Server-side: sanitize_text_field() which trims whitespace
		var value = ($field.val() || '').trim();
		
		$field.val(value);
		$errorContainer.empty();
		$field.removeClass('payu-field-invalid');
		
		// Server-side: empty() check - matches empty string after trim
		if (!value || value === '') {
			$field.addClass('payu-field-invalid');
			$errorContainer.html('<span class="payu-field-error" role="alert">Merchant ID is required.</span>');
			return false;
		}
		// Server-side: strlen( $merchant_id ) > 100
		else if (value.length > 100) {
			$field.addClass('payu-field-invalid');
			$errorContainer.html('<span class="payu-field-error" role="alert">Merchant ID must not exceed 100 characters.</span>');
			return false;
		}
		
		return true;
	}

	function validateClientId() {
		var $field = $('#payu_config_client_id');
		var $errorContainer = $('#payu_config_client_id_error');
		// Server-side: sanitize_text_field() which trims whitespace
		var value = ($field.val() || '').trim();
		
		$field.val(value);
		$errorContainer.empty();
		$field.removeClass('payu-field-invalid');
		
		// Server-side: empty() check - matches empty string after trim
		if (!value || value === '') {
			$field.addClass('payu-field-invalid');
			$errorContainer.html('<span class="payu-field-error" role="alert">Client ID is required.</span>');
			return false;
		}
		// Server-side: strlen( $client_id ) > 255
		else if (value.length > 255) {
			$field.addClass('payu-field-invalid');
			$errorContainer.html('<span class="payu-field-error" role="alert">Client ID must not exceed 255 characters.</span>');
			return false;
		}
		
		return true;
	}

	function validateClientSecret() {
		var $field = $('#payu_config_client_secret');
		var $errorContainer = $('#payu_config_client_secret_error');
		// Server-side: sanitize_textarea_field() which trims whitespace
		var value = ($field.val() || '').trim();
		
		$field.val(value);
		$errorContainer.empty();
		$field.removeClass('payu-field-invalid');
		
		// Server-side: empty() check - matches empty string after trim
		if (!value || value === '') {
			$field.addClass('payu-field-invalid');
			$errorContainer.html('<span class="payu-field-error" role="alert">Client Secret is required.</span>');
			return false;
		}
		
		return true;
	}

	// AJAX Filtering for Configuration List
	var PayUConfigFilter = {
		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			var self = this;

			// Search button click - AJAX on click
			$(document).on('click', '.payu-config-list .search-box #search-submit', function(e) {
				e.preventDefault();
				var searchTerm = $('.payu-config-list .search-box input[name="s"]').val() || '';
				
				// Get current filters
				var environmentFilter = $('#payu-filter-by-environment').val() || '';
				
				self.filterConfigs({
					s: searchTerm,
					environment_filter: environmentFilter,
					paged: 1 // Reset to first page when searching
				});
			});

			// Search input - AJAX on Enter key
			$(document).on('keypress', '.payu-config-list .search-box input[name="s"]', function(e) {
				if (e.which === 13) { // Enter key
					e.preventDefault();
					var searchTerm = $(this).val() || '';
					var environmentFilter = $('#payu-filter-by-environment').val() || '';
					
					self.filterConfigs({
						s: searchTerm,
						environment_filter: environmentFilter,
						paged: 1
					});
				}
			});

			// Environment dropdown filter - AJAX on change
			$(document).on('change', '#payu-filter-by-environment', function(e) {
				e.preventDefault();
				var environment = $(this).val();
				
				// Preserve search term
				var searchTerm = $('.payu-config-list .search-box input[name="s"]').val() || '';
				
				self.filterConfigs({
					s: searchTerm,
					environment_filter: environment,
					paged: 1 // Reset to first page when filter changes
				});
			});

			// Pagination links - AJAX on click (handle all pagination links)
			$(document).on('click', '.payu-config-list .tablenav-pages a, .payu-config-list .pagination-links a', function(e) {
				e.preventDefault();
				var $link = $(this);
				
				// Skip if disabled
				if ($link.hasClass('disabled') || $link.closest('.disabled').length) {
					return false;
				}
				
				var href = $link.attr('href') || '';
				
				// Preserve current filters
				var searchTerm = $('.payu-config-list .search-box input[name="s"]').val() || '';
				var environmentFilter = $('#payu-filter-by-environment').val() || '';
				
				// Get current page and total pages
				var currentPage = parseInt($('.payu-config-list .current-page').val() || '1', 5);
				var totalPages = parseInt($('.payu-config-list .total-pages').text() || '1', 5);
				
				// Extract paged from URL or determine from button class
				var paged = 1;
				
				// First try to extract from href URL
				if (href && href.indexOf('paged=') !== -1) {
					var match = href.match(/paged=(\d+)/);
					if (match && match[1]) {
						paged = parseInt(match[1], 10);
					}
				}
				// If no paged in URL, check button classes
				else if ($link.hasClass('first-page')) {
					paged = 1;
				} else if ($link.hasClass('prev-page')) {
					paged = Math.max(1, currentPage - 1);
				} else if ($link.hasClass('next-page')) {
					paged = Math.min(totalPages, currentPage + 1);
				} else if ($link.hasClass('last-page')) {
					paged = totalPages;
				}
				// If still no paged determined, try to extract from link text (page numbers)
				else {
					var linkText = $link.text().trim();
					var pageNum = parseInt(linkText, 10);
					if (!isNaN(pageNum) && pageNum > 0) {
						paged = pageNum;
					}
				}

				// Debug logging
				if (typeof console !== 'undefined' && console.log) {
					console.log('Pagination click:', {
						href: href,
						classes: $link.attr('class'),
						currentPage: currentPage,
						totalPages: totalPages,
						calculatedPaged: paged
					});
				}

				self.filterConfigs({
					s: searchTerm,
					environment_filter: environmentFilter,
					paged: paged
				});
			});

			// Handle pagination input field (direct page number entry)
			$(document).on('keypress', '.payu-config-list .current-page', function(e) {
				if (e.which === 13) { // Enter key
					e.preventDefault();
					var $input = $(this);
					var paged = parseInt($input.val() || '1', 5);
					var totalPages = parseInt($('.payu-config-list .total-pages').text() || '1', 5);
					
					if (paged < 1) paged = 1;
					if (paged > totalPages) paged = totalPages;
					
					var searchTerm = $('.payu-config-list .search-box input[name="s"]').val() || '';
					var environmentFilter = $('#payu-filter-by-environment').val() || '';
					self.filterConfigs({
						s: searchTerm,
						environment_filter: environmentFilter,
						paged: paged
					});
				}
			});

			// Sortable column headers - AJAX on click
			$(document).on('click', '.payu-config-list .wp-list-table th.sortable a, .payu-config-list .wp-list-table th.sorted a', function(e) {
				e.preventDefault();
				var $link = $(this);
				var $th = $link.closest('th');
				var href = $link.attr('href');
				
				// Extract orderby from URL
				var clickedOrderby = '';
				if (href && href.indexOf('orderby=') !== -1) {
					var match = href.match(/orderby=([^&]*)/);
					if (match && match[1]) {
						clickedOrderby = decodeURIComponent(match[1]);
					}
				}
				
				// If no orderby in URL, try to get from column class
				if (!clickedOrderby) {
					var columnClass = $th.attr('class') || '';
					if (columnClass.indexOf('column-') !== -1) {
						var match = columnClass.match(/column-([^\s]+)/);
						if (match && match[1]) {
							clickedOrderby = match[1];
						}
					}
				}
				
				// Get current sort state from URL parameters
				var urlParams = new URLSearchParams(window.location.search);
				var currentOrderby = urlParams.get('orderby') || '';
				var currentOrder = (urlParams.get('order') || 'ASC').toUpperCase();
				
				// If no URL params, check table header for current sort state
				if (!currentOrderby && $th.hasClass('sorted')) {
					// Check sorting indicator to determine current order
					var $sortIndicator = $th.find('.sorting-indicator');
					if ($sortIndicator.length) {
						var indicatorClass = $sortIndicator.attr('class') || '';
						if (indicatorClass.indexOf('asc') !== -1) {
							currentOrder = 'ASC';
						} else if (indicatorClass.indexOf('desc') !== -1) {
							currentOrder = 'DESC';
						}
					}
					currentOrderby = clickedOrderby; // Assume current column is sorted
				}
				
				// Determine new order: toggle if same column, default to ASC if different column
				var newOrder = 'ASC';
				if (clickedOrderby && currentOrderby === clickedOrderby) {
					// Same column clicked - toggle order
					newOrder = (currentOrder === 'ASC') ? 'DESC' : 'ASC';
				} else {
					// Different column clicked - default to ASC
					newOrder = 'ASC';
				}

				// Preserve current filters
				var searchTerm = $('.payu-config-list .search-box input[name="s"]').val() || '';
				var environmentFilter = $('#payu-filter-by-environment').val() || '';
				var paged = 1;
				
				// Get page from URL params first (most accurate)
				if (urlParams.has('paged')) {
					paged = parseInt(urlParams.get('paged'), 5) || 1;
				} else if (href && href.indexOf('paged=') !== -1) {
					var match = href.match(/paged=(\d+)/);
					if (match && match[1]) {
						paged = parseInt(match[1], 5);
					}
				}

				self.filterConfigs({
					environment_filter: environmentFilter,
					paged: paged,
					orderby: clickedOrderby,
					order: newOrder
				});
			});
		},

		filterConfigs: function(filters) {
			var $wrapper = $('#payu-config-list-wrapper');
			var $container = $('#payu-config-list-container');
			
			// Show loading state
			$wrapper.addClass('payu-loading');
			$container.append('<div class="payu-loading-overlay"><span class="spinner is-active"></span></div>');

			// Validate AJAX data before sending
			if (typeof payuAjaxData === 'undefined' || !payuAjaxData.filterNonce) {
				console.error('PayU AJAX Data not loaded. Please refresh the page.');
				alert('Configuration error. Please refresh the page.');
				$wrapper.removeClass('payu-loading');
				$container.find('.payu-loading-overlay').remove();
				return;
			}

			// Prepare AJAX data
			var ajaxData = {
				action: 'payu_filter_configs',
				nonce: payuAjaxData.filterNonce,
				s: filters.s || '', // Search term
				environment_filter: filters.environment_filter || '',
				paged: filters.paged || 1,
				orderby: filters.orderby || '',
				order: filters.order || ''
			};

			// Debug: Log AJAX request
			if (typeof console !== 'undefined' && console.log) {
				console.log('PayU Filter AJAX Request:', ajaxData);
			}

			$.ajax({
				url: typeof payuAjaxData !== 'undefined' ? payuAjaxData.ajaxUrl : ajaxurl,
				type: 'POST',
				data: ajaxData,
				dataType: 'json',
				timeout: 30000,
				beforeSend: function(xhr) {
					// Set proper headers
					xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
				},
				success: function(response) {
					// Debug: Log response
					if (typeof console !== 'undefined' && console.log) {
						console.log('PayU Filter AJAX Response:', response);
					}
					$wrapper.removeClass('payu-loading');
					$container.find('.payu-loading-overlay').remove();

					if (response.success && response.data && response.data.html) {
						// Store current filter values before replacing HTML
						var currentEnvironmentFilter = ajaxData.environment_filter || '';
						var currentSearchTerm = ajaxData.s || '';
						
						// Replace table content
						$wrapper.html(response.data.html);
						
						// Restore filter dropdown value after AJAX reload
						if (currentEnvironmentFilter) {
							$('#payu-filter-by-environment').val(currentEnvironmentFilter);
						}
						
						// Restore search input value after AJAX reload
						if (currentSearchTerm) {
							$('.payu-config-list .search-box input[name="s"]').val(currentSearchTerm);
						}
						
						// Note: Status toggle handlers work automatically via event delegation
						// No need to re-initialize after AJAX refresh
						
						// Update URL without page refresh
						var url = new URL(window.location.href);
						if (ajaxData.s) {
							url.searchParams.set('s', ajaxData.s);
						} else {
							url.searchParams.delete('s');
						}
						if (ajaxData.environment_filter) {
							url.searchParams.set('environment_filter', ajaxData.environment_filter);
						} else {
							url.searchParams.delete('environment_filter');
						}
						if (ajaxData.paged && ajaxData.paged > 1) {
							url.searchParams.set('paged', ajaxData.paged);
						} else {
							url.searchParams.delete('paged');
						}
						if (ajaxData.orderby) {
							url.searchParams.set('orderby', ajaxData.orderby);
							url.searchParams.set('order', ajaxData.order || 'ASC');
						} else {
							url.searchParams.delete('orderby');
							url.searchParams.delete('order');
						}
						window.history.pushState({}, '', url.toString());
					} else {
						console.error('Filter request failed:', response);
						alert('Failed to load configurations. Please refresh the page.');
					}
				},
				error: function(xhr, status, error) {
					$wrapper.removeClass('payu-loading');
					$container.find('.payu-loading-overlay').remove();
					
					// Enhanced error logging
					console.error('PayU Filter AJAX Error:', {
						status: status,
						error: error,
						statusCode: xhr.status,
						statusText: xhr.statusText,
						responseText: xhr.responseText,
						requestData: ajaxData
					});

					// Show user-friendly error message
					var errorMessage = 'An error occurred while filtering configurations. ';
					if (xhr.status === 400) {
						errorMessage += 'Bad Request - Please check the browser console for details.';
					} else if (xhr.status === 403) {
						errorMessage += 'Permission denied. Please refresh the page.';
					} else if (xhr.status === 500) {
						errorMessage += 'Server error. Please try again later.';
					} else if (status === 'timeout') {
						errorMessage += 'Request timed out. Please try again.';
					} else {
						errorMessage += 'Please refresh the page and try again.';
					}

					// Try to parse error response
					try {
						var errorResponse = JSON.parse(xhr.responseText);
						if (errorResponse.data && errorResponse.data.message) {
							errorMessage = errorResponse.data.message;
						}
					} catch (e) {
						// Use default error message
					}

					alert(errorMessage);
				}
			});
		}
	};

	// Status Toggle Handler
	var PayUStatusToggle = {
		initialized: false,

		init: function() {
			// Only bind events once (event delegation handles dynamic content)
			if (!this.initialized) {
				this.bindEvents();
				this.initialized = true;
			}
		},

		bindEvents: function() {
			var self = this;

			// Handle toggle switch clicks using event delegation
			// This works for dynamically loaded content (AJAX table refreshes)
			$(document).off('change', '.payu-status-toggle-input').on('change', '.payu-status-toggle-input', function(e) {
				e.preventDefault();
				var $toggle = $(this);
				var $label = $toggle.closest('.payu-status-toggle');
				var configId = $label.data('config-id');
				var currency = $label.data('currency');
				var isChecked = $toggle.is(':checked');
				var newStatus = isChecked ? 'active' : 'inactive';

				// Validate required data
				if (!configId || !currency) {
					console.error('PayU Toggle: Missing config ID or currency');
					$toggle.prop('checked', !isChecked); // Revert toggle
					return;
				}

				// Disable toggle during AJAX request
				$toggle.prop('disabled', true);
				$label.addClass('payu-status-toggle-loading');

				self.toggleStatus(configId, currency, newStatus, $toggle, $label);
			});
		},

		toggleStatus: function(configId, currency, newStatus, $toggle, $label) {
			var self = this;

			// Validate AJAX data
			if (typeof payuAjaxData === 'undefined' || !payuAjaxData.toggleNonce) {
				console.error('PayU AJAX Data not loaded. Please refresh the page.');
				alert('Configuration error. Please refresh the page.');
				$toggle.prop('disabled', false);
				$label.removeClass('payu-status-toggle-loading');
				// Revert toggle state
				$toggle.prop('checked', !$toggle.prop('checked'));
				return;
			}

			// Prepare AJAX data
			var ajaxData = {
				action: 'payu_toggle_status',
				nonce: payuAjaxData.toggleNonce,
				config_id: configId,
				currency: currency,
				status: newStatus
			};

			$.ajax({
				url: typeof payuAjaxData !== 'undefined' ? payuAjaxData.ajaxUrl : ajaxurl,
				type: 'POST',
				data: ajaxData,
				dataType: 'json',
				timeout: 10000, // Reduced timeout for faster feedback
				cache: false, // Ensure fresh request
				success: function(response) {
					$toggle.prop('disabled', false);
					$label.removeClass('payu-status-toggle-loading');

					if (response.success) {
						// Update toggle state based on response
						if (response.data && response.data.status !== undefined) {
							var isActive = response.data.status === 'active';
							$toggle.prop('checked', isActive);
							
							if (isActive) {
								$label.addClass('payu-status-toggle-active');
							} else {
								$label.removeClass('payu-status-toggle-active');
							}
						}
					} else {
						// Revert toggle state on error (especially if prevented)
						var shouldRevert = true;
						if (response.data && response.data.prevent_update === true) {
							// This is a prevention case - definitely revert
							shouldRevert = true;
						}
						
						if (shouldRevert) {
							$toggle.prop('checked', !$toggle.prop('checked'));
							if ($toggle.prop('checked')) {
								$label.addClass('payu-status-toggle-active');
							} else {
								$label.removeClass('payu-status-toggle-active');
							}
						}

						var errorMessage = response.data && response.data.message 
							? response.data.message 
							: 'Failed to update status. Please try again.';
						
						// Show alert with the error message
						alert(errorMessage);
					}
				},
				error: function(xhr, status, error) {
					$toggle.prop('disabled', false);
					$label.removeClass('payu-status-toggle-loading');
					
					// Revert toggle state on error
					$toggle.prop('checked', !$toggle.prop('checked'));
					if ($toggle.prop('checked')) {
						$label.addClass('payu-status-toggle-active');
					} else {
						$label.removeClass('payu-status-toggle-active');
					}

					var errorMessage = 'Network error occurred. Please check your connection and try again.';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMessage = xhr.responseJSON.data.message;
					} else if (status === 'timeout') {
						errorMessage = 'Request timed out. Please try again.';
					}

					console.error('PayU Toggle Status AJAX Error:', {
						status: status,
						error: error,
						statusCode: xhr.status,
						responseText: xhr.responseText
					});

					alert(errorMessage);
				}
			});
		}
	};

	function validateEditMerchantId() {
		var $field = $('#payu_edit_merchant_id');
		var $error = $('#payu_edit_merchant_id_error');
		var value = ($field.val() || '').trim();

		$error.empty();
		$field.removeClass('payu-field-invalid');

		if (!value) {
			$field.addClass('payu-field-invalid');
			$error.html('<span class="payu-field-error">Merchant ID is required.</span>');
			return false;
		}

		if (value.length > 100) {
			$field.addClass('payu-field-invalid');
			$error.html('<span class="payu-field-error">Max 100 characters allowed.</span>');
			return false;
		}

		return true;
	}

	function validateEditClientId() {
		var $field = $('#payu_edit_client_id');
		var $error = $('#payu_edit_client_id_error');
		var value = ($field.val() || '').trim();

		$error.empty();
		$field.removeClass('payu-field-invalid');

		if (!value) {
			$field.addClass('payu-field-invalid');
			$error.html('<span class="payu-field-error">Client ID is required.</span>');
			return false;
		}

		if (value.length > 255) {
			$field.addClass('payu-field-invalid');
			$error.html('<span class="payu-field-error">Max 255 characters allowed.</span>');
			return false;
		}

		return true;
	}

	function validateEditClientSecret() {
		var $field = $('#payu_edit_client_secret');
		var $error = $('#payu_edit_client_secret_error');
		var value = $field.val() || '';

		$error.empty();
		$field.removeClass('payu-field-invalid');
		if (value.length === 0) {
			return true;
		}
		if (value.length > 500) {
			$field.addClass('payu-field-invalid');
			$error.html('<span class="payu-field-error">Client Secret must not exceed 500 characters.</span>');
			return false;
		}
		return true;
	}

	// Simple form validation
	$(document).ready(function() {
		// Initialize form toggle
		if ($('#payu-add-configuration-button').length && $('#payu-add-configuration-form').length) {
			PayUInlineFormToggle(
				'payu-add-configuration-button',
				'payu-add-configuration-form',
				'payu-cancel-configuration'
			);
		}

		// Initialize AJAX filtering
		if ($('#payu-config-list-container').length) {
			PayUConfigFilter.init();
		}

		// Initialize status toggle (always initialize - uses event delegation)
		PayUStatusToggle.init();

		// Field-wise validation on blur/change
		$('#payu_config_currency').on('change', function() {
			validateCurrency();
		});

		$('#payu_config_merchant_id').on('blur', function() {
			validateMerchantId();
		});

		$('#payu_config_client_id').on('blur', function() {
			validateClientId();
		});

		$('#payu_config_client_secret').on('blur', function() {
			validateClientSecret();
		});

		// AJAX form submission with client-side validation
		$('.payu-config-form').on('submit', function(e) {
			e.preventDefault();
			
			var $form = $(this);
			var $submitButton = $form.find('.payu-submit-button');
			var $messagesContainer = $('#payu-ajax-messages');
			var isValid = true;
			var firstInvalid = null;

			// Hide previous messages and clear field errors
			$messagesContainer.hide().empty();
			$form.find('.payu-field-invalid').removeClass('payu-field-invalid');
			$form.find('.payu-field-error-container').empty();

			// Client-side validation - validate all fields
			if (!validateCurrency()) {
				isValid = false;
				if (!firstInvalid) firstInvalid = $('#payu_config_currency');
			}

			if (!validateMerchantId()) {
				isValid = false;
				if (!firstInvalid) firstInvalid = $('#payu_config_merchant_id');
			}

			if (!validateClientId()) {
				isValid = false;
				if (!firstInvalid) firstInvalid = $('#payu_config_client_id');
			}

			if (!validateClientSecret()) {
				isValid = false;
				if (!firstInvalid) firstInvalid = $('#payu_config_client_secret');
			}

			// Block submission if client-side validation fails
			if (!isValid) {
				if (firstInvalid) {
					firstInvalid.focus();
				}
				return false;
			}

			// Disable submit button and show loading state
			$submitButton.prop('disabled', true).text('Saving...');

			// Prepare form data
			var formData = {
				action: 'payu_save_currency_config',
				nonce: typeof payuAjaxData !== 'undefined' ? payuAjaxData.nonce : '',
				payu_config_currency: $('#payu_config_currency').val(),
				payu_config_merchant_id: $('#payu_config_merchant_id').val(),
				payu_config_client_id: $('#payu_config_client_id').val(),
				payu_config_client_secret: $('#payu_config_client_secret').val(),
				payu_config_environment: $('#payu_config_environment').val()
			};

			// AJAX request
			$.ajax({
				url: typeof payuAjaxData !== 'undefined' ? payuAjaxData.ajaxUrl : ajaxurl,
				type: 'POST',
				data: formData,
				dataType: 'json',
				timeout: 30000,
				success: function(response) {
					$submitButton.prop('disabled', false).text('Save Configuration');

					if (response.success) {
						// Success: Show success message
						$messagesContainer
							.removeClass('notice-error')
							.addClass('notice notice-success is-dismissible')
							.html('<p>' + (response.data.message || 'Configuration saved successfully.') + '</p>')
							.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>')
							.fadeIn();

						// Reset form and close it after 2 seconds
						setTimeout(function() {
							$form[0].reset();
							$form.find('.payu-field-invalid').removeClass('payu-field-invalid');
							$form.find('.payu-field-error-container').empty();
							
							// Close form
							var $formContainer = $('#payu-add-configuration-form');
							$formContainer.slideUp(250, function() {
								$formContainer.removeClass('is-visible');
								$('#payu-add-configuration-button').attr('aria-expanded', 'false');
							});
							
							setTimeout(function() {
								window.location.reload();
							}, 1000);
						}, 2000);
					} else {
						// Error: Handle field-specific errors
						var errorMessage = response.data && response.data.message 
							? response.data.message 
							: 'An error occurred. Please try again.';
						
						// Clear all previous field errors
						$form.find('.payu-field-invalid').removeClass('payu-field-invalid');
						$form.find('.payu-field-error-container').empty();

						// Display field-specific errors
						if (response.data && response.data.field_errors) {
							var fieldErrors = response.data.field_errors;
							var firstInvalidField = null;

							// Display errors for each field
							$.each(fieldErrors, function(fieldName, errorMsg) {
								var $field = $('#' + fieldName);
								var $errorContainer = $('#' + fieldName + '_error');
								
								if ($field.length && $errorContainer.length) {
									$field.addClass('payu-field-invalid');
									$errorContainer.html('<span class="payu-field-error" role="alert">' + errorMsg + '</span>');
									
									if (!firstInvalidField) {
										firstInvalidField = $field;
									}
								}
							});

							// Focus first invalid field
							if (firstInvalidField) {
								firstInvalidField.focus();
							}
						}

						// Show general error message
						$messagesContainer
							.removeClass('notice-success')
							.addClass('notice notice-error is-dismissible')
							.html('<p>' + errorMessage + '</p>')
							.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>')
							.fadeIn();
					}
				},
				error: function(xhr, status, error) {
					$submitButton.prop('disabled', false).text('Save Configuration');

					var errorMessage = 'Network error occurred. Please check your connection and try again.';
					var fieldErrors = {};
					
					if (xhr.responseJSON && xhr.responseJSON.data) {
						if (xhr.responseJSON.data.message) {
							errorMessage = xhr.responseJSON.data.message;
						}
						if (xhr.responseJSON.data.field_errors) {
							fieldErrors = xhr.responseJSON.data.field_errors;
						}
					} else if (status === 'timeout') {
						errorMessage = 'Request timed out. The API verification is taking longer than expected. Please try again.';
					}

					// Clear all previous field errors
					$form.find('.payu-field-invalid').removeClass('payu-field-invalid');
					$form.find('.payu-field-error-container').empty();

					// Display field-specific errors if available
					if (Object.keys(fieldErrors).length > 0) {
						var firstInvalidField = null;
						$.each(fieldErrors, function(fieldName, errorMsg) {
							var $field = $('#' + fieldName);
							var $errorContainer = $('#' + fieldName + '_error');
							
							if ($field.length && $errorContainer.length) {
								$field.addClass('payu-field-invalid');
								$errorContainer.html('<span class="payu-field-error" role="alert">' + errorMsg + '</span>');
								
								if (!firstInvalidField) {
									firstInvalidField = $field;
								}
							}
						});

						if (firstInvalidField) {
							firstInvalidField.focus();
						}
					}

					$messagesContainer
						.removeClass('notice-success')
						.addClass('notice notice-error is-dismissible')
						.html('<p>' + errorMessage + '</p>')
						.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>')
						.fadeIn();
				}
			});

			return false;
		});

		// Double-submit guard: ignore second submit while AJAX is in progress
		var payuEditFormSubmitting = false;

		// Edit configuration form: always prevent default; run client-side validation first, then submit via AJAX
		$(document).on('submit', '#payu-edit-config-form', function (e) {
			e.preventDefault();
			if (payuEditFormSubmitting) {
				return false;
			}
			var $form = $(this);
			var $ajaxError = $form.find('#payu-edit-ajax-error');
			var isValid = true;
			var firstInvalid = null;

			$form.find('.payu-field-error-container').empty();
			$form.find('.payu-field-invalid').removeClass('payu-field-invalid');
			$ajaxError.hide().find('p').empty();

			if (!validateEditMerchantId()) {
				isValid = false;
				firstInvalid = $('#payu_edit_merchant_id');
			}
			if (!validateEditClientId()) {
				isValid = false;
				if (!firstInvalid) firstInvalid = $('#payu_edit_client_id');
			}
			if (!validateEditClientSecret()) {
				isValid = false;
				if (!firstInvalid) firstInvalid = $('#payu_edit_client_secret');
			}
			if (!isValid) {
				if (firstInvalid) firstInvalid.focus();
				return false;
			}

			payuEditFormSubmitting = true;
			var $btn = $form.find('button[type="submit"]');
			var data = $form.serialize() + '&action=payu_update_currency_config';
			$btn.prop('disabled', true);

			function showUpdateError(msg) {
				var $err = $form.find('#payu-edit-ajax-error');
				if (!$err.length) $err = $('#payu-edit-ajax-error');
				if (!$err.length) return;
				$err.find('p').text(msg || 'Update failed.');
				$err.css('display', 'block').show();
				if ($err[0]) $err[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
			}

			function resetSubmitState() {
				payuEditFormSubmitting = false;
				$btn.prop('disabled', false);
			}

			$.ajax({
				url: typeof payuAjaxData !== 'undefined' ? payuAjaxData.ajaxUrl : '',
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					if (response && response.success && response.data && response.data.redirect) {
						window.location.href = response.data.redirect;
						return;
					}
					// WordPress wp_send_json_error returns 200 with success: false and data.message
					var msg = (response && response.data && response.data.message) ? response.data.message : 'Update failed.';
					showUpdateError(msg);
					resetSubmitState();
				},
				error: function (xhr) {
					var msg = 'Request failed. Please try again.';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					} else if (xhr.responseText) {
						try {
							var parsed = JSON.parse(xhr.responseText);
							if (parsed.data && parsed.data.message) msg = parsed.data.message;
						} catch (e) {}
					}
					showUpdateError(msg);
					resetSubmitState();
				}
			});
			return false;
		});

		// Remove error param from URL on edit page so refresh does not show the message again
		if ($('#payu-edit-config-form').length && window.location.search.indexOf('payu_config_error') !== -1) {
			var url = new URL(window.location.href);
			url.searchParams.delete('payu_config_error');
			window.history.replaceState({}, document.title, url.toString());
		}
	});

	


})(jQuery);
