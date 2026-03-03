<?php
/**
 * PayU Payment Links – Status display labels (human-readable, not raw DB values).
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return human-readable label for payment status (money outcome).
 *
 * @param string $status Raw value: PENDING, PAID, PARTIALLY_PAID, FAILED, etc.
 * @return string Label for display (e.g. Pending, Paid, Partial paid).
 */
function payu_payment_links_payment_status_label( $status ) {
	$s = $status ? strtoupper( trim( (string) $status ) ) : '';
	switch ( $s ) {
		case 'PAID':
			return __( 'Paid', 'payu-payment-links' );
		case 'PARTIALLY_PAID':
			return __( 'Partial paid', 'payu-payment-links' );
		case 'PENDING':
		default:
			return __( 'Pending', 'payu-payment-links' );
	}
}

/**
 * Return human-readable label for payment link lifecycle status.
 *
 * @param string $link_status Raw value: active, expired, deactivated.
 * @return string Label for display (e.g. Active, Expired, Deactivated).
 */
function payu_payment_links_link_status_label( $link_status ) {
	$s = $link_status ? strtolower( trim( (string) $link_status ) ) : '';
	switch ( $s ) {
		case 'active':
			return __( 'Active', 'payu-payment-links' );
		case 'expired':
			return __( 'Expired', 'payu-payment-links' );
		case 'deactivated':
			return __( 'Deactivated', 'payu-payment-links' );
		default:
			return $link_status !== '' ? esc_html( $link_status ) : '—';
	}
}

/**
 * Return human-readable label for environment.
 *
 * @param string $env Raw value: uat, prod.
 * @return string Label for display.
 */
function payu_payment_links_environment_label( $env ) {
	$s = $env ? strtolower( trim( (string) $env ) ) : '';
	switch ( $s ) {
		case 'prod':
		case 'production':
			return __( 'Production', 'payu-payment-links' );
		case 'uat':
			return __( 'UAT', 'payu-payment-links' );
		default:
			return $env !== '' ? esc_html( $env ) : '—';
	}
}
