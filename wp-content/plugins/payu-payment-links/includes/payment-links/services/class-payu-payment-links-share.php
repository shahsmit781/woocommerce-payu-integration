<?php
/**
 * PayU Payment Links – Share Payment Link API service
 *
 * Calls PayU Share Payment Link API (POST /payment-links/{id}/share).
 * Uses scope: create_payment_links update_payment_links read_payment_links.
 * Response: HTTP 2xx and status 0 = success. We do not rely on per-recipient result;
 * show a standard success message. On failure, return a generic message about checking format.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Payment_Links_Share
 */
class PayU_Payment_Links_Share {

	/**
	 * Share payment link via PayU API to one or more recipients (email and/or SMS in one call).
	 *
	 * @param string $payu_invoice_number PayU invoice number (payment link ID for API path).
	 * @param array  $channel_list        List of recipient strings: emails and/or phones (e.g. ['user@example.com', '+919876543210']).
	 * @param object $config              PayU config row (merchant_id, client_id, client_secret, environment).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function share( $payu_invoice_number, array $channel_list, $config ) {
		$payu_invoice_number = sanitize_text_field( (string) $payu_invoice_number );
		if ( '' === $payu_invoice_number ) {
			return new WP_Error( 'invalid_invoice', __( 'Invalid payment link invoice number.', 'payu-payment-links' ) );
		}

		$api_channel_list = array();
		foreach ( $channel_list as $recipient ) {
			$recipient = trim( (string) $recipient );
			if ( '' === $recipient ) {
				continue;
			}
			if ( is_email( $recipient ) ) {
				$api_channel_list[] = sanitize_email( $recipient );
			} else {
				$phone = self::normalize_phone( $recipient );
				if ( '' !== $phone ) {
					$api_channel_list[] = $phone;
				}
			}
		}
		if ( empty( $api_channel_list ) ) {
			return new WP_Error( 'invalid_channel_list', __( 'Please add at least one email or phone number.', 'payu-payment-links' ) );
		}

		if ( ! $config || empty( $config->merchant_id ) ) {
			return new WP_Error( 'no_config', __( 'Unable to send right now. Please try again later.', 'payu-payment-links' ) );
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

		$token = PayU_Token_Manager::get_token_for_share_payment_link(
			$merchant_id,
			$config->client_id,
			$client_secret,
			$environment
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$api_base = ( 'prod' === $environment ) ? 'https://oneapi.payu.in' : 'https://uatoneapi.payu.in';
		$url      = $api_base . '/payment-links/' . rawurlencode( $payu_invoice_number ) . '/share';

		// PayU expects channelList as request parameter (query or body). Use query: comma-separated list.
		$channel_list_param = implode( ',', $api_channel_list );
		$url = add_query_arg( 'channelList', $channel_list_param, $url );

		try {
			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'timeout' => 60,
					'headers' => array(
						'Content-Type'  => 'text/plain',
						'Accept'         => 'application/json',
						'Authorization' => 'Bearer ' . $token,
						'merchantId'    => $merchant_id,
					),
					'body'    => '',
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'PayU Share API network error: ' . $response->get_error_message() );
				}
				return new WP_Error( 'network', __( 'Something went wrong. Please try again.', 'payu-payment-links' ) );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body_res = wp_remote_retrieve_body( $response );
			$dec = json_decode( $body_res, true );

			if ( 401 === $code ) {
				$scope  = PayU_Token_Manager::SCOPE_SHARE_PAYMENT_LINKS;
				$scope  = function_exists( 'payu_normalize_scope_string' ) ? payu_normalize_scope_string( $scope ) : $scope;
				$hash   = function_exists( 'payu_scope_hash' ) ? payu_scope_hash( $scope ) : hash( 'sha256', $scope );
				$env_db = PayU_Token_Manager::normalize_environment_for_db( $environment );
				PayU_Token_Manager::invalidate_token( $merchant_id, $env_db, $hash );
				$token = PayU_Token_Manager::get_token_for_share_payment_link( $merchant_id, $config->client_id, $client_secret, $environment );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				$response = wp_remote_post(
					$url,
					array(
						'method'  => 'POST',
						'timeout' => 60,
						'headers' => array(
							'Content-Type'  => 'text/plain',
							'Accept'         => 'application/json',
							'Authorization' => 'Bearer ' . $token,
							'merchantId'    => $merchant_id,
						),
						'body'    => '',
					)
				);
				if ( is_wp_error( $response ) ) {
					return new WP_Error( 'network', __( 'Network or timeout error. Please try again.', 'payu-payment-links' ) );
				}
				$code    = wp_remote_retrieve_response_code( $response );
				$body_res = wp_remote_retrieve_body( $response );
				$dec     = json_decode( $body_res, true );
			}

			if ( $code < 200 || $code >= 300 ) {
				$msg = isset( $dec['message'] ) ? $dec['message'] : ( isset( $dec['error_description'] ) ? $dec['error_description'] : __( 'Something went wrong. Please try again.', 'payu-payment-links' ) );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'PayU Share API error: ' . $msg . ' (code ' . $code . ')' );
				}
				return new WP_Error( 'api_error', $msg );
			}

			$status = isset( $dec['status'] ) ? (int) $dec['status'] : -1;
			if ( 0 !== $status ) {
				$msg = isset( $dec['message'] ) && is_string( $dec['message'] ) ? $dec['message'] : __( 'Couldn’t send the link. Please check the email address and phone number (include country code, e.g. +91) and try again.', 'payu-payment-links' );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'PayU Share API business error: status=' . $status . ' ' . $msg );
				}
				return new WP_Error( 'api_error', $msg );
			}

			// status === 0: treat as success. We do not rely on result[email/phone] (docs not specified).
			return true;
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PayU Share API exception: ' . $e->getMessage() );
			}
			return new WP_Error( 'unexpected', __( 'An unexpected error occurred. Please try again.', 'payu-payment-links' ) );
		}
	}

	/**
	 * Normalize phone for PayU (digits only; optional leading +).
	 *
	 * @param string $phone Raw input.
	 * @return string Normalized phone or empty if invalid.
	 */
	private static function normalize_phone( $phone ) {
		$phone = trim( (string) $phone );
		$phone = preg_replace( '/[^\d+]/', '', $phone );
		if ( strlen( $phone ) < 6 ) {
			return '';
		}
		if ( substr( $phone, 0, 1 ) !== '+' ) {
			$phone = '+' . $phone;
		}
		return $phone;
	}
}
