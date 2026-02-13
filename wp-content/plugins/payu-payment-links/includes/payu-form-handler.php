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
 * Handle delete configuration request (soft delete via GET + nonce).
 * Runs on admin_init; redirects back to PayU settings on success.
 *
 * @return void
 */
function payu_handle_delete_config() {
	if ( ! isset( $_GET['payu_delete_config'] ) || ! isset( $_GET['_wpnonce'] ) ) {
		return;
	}
	$config_id = absint( $_GET['payu_delete_config'] );
	if ( ! $config_id ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'payu_delete_config_' . $config_id ) ) {
		return;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'payu_currency_configs';
	$updated   = $wpdb->update(
		$table_name,
		array( 'deleted_at' => current_time( 'mysql' ) ),
		array( 'id' => $config_id ),
		array( '%s' ),
		array( '%d' )
	);

	$redirect = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' );
	$redirect = add_query_arg( 'payu_config_deleted', $updated ? '1' : '0', $redirect );
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_init', 'payu_handle_delete_config' );

/**
 * Validate currency configuration input
 *
 * @param array $post_data POST data.
 * @return array|WP_Error Sanitized data on success, WP_Error with field-specific errors on failure.
 */
function payu_validate_currency_config_input( $post_data ) {
	$field_errors = array();

	$currency = isset( $post_data['payu_config_currency'] ) ? sanitize_text_field( $post_data['payu_config_currency'] ) : '';
	if ( empty( $currency ) ) {
		$field_errors['payu_config_currency'] = __( 'Currency is required.', 'payu-payment-links' );
	} elseif ( ! array_key_exists( $currency, get_woocommerce_currencies() ) ) {
		$field_errors['payu_config_currency'] = __( 'Invalid currency selected.', 'payu-payment-links' );
	}

	$merchant_id = isset( $post_data['payu_config_merchant_id'] ) ? sanitize_text_field( $post_data['payu_config_merchant_id'] ) : '';
	if ( empty( $merchant_id ) ) {
		$field_errors['payu_config_merchant_id'] = __( 'Merchant ID is required.', 'payu-payment-links' );
	} elseif ( strlen( $merchant_id ) > 100 ) {
		$field_errors['payu_config_merchant_id'] = __( 'Merchant ID must not exceed 100 characters.', 'payu-payment-links' );
	}

	$client_id = isset( $post_data['payu_config_client_id'] ) ? sanitize_text_field( $post_data['payu_config_client_id'] ) : '';
	if ( empty( $client_id ) ) {
		$field_errors['payu_config_client_id'] = __( 'Client ID is required.', 'payu-payment-links' );
	} elseif ( strlen( $client_id ) > 255 ) {
		$field_errors['payu_config_client_id'] = __( 'Client ID must not exceed 255 characters.', 'payu-payment-links' );
	}

	$client_secret = isset( $post_data['payu_config_client_secret'] ) ? sanitize_textarea_field( $post_data['payu_config_client_secret'] ) : '';
	if ( empty( $client_secret ) ) {
		$field_errors['payu_config_client_secret'] = __( 'Client Secret is required.', 'payu-payment-links' );
	}

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
 * Check if currency + merchant_id + environment combination is unique.
 *
 * @param string $currency    Currency code.
 * @param string $merchant_id Merchant ID.
 * @param string $environment Environment (uat/prod).
 * @return bool True if unique, false if an identical record exists.
 */
function payu_is_currency_environment_unique( $currency, $merchant_id, $environment ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'payu_currency_configs';

	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE currency = %s AND merchant_id = %s AND environment = %s AND deleted_at IS NULL",
			$currency,
			$merchant_id,
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

	// Ensure table exists
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
	);

	if ( $table_exists !== $table_name ) {
		return false;
	}

	// Sanitize input
	$currency      = sanitize_text_field( $data['currency'] );
	$merchant_id   = sanitize_text_field( $data['merchant_id'] );
	$client_id     = sanitize_text_field( $data['client_id'] );
	$environment   = sanitize_text_field( $data['environment'] );
	$client_secret = $data['client_secret'];

	// Encrypt client secret
	$encrypted_secret = payu_encrypt_client_secret( $client_secret );
	if ( false === $encrypted_secret ) {
		return false;
	}

	$wpdb->query( 'START TRANSACTION' );

	$existing = $wpdb->get_row(
		$wpdb->prepare(
				"SELECT id FROM {$table_name}
				WHERE currency = %s
				AND merchant_id = %s
				AND status = 'active'
				AND deleted_at IS NULL
				LIMIT 1",
				$currency,
				$merchant_id
			),
			ARRAY_A
	);

	if ( $existing ) {
		$wpdb->update(
			$table_name,
			array(
				'status'     => 'inactive',
				'updated_at' => current_time('mysql'),
			),
			array( 'id' => $existing['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	$inserted = $wpdb->insert(
		$table_name,
		array(
			'currency'      => $currency,
			'merchant_id'   => $merchant_id,
			'client_id'     => $client_id,
			'client_secret' => $encrypted_secret,
			'environment'   => $environment,
			'status'        => 'active',
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		$wpdb->query( 'ROLLBACK' );
		return false;
	}

	$wpdb->query( 'COMMIT' );

	return $wpdb->insert_id;
}

/**
 * Get encryption key for client secrets
 * Uses WordPress standard approach: custom constant or wp_salt() fallback
 *
 * @return string Encryption key.
 */
function payu_get_encryption_key() {
	// Use custom constant from wp-config.php if defined (WordPress best practice)
	if ( defined( 'PAYU_ENCRYPTION_KEY' ) && ! empty( PAYU_ENCRYPTION_KEY ) ) {
		return PAYU_ENCRYPTION_KEY;
	}

	// Note: If wp_salt rotates, decryption will fail - recommend defining PAYU_ENCRYPTION_KEY in wp-config.php
	return wp_salt( 'secure_auth' );
}

/**
 * Encrypt client secret using WordPress standard encryption approach
 * Uses AES-256-CBC with key from wp-config.php constant or wp_salt()
 *
 * @param string $client_secret Plain text client secret.
 * @return string|false Encrypted secret on success, false on failure.
 */
function payu_encrypt_client_secret( $client_secret ) {
	if ( empty( $client_secret ) ) {
		return false;
	}

	// Use OpenSSL if available (WordPress standard approach)
	if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_random_pseudo_bytes' ) ) {
		$method = 'AES-256-CBC';
		$key = hash( 'sha256', payu_get_encryption_key(), true );
		$iv_length = openssl_cipher_iv_length( $method );
		
		if ( false === $iv_length ) {
			return false;
		}

		$iv = openssl_random_pseudo_bytes( $iv_length );
		
		if ( false === $iv ) {
			return false;
		}

		$encrypted = openssl_encrypt( $client_secret, $method, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return false;
		}

		return base64_encode( $iv . $encrypted );
	}

	return base64_encode( $client_secret );
}

/**
 * Decrypt client secret using WordPress standard encryption approach
 *
 * @param string $encrypted_secret Encrypted client secret.
 * @return string|false Decrypted secret on success, false on failure.
 */
function payu_decrypt_client_secret( $encrypted_secret ) {
	if ( empty( $encrypted_secret ) ) {
		return false;
	}

	$decoded = base64_decode( $encrypted_secret, true );
	if ( false === $decoded ) {
		return false;
	}

	if ( function_exists( 'openssl_decrypt' ) && strlen( $decoded ) > 16 ) {
		$method = 'AES-256-CBC';
		$iv_length = openssl_cipher_iv_length( $method );
		
		if ( false === $iv_length || strlen( $decoded ) < $iv_length ) {
			// Not encrypted with OpenSSL, try base64 decode
			return base64_decode( $encrypted_secret, true );
		}

		$iv = substr( $decoded, 0, $iv_length );
		$encrypted = substr( $decoded, $iv_length );
		$key = hash( 'sha256', payu_get_encryption_key(), true );

		$decrypted = openssl_decrypt( $encrypted, $method, $key, OPENSSL_RAW_DATA, $iv );
		if ( false !== $decrypted ) {
			return $decrypted;
		}
	}

	return $decoded;
}

/**
 * Verify PayU API credentials by calling Payment Links Token API
 * This validates that the provided credentials are valid and can generate a payment token
 * Reference: https://docs.payu.in/reference/get-token-api-for-payment-links
 *
 * @param string $merchant_id   Merchant ID.
 * @param string $client_id      Client ID.
 * @param string $client_secret  Client Secret.
 * @param string $environment    Environment (uat/prod).
 * @return array|WP_Error Array with 'success' => true and token data on success, WP_Error on failure.
 */
function payu_verify_api_credentials( $merchant_id, $client_id, $client_secret, $environment ) {
	// Payment Links API uses accounts.payu.in (not api.payu.in)
	$api_base_url = ( 'prod' === $environment ) 
		? 'https://accounts.payu.in' 
		: 'https://uat-accounts.payu.in';

	$auth_url = $api_base_url . '/oauth/token';

		$auth_data = array(
		'grant_type'   => 'client_credentials',
		'client_id'    => $client_id,
		'client_secret' => $client_secret,
		'scope'        => 'create_payment_links update_payment_links read_payment_links',
	);

	$request_args = array(
		'method'      => 'POST',
		'timeout'     => 15, 
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

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'api_connection_error',
			__( 'Unable to connect to PayU API. Please check your internet connection.', 'payu-payment-links' )
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );
	$response_data = json_decode( $response_body, true );

	switch ( $response_code ) {
		case 200:
			if ( ! isset( $response_data['access_token'] ) || empty( $response_data['access_token'] ) ) {
				return new WP_Error(
					'api_auth_error',
					__( 'Invalid Credential: Access token not received from PayU API.', 'payu-payment-links' )
				);
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'payu_currency_configs';

			$existing_merchant = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT currency FROM {$table_name}
					WHERE merchant_id = %s
					AND deleted_at IS NULL
					LIMIT 1",
					sanitize_text_field( $merchant_id )
				)
			);

			if ( $existing_merchant ) {
				return new WP_Error(
					'merchant_already_allocated',
					sprintf(
						__( 'This Merchant ID is already allocated to currency: %s. Please use a different Merchant ID.', 'payu-payment-links' ),
						esc_html( $existing_merchant )
					)
				);
			}

			$scope = isset( $response_data['scope'] ) ? sanitize_text_field( $response_data['scope'] ) : '';
			$required_scopes = array( 'create_payment_links', 'update_payment_links', 'read_payment_links' );
			$scope_array = ! empty( $scope ) ? explode( ' ', $scope ) : array();
			
			$has_required_scope = false;
			foreach ( $required_scopes as $required_scope ) {
				if ( in_array( $required_scope, $scope_array, true ) ) {
					$has_required_scope = true;
					break;
				}
			}
			
			if ( ! $has_required_scope ) {
				return new WP_Error(
					'api_auth_error',
					__( 'Invalid Credential: Token does not have required Payment Links permissions.', 'payu-payment-links' )
				);
			}

			return array(
				'success'      => true,
				'access_token' => sanitize_text_field( $response_data['access_token'] ),
				'token_type'   => isset( $response_data['token_type'] ) ? sanitize_text_field( $response_data['token_type'] ) : 'Bearer',
				'expires_in'   => isset( $response_data['expires_in'] ) ? absint( $response_data['expires_in'] ) : 0,
				'scope'        => $scope,
				'created_at'   => isset( $response_data['created_at'] ) ? absint( $response_data['created_at'] ) : time(),
				'message'      => __( 'Payment token generated successfully.', 'payu-payment-links' ),
			);

		case 400:
			return new WP_Error(
				'api_auth_error',
				__( 'Invalid Credential: Invalid request parameters.', 'payu-payment-links' )
			);

		case 401:
			return new WP_Error(
				'api_auth_error',
				__( 'Invalid Credential: Client ID or Client Secret is incorrect.', 'payu-payment-links' )
			);

		case 403:
			return new WP_Error(
				'api_auth_error',
				__( 'Invalid Credential: Insufficient permissions.', 'payu-payment-links' )
			);

		case 429:
			return new WP_Error(
				'api_rate_limit_error',
				__( 'Too many requests. Please try again later.', 'payu-payment-links' )
			);

		case 500:
		case 502:
		case 503:
		case 504:
			return new WP_Error(
				'api_server_error',
				__( 'PayU server error. Please try again later.', 'payu-payment-links' )
			);

		default:
			return new WP_Error(
				'api_auth_error',
				__( 'Invalid Credential: Unable to verify credentials.', 'payu-payment-links' )
			);
	}
}

/**
 * AJAX handler for saving currency configuration
 * Handles AJAX form submissions with validation and API verification
 *
 * @return void
 */
function payu_ajax_save_currency_config() {
	if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
		wp_send_json_error( array(
			'message' => __( 'Invalid request method.', 'payu-payment-links' ),
		) );
		return;
	}

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'payu_save_currency_config' ) ) {
		wp_send_json_error( array(
			'message' => __( 'Security check failed. Please refresh the page and try again.', 'payu-payment-links' ),
		) );
		return;
	}

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ),
		) );
		return;
	}

	$validation_result = payu_validate_currency_config_input( $_POST );

	if ( is_wp_error( $validation_result ) ) {
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

	if ( ! payu_is_currency_environment_unique( $sanitized_data['currency'], $sanitized_data['merchant_id'], $sanitized_data['environment'] ) ) {
		wp_send_json_error( array(
			'message' => __( 'This configuration already exists. Please use a different Currency, Merchant ID, or Environment.', 'payu-payment-links' )
		) );
		return;
	}

	$api_verification = payu_verify_api_credentials(
		$sanitized_data['merchant_id'],
		$sanitized_data['client_id'],
		$sanitized_data['client_secret'],
		$sanitized_data['environment']
	);

	if ( is_wp_error( $api_verification ) ) {
		// Log error securely (without exposing secrets)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'PayU Payment Links: API verification failed - %s',
				$api_verification->get_error_code()
			) );
		}

		wp_send_json_error( array(
			'message' => $api_verification->get_error_message(),
		) );
		return;
	}

	$result = payu_save_currency_config_to_db( $sanitized_data );

	if ( $result ) {
		wp_send_json_success( array(
			'message' => __( 'Currency configuration saved successfully.', 'payu-payment-links' ),
			'config_id' => $result,
		) );
	} else {
		// Log database error securely
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PayU Payment Links: Failed to save configuration to database' );
		}

		wp_send_json_error( array(
			'message' => __( 'Failed to save configuration. Please try again.', 'payu-payment-links' ),
		) );
	}
}

/**
 * AJAX handler for filtering configurations list
 * Returns filtered table HTML via AJAX without page refresh
 *
 * @return void
 */
function payu_ajax_filter_configs() {
	// Debug: Log request for troubleshooting
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'PayU Filter AJAX Request: ' . print_r( $_POST, true ) );
	}

	// Check if action parameter exists
	if ( ! isset( $_POST['action'] ) || 'payu_filter_configs' !== $_POST['action'] ) {
		wp_send_json_error( array(
			'message' => __( 'Invalid action parameter.', 'payu-payment-links' ),
			'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array( 'received_action' => isset( $_POST['action'] ) ? $_POST['action'] : 'missing' ) : null,
		) );
		return;
	}

	// Check nonce
	if ( ! isset( $_POST['nonce'] ) ) {
		wp_send_json_error( array(
			'message' => __( 'Security nonce is missing.', 'payu-payment-links' ),
		) );
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'payu_filter_configs' ) ) {
		wp_send_json_error( array(
			'message' => __( 'Security check failed. Please refresh the page and try again.', 'payu-payment-links' ),
			'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array( 'nonce_received' => substr( $nonce, 0, 10 ) . '...' ) : null,
		) );
		return;
	}

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ),
		) );
		return;
	}

	// Set up request parameters for list table
	$_REQUEST['environment_filter'] = isset( $_POST['environment_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['environment_filter'] ) ) : '';
	$_REQUEST['paged'] = isset( $_POST['paged'] ) && absint( $_POST['paged'] ) > 0 ? absint( $_POST['paged'] ) : 1;
	$_REQUEST['orderby'] = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : '';
	$_REQUEST['order'] = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : '';
	$_REQUEST['s'] = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : ''; // Search term

	// Load the list table class
	require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-config-list-table.php';

	// Create an instance of our custom list table
	$list_table = new PayU_Config_List_Table();

	// Prepare items (this handles pagination and filtering)
	$list_table->prepare_items();

	// Capture the table output
	// Note: display() method automatically calls extra_tablenav('top') internally
	ob_start();
	$list_table->display();
	$table_html = ob_get_clean();

	// Check if output was captured successfully
	if ( false === $table_html ) {
		wp_send_json_error( array(
			'message' => __( 'Failed to generate table HTML.', 'payu-payment-links' ),
		) );
		return;
	}

	wp_send_json_success( array(
		'html' => $table_html,
	) );
}

/**
 * AJAX handler for toggling configuration status.
 *
 * Logic: one active config per currency. Single SELECT then one UPDATE when allowed.
 * Response shape: success always has message, status, config_id; error has message, optional prevent_update.
 *
 * @return void
 */
function payu_ajax_toggle_status() {
	// 1. Request guard
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '';
	if ( 'POST' !== $request_method ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request method.', 'payu-payment-links' ) ) );
		return;
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'payu_toggle_status' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'payu-payment-links' ) ) );
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'payu-payment-links' ) ) );
		return;
	}

	// 2. Input
	$config_id  = isset( $_POST['config_id'] ) ? absint( $_POST['config_id'] ) : 0;
	$currency   = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '';
	$new_status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

	if ( ! $config_id || ! $currency ) {
		wp_send_json_error( array( 'message' => __( 'Invalid configuration ID or currency.', 'payu-payment-links' ) ) );
		return;
	}
	if ( ! in_array( $new_status, array( 'active', 'inactive' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid status value.', 'payu-payment-links' ) ) );
		return;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'payu_currency_configs';

	// 3. Single read: current row + (when activating) whether another config for same currency is active
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT c.id, c.status,
				(SELECT id FROM {$table_name} t2
				 WHERE t2.currency = c.currency AND t2.id != c.id AND t2.status = 'active' AND t2.deleted_at IS NULL
				 LIMIT 1) AS other_active_id
			FROM {$table_name} c
			WHERE c.id = %d AND c.currency = %s AND c.deleted_at IS NULL",
			$config_id,
			$currency
		),
		ARRAY_A
	);

	if ( ! $row ) {
		wp_send_json_error( array( 'message' => __( 'Configuration not found or currency mismatch.', 'payu-payment-links' ) ) );
		return;
	}

	// 4. No change needed
	if ( $row['status'] === $new_status ) {
		payu_toggle_status_success( $config_id, $new_status, __( 'Status already set.', 'payu-payment-links' ) );
		return;
	}

	// 5. Activating: block if another config for this currency is already active
	if ( 'active' === $new_status && ! empty( $row['other_active_id'] ) ) {
		wp_send_json_error( array(
			'message'        => sprintf(
				__( 'Cannot activate: There is already an active configuration for currency %s. Only one active configuration per currency is allowed. Please deactivate the existing active configuration first.', 'payu-payment-links' ),
				$currency
			),
			'prevent_update' => true,
		) );
		return;
	}

	// 6. Update
	$updated = $wpdb->update(
		$table_name,
		array( 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ),
		array( 'id' => $config_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		wp_send_json_error( array( 'message' => __( 'Failed to update configuration status.', 'payu-payment-links' ) ) );
		return;
	}

	payu_toggle_status_success( $config_id, $new_status, __( 'Status updated successfully.', 'payu-payment-links' ) );
}

/**
 * Send consistent success payload for toggle status AJAX.
 *
 * @param int    $config_id Config ID.
 * @param string $status    New status (active|inactive).
 * @param string $message   Message to return.
 */
function payu_toggle_status_success( $config_id, $status, $message ) {
	wp_send_json_success( array(
		'message'         => $message,
		'status'          => $status,
		'config_id'       => $config_id,
		'deactivated_ids' => array(),
	) );
}
