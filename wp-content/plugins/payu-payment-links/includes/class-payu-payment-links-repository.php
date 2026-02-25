<?php
/**
 * PayU Payment Links Repository – Fetches payment link data from the database.
 *
 * Read-only. No API calls. Used by order admin UI to display links for an order.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Payment_Links_Repository
 */
class PayU_Payment_Links_Repository {

	/**
	 * Get payment links for an order from wp_payu_payment_links.
	 * Returns aggregated row data only. Sorted by updated_at DESC (latest first).
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array List of stdClass objects with: id, payu_invoice_number, payment_link_url, payment_link_status, status, amount, paid_amount, remaining_amount, currency, updated_at.
	 */
	public static function get_payment_links_by_order_id( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return array();
		}

		$table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payu_invoice_number, payment_link_url, payment_link_status, status, amount, paid_amount, remaining_amount, currency, updated_at
				FROM {$table}
				WHERE order_id = %d AND ( is_deleted = 0 OR is_deleted IS NULL )
				ORDER BY updated_at DESC",
				$order_id
			),
			OBJECT
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get remaining payable amount for an order from latest database state.
	 * Formula: order_total - SUM(successful_transaction.amount).
	 * Only successful transactions (SUCCESS, PAID) are summed; link lifecycle is ignored.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{ order_total: float, paid_total: float, remaining: float } Order total, sum of successful transaction amounts, and remaining (>= 0).
	 */
	public static function get_remaining_payable_for_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		$default  = array(
			'order_total' => 0.0,
			'paid_total'  => 0.0,
			'remaining'   => 0.0,
		);
		if ( $order_id <= 0 ) {
			return $default;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! $order->get_id() ) {
			return $default;
		}

		$order_total = (float) $order->get_total();
		$links_table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';
		$txn_table   = function_exists( 'payu_get_payment_transactions_table_name' ) ? payu_get_payment_transactions_table_name() : $wpdb->prefix . 'payu_payment_transactions';

		$paid_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(t.amount), 0) FROM {$txn_table} t
				INNER JOIN {$links_table} l ON l.id = t.payment_link_id AND l.order_id = %d
				WHERE t.status IN ('SUCCESS', 'PAID')",
				$order_id
			)
		);
		$remaining = max( 0.0, $order_total - $paid_total );

		return array(
			'order_total' => $order_total,
			'paid_total'  => $paid_total,
			'remaining'   => $remaining,
		);
	}
}
