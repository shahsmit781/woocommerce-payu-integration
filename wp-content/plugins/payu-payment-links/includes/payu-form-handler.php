<?php
/**
 * PayU Payment Links Form Handler
 *
 * Handles form submission and validation for currency configuration.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate currency configuration input
 *
 * @param array $post_data POST data.
 * @return array|WP_Error Sanitized data on success, WP_Error with field-specific errors on failure.
 */
function payu_validate_currency_config_input( $post_data ) {
	$field_errors = array();

	// Currency validation
	$currency = isset( $post_data['payu_config_currency'] ) ? sanitize_text_field( $post_data['payu_config_currency'] ) : '';
	if ( empty( $currency ) ) {
		$field_errors['payu_config_currency'] = __( 'Currency is required.', 'payu-payment-links' );
	} elseif ( ! array_key_exists( $currency, get_woocommerce_currencies() ) ) {
		$field_errors['payu_config_currency'] = __( 'Invalid currency selected.', 'payu-payment-links' );
	}

	// Merchant ID validation
	$merchant_id = isset( $post_data['payu_config_merchant_id'] ) ? sanitize_text_field( $post_data['payu_config_merchant_id'] ) : '';
	if ( empty( $merchant_id ) ) {
		$field_errors['payu_config_merchant_id'] = __( 'Merchant ID is required.', 'payu-payment-links' );
	} elseif ( strlen( $merchant_id ) > 100 ) {
		$field_errors['payu_config_merchant_id'] = __( 'Merchant ID must not exceed 100 characters.', 'payu-payment-links' );
	}

	// Client ID validation
	$client_id = isset( $post_data['payu_config_client_id'] ) ? sanitize_text_field( $post_data['payu_config_client_id'] ) : '';
	if ( empty( $client_id ) ) {
		$field_errors['payu_config_client_id'] = __( 'Client ID is required.', 'payu-payment-links' );
	} elseif ( strlen( $client_id ) > 255 ) {
		$field_errors['payu_config_client_id'] = __( 'Client ID must not exceed 255 characters.', 'payu-payment-links' );
	}

	// Client Secret validation
	$client_secret = isset( $post_data['payu_config_client_secret'] ) ? sanitize_textarea_field( $post_data['payu_config_client_secret'] ) : '';
	if ( empty( $client_secret ) ) {
		$field_errors['payu_config_client_secret'] = __( 'Client Secret is required.', 'payu-payment-links' );
	}

	// Environment validation
	$environment = isset( $post_data['payu_config_environment'] ) ? sanitize_text_field( $post_data['payu_config_environment'] ) : 'uat';
	if ( ! in_array( $environment, array( 'uat', 'prod' ), true ) ) {
		$environment = 'uat';
	}

	if ( ! empty( $field_errors ) ) {
		$error = new WP_Error( 'validation_error', __( 'Validation failed.', 'payu-payment-links' ) );
		foreach ( $field_errors as $field => $message ) {
			$error->add( $field, $message );
		}
		return $error;
	}

	return array(
		'currency'      => $currency,
		'merchant_id'   => $merchant_id,
		'client_id'     => $client_id,
		'client_secret' => $client_secret,
		'environment'   => $environment,
	);
}

/**
 * Check if currency + environment combination is unique
 *
 * @param string $currency    Currency code.
 * @param string $environment Environment (uat/prod).
 * @return bool True if unique, false if exists.
 */
function payu_is_currency_environment_unique( $currency, $environment ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'payu_currency_configs';

	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE currency = %s AND environment = %s AND deleted_at IS NULL",
			$currency,
			$environment
		)
	);

	return (int) $existing === 0;
}

/**
 * Save currency configuration to database
 *
 * @param array $data Sanitized configuration data.
 * @return int|false Insert ID on success, false on failure.
 */
function payu_save_currency_config_to_db( $data ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'payu_currency_configs';

	// Check if table exists
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	if ( ! $table_exists ) {
		return false;
	}

	$result = $wpdb->insert(
		$table_name,
		array(
			'currency'      => $data['currency'],
			'merchant_id'   => $data['merchant_id'],
			'client_id'     => $data['client_id'],
			'client_secret' => $data['client_secret'],
			'environment'   => $data['environment'],
			'status'        => 'active',
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return $result ? $wpdb->insert_id : false;
}

/**
 * Verify PayU API credentials by making a test API call
 * This validates that the provided credentials are valid and can authenticate with PayU
 *
 * @param string $merchant_id   Merchant ID.
 * @param string $client_id      Client ID.
 * @param string $client_secret  Client Secret.
 * @param string $environment    Environment (uat/prod).
 * @return array|WP_Error Array with 'success' => true on success, WP_Error on failure.
 */
function payu_verify_api_credentials( $merchant_id, $client_id, $client_secret, $environment ) {
	// Determine API base URL based on environment
	$api_base_url = ( 'prod' === $environment ) 
		? 'https://api.payu.in' 
		: 'https://sandbox.payu.in';

	// PayU OneAPI authentication endpoint
	$auth_url = $api_base_url . '/oauth/token';

	// Prepare authentication request
	$auth_data = array(
		'grant_type' => 'client_credentials',
		'client_id' => $client_id,
		'client_secret' => $client_secret,
	);

	$request_args = array(
		'method'      => 'POST',
		'timeout'     => 10,
		'redirection' => 5,
		'httpversion' => '1.1',
		'blocking'    => true,
		'headers'     => array(
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept'       => 'application/json',
		),
		'body'        => http_build_query( $auth_data ),
	);

	$response = wp_remote_post( $auth_url, $request_args );

	// Check for request errors
	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'api_connection_error',
			sprintf(
				/* translators: %s: Error message */
				__( 'Unable to connect to PayU API: %s', 'payu-payment-links' ),
				$response->get_error_message()
			)
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );
	$response_data = json_decode( $response_body, true );

	// Check HTTP response code
	if ( 200 !== $response_code ) {
		$error_message = isset( $response_data['error_description'] ) 
			? $response_data['error_description'] 
			: __( 'Invalid credentials. Please verify your Client ID and Client Secret.', 'payu-payment-links' );
		
		return new WP_Error(
			'api_auth_error',
			$error_message
		);
	}

	// Check if access token was received
	if ( ! isset( $response_data['access_token'] ) || empty( $response_data['access_token'] ) ) {
		return new WP_Error(
			'api_auth_error',
			__( 'Invalid API response. Please verify your credentials.', 'payu-payment-links' )
		);
	}

	// Credentials are valid
	return array(
		'success'      => true,
		'access_token' => $response_data['access_token'],
		'message'      => __( 'Credentials verified successfully.', 'payu-payment-links' ),
	);
}

/**
 * AJAX handler for saving currency configuration
 * Handles AJAX form submissions with validation and API verification
 *
 * @return void
 */
function payu_ajax_save_currency_config() {
	// Verify nonce
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'payu_save_currency_config' ) ) {
		wp_send_json_error( array(
			'message' => __( 'Security check failed. Please refresh the page and try again.', 'payu-payment-links' ),
		) );
		return;
	}

	// Check user capabilities
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ),
		) );
		return;
	}

	// Validate and sanitize input
	$validation_result = payu_validate_currency_config_input( $_POST );

	if ( is_wp_error( $validation_result ) ) {
		// Extract field-specific errors
		$field_errors = array();
		$error_codes = $validation_result->get_error_codes();
		foreach ( $error_codes as $code ) {
			if ( 'validation_error' !== $code ) {
				$field_errors[ $code ] = $validation_result->get_error_message( $code );
			}
		}

		wp_send_json_error( array(
			'message' => __( 'Please fix the errors below.', 'payu-payment-links' ),
			'field_errors' => $field_errors,
		) );
		return;
	}

	$sanitized_data = $validation_result;

	// Check uniqueness (Currency + Environment combination)
	if ( ! payu_is_currency_environment_unique( $sanitized_data['currency'], $sanitized_data['environment'] ) ) {
		$field_errors = array(
			'payu_config_currency' => sprintf(
				/* translators: %1$s: Currency code, %2$s: Environment */
				__( 'A configuration for %1$s (%2$s) already exists.', 'payu-payment-links' ),
				esc_html( $sanitized_data['currency'] ),
				esc_html( strtoupper( $sanitized_data['environment'] ) )
			),
		);

		wp_send_json_error( array(
			'message' => sprintf(
				/* translators: %1$s: Currency code, %2$s: Environment */
				__( 'A configuration for %1$s (%2$s) already exists.', 'payu-payment-links' ),
				esc_html( $sanitized_data['currency'] ),
				esc_html( strtoupper( $sanitized_data['environment'] ) )
			),
			'field_errors' => $field_errors,
		) );
		return;
	}

	// Verify PayU API credentials (third-party API verification)
	$api_verification = payu_verify_api_credentials(
		$sanitized_data['merchant_id'],
		$sanitized_data['client_id'],
		$sanitized_data['client_secret'],
		$sanitized_data['environment']
	);

	if ( is_wp_error( $api_verification ) ) {
		// API errors typically relate to Client ID and Client Secret
		$field_errors = array();
		$error_code = $api_verification->get_error_code();
		
		if ( 'api_auth_error' === $error_code ) {
			// Authentication failed - likely Client ID or Client Secret issue
			$field_errors['payu_config_client_id'] = __( 'Invalid credentials.', 'payu-payment-links' );
			$field_errors['payu_config_client_secret'] = __( 'Invalid credentials.', 'payu-payment-links' );
		} else {
			// Connection error - show general message
			$field_errors['payu_config_client_secret'] = $api_verification->get_error_message();
		}

		wp_send_json_error( array(
			'message' => sprintf(
				/* translators: %s: API error message */
				__( 'API Verification Failed: %s', 'payu-payment-links' ),
				$api_verification->get_error_message()
			),
			'field_errors' => $field_errors,
		) );
		return;
	}

	// Save to database
	$result = payu_save_currency_config_to_db( $sanitized_data );

	if ( $result ) {
		wp_send_json_success( array(
			'message' => __( 'Currency configuration saved successfully.', 'payu-payment-links' ),
			'config_id' => $result,
		) );
	} else {
		wp_send_json_error( array(
			'message' => __( 'Failed to save configuration. Please try again.', 'payu-payment-links' ),
		) );
	}
}
