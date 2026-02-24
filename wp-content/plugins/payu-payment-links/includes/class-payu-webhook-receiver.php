<?php
/**
 * PayU Webhook Receiver – Capture and log incoming webhook requests only.
 *
 * Endpoint: /payu/webhook (POST only, public, no auth).
 * PayU sends application/x-www-form-urlencoded. Extract and log only required fields; no payment logic.
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
		'mihpayid', 'mode', 'status', 'unmappedstatus', 'key', 'txnid', 'amount', 'productinfo',
		'firstname', 'email', 'phone', 'udf1', 'udf2', 'udf3', 'udf4', 'udf5', 'udf6', 'udf7', 'udf8', 'udf9', 'udf10',
		'hash', 'error', 'error_Message', 'bank_ref_num', 'pg_type', 'bankcode', 'cardnum', 'issuing_bank', 'card_type',
		'name_on_card', 'payment_source', 'invoice_number', 'invoiceNumber', 'event', 'eventType', 'type',
	);

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
		$extracted = self::extract_webhook_fields( $raw_body );

		self::append_log(
			array(
				'timestamp' => $timestamp,
				'ip'        => $ip,
				'extracted' => $extracted,
			)
		);

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo 'OK';
		exit;
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
	 * Get request headers as associative array (key lowercase).
	 *
	 * @return array
	 */
	private static function get_request_headers() {
		if ( function_exists( 'getallheaders' ) ) {
			$h = getallheaders();
			if ( is_array( $h ) ) {
				$out = array();
				foreach ( $h as $k => $v ) {
					$out[ strtolower( $k ) ] = is_string( $v ) ? $v : '';
				}
				return $out;
			}
		}
		$out = array();
		foreach ( $_SERVER as $k => $v ) {
			if ( strpos( $k, 'HTTP_' ) === 0 && is_string( $v ) ) {
				$name = strtolower( str_replace( '_', '-', substr( $k, 5 ) ) );
				$out[ $name ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Parse application/x-www-form-urlencoded body and return only whitelisted keys for logging.
	 * PayU webhook payload is form-urlencoded; we do not modify or store full payload structure.
	 *
	 * @param string $raw_body Raw POST body (application/x-www-form-urlencoded).
	 * @return array Associative array of key => value for keys in WEBHOOK_LOG_KEYS that are present.
	 */
	private static function extract_webhook_fields( $raw_body ) {
		$out = array();
		if ( ! is_string( $raw_body ) || $raw_body === '' ) {
			return $out;
		}
		$parsed = array();
		parse_str( $raw_body, $parsed );
		if ( ! is_array( $parsed ) ) {
			return $out;
		}
		foreach ( self::WEBHOOK_LOG_KEYS as $key ) {
			if ( array_key_exists( $key, $parsed ) ) {
				$val = $parsed[ $key ];
				$out[ $key ] = is_string( $val ) ? $val : ( is_scalar( $val ) ? (string) $val : '' );
			}
		}
		return $out;
	}

	/**
	 * Append one log entry (one line, JSON-encoded) to the log file. Append-only.
	 *
	 * @param array $entry Keys: timestamp, ip, extracted.
	 */
	private static function append_log( $entry ) {
		$log_path = self::get_log_path();
		if ( $log_path === '' ) {
			return;
		}
		$line = wp_json_encode( $entry ) . "\n";
		// Append only; do not lock to keep response fast (optional: flock LOCK_EX).
		$fp = @fopen( $log_path, 'a' );
		if ( is_resource( $fp ) ) {
			fwrite( $fp, $line );
			fclose( $fp );
		}
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
