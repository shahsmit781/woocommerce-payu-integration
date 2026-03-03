<?php
/**
 * PayU Payment Links – Resend modal (HTML only)
 *
 * Variables: none. Modal is filled by JS with payment_link_id, payu_invoice_number, email, phone from DB.
 * User can select Send via Email and/or Send via SMS; both inputs pre-filled from record.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="payu-resend-modal" class="payu-modal" role="dialog" aria-labelledby="payu-resend-modal-title" aria-modal="true" hidden>
	<div class="payu-modal-backdrop"></div>
	<div class="payu-modal-content payu-resend-modal-content">
		<h2 id="payu-resend-modal-title" class="payu-modal-title"><?php esc_html_e( 'Resend payment link', 'payu-payment-links' ); ?></h2>
		<p class="payu-modal-description"><?php esc_html_e( 'Select one or both channels. Email and phone are pre-filled from the payment link record.', 'payu-payment-links' ); ?></p>
		<p class="payu-resend-modal-error payu-modal-error" role="alert" aria-live="polite" style="display:none;"></p>

		<p class="payu-modal-field payu-resend-checkboxes">
			<label class="payu-resend-checkbox-label">
				<input type="checkbox" id="payu-resend-via-email" class="payu-resend-via-email" value="1" />
				<?php esc_html_e( 'Send via Email', 'payu-payment-links' ); ?>
			</label>
			<label class="payu-resend-checkbox-label">
				<input type="checkbox" id="payu-resend-via-sms" class="payu-resend-via-sms" value="1" />
				<?php esc_html_e( 'Send via SMS', 'payu-payment-links' ); ?>
			</label>
		</p>

		<p class="payu-modal-field payu-resend-field-email" style="display:none;">
			<label for="payu-resend-email"><?php esc_html_e( 'Email address', 'payu-payment-links' ); ?> <span class="required">*</span></label>
			<input type="email" id="payu-resend-email" class="regular-text" placeholder="<?php esc_attr_e( 'user@example.com', 'payu-payment-links' ); ?>" value="" />
		</p>

		<p class="payu-modal-field payu-resend-field-phone" style="display:none;">
			<label for="payu-resend-phone"><?php esc_html_e( 'Phone number', 'payu-payment-links' ); ?> <span class="required">*</span></label>
			<input type="tel" id="payu-resend-phone" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. +91 9876543210 or +1 555 123 4567', 'payu-payment-links' ); ?>" value="" />
			<span class="description"><?php esc_html_e( 'Use international format with country code. PayU will validate the number.', 'payu-payment-links' ); ?></span>
		</p>

		<p class="payu-modal-actions">
			<button type="button" id="payu-resend-submit" class="button button-primary"><?php esc_html_e( 'Resend', 'payu-payment-links' ); ?></button>
			<button type="button" id="payu-resend-cancel" class="button"><?php esc_html_e( 'Cancel', 'payu-payment-links' ); ?></button>
			<span class="payu-resend-spinner is-hidden" aria-hidden="true"></span>
		</p>
		<div id="payu-resend-loading-overlay" class="payu-resend-loading-overlay" aria-hidden="true" hidden>
			<span class="payu-resend-loading-spinner" aria-hidden="true"></span>
			<p class="payu-resend-loading-text"><?php esc_html_e( 'Sending… Please wait.', 'payu-payment-links' ); ?></p>
		</div>
	</div>
</div>
