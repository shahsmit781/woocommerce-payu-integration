<?php
/**
 * PayU Webhook Receiver – Parse, persist transaction, update payment link aggregates.
 *
 * Endpoint: /payu/webhook (POST only, public, no auth).
 * PayU sends application/x-www-form-urlencoded. Parse → upsert transaction → recalc link aggregates.
 * No UI rendering; no WooCommerce order updates. Idempotent.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Webhook_Receiver
 */
class PayU_Webhook_Receiver {

	const QUERY_VAR = 'payu_webhook';
	const REWRITE_PATH = 'payu/webhook';

	/** Whitelist of webhook body keys to extract and log (PayU form-urlencoded payload). */
	const WEBHOOK_LOG_KEYS = array(
		'mihpayid', 'mode', 'status', 'unmappedstatus', 'key', 'txnid', 'amount', 'net_amount_debit', 'productinfo',
		'firstname', 'lastname', 'email', 'phone', 'udf1', 'udf2', 'udf3', 'udf4', 'udf5', 'udf6', 'udf7', 'udf8', 'udf9', 'udf10',
		'hash', 'error', 'error_Message', 'bank_ref_num', 'pg_type', 'bankcode', 'cardnum', 'issuing_bank', 'card_type',
		'name_on_card', 'payment_source', 'invoice_number', 'invoiceNumber', 'event', 'eventType', 'type',
	);

	/** PayU status values that mean success (normalize to SUCCESS). */
	const SUCCESS_STATUSES = array( 'success', 'credited', 'captured', 'paid' );

	/**
	 * Register rewrite rule and template redirect.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rule' ), 10 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ), 10, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_webhook' ), 1 );
	}

	/**
	 * Register rewrite rule for payu/webhook.
	 */
	public static function register_rewrite_rule() {
		add_rewrite_rule(
			'^payu/webhook/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Expose query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * If request is the webhook URL, handle and exit. POST only; respond 200 OK.
	 */
	public static function maybe_handle_webhook() {
		if ( ! get_query_var( self::QUERY_VAR ) && ! self::is_webhook_path() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			status_header( 405 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Method Not Allowed';
			exit;
		}

		$raw_body = @file_get_contents( 'php://input' );
		if ( false === $raw_body || ! is_string( $raw_body ) ) {
			$raw_body = '';
		}

		$timestamp = current_time( 'c' );
		$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$parsed    = self::parse_webhook_body( $raw_body );
		$normalized = self::normalize_webhook_payload( $parsed );

		if ( $normalized && ! empty( $normalized['transaction_id'] ) ) {
			$link_row = self::get_payment_link_by_invoice( $normalized['invoice_number'] );
			if ( $link_row ) {
				self::upsert_transaction( (int) $link_row->id, $normalized, $parsed );
				self::update_payment_link_aggregates( (int) $link_row->id );
			}
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo 'OK';
		exit;
	}

	/**
	 * Parse application/x-www-form-urlencoded body into associative array (all keys).
	 *
	 * @param string $raw_body Raw POST body.
	 * @return array Parsed key => value (values as string or scalar).
	 */
	private static function parse_webhook_body( $raw_body ) {
		$out = array();
		if ( ! is_string( $raw_body ) || $raw_body === '' ) {
			return $out;
		}
		parse_str( $raw_body, $out );
		if ( ! is_array( $out ) ) {
			return array();
		}
		foreach ( $out as $k => $v ) {
			if ( ! is_scalar( $v ) ) {
				$out[ $k ] = '';
			} elseif ( ! is_string( $v ) ) {
				$out[ $k ] = (string) $v;
			}
		}
		return $out;
	}

	/**
	 * Normalize parsed webhook payload for persistence.
	 * Extract: mihpayid, udf2 (invoice), txnid, amount/net_amount_debit, status (SUCCESS|FAILED), payer, bank/mode.
	 *
	 * @param array $parsed Full parsed form body.
	 * @return array|null Normalized keys: transaction_id, invoice_number, merchantReferenceId, amount, status, payment_mode, bank_reference, bankCode, card_num, payer_name, payer_phone, payer_email; or null if missing mihpayid.
	 */
	private static function normalize_webhook_payload( $parsed ) {
		$get = function ( $key, $alt = '' ) use ( $parsed ) {
			if ( array_key_exists( $key, $parsed ) && (string) $parsed[ $key ] !== '' ) {
				return sanitize_text_field( (string) $parsed[ $key ] );
			}
			return $alt !== '' ? sanitize_text_field( (string) $alt ) : '';
		};

		$transaction_id = $get( 'mihpayid' );
		if ( $transaction_id === '' ) {
			return null;
		}

		$invoice_number = $get( 'udf2', $get( 'invoice_number', $get( 'invoiceNumber' ) ) );
		$merchant_ref   = $get( 'txnid' );
		$amount_raw     = array_key_exists( 'net_amount_debit', $parsed ) ? $parsed['net_amount_debit'] : ( array_key_exists( 'amount', $parsed ) ? $parsed['amount'] : 0 );
		$amount         = is_numeric( $amount_raw ) ? (float) $amount_raw : 0.0;

		$status_raw = strtolower( $get( 'status', $get( 'unmappedstatus' ) ) );
		$status     = self::normalize_transaction_status( $status_raw, $parsed );

		$payment_mode  = $get( 'mode', $get( 'pg_type', $get( 'payment_source' ) ) );
		$bank_reference = $get( 'bank_ref_num' );
		$bank_code     = $get( 'bankcode' );
		$card_num      = $get( 'cardnum' );

		$first = $get( 'firstname' );
		$last  = $get( 'lastname' );
		$payer_name = trim( $first . ' ' . $last );
		if ( $payer_name === '' ) {
			$payer_name = $get( 'name_on_card' );
		}
		$payer_phone = $get( 'phone' );
		$payer_email = $get( 'email' );

		return array(
			'transaction_id'      => $transaction_id,
			'invoice_number'      => $invoice_number,
			'merchantReferenceId' => $merchant_ref,
			'amount'              => $amount,
			'status'              => $status,
			'payment_mode'        => $payment_mode,
			'bank_reference'      => $bank_reference,
			'bankCode'            => $bank_code,
			'card_num'            => $card_num,
			'payer_name'          => $payer_name,
			'payer_phone'         => $payer_phone,
			'payer_email'         => $payer_email,
		);
	}

	/**
	 * Normalize raw status to SUCCESS or FAILED.
	 *
	 * @param string $status_raw Lowercase status or unmappedstatus.
	 * @param array  $parsed     Full payload (for error / error_Message).
	 * @return string SUCCESS or FAILED.
	 */
	private static function normalize_transaction_status( $status_raw, $parsed ) {
		$status_raw = is_string( $status_raw ) ? trim( strtolower( $status_raw ) ) : '';
		if ( in_array( $status_raw, self::SUCCESS_STATUSES, true ) ) {
			return 'SUCCESS';
		}
		if ( $status_raw === 'failed' || $status_raw === 'failure' || $status_raw === 'cancelled' || $status_raw === 'error' ) {
			return 'FAILED';
		}
		$error = isset( $parsed['error'] ) && (string) $parsed['error'] !== '' ? true : ( isset( $parsed['error_Message'] ) && (string) $parsed['error_Message'] !== '' );
		if ( $error ) {
			return 'FAILED';
		}
		return 'SUCCESS';
	}

	/**
	 * Get payment link row by invoice number (payu_invoice_number or udf2).
	 *
	 * @param string $invoice_number Invoice from webhook (udf2).
	 * @return object|null Row with id, amount, etc. or null.
	 */
	private static function get_payment_link_by_invoice( $invoice_number ) {
		global $wpdb;
		if ( $invoice_number === '' ) {
			return null;
		}
		$table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, amount, paid_amount, remaining_amount, status FROM {$table} WHERE ( is_deleted = 0 OR is_deleted IS NULL ) AND payu_invoice_number = %s LIMIT 1",
				$invoice_number
			)
		);
		return $row;
	}

	/**
	 * Upsert transaction: UPDATE if transaction_id exists, else INSERT.
	 * Store full webhook payload as JSON at transaction level.
	 *
	 * @param int   $payment_link_id Link row id.
	 * @param array $normalized      Normalized fields from normalize_webhook_payload().
	 * @param array $parsed          Full parsed body for webhook_payload_json.
	 */
	private static function upsert_transaction( $payment_link_id, $normalized, $parsed ) {
		global $wpdb;
		$table = function_exists( 'payu_get_payment_transactions_table_name' ) ? payu_get_payment_transactions_table_name() : $wpdb->prefix . 'payu_payment_transactions';
		$tid   = $normalized['transaction_id'];
		$payload_json = wp_json_encode( $parsed );

		$data = array(
			'payment_link_id'       => $payment_link_id,
			'transaction_id'        => $tid,
			'merchantReferenceId'   => $normalized['merchantReferenceId'],
			'invoice_number'        => $normalized['invoice_number'],
			'amount'                => $normalized['amount'],
			'status'                => $normalized['status'],
			'payment_mode'          => $normalized['payment_mode'],
			'bank_reference'        => $normalized['bank_reference'],
			'bankCode'              => $normalized['bankCode'],
			'card_num'              => $normalized['card_num'],
			'payer_name'             => $normalized['payer_name'],
			'payer_phone'            => $normalized['payer_phone'],
			'payer_email'            => $normalized['payer_email'],
			'webhook_payload_json'   => $payload_json,
		);

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE transaction_id = %s LIMIT 1", $tid ) );
		if ( $existing ) {
			unset( $data['payment_link_id'] );
			$wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing ),
				array( '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				$data,
				array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Recalculate paid_amount, remaining_amount, status from successful transactions and update payment link.
	 * Update only aggregated fields; do not overwrite link-level customer data or payu_api_response_json.
	 *
	 * @param int $payment_link_id Link row id.
	 */
	private static function update_payment_link_aggregates( $payment_link_id ) {
		global $wpdb;
		$links_table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';
		$txn_table   = function_exists( 'payu_get_payment_transactions_table_name' ) ? payu_get_payment_transactions_table_name() : $wpdb->prefix . 'payu_payment_transactions';

		$link = $wpdb->get_row( $wpdb->prepare( "SELECT id, amount FROM {$links_table} WHERE id = %d LIMIT 1", $payment_link_id ) );
		if ( ! $link ) {
			return;
		}

		$total = (float) $link->amount;
		$paid  = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$txn_table} WHERE payment_link_id = %d AND status IN ('SUCCESS', 'PAID')",
			$payment_link_id
		) );
		$remaining = max( 0, $total - $paid );

		if ( $total > 0 && $paid >= $total ) {
			$status = 'PAID';
		} elseif ( $paid > 0 ) {
			$status = 'PARTIALLY_PAID';
		} else {
			$status = 'FAILED';
		}

		$wpdb->update(
			$links_table,
			array(
				'paid_amount'     => $paid,
				'remaining_amount' => $remaining,
				'status'          => $status,
			),
			array( 'id' => $payment_link_id ),
			array( '%f', '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Check if current request path is payu/webhook (fallback when rewrites not flushed).
	 *
	 * @return bool
	 */
	private static function is_webhook_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri = trim( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), '/' );
		list( $uri ) = array_map( 'trim', explode( '?', $uri, 2 ) );
		$uri = trim( $uri, '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( $home_path !== '' ) {
			$uri = preg_replace( '#^' . preg_quote( $home_path, '#' ) . '/#', '', $uri );
			$uri = trim( $uri, '/' );
		}
		return $uri === self::REWRITE_PATH || $uri === self::REWRITE_PATH . '/';
	}

	/**
	 * Get path to webhook log file (wp-content/payu-webhook.log).
	 *
	 * @return string Absolute path or empty if not writable.
	 */
	public static function get_log_path() {
		$dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( dirname( dirname( dirname( __DIR__ ) ) ) );
		$file = $dir . '/payu-webhook.log';
		if ( file_exists( $file ) && ! is_writable( $file ) ) {
			return '';
		}
		if ( ! file_exists( $file ) && ( ! is_writable( $dir ) || ! is_writable( dirname( $file ) ) ) ) {
			return '';
		}
		return $file;
	}

	/**
	 * Register rewrite for activation flush.
	 */
	public static function register_rewrite_rule_for_activation() {
		self::register_rewrite_rule();
	}
}
