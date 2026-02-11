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
	});

})(jQuery);
