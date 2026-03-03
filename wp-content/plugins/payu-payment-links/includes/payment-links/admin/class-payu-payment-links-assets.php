<?php
defined( 'ABSPATH' ) || exit;

class PayU_Payment_Links_Assets {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( $hook ) {

		/**
		 * Load assets ONLY on:
		 */
		if ( $hook !== 'woocommerce_page_payu-payment-links' ) {
			return;
		}

		// Dashicons (icons)
		wp_enqueue_style( 'dashicons' );

		// Admin CSS
		wp_enqueue_style(
			'payu-payment-links-admin',
			PAYU_PAYMENT_LINKS_PLUGIN_URL . 'assets/css/payment-links-admin.css',
			[],
			PAYU_PAYMENT_LINKS_VERSION
		);

		// Admin JS
		wp_enqueue_script(
			'payu-payment-links-actions',
			PAYU_PAYMENT_LINKS_PLUGIN_URL . 'assets/js/payment-links-actions.js',
			[ 'jquery' ],
			PAYU_PAYMENT_LINKS_VERSION,
			true
		);

		// Pass data to JS
		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/class-payu-status-labels.php';
		$status_labels = [
			'payment' => [
				'PENDING'         => payu_payment_links_payment_status_label( 'PENDING' ),
				'PAID'            => payu_payment_links_payment_status_label( 'PAID' ),
				'PARTIALLY_PAID'  => payu_payment_links_payment_status_label( 'PARTIALLY_PAID' )				
			],
			'link' => [
				'active'      => payu_payment_links_link_status_label( 'active' ),
				'expired'     => payu_payment_links_link_status_label( 'expired' ),
				'deactivated' => payu_payment_links_link_status_label( 'deactivated' ),
			],
		];
		wp_localize_script(
			'payu-payment-links-actions',
			'payuPaymentLinks',
			[
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'expiryUpdateNonce' => wp_create_nonce( 'payu_update_payment_link_expiry' ),
				'expiryUpdateAction' => 'payu_update_payment_link_expiry',
				'resendNonce'      => wp_create_nonce( 'payu_resend_payment_link' ),
				'resendAction'     => 'payu_resend_payment_link',
				'getLinkDetailsNonce'  => wp_create_nonce( 'payu_get_payment_link_details' ),
				'getLinkDetailsAction' => 'payu_get_payment_link_details',
				'refreshNonce'         => wp_create_nonce( 'payu_refresh_payment_link' ),
				'refreshAction'        => 'payu_refresh_payment_link',
				'statusLabels'    => $status_labels,
				'i18n'             => [
					'success'       => __( 'Expiry date updated successfully.', 'payu-payment-links' ),
					'errorGeneric'  => __( 'Something went wrong. Please try again.', 'payu-payment-links' ),
					'resendErrorFormat' => __( 'Couldn’t send the link. Please check the email address and phone number (include country code, e.g. +91) and try again.', 'payu-payment-links' ),
					'expiryRequired' => __( 'Please select an expiry date and time.', 'payu-payment-links' ),
					'expiryFuture'  => __( 'Expiry date and time must be after the current time.', 'payu-payment-links' ),
					'expiryMinuteAhead' => __( 'Expiry must be at least 1 minute from now.', 'payu-payment-links' ),
					'resendSuccess' => __( 'Done! The payment link has been sent.', 'payu-payment-links' ),
					'resendSelectChannel' => __( 'Please choose Email or SMS (or both) to send the link.', 'payu-payment-links' ),
					'resendEmailRequired' => __( 'Please enter a valid email address.', 'payu-payment-links' ),
					'resendPhoneRequired' => __( 'Please enter a valid phone number with country code (e.g. +91 9876543210).', 'payu-payment-links' ),
					'resendTimeout' => __( 'This is taking longer than usual. Please try again.', 'payu-payment-links' ),
					'refreshSuccess' => __( 'Payment link data refreshed successfully.', 'payu-payment-links' ),
					'refreshError'   => __( 'Could not refresh. Please try again.', 'payu-payment-links' ),
				],
			]
		);
	}
}