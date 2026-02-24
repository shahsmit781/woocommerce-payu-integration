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
				"SELECT id, order_id, udf1, config_id, currency, amount, paid_amount, remaining_amount, status, payu_invoice_number, created_at FROM {$table} WHERE ( is_deleted = 0 OR is_deleted IS NULL ) AND ( payu_invoice_number = %s OR ( order_id = %d AND %d > 0 ) ) LIMIT 1",
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

		$result       = isset( $dec['result'] ) ? $dec['result'] : array();
		$display      = self::build_display_from_api_result( $result, $invoice_number, $row );
		$payu_invoice = ! empty( $row->payu_invoice_number ) ? $row->payu_invoice_number : $invoice_number;

		$saved = self::persist_api_response( (int) $row->id, $result, $body, $display, $payu_invoice );
		if ( ! $saved ) {
			return new WP_Error(
				'payu_persist_failed',
				__( 'Something went wrong while saving payment status. Please try again.', 'payu-payment-links' )
			);
		}
		
		$udf2_invoice = self::extract_udf2_invoice_number( $result );
		$invoice_for_txn = $payu_invoice;
		if ( $udf2_invoice !== '' ) {
			if ( $udf2_invoice === $invoice_number || $udf2_invoice === $payu_invoice ) {
				$invoice_for_txn = $udf2_invoice;
			}
		}

		$date_from = isset( $row->created_at ) ? gmdate( 'Y-m-d', strtotime( $row->created_at ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$date_to   = gmdate( 'Y-m-d' );

		$txn_list  = self::fetch_transaction_details( $invoice_for_txn, $date_from, $date_to, $config );
		if ( is_wp_error( $txn_list ) ) {
			return new WP_Error(
				$txn_list->get_error_code(),
				$txn_list->get_error_message()
			);
		}

		if ( ! is_array( $txn_list ) || count( $txn_list ) === 0 ) {
			return new WP_Error(
				'payu_no_transactions',
				__( 'Payment details are not available yet. Please try again in a moment or contact support with your invoice number.', 'payu-payment-links' )
			);
		}
		
		$txn_saved = self::persist_transactions( (int) $row->id, $invoice_number, $txn_list );
		if ( ! $txn_saved ) {
			return new WP_Error(
				'payu_persist_transactions_failed',
				__( 'Something went wrong while saving transaction details. Please try again.', 'payu-payment-links' )
			);
		}

		$display['invoice']      = $invoice_number;
		$display['transactions'] = self::format_transactions_for_display( $txn_list, $display['currency'] );
		return $display;
	}

	/**
	 * Format transaction list for status page display (transaction no, amount, status, date & time).
	 * Includes rows with null transactionId (display as —). Date/time from createdOn (e.g. 2026-02-23 16:17:31.0).
	 *
	 * @param array  $txn_list List of transaction objects from API (transactionId, settledAmount, status, createdOn, etc.).
	 * @param string $currency Currency code for amount display.
	 * @return array List of { transaction_id, amount, status, date, date_display, time_display } for front-end.
	 */
	private static function format_transactions_for_display( $txn_list, $currency = '' ) {
		if ( ! is_array( $txn_list ) ) {
			return array();
		}
		$out = array();
		foreach ( $txn_list as $txn ) {
			$raw_id = isset( $txn['transactionId'] ) ? $txn['transactionId'] : ( isset( $txn['transaction_id'] ) ? $txn['transaction_id'] : null );
			$id     = ( $raw_id !== null && $raw_id !== '' ) ? sanitize_text_field( (string) $raw_id ) : '—';
			$amount = isset( $txn['settledAmount'] ) ? (float) $txn['settledAmount'] : ( isset( $txn['amount'] ) ? (float) $txn['amount'] : 0 );
			$status = isset( $txn['status'] ) ? sanitize_text_field( $txn['status'] ) : '';
			$date_display = '';
			$time_display = '';
			$date         = '';
			if ( ! empty( $txn['createdOn'] ) ) {
				$raw = $txn['createdOn'];
				if ( ! is_numeric( $raw ) && is_string( $raw ) ) {
					$raw = preg_replace( '/\.\d+$/', '', $raw );
				}
				$ts = is_numeric( $raw ) ? (int) $raw : strtotime( $raw );
				if ( $ts ) {
					$date_display = date_i18n( 'j M', $ts ) . "'" . date_i18n( 'y', $ts );
					$time_display = date_i18n( 'h:i:s A', $ts );
					$date         = $date_display . ', ' . $time_display;
				}
			}
			$out[] = array(
				'transaction_id' => $id,
				'amount'         => $amount,
				'status'         => $status,
				'date'           => $date,
				'date_display'   => $date_display,
				'time_display'   => $time_display,
			);
		}
		return $out;
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
		$order_id = $row && ! empty( $row->order_id ) ? (int) $row->order_id : 0;
		$order_ref = function_exists( 'payu_order_reference_display' ) ? payu_order_reference_display( $order_id ) : 'ORD - ' . $order_id;		
		$currency  = $row && ! empty( $row->currency ) ? $row->currency : ( isset( $result['currency'] ) ? sanitize_text_field( $result['currency'] ) : '' );
		if ( '' === $currency && function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
		}
		$total = self::get_amount_from_result( $result, array( 'totalAmount', 'subAmount', 'amountRequested' ), $row ? (float) $row->amount : 0 );
		$amount_paid = self::get_amount_from_result( $result, array( 'totalAmountCollected', 'totalRevenue', 'amountCollected' ), $row ? (float) $row->paid_amount : 0 );

		$remaining      = $total > 0 ? (float) ( $total - $amount_paid ) : 0;
		$display_status = self::resolve_canonical_payment_status( $amount_paid, $total, $result );
		$link_status    = self::resolve_payment_link_status( $result );

		$transaction_summary = null;
		if ( ! empty( $result['summary'] ) && is_array( $result['summary'] ) ) {
			$transaction_summary = wp_json_encode( $result['summary'] );
		}

		return array(
			'display_status'       => $display_status,
			'payment_link_status'  => $link_status,
			'amount_paid'          => $amount_paid,
			'remaining'            => $remaining,
			'total'                => $total,
			'currency'             => $currency,
			'order_ref'            => $order_ref,
			'order_id'             => $order_id,
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
	 * Resolve canonical payment status (money outcome) for display and DB.
	 * Returns PAYU_PAYMENT_STATUS_PAID, PAYU_PAYMENT_STATUS_PARTIALLY_PAID, or PAYU_PAYMENT_STATUS_FAILED.
	 * For wp_payu_payment_links.status we only store PENDING, PAID, PARTIALLY_PAID (map FAILED -> PENDING in persist).
	 *
	 * @param float $amount_paid Amount collected.
	 * @param float $total       Total amount.
	 * @param array $result      PayU API result (unused; for future use).
	 * @return string One of PAYU_PAYMENT_STATUS_* constants.
	 */
	private static function resolve_canonical_payment_status( $amount_paid, $total, $result ) {
		if ( $total > 0 && $amount_paid >= $total ) {
			return defined( 'PAYU_PAYMENT_STATUS_PAID' ) ? PAYU_PAYMENT_STATUS_PAID : 'PAID';
		}
		if ( $total > 0 && $amount_paid > 0 && $amount_paid < $total ) {
			return defined( 'PAYU_PAYMENT_STATUS_PARTIALLY_PAID' ) ? PAYU_PAYMENT_STATUS_PARTIALLY_PAID : 'PARTIALLY_PAID';
		}
		return defined( 'PAYU_PAYMENT_STATUS_FAILED' ) ? PAYU_PAYMENT_STATUS_FAILED : 'FAILED';
	}

	/**
	 * Resolve PayU payment link lifecycle status from API (active, expired, deactivated).
	 * Prefer result.status from Get Single Payment Link API; fallback to active/expiryDate.
	 *
	 * @param array $result PayU API result (status, active, expiryDate).
	 * @return string One of PAYU_LINK_STATUS_* (active, expired, deactivated).
	 */
	private static function resolve_payment_link_status( $result ) {
		$api_status = isset( $result['status'] ) ? strtoupper( sanitize_text_field( $result['status'] ) ) : '';
		$map        = array(
			'ACTIVE'      => defined( 'PAYU_LINK_STATUS_ACTIVE' ) ? PAYU_LINK_STATUS_ACTIVE : 'active',
			'EXPIRED'     => defined( 'PAYU_LINK_STATUS_EXPIRED' ) ? PAYU_LINK_STATUS_EXPIRED : 'expired',
			'DEACTIVATED' => defined( 'PAYU_LINK_STATUS_DEACTIVATED' ) ? PAYU_LINK_STATUS_DEACTIVATED : 'deactivated',
		);
		if ( isset( $map[ $api_status ] ) ) {
			return $map[ $api_status ];
		}
		$active = isset( $result['active'] ) ? (bool) $result['active'] : true;
		$expiry = isset( $result['expiryDate'] ) ? $result['expiryDate'] : '';
		if ( $expiry && strtotime( $expiry ) < time() ) {
			return defined( 'PAYU_LINK_STATUS_EXPIRED' ) ? PAYU_LINK_STATUS_EXPIRED : 'expired';
		}
		if ( ! $active ) {
			return defined( 'PAYU_LINK_STATUS_DEACTIVATED' ) ? PAYU_LINK_STATUS_DEACTIVATED : 'deactivated';
		}
		return defined( 'PAYU_LINK_STATUS_ACTIVE' ) ? PAYU_LINK_STATUS_ACTIVE : 'active';
	}

	/**
	 * Extract invoice number from PayU result udf2 (udf2 = invoiceNumber per requirement).
	 *
	 * @param array $result PayU API result (udf.udf2 or udf2).
	 * @return string Invoice number or empty string.
	 */
	private static function extract_udf2_invoice_number( $result ) {
		if ( ! is_array( $result ) ) {
			return '';
		}
		if ( ! empty( $result['udf'] ) && is_array( $result['udf'] ) && isset( $result['udf']['udf2'] ) && is_string( $result['udf']['udf2'] ) ) {
			return sanitize_text_field( $result['udf']['udf2'] );
		}
		if ( isset( $result['udf2'] ) && is_string( $result['udf2'] ) ) {
			return sanitize_text_field( $result['udf2'] );
		}
		return '';
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
	 * @return bool True if at least one row was updated.
	 */
	private static function persist_api_response( $row_id, $result, $raw_json, $display, $fallback_invoice = null ) {
		global $wpdb;
		$table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';

		// Canonical payment status (DB allows only PENDING, PAID, PARTIALLY_PAID). Map FAILED -> PENDING.
		$display_status = isset( $display['display_status'] ) ? strtoupper( sanitize_text_field( $display['display_status'] ) ) : '';
		$status         = ( $display_status === ( defined( 'PAYU_PAYMENT_STATUS_PAID' ) ? PAYU_PAYMENT_STATUS_PAID : 'PAID' ) || $display_status === ( defined( 'PAYU_PAYMENT_STATUS_PARTIALLY_PAID' ) ? PAYU_PAYMENT_STATUS_PARTIALLY_PAID : 'PARTIALLY_PAID' ) )
			? $display_status
			: ( defined( 'PAYU_PAYMENT_STATUS_PENDING' ) ? PAYU_PAYMENT_STATUS_PENDING : 'PENDING' );

		$link_status = isset( $display['payment_link_status'] ) ? sanitize_text_field( $display['payment_link_status'] ) : null;
		if ( $link_status !== null && $link_status !== '' ) {
			$allowed = array( 'active', 'expired', 'deactivated' );
			if ( ! in_array( $link_status, $allowed, true ) ) {
				$link_status = null;
			}
		}

		$total   = isset( $display['total'] ) ? (float) $display['total'] : 0;
		$paid    = isset( $display['amount_paid'] ) ? (float) $display['amount_paid'] : 0;
		$remain  = isset( $display['remaining'] ) ? (float) $display['remaining'] : 0;
		$summary = isset( $display['transaction_summary'] ) ? $display['transaction_summary'] : null;
		if ( ! empty( $result['summary'] ) && is_array( $result['summary'] ) ) {
			$summary = wp_json_encode( $result['summary'] );
		}
		$raw_json_safe = is_string( $raw_json ) ? $raw_json : '';
		$decoded       = is_string( $raw_json ) ? json_decode( $raw_json, true ) : null;
		if ( is_array( $decoded ) ) {
			$encoded = wp_json_encode( $decoded );
			if ( false !== $encoded ) {
				$raw_json_safe = $encoded;
			}
		}

		$cols = array(
			'status'                 => $status,
			'payment_link_status'    => $link_status,
			'amount'                 => $total,
			'paid_amount'            => $paid,
			'remaining_amount'       => $remain,
			'transaction_summary'    => $summary,
			'payu_api_response_json' => $raw_json_safe,
			'updated_at'             => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s' );

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

	/**
	 * Fetch transaction details from PayU Get Transaction Details API.
	 * Call only after successfully saving payment link status. Uses invoiceNumber, dateFrom, dateTo.
	 *
	 * @param string $invoice_number Invoice number (from udf2 or payu_invoice).
	 * @param string $date_from      YYYY-MM-DD.
	 * @param string $date_to        YYYY-MM-DD.
	 * @param object $config         PayU config (merchant_id, client_id, client_secret, environment).
	 * @return array|WP_Error List of transactions (result.data) or WP_Error on API failure.
	 */
	private static function fetch_transaction_details( $invoice_number, $date_from, $date_to, $config ) {
		if ( ! class_exists( 'PayU_Token_Manager' ) || ! function_exists( 'payu_decrypt_client_secret' ) ) {
			return new WP_Error( 'payu_deps', __( 'Token manager or decrypt not available.', 'payu-payment-links' ) );
		}
		$client_secret = payu_decrypt_client_secret( $config->client_secret );
		if ( false === $client_secret || '' === $client_secret ) {
			return new WP_Error( 'decrypt_failed', __( 'Unable to use stored credentials.', 'payu-payment-links' ) );
		}
		$environment = isset( $config->environment ) ? $config->environment : 'uat';
		$api_base    = ( 'prod' === $environment ) ? 'https://oneapi.payu.in' : 'https://uatoneapi.payu.in';
		$token       = PayU_Token_Manager::get_token_for_read_payment_link( $config->merchant_id, $config->client_id, $client_secret, $environment );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = $api_base . '/payment-links/' . rawurlencode( $invoice_number ) . '/txns';
		$url = add_query_arg( array(
			'dateFrom' => $date_from,
			'dateTo'   => $date_to,
			'pageSize' => 100,
		), $url );

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
				'merchantId'    => $config->merchant_id,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$dec  = json_decode( $body, true );
		if ( 200 !== $code && 201 !== $code ) {
			$msg = isset( $dec['message'] ) ? $dec['message'] : ( isset( $dec['error_description'] ) ? $dec['error_description'] : __( 'Failed to fetch transaction details.', 'payu-payment-links' ) );
			return new WP_Error( 'payu_txn_api', $msg );
		}
		$api_status = isset( $dec['status'] ) ? (int) $dec['status'] : -1;
		if ( 0 !== $api_status ) {
			$msg = isset( $dec['message'] ) ? $dec['message'] : __( 'Transaction details not available.', 'payu-payment-links' );
			return new WP_Error( 'payu_txn_result', $msg );
		}
		$data = isset( $dec['result'] ) && is_array( $dec['result'] ) ? $dec['result'] : array();
		$list = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		return $list;
	}

	/**
	 * Persist transactions into wp_payu_payment_transactions.
	 * Idempotent by transaction_id when non-null; when transaction_id is null, insert (record is still saved).
	 * Transaction status: success, initiated, userCancelled, failed (per record). Invoice status is separate (paid/partial paid/pending).
	 *
	 * @param int    $payment_link_id  Payment link row id.
	 * @param string $invoice_number   Invoice number for merchantReferenceId / reconciliation.
	 * @param array  $transactions     List of transaction objects from API (transactionId, merchantReferenceId, settledAmount, status, createdOn, etc.).
	 * @return bool True when all items processed.
	 */
	
	private static function persist_transactions( $payment_link_id, $invoice_number, $transactions ) {
		global $wpdb;
		$table = function_exists( 'payu_get_payment_transactions_table_name' ) ? payu_get_payment_transactions_table_name() : $wpdb->prefix . 'payu_payment_transactions';
		$payment_link_id = (int) $payment_link_id;
		if ( $payment_link_id <= 0 || ! is_array( $transactions ) ) {
			return false;
		}
		foreach ( $transactions as $txn ) {
			$raw_id = isset( $txn['transactionId'] ) ? $txn['transactionId'] : ( isset( $txn['transaction_id'] ) ? $txn['transaction_id'] : null );
			$transaction_id = ( $raw_id !== null && trim( (string) $raw_id ) !== '' ) ? sanitize_text_field( (string) $raw_id ) : null;
			$merchant_ref = isset( $txn['merchantReferenceId'] ) ? sanitize_text_field( $txn['merchantReferenceId'] ) : '';
			$amount       = isset( $txn['settledAmount'] ) ? (float) $txn['settledAmount'] : ( isset( $txn['amount'] ) ? (float) $txn['amount'] : 0 );
			$status       = isset( $txn['status'] ) ? strtoupper( sanitize_text_field( $txn['status'] ) ) : 'N/A';
			$mode      = isset( $txn['mode'] ) ? sanitize_text_field( $txn['mode'] ) : null;
			$bank_code = isset( $txn['bankCode'] ) ? sanitize_text_field( $txn['bankCode'] ) : ( isset( $txn['bankReference'] ) ? sanitize_text_field( $txn['bankReference'] ) : null );
			$card_num  = isset( $txn['cardNum'] ) ? sanitize_text_field( $txn['cardNum'] ) : null;
			$created_on = null;
			if ( ! empty( $txn['createdOn'] ) ) {
				$raw = $txn['createdOn'];
				if ( ! is_numeric( $raw ) && is_string( $raw ) ) {
					$raw = preg_replace( '/\.\d+$/', '', $raw );
				}
				$ts = is_numeric( $raw ) ? (int) $raw : strtotime( $raw );
				$created_on = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
			}

			if ( $transaction_id !== null && $transaction_id !== '' ) {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE transaction_id = %s LIMIT 1",
					$transaction_id
				) );
				if ( $existing ) {
					$wpdb->update(
						$table,
						array(
							'merchantReferenceId' => $merchant_ref,
							'invoice_number'      => $invoice_number,
							'amount'              => $amount,
							'payment_mode'        => $mode,
							'bankCode'            => $bank_code,
							'card_num'            => $card_num,
							'status'              => $status,
						),
						array( 'id' => (int) $existing ),
						array( '%s', '%s', '%f', '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
				} else {
					$wpdb->insert( $table, array(
						'payment_link_id'     => $payment_link_id,
						'transaction_id'      => $transaction_id,
						'merchantReferenceId' => $merchant_ref,
						'invoice_number'      => $invoice_number,
						'amount'              => $amount,
						'payment_mode'        => $mode,
						'bankCode'            => $bank_code,
						'card_num'            => $card_num,
						'status'              => $status,
						'created_at'          => $created_on ? $created_on : current_time( 'mysql' ),
					), array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ) );
				}
			} else {
				// transaction_id is null: deduplicate to avoid unbounded rows on every status-page refresh.
				$created_at_val = $created_on ? $created_on : current_time( 'mysql' );
				if ( $created_on ) {
					$existing_null = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$table} WHERE transaction_id IS NULL AND payment_link_id = %d AND invoice_number = %s AND amount = %f AND status = %s AND created_at = %s LIMIT 1",
						$payment_link_id,
						$invoice_number,
						$amount,
						$status,
						$created_at_val
					) );
				} else {
					// No createdOn from API: allow only one null-ID row per (payment_link_id, invoice_number, amount, status).
					$existing_null = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$table} WHERE transaction_id IS NULL AND payment_link_id = %d AND invoice_number = %s AND amount = %f AND status = %s LIMIT 1",
						$payment_link_id,
						$invoice_number,
						$amount,
						$status
					) );
				}
				if ( ! $existing_null ) {
					$wpdb->insert( $table, array(
						'payment_link_id'     => $payment_link_id,
						'transaction_id'      => null,
						'merchantReferenceId' => $merchant_ref,
						'invoice_number'      => $invoice_number,
						'amount'              => $amount,
						'payment_mode'        => $mode,
						'bankCode'            => $bank_code,
						'card_num'            => $card_num,
						'status'              => $status,
						'created_at'          => $created_at_val,
					), array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ) );
				}
			}
		}
		return true;
	}
}

PayU_Payment_Link_Status_Ajax::register();
