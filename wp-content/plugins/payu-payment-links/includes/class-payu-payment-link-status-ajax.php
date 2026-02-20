<?php
/**
 * PayU Payment Link Status – AJAX handler for fetching status from PayU Get Single Payment Link API
 *
 * Validates invoice, calls PayU API, persists response, returns JSON for status page.
 *
 * @package PayU_Payment_Links
*/

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Payment_Link_Status_Ajax
*/

class PayU_Payment_Link_Status_Ajax {

	const AJAX_ACTION = 'payu_fetch_payment_link_status';

	/**
	 * Register AJAX actions (logged-in and non-logged-in for public status page).
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_fetch_status' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_fetch_status' ) );
	}

	/**
	 * Handle AJAX request: validate invoice, call PayU API, persist, return JSON.
	 */
	public static function handle_fetch_status() {
		$invoice = isset( $_POST['invoice'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice'] ) ) : '';

		if ( '' === $invoice ) {
			wp_send_json_error( array(
				'message' => __( 'Invalid or missing invoice number.', 'payu-payment-links' ),
				'code'    => 'invalid_invoice',
			) );
		}

		$result = self::fetch_and_persist_payment_link_status( $invoice );
		if ( is_wp_error( $result ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PayU status fetch error: ' . $result->get_error_message() );
			}
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Fetch payment link from PayU API, persist to DB, return display payload.
	 *
	 * @param string $invoice_number PayU invoice number.
	 * @return array|WP_Error Display data (display_status, amount_paid, remaining, total, currency, order_ref, invoice, transaction_summary) or WP_Error.
	 */
	public static function fetch_and_persist_payment_link_status( $invoice_number ) {
		global $wpdb;

		$invoice_number = sanitize_text_field( $invoice_number );
		if ( '' === $invoice_number ) {
			return new WP_Error( 'invalid_invoice', __( 'Invalid invoice number.', 'payu-payment-links' ) );
		}

		$table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';

		// Single query: find row by payu_invoice_number or by order_id when invoice is WC{id}.
		$order_id = 0;
		if ( preg_match( '/^WC(\d+)$/i', $invoice_number, $m ) ) {
			$order_id = (int) $m[1];
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, order_id, config_id, currency, amount, paid_amount, remaining_amount, status, payu_invoice_number FROM {$table} WHERE ( is_deleted = 0 OR is_deleted IS NULL ) AND ( payu_invoice_number = %s OR ( order_id = %d AND %d > 0 ) ) LIMIT 1",
				$invoice_number,
				$order_id,
				$order_id
			)
		);
		if ( ! $row ) {
			return new WP_Error( 'no_link', __( 'Payment link not found for this invoice.', 'payu-payment-links' ) );
		}

		// Config: one query when possible (by config_id from row, else by currency, else first active).
		$config = null;
		if ( ! empty( $row->config_id ) && function_exists( 'payu_get_currency_config_by_id' ) ) {
			$config = payu_get_currency_config_by_id( $row->config_id );
		}
		if ( ( ! $config || empty( $config->merchant_id ) ) && ! empty( $row->currency ) && function_exists( 'payu_get_active_config_by_currency' ) ) {
			$config = payu_get_active_config_by_currency( $row->currency );
		}
		if ( ( ! $config || empty( $config->merchant_id ) ) && function_exists( 'payu_get_first_active_config' ) ) {
			$config = payu_get_first_active_config();
		}
		if ( ! $config || empty( $config->merchant_id ) ) {
			return new WP_Error( 'no_config', __( 'No PayU configuration found. Add an active PayU currency config in WooCommerce → Settings → Payments.', 'payu-payment-links' ) );
		}
		if ( ! function_exists( 'payu_decrypt_client_secret' ) ) {
			return new WP_Error( 'decrypt_missing', __( 'Unable to use stored credentials.', 'payu-payment-links' ) );
		}
		$client_secret = payu_decrypt_client_secret( $config->client_secret );
		if ( false === $client_secret || '' === $client_secret ) {
			return new WP_Error( 'decrypt_failed', __( 'Unable to use stored credentials.', 'payu-payment-links' ) );
		}

		if ( ! class_exists( 'PayU_Token_Manager' ) ) {
			return new WP_Error( 'token_missing', __( 'Token manager not available.', 'payu-payment-links' ) );
		}

		$environment = isset( $config->environment ) ? $config->environment : 'uat';
		$api_base    = ( 'prod' === $environment ) ? 'https://oneapi.payu.in' : 'https://uatoneapi.payu.in';
		$payu_invoice = ( $row && ! empty( $row->payu_invoice_number ) ) ? $row->payu_invoice_number : $invoice_number;
		$url         = $api_base . '/payment-links/' . rawurlencode( $payu_invoice );

		$token = PayU_Token_Manager::get_token_for_read_payment_link( $config->merchant_id, $config->client_id, $client_secret, $environment );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'merchantId'    => $config->merchant_id,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$dec  = json_decode( $body, true );

		if ( 401 === $code ) {
			$scope   = PayU_Token_Manager::SCOPE_READ_PAYMENT_LINKS;
			$scope   = function_exists( 'payu_normalize_scope_string' ) ? payu_normalize_scope_string( $scope ) : $scope;
			$hash    = function_exists( 'payu_scope_hash' ) ? payu_scope_hash( $scope ) : hash( 'sha256', $scope );
			$env_db  = PayU_Token_Manager::normalize_environment_for_db( $environment );
			PayU_Token_Manager::invalidate_token( $config->merchant_id, $env_db, $hash );
			$token = PayU_Token_Manager::get_token_for_read_payment_link( $config->merchant_id, $config->client_id, $client_secret, $environment );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $token,
						'merchantId'    => $config->merchant_id,
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$dec  = json_decode( $body, true );
		}

		if ( 200 !== $code && 201 !== $code ) {
			$msg = isset( $dec['message'] ) ? $dec['message'] : ( isset( $dec['error_description'] ) ? $dec['error_description'] : __( 'PayU API error.', 'payu-payment-links' ) );
			return new WP_Error( 'payu_api', $msg );
		}
		$api_status = isset( $dec['status'] ) ? (int) $dec['status'] : -1;
		if ( 0 !== $api_status ) {
			$msg = isset( $dec['message'] ) ? $dec['message'] : __( 'Payment link not found or error.', 'payu-payment-links' );
			return new WP_Error( 'payu_result', $msg );
		}

		$result  = isset( $dec['result'] ) ? $dec['result'] : array();
		$display = self::build_display_from_api_result( $result, $invoice_number, $row );
		$payu_invoice = ! empty( $row->payu_invoice_number ) ? $row->payu_invoice_number : $invoice_number;
		$saved = self::persist_api_response( (int) $row->id, $result, $body, $display, $payu_invoice );
		if ( ! $saved ) {
			return new WP_Error(
				'payu_persist_failed',
				__( 'Something went wrong while saving payment status. Please try again.', 'payu-payment-links' )
			);
		}
		$display['invoice'] = $invoice_number;
		return $display;
	}

	/**
	 * Map PayU result to display payload and resolve display_status.
	 *
	 * @param array       $result  PayU API result object.
	 * @param string      $invoice Invoice number.
	 * @param object|null $row     Existing payment link row or null.
	 * @return array display_status, amount_paid, remaining, total, currency, order_ref, invoice, transaction_summary.
	 */
	private static function build_display_from_api_result( $result, $invoice, $row ) {
		$order_ref = $row && ! empty( $row->order_id ) ? (string) $row->order_id : '—';
		$currency  = $row && ! empty( $row->currency ) ? $row->currency : ( isset( $result['currency'] ) ? sanitize_text_field( $result['currency'] ) : '' );
		if ( '' === $currency && function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
		}
		$total = self::get_amount_from_result( $result, array( 'totalAmount', 'subAmount', 'amountRequested' ), $row ? (float) $row->amount : 0 );
		$amount_paid = self::get_amount_from_result( $result, array( 'totalAmountCollected', 'totalRevenue', 'amountCollected' ), $row ? (float) $row->paid_amount : 0 );

		$remaining    = $total > 0 ? (float) ( $total - $amount_paid ) : 0;
		$display_status = self::resolve_display_status($amount_paid, $total, $result );

		$transaction_summary = null;
		if ( ! empty( $result['summary'] ) && is_array( $result['summary'] ) ) {
			$transaction_summary = wp_json_encode( $result['summary'] );
		}

		return array(
			'display_status'       => $display_status,
			'amount_paid'          => $amount_paid,
			'remaining'            => $remaining,
			'total'                => $total,
			'currency'             => $currency,
			'order_ref'            => $order_ref,
			'invoice'              => $invoice,
			'transaction_summary'  => $transaction_summary,
		);
	}

	/**
	 * Get a single numeric amount from PayU result, trying multiple keys (top-level and result.summary).
	 *
	 * @param array  $result   PayU result object.
	 * @param array  $keys     Key names to try (e.g. totalAmount, totalAmountCollected, totalRevenue).
	 * @param float  $fallback Fallback value if none found.
	 * @return float
	 */
	private static function get_amount_from_result(array $result, array $keys, float $fallback = 0): float
	{
		foreach ($keys as $key) {
			if (isset($result[$key]) && is_numeric($result[$key])) {
				return (float) $result[$key];
			}
		}

		if (!empty($result['summary']) && is_array($result['summary'])) {
			foreach ($keys as $key) {
				if (isset($result['summary'][$key]) && is_numeric($result['summary'][$key])) {
					return (float) $result['summary'][$key];
				}
			}
		}

		return $fallback;
	}

	/**
	 * Resolve final display status: PAID, PARTIALLY_PAID, FAILED, ACTIVE, EXPIRED.
	 * Payment status is driven by amount collected first; API "expired" means link inactive, not necessarily unpaid.
	 *
	 * @param string $payu_status  Status from API (e.g. ACTIVE, PAID, EXPIRED).
	 * @param float  $amount_paid  Amount collected.
	 * @param float  $total        Total amount.
	 * @param array  $result       Full result for active/expiry.
	 * @return string
	 */
	/**
	 * Resolve canonical payment status for PayU Payment Links.
	 *
	 * Returns one of:
	 * PAID, PARTIALLY_PAID, FAILED
	 */
	private static function resolve_display_status( $amount_paid, $total, $result ) {

		// Full payment
		if ( $total > 0 && $amount_paid >= $total ) {
			return 'PAID';
		}

		// Partial payment
		if ( $total > 0 && $amount_paid > 0 && $amount_paid < $total ) {
			return 'PARTIALLY_PAID';
		}

		return 'FAILED';
	}

	/**
	 * Persist API response: update wp_payu_payment_links with data from PayU API response.
	 * Updates status, amounts, transaction_summary, and raw JSON. Tries by row id first; falls back to payu_invoice_number if no row updated.
	 *
	 * @param int         $row_id            Payment link row id (primary key).
	 * @param array       $result            PayU API result object (from response).
	 * @param string      $raw_json          Raw API response body.
	 * @param array       $display           Display payload (total, amount_paid, remaining, display_status, transaction_summary).
	 * @param string|null $fallback_invoice   PayU invoice number for fallback WHERE when update by id affects 0 rows.
	 */
	private static function persist_api_response( $row_id, $result, $raw_json, $display, $fallback_invoice = null ) {
		global $wpdb;
		$table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';
		// Payment status from API: store resolved display status (PAID, PARTIALLY_PAID, FAILED).
		$status = isset( $display['display_status'] ) ? sanitize_text_field( $display['display_status'] ) : 'pending';
		$total  = isset( $display['total'] ) ? (float) $display['total'] : 0;
		$paid   = isset( $display['amount_paid'] ) ? (float) $display['amount_paid'] : 0;
		$remain = isset( $display['remaining'] ) ? (float) $display['remaining'] : 0;
		$summary = isset( $display['transaction_summary'] ) ? $display['transaction_summary'] : null;
		if ( ! empty( $result['summary'] ) && is_array( $result['summary'] ) ) {
			$summary = wp_json_encode( $result['summary'] );
		}
		$raw_json_safe = is_string( $raw_json ) ? $raw_json : '';
		$decoded = is_string( $raw_json ) ? json_decode( $raw_json, true ) : null;
		if ( is_array( $decoded ) ) {
			$encoded = wp_json_encode( $decoded );
			if ( false !== $encoded ) {
				$raw_json_safe = $encoded;
			}
		}

		$cols = array(
			'status'                 => $status,
			'amount'                 => $total,
			'paid_amount'            => $paid,
			'remaining_amount'       => $remain,
			'transaction_summary'    => $summary,
			'payu_api_response_json' => $raw_json_safe,
			'updated_at'             => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%f', '%f', '%f', '%s', '%s', '%s' );

		$rows_affected = 0;
		$row_id = (int) $row_id;
		if ( $row_id > 0 ) {
			$rows_affected = $wpdb->update(
				$table,
				$cols,
				array( 'id' => $row_id ),
				$formats,
				array( '%d' )
			);
			if ( false === $rows_affected && $wpdb->last_error && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PayU persist_api_response (by id): ' . $wpdb->last_error );
			}
			$rows_affected = (int) $rows_affected;
		}
		// Fallback: update by payu_invoice_number if no row updated (e.g. id mismatch or table prefix).
		if ( $rows_affected === 0 && ! empty( $fallback_invoice ) ) {
			$rows_affected = $wpdb->update(
				$table,
				$cols,
				array( 'payu_invoice_number' => $fallback_invoice ),
				$formats,
				array( '%s' )
			);
			if ( false === $rows_affected && $wpdb->last_error && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PayU persist_api_response (by invoice): ' . $wpdb->last_error );
			}
			$rows_affected = (int) max( 0, $rows_affected );
		}
		return ( $rows_affected > 0 );
	}
}

PayU_Payment_Link_Status_Ajax::register();
