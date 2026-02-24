<?php
/**
 * PayU Payment Status Constants – Canonical values for payment outcome and link lifecycle.
 *
 * Distinction (documented for DB, resolution logic, and admin UI):
 *
 * - Payment Status (money outcome): Whether and how much was paid. Stored in wp_payu_payment_links.status
 *   and wp_payu_payment_transactions.status. Allowed: PENDING, PAID, PARTIALLY_PAID. For display/transactions
 *   FAILED is also used. Use PAYU_PAYMENT_STATUS_* constants only; store in UPPERCASE.
 *
 * - Payment Link Status (link lifecycle): PayU link state (active, expired, deactivated), independent of
 *   payment result. Stored in wp_payu_payment_links.payment_link_status. Use PAYU_LINK_STATUS_* constants.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical payment status: no successful payment yet (awaiting or failed).
 * Stored in wp_payu_payment_links.status and used for display when no amount collected.
 */
if ( ! defined( 'PAYU_PAYMENT_STATUS_PENDING' ) ) {
	define( 'PAYU_PAYMENT_STATUS_PENDING', 'PENDING' );
}

/**
 * Canonical payment status: full amount collected.
 * Stored in wp_payu_payment_links.status when amount_paid >= total.
 */
if ( ! defined( 'PAYU_PAYMENT_STATUS_PAID' ) ) {
	define( 'PAYU_PAYMENT_STATUS_PAID', 'PAID' );
}

/**
 * Canonical payment status: partial amount collected (0 < amount_paid < total).
 * Stored in wp_payu_payment_links.status when some but not all paid.
 */
if ( ! defined( 'PAYU_PAYMENT_STATUS_PARTIALLY_PAID' ) ) {
	define( 'PAYU_PAYMENT_STATUS_PARTIALLY_PAID', 'PARTIALLY_PAID' );
}

/**
 * Canonical payment status: payment failed or error (used in transactions and display).
 * Not stored in wp_payu_payment_links.status (allowed values there: PENDING, PAID, PARTIALLY_PAID only).
 */
if ( ! defined( 'PAYU_PAYMENT_STATUS_FAILED' ) ) {
	define( 'PAYU_PAYMENT_STATUS_FAILED', 'FAILED' );
}

/**
 * Payment link lifecycle: link is active and can accept payment.
 */
if ( ! defined( 'PAYU_LINK_STATUS_ACTIVE' ) ) {
	define( 'PAYU_LINK_STATUS_ACTIVE', 'active' );
}

/**
 * Payment link lifecycle: link has expired (time-based).
 */
if ( ! defined( 'PAYU_LINK_STATUS_EXPIRED' ) ) {
	define( 'PAYU_LINK_STATUS_EXPIRED', 'expired' );
}

/**
 * Payment link lifecycle: link was deactivated (manually or by PayU).
 */
if ( ! defined( 'PAYU_LINK_STATUS_DEACTIVATED' ) ) {
	define( 'PAYU_LINK_STATUS_DEACTIVATED', 'deactivated' );
}
