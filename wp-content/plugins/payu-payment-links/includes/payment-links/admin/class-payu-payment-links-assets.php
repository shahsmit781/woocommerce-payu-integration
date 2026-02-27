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
		wp_localize_script(
			'payu-payment-links-actions',
			'payuPaymentLinks',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'payu_payment_links_action' ),
			]
		);
	}
}