<?php
/**
 * PayU Token Manager – DB-backed, scope-aware access token retrieval
 *
 * Get Token API: https://docs.payu.in/reference/get-token-api-for-payment-links
 * Reuses valid tokens; regenerates on expiry or 401. No token in order/user meta.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Token_Manager
 */
class PayU_Token_Manager {

	const SCOPE_CREATE_PAYMENT_LINKS = 'create_payment_links';
	const BUFFER_SECONDS             = 60;

	/**
	 * Normalize environment for token table (config uses 'prod', table uses 'production').
	 *
	 * @param string $environment uat or prod.
	 * @return string uat or production.
	 */
	public static function normalize_environment_for_db( $environment ) {
		$environment = sanitize_text_field( $environment );
		return ( 'prod' === $environment ) ? 'production' : $environment;
	}

	/**
	 * Get PayU access token for create_payment_links scope.
	 * Returns existing valid token from DB or fetches new one and stores it.
	 *
	 * @param string $merchant_id   PayU Merchant ID.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client Secret (plain).
	 * @param string $environment   uat or prod.
	 * @return string|WP_Error Access token on success, WP_Error on failure.
	 */
	public static function get_token_for_create_payment_link( $merchant_id, $client_id, $client_secret, $environment ) {
		$scope   = self::SCOPE_CREATE_PAYMENT_LINKS;
		$scope   = function_exists( 'payu_normalize_scope_string' ) ? payu_normalize_scope_string( $scope ) : $scope;
		$hash    = function_exists( 'payu_scope_hash' ) ? payu_scope_hash( $scope ) : hash( 'sha256', $scope );
		$env_db  = self::normalize_environment_for_db( $environment );

		$existing = self::get_valid_token_from_db( $merchant_id, $env_db, $hash );
		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}

		$result = self::fetch_and_store_token( $merchant_id, $client_id, $client_secret, $environment, $scope, $hash );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['access_token'] ) ? $result['access_token'] : new WP_Error( 'no_token', __( 'No access token received.', 'payu-payment-links' ) );
	}

	/**
	 * Get a valid (active, not expired) token from DB.
	 *
	 * @param string $merchant_id Merchant ID.
	 * @param string $environment uat or production.
	 * @param string $scope_hash  SHA-256 of normalized scope.
	 * @return string|null Token string or null if none valid.
	 */
	public static function get_valid_token_from_db( $merchant_id, $environment, $scope_hash ) {
		global $wpdb;
		$table = function_exists( 'payu_get_api_tokens_table_name' ) ? payu_get_api_tokens_table_name() : $wpdb->prefix . 'payu_api_tokens';
		$buffer = gmdate( 'Y-m-d H:i:s', time() + self::BUFFER_SECONDS );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT access_token FROM {$table}
				WHERE merchant_id = %s AND environment = %s AND scope_hash = %s
				AND status = 'active' AND expires_at > %s
				ORDER BY updated_at DESC LIMIT 1",
				$merchant_id,
				$environment,
				$scope_hash,
				$buffer
			),
			ARRAY_A
		);
		return ( $row && ! empty( $row['access_token'] ) ) ? $row['access_token'] : null;
	}

	/**
	 * Call PayU OAuth token API and store result in DB.
	 *
	 * @param string $merchant_id   Merchant ID.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client Secret (plain).
	 * @param string $environment   uat or prod.
	 * @param string $scope_normal  Normalized scope string.
	 * @param string $scope_hash    SHA-256 of scope.
	 * @return array|WP_Error Token data or WP_Error.
	 */
	public static function fetch_and_store_token( $merchant_id, $client_id, $client_secret, $environment, $scope_normal, $scope_hash ) {
		$api_base = ( 'prod' === $environment ) ? 'https://accounts.payu.in' : 'https://uat-accounts.payu.in';
		$auth_url = $api_base . '/oauth/token';
		$body    = array(
			'grant_type'    => 'client_credentials',
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'scope'         => $scope_normal,
		);
		$response = wp_remote_post(
			$auth_url,
			array(
				'method'  => 'POST',
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => http_build_query( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = isset( $data['error_description'] ) ? $data['error_description'] : ( isset( $data['message'] ) ? $data['message'] : __( 'Token request failed.', 'payu-payment-links' ) );
			return new WP_Error( 'token_api_error', $msg );
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'token_api_error', __( 'Access token not received.', 'payu-payment-links' ) );
		}
		$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in );
		$env_db     = self::normalize_environment_for_db( $environment );
		self::upsert_token( $merchant_id, $env_db, $scope_normal, $scope_hash, $data['access_token'], $expires_at );
		return array(
			'access_token' => $data['access_token'],
			'expires_at'   => $expires_at,
		);
	}

	/**
	 * Insert or update token row (on duplicate key update).
	 *
	 * @param string $merchant_id  Merchant ID.
	 * @param string $environment  uat or production.
	 * @param string $scope        Normalized scope string.
	 * @param string $scope_hash   SHA-256 of scope.
	 * @param string $access_token Access token.
	 * @param string $expires_at   Datetime expiry.
	 */
	public static function upsert_token( $merchant_id, $environment, $scope, $scope_hash, $access_token, $expires_at ) {
		global $wpdb;
		$table = function_exists( 'payu_get_api_tokens_table_name' ) ? payu_get_api_tokens_table_name() : $wpdb->prefix . 'payu_api_tokens';
		$wpdb->replace(
			$table,
			array(
				'merchant_id'   => $merchant_id,
				'environment'   => $environment,
				'scope'         => $scope,
				'scope_hash'    => $scope_hash,
				'access_token'  => $access_token,
				'expires_at'    => $expires_at,
				'status'       => 'active',
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Mark token as expired for (merchant_id, environment, scope_hash) so next call fetches a new one.
	 *
	 * @param string $merchant_id  Merchant ID.
	 * @param string $environment  uat or production.
	 * @param string $scope_hash   SHA-256 of scope.
	 */
	public static function invalidate_token( $merchant_id, $environment, $scope_hash ) {
		global $wpdb;
		$table = function_exists( 'payu_get_api_tokens_table_name' ) ? payu_get_api_tokens_table_name() : $wpdb->prefix . 'payu_api_tokens';
		$wpdb->update(
			$table,
			array( 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ),
			array( 'merchant_id' => $merchant_id, 'environment' => $environment, 'scope_hash' => $scope_hash ),
			array( '%s', '%s' ),
			array( '%s', '%s', '%s' )
		);
	}
}
