<?php
/**
 * PayU Payment Links – Admin action handlers (AJAX)
 *
 * Handles: Update expiry date (Expire / Set Expiry) via PayU Change Status API.
 * All actions are secured with nonce and manage_woocommerce capability.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Payment_Links_Actions
 */
class PayU_Payment_Links_Actions {

	const AJAX_ACTION_UPDATE_EXPIRY = 'payu_update_payment_link_expiry';
	const NONCE_ACTION_UPDATE_EXPIRY = 'payu_update_payment_link_expiry';
	const AJAX_ACTION_RESEND = 'payu_resend_payment_link';
	const NONCE_ACTION_RESEND = 'payu_resend_payment_link';
	const AJAX_ACTION_GET_LINK_DETAILS = 'payu_get_payment_link_details';
	const NONCE_ACTION_GET_LINK_DETAILS = 'payu_get_payment_link_details';
	const AJAX_ACTION_REFRESH = 'payu_refresh_payment_link';
	const NONCE_ACTION_REFRESH = 'payu_refresh_payment_link';

	/**
	 * Constructor – register AJAX handlers.
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION_UPDATE_EXPIRY, array( $this, 'handle_ajax_update_expiry' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_RESEND, array( $this, 'handle_ajax_resend_payment_link' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_GET_LINK_DETAILS, array( $this, 'handle_ajax_get_payment_link_details' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_REFRESH, array( $this, 'handle_ajax_refresh_payment_link' ) );
	}

	/**
	 * Handle AJAX: update payment link expiry (server-side PayU API call, then DB update).
	 */
	public function handle_ajax_update_expiry() {
		check_ajax_referer( self::NONCE_ACTION_UPDATE_EXPIRY, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ) ) );
		}

		$link_id   = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		$invoice   = isset( $_POST['payu_invoice_number'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_invoice_number'] ) ) : '';
		$new_dt    = isset( $_POST['new_expiry_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['new_expiry_datetime'] ) ) : '';

		if ( $link_id <= 0 || '' === $invoice ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or missing payment link reference.', 'payu-payment-links' ) ) );
		}

		$parsed = $this->parse_and_validate_expiry_datetime( $new_dt );
		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}

		try {
			$result = $this->update_expiry_via_api_and_db( $link_id, $invoice, $parsed );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array(
				'message'              => __( 'Expiry date updated successfully.', 'payu-payment-links' ),
				'payment_link_id'      => $result['payment_link_id'],
				'expiry_date'          => $result['expiry_date'],
				'status'               => $result['status'],
				'payment_link_status'  => isset( $result['payment_link_status'] ) ? $result['payment_link_status'] : 'active',
			) );
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => __( 'An unexpected error occurred. Please try again.', 'payu-payment-links' ),
			) );
		}
	}

	/**
	 * Parse and validate expiry datetime string (expect Y-m-d H:i or Y-m-d\TH:i).
	 * Must be in the future.
	 *
	 * @param string $input Raw input from request.
	 * @return string|WP_Error MySQL datetime string or WP_Error.
	 */
	private function parse_and_validate_expiry_datetime( $input ) {
		$input = trim( (string) $input );
		if ( '' === $input ) {
			return new WP_Error( 'missing', __( 'Expiry date and time are required.', 'payu-payment-links' ) );
		}

		$normalized = str_replace( 'T', ' ', $input );
		$ts = strtotime( $normalized );
		if ( false === $ts ) {
			return new WP_Error( 'invalid', __( 'Invalid expiry date or time format.', 'payu-payment-links' ) );
		}

		// Expiry must be strictly after current time (not current moment). Require at least 1 minute in the future.
		$now_ts = time();
		if ( $ts <= $now_ts ) {
			return new WP_Error( 'past', __( 'Expiry date and time must be after the current time.', 'payu-payment-links' ) );
		}
		$one_minute = 60;
		if ( $ts < $now_ts + $one_minute ) {
			return new WP_Error( 'too_soon', __( 'Expiry must be at least 1 minute from now.', 'payu-payment-links' ) );
		}

		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Call PayU Change Status API and update local DB on success.
	 *
	 * @param int    $link_id   Payment link row ID.
	 * @param string $invoice   PayU invoice number.
	 * @param string $expiry_mysql MySQL datetime (Y-m-d H:i:s).
	 * @return array|WP_Error On success: array( 'expiry_date' => ... ). Otherwise WP_Error.
	 */
	private function update_expiry_via_api_and_db( $link_id, $invoice, $expiry_mysql ) {
		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/repository/class-payment-links-repository.php';
		$repo = new Payment_Links_Repository();
		$link = $repo->get_link_by_id( $link_id );
		if ( ! $link || ( isset( $link->payu_invoice_number ) && $link->payu_invoice_number !== $invoice ) ) {
			return new WP_Error( 'not_found', __( 'Payment link not found.', 'payu-payment-links' ) );
		}

		$config = $this->get_config_for_link( $link );
		if ( ! $config ) {
			return new WP_Error( 'no_config', __( 'No PayU configuration found for this payment link.', 'payu-payment-links' ) );
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
		$merchant_id = $config->merchant_id;

		$token = PayU_Token_Manager::get_token_for_update_payment_link(
			$merchant_id,
			$config->client_id,
			$client_secret,
			$environment
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$api_base = ( 'prod' === $environment ) ? 'https://oneapi.payu.in' : 'https://uatoneapi.payu.in';
		$url      = $api_base . '/payment-links/' . rawurlencode( $invoice );

		// PayU Change Status API: body must match API (expiry full datetime, status active). Link identified by URL path.
		$body = array(
			'expiry' => $expiry_mysql,
			'status' => 'active',
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 30,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json',
					'Authorization'  => 'Bearer ' . $token,
					'mid'            => $merchant_id,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'network', __( 'Network error while updating PayU. Please try again.', 'payu-payment-links' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_res = wp_remote_retrieve_body( $response );
		$dec = json_decode( $body_res, true );

		if ( 401 === $code ) {
			$scope  = PayU_Token_Manager::SCOPE_UPDATE_PAYMENT_LINKS;
			$scope  = function_exists( 'payu_normalize_scope_string' ) ? payu_normalize_scope_string( $scope ) : $scope;
			$hash   = function_exists( 'payu_scope_hash' ) ? payu_scope_hash( $scope ) : hash( 'sha256', $scope );
			$env_db = PayU_Token_Manager::normalize_environment_for_db( $environment );
			PayU_Token_Manager::invalidate_token( $merchant_id, $env_db, $hash );
			$token = PayU_Token_Manager::get_token_for_update_payment_link( $merchant_id, $config->client_id, $client_secret, $environment );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$response = wp_remote_request(
				$url,
				array(
					'method'  => 'PUT',
					'timeout' => 30,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'Accept'         => 'application/json',
						'Authorization'  => 'Bearer ' . $token,
						'mid'            => $merchant_id,
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'network', __( 'Network error while updating PayU. Please try again.', 'payu-payment-links' ) );
			}
			$code    = wp_remote_retrieve_response_code( $response );
			$body_res = wp_remote_retrieve_body( $response );
			$dec     = json_decode( $body_res, true );
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = $this->parse_payu_api_error_message( $body_res, $dec, $code );
			return new WP_Error( 'api_error', $msg );
		}

		// API success: update local DB (expiry + set link status to active after reactivation).
		global $wpdb;
		$table = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';
		$updated = $wpdb->update(
			$table,
			array(
				'expiry_date'         => $expiry_mysql,
				'payment_link_status' => 'active',
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $link_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		// false = DB error; 0 = no rows changed (e.g. same expiry); 1+ = success.
		if ( false === $updated ) {
			return new WP_Error( 'db_error', __( 'Expiry was updated on PayU but could not update local record. Please refresh the list.', 'payu-payment-links' ) );
		}

		// Return values needed for inline table update (no full page reload).
		$current_status = isset( $link->status ) ? trim( (string) $link->status ) : 'PENDING';
		return array(
			'payment_link_id'     => (int) $link_id,
			'expiry_date'         => $expiry_mysql,
			'status'              => $current_status,
			'payment_link_status' => 'active',
		);
	}

	/**
	 * Handle AJAX: get payment link details (email, phone) from DB for Resend modal pre-fill.
	 */
	public function handle_ajax_get_payment_link_details() {
		check_ajax_referer( self::NONCE_ACTION_GET_LINK_DETAILS, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ) ) );
		}

		$payment_link_id = isset( $_GET['payment_link_id'] ) ? absint( $_GET['payment_link_id'] ) : 0;
		if ( $payment_link_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment link.', 'payu-payment-links' ) ) );
		}

		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/repository/class-payment-links-repository.php';
		$repo = new Payment_Links_Repository();
		$link = $repo->get_link_by_id( $payment_link_id );
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Payment link not found.', 'payu-payment-links' ) ) );
		}

		wp_send_json_success( array(
			'customerEmail'       => isset( $link->customerEmail ) ? (string) $link->customerEmail : '',
			'customerPhone'       => isset( $link->customerPhone ) ? (string) $link->customerPhone : '',
			'payu_invoice_number' => isset( $link->payu_invoice_number ) ? (string) $link->payu_invoice_number : '',
		) );
	}

	/**
	 * Handle AJAX: refresh payment link data from PayU (Get Single + Transactions API, update DB).
	 * Uses refresh_by_payment_link_id (not fetch_and_persist_payment_link_status) so refresh
	 * succeeds even when there are no transactions yet; fetch_and_persist requires transactions.
	 */
	public function handle_ajax_refresh_payment_link() {
		check_ajax_referer( self::NONCE_ACTION_REFRESH, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ) ) );
		}

		$payment_link_id = isset( $_POST['payment_link_id'] ) ? absint( $_POST['payment_link_id'] ) : 0;
		if ( $payment_link_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment link.', 'payu-payment-links' ) ) );
		}

		if ( ! class_exists( 'PayU_Payment_Link_Status_Ajax' ) ) {
			wp_send_json_error( array( 'message' => __( 'Refresh service not available.', 'payu-payment-links' ) ) );
		}

		$result = PayU_Payment_Link_Status_Ajax::refresh_by_payment_link_id( $payment_link_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'payment_link_id'     => isset( $result['payment_link_id'] ) ? (int) $result['payment_link_id'] : $payment_link_id,
			'status'              => isset( $result['status'] ) ? $result['status'] : '',
			'payment_link_status' => isset( $result['payment_link_status'] ) ? $result['payment_link_status'] : '',
			'paid_amount'         => isset( $result['paid_amount'] ) ? $result['paid_amount'] : '0.00',
			'amount'              => isset( $result['amount'] ) ? $result['amount'] : '0.00',
			'expiry_date'         => isset( $result['expiry_date'] ) ? $result['expiry_date'] : '',
			'currency'            => isset( $result['currency'] ) ? $result['currency'] : '',
		) );
	}

	/**
	 * Handle AJAX: resend payment link via PayU Share API (email or SMS).
	 */
	public function handle_ajax_resend_payment_link() {
		check_ajax_referer( self::NONCE_ACTION_RESEND, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ) ) );
		}

		$payment_link_id   = isset( $_POST['payment_link_id'] ) ? absint( $_POST['payment_link_id'] ) : 0;
		$payu_invoice_number = isset( $_POST['payu_invoice_number'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_invoice_number'] ) ) : '';
		$send_email        = ! empty( $_POST['send_email'] );
		$send_sms          = ! empty( $_POST['send_sms'] );
		$email             = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$phone             = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		if ( $payment_link_id <= 0 || '' === $payu_invoice_number ) {
			wp_send_json_error( array( 'message' => __( 'This payment link could not be found. Please refresh the page and try again.', 'payu-payment-links' ) ) );
		}

		if ( ! $send_email && ! $send_sms ) {
			wp_send_json_error( array( 'message' => __( 'Please choose Email or SMS (or both) to send the link.', 'payu-payment-links' ) ) );
		}

		$channel_list = array();
		if ( $send_email ) {
			$email = sanitize_email( $email );
			if ( '' === $email || ! is_email( $email ) ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'payu-payment-links' ) ) );
			}
			$channel_list[] = $email;
		}
		if ( $send_sms ) {
			$phone = preg_replace( '/[^\d+]/', '', $phone );
			if ( strlen( $phone ) < 6 ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a valid phone number with country code (e.g. +91 9876543210).', 'payu-payment-links' ) ) );
			}
			$channel_list[] = $phone;
		}

		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/repository/class-payment-links-repository.php';
		$repo = new Payment_Links_Repository();
		$link = $repo->get_link_by_id( $payment_link_id );
		if ( ! $link || ( isset( $link->payu_invoice_number ) && $link->payu_invoice_number !== $payu_invoice_number ) ) {
			wp_send_json_error( array( 'message' => __( 'This payment link could not be found. Please refresh the page and try again.', 'payu-payment-links' ) ) );
		}

		$config = $this->get_config_for_link( $link );
		if ( ! $config ) {
			wp_send_json_error( array( 'message' => __( 'Unable to send right now. Please try again later.', 'payu-payment-links' ) ) );
		}

		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/services/class-payu-payment-links-share.php';
		try {
			$result = PayU_Payment_Links_Share::share( $payu_invoice_number, $channel_list, $config );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( array(
				'message' => __( 'Done! The payment link has been sent.', 'payu-payment-links' ),
			) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'payu-payment-links' ) ) );
		}
	}

	/**
	 * Get PayU config for a payment link row (config_id, then currency, then first active).
	 *
	 * @param object $link Payment link row.
	 * @return object|null Config row or null.
	 */
	private function get_config_for_link( $link ) {
		if ( ! empty( $link->config_id ) && function_exists( 'payu_get_currency_config_by_id' ) ) {
			$config = payu_get_currency_config_by_id( $link->config_id );
			if ( $config && ! empty( $config->merchant_id ) ) {
				return $config;
			}
		}
		if ( ! empty( $link->currency ) && function_exists( 'payu_get_active_config_by_currency' ) ) {
			$config = payu_get_active_config_by_currency( $link->currency );
			if ( $config ) {
				return $config;
			}
		}
		if ( function_exists( 'payu_get_first_active_config' ) ) {
			return payu_get_first_active_config();
		}
		return null;
	}

	/**
	 * Parse error message from PayU API response body (JSON or plain text).
	 * Tries common keys: message, error_description, error, errors[0].message, result.message, etc.
	 *
	 * @param string     $body_raw Raw response body.
	 * @param array|null $dec     Decoded JSON array or null.
	 * @param int        $code    HTTP status code.
	 * @return string User-facing error message (never empty).
	 */
	private function parse_payu_api_error_message( $body_raw, $dec, $code ) {
		$fallback = __( 'PayU API error. Please try again.', 'payu-payment-links' );
		if ( ! is_array( $dec ) ) {
			$dec = json_decode( $body_raw, true );
		}
		if ( is_array( $dec ) ) {
			if ( ! empty( $dec['message'] ) && is_string( $dec['message'] ) ) {
				return sanitize_text_field( $dec['message'] );
			}
			if ( ! empty( $dec['error_description'] ) && is_string( $dec['error_description'] ) ) {
				return sanitize_text_field( $dec['error_description'] );
			}
			if ( ! empty( $dec['error'] ) && is_string( $dec['error'] ) ) {
				$msg = sanitize_text_field( $dec['error'] );
				if ( ! empty( $dec['error_description'] ) && is_string( $dec['error_description'] ) ) {
					$msg .= ': ' . sanitize_text_field( $dec['error_description'] );
				}
				return $msg;
			}
			if ( ! empty( $dec['errors'] ) && is_array( $dec['errors'] ) ) {
				$first = reset( $dec['errors'] );
				if ( is_array( $first ) && ! empty( $first['message'] ) ) {
					return sanitize_text_field( $first['message'] );
				}
				if ( is_string( $first ) ) {
					return sanitize_text_field( $first );
				}
			}
			if ( ! empty( $dec['result'] ) && is_array( $dec['result'] ) && ! empty( $dec['result']['message'] ) ) {
				return sanitize_text_field( $dec['result']['message'] );
			}
			if ( isset( $dec['status'] ) && (int) $dec['status'] !== 0 && ! empty( $dec['message'] ) ) {
				return sanitize_text_field( $dec['message'] );
			}
		}
		$body_trim = is_string( $body_raw ) ? trim( wp_strip_all_tags( $body_raw ) ) : '';
		if ( '' !== $body_trim && strlen( $body_trim ) < 500 ) {
			return $body_trim;
		}
		if ( $code >= 400 && $code < 500 ) {
			return __( 'PayU rejected the request. Please check the payment link and try again.', 'payu-payment-links' );
		}
		if ( $code >= 500 ) {
			return __( 'PayU server error. Please try again later.', 'payu-payment-links' );
		}
		return $fallback;
	}
}
