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
	$table_name = payu_get_currency_configs_table_name();
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
add_action( 'admin_init', 'payu_handle_update_config' );

/**
 * Soft-delete a currency config by ID (set deleted_at).
 *
 * @param int $config_id Config ID.
 * @return bool True if row was updated.
 */
function payu_soft_delete_currency_config( $config_id ) {
	$config_id = absint( $config_id );
	if ( ! $config_id ) {
		return false;
	}
	global $wpdb;
	$table_name = payu_get_currency_configs_table_name();
	$result     = $wpdb->update(
		$table_name,
		array( 'deleted_at' => current_time( 'mysql' ) ),
		array( 'id' => $config_id ),
		array( '%s' ),
		array( '%d' )
	);
	return false !== $result;
}

/**
 * Get a single currency config by ID (non-deleted).
 *
 * @param int $config_id Config ID.
 * @return object|null Row object or null if not found.
 */
function payu_get_currency_config_by_id( $config_id ) {
	$config_id = absint( $config_id );
	if ( ! $config_id ) {
		return null;
	}
	global $wpdb;
	$table_name = payu_get_currency_configs_table_name();
	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d AND deleted_at IS NULL",
			$config_id
		)
	);
}

/**
 * Get active PayU config for a currency (status = active, deleted_at IS NULL).
 *
 * @param string $currency Currency code (e.g. INR, USD).
 * @return object|null Config row or null if not found.
 */
function payu_get_active_config_by_currency( $currency ) {
	$currency = sanitize_text_field( $currency );
	if ( '' === $currency ) {
		return null;
	}
	global $wpdb;
	$table_name = payu_get_currency_configs_table_name();
	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE currency = %s AND status = 'active' AND deleted_at IS NULL LIMIT 1",
			$currency
		)
	);
}

/**
 * Get first active PayU config (any currency). Used as fallback when payment link row has no config_id/currency.
 *
 * @return object|null Config row or null.
 */
function payu_get_first_active_config() {
	global $wpdb;
	$table_name = payu_get_currency_configs_table_name();
	return $wpdb->get_row(
		"SELECT * FROM {$table_name} WHERE status = 'active' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1"
	);
}

/**
 * Get list of currency codes that have an active PayU configuration (for dropdowns).
 *
 * @return array Array of currency codes, keyed by currency code, value is display label (code + name if available).
 */
function payu_get_active_payu_currencies() {
	global $wpdb;
	$table_name  = payu_get_currency_configs_table_name();
	$currencies  = get_woocommerce_currencies();
	$rows        = $wpdb->get_col(
		"SELECT DISTINCT currency FROM {$table_name} WHERE status = 'active' AND deleted_at IS NULL ORDER BY currency ASC"
	);
	$result = array();
	foreach ( (array) $rows as $code ) {
		$code = sanitize_text_field( $code );
		if ( '' !== $code ) {
			$result[ $code ] = isset( $currencies[ $code ] ) ? $code . ' – ' . $currencies[ $code ] : $code;
		}
	}
	return $result;
}

/**
 * Persist PayU payment link response in database (wp_payu_payment_links).
 *
 * @param int    $order_id          WooCommerce order ID.
 * @param string $payu_invoice_number PayU invoice number.
 * @param string $payment_link_url  Payment link URL (stored in varchar(500)).
 * @param float  $amount            Link amount.
 * @param string $currency         Currency code.
 * @param string $environment      uat or prod.
 * @param string $expiry_date      Expiry datetime or empty.
 * @param array  $extra            Optional: config_id, mid, env, isPartialPaymentAllowed, min_initial_payment, max_instalments, customerName, customerPhone, customerEmail, emailStatus, smsStatus, udf1, udf5.
 * @return int|false Insert ID or false.
 */
function payu_save_payment_link_response( $order_id, $payu_invoice_number, $payment_link_url, $amount, $currency, $environment, $expiry_date, $extra = array() ) {
	global $wpdb;
	$table  = function_exists( 'payu_get_payment_links_table_name' ) ? payu_get_payment_links_table_name() : $wpdb->prefix . 'payu_payment_links';
	$amount = (float) $amount;
	$url    = esc_url_raw( $payment_link_url );
	if ( strlen( $url ) > 255 ) {
		$url = substr( $url, 0, 255 );
	}
	$row = array(
		'order_id'                  => absint( $order_id ),
		'payu_invoice_number'       => sanitize_text_field( $payu_invoice_number ),
		'payment_link_url'          => $url,
		'amount'                    => $amount,
		'currency'                  => sanitize_text_field( $currency ),
		'paid_amount'               => 0,
		'remaining_amount'          => $amount,
		'status'                    => 'pending',
		'expiry_date'               => $expiry_date ? sanitize_text_field( $expiry_date ) : null,
		'environment'               => sanitize_text_field( $environment ),
		'isPartialPaymentAllowed'   => isset( $extra['isPartialPaymentAllowed'] ) ? (int) (bool) $extra['isPartialPaymentAllowed'] : 0,
		'min_initial_payment'       => isset( $extra['min_initial_payment'] ) ? wc_format_decimal( $extra['min_initial_payment'], 2 ) : null,
		'max_instalments'           => isset( $extra['max_instalments'] ) ? absint( $extra['max_instalments'] ) : null,
		'mid'                       => isset( $extra['mid'] ) ? sanitize_text_field( $extra['mid'] ) : null,
		'customerName'              => isset( $extra['customerName'] ) ? sanitize_text_field( $extra['customerName'] ) : null,
		'customerPhone'             => isset( $extra['customerPhone'] ) ? sanitize_text_field( $extra['customerPhone'] ) : null,
		'customerEmail'             => isset( $extra['customerEmail'] ) ? sanitize_email( $extra['customerEmail'] ) : null,
		'is_email_sent'              => isset( $extra['is_email_sent'] ) ? (int) (bool) $extra['is_email_sent'] : 0,
		'is_sms_sent'                => isset( $extra['is_sms_sent'] ) ? (int) (bool) $extra['is_sms_sent'] : 0,
		'emailStatus'               => isset( $extra['emailStatus'] ) ? sanitize_textarea_field( $extra['emailStatus'] ) : null,
		'smsStatus'                 => isset( $extra['smsStatus'] ) ? sanitize_textarea_field( $extra['smsStatus'] ) : null,
		'udf1'                      => isset( $extra['udf1'] ) ? sanitize_text_field( $extra['udf1'] ) : (string) $order_id,
		'udf5'                      => ( isset( $extra['udf5'] ) && '' !== trim( (string) $extra['udf5'] ) ) ? sanitize_text_field( $extra['udf5'] ) : 'WooCommerce_paymentlink',
		'config_id'                 => isset( $extra['config_id'] ) ? absint( $extra['config_id'] ) : null,
	);

	$formats = array( '%d', '%s', '%s', '%f', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d' );

	$r = $wpdb->insert( $table, $row, $formats );
	return $r ? $wpdb->insert_id : false;
}

/**
 * Create PayU payment link via API: validate → get token → call API → save response.
 * Uses DB-backed token manager; on 401 invalidates token and retries once.
 *
 * @param WC_Order $order Order.
 * @param array    $data  Sanitized form data (customer_name, customer_email, customer_phone, amount, currency, expiry_date, description, partial_payment, min_initial_payment, num_instalments, notify_email, notify_email_address, notify_sms, notify_sms_number).
 * @return string|WP_Error Payment link URL on success, WP_Error on failure.
 */
function payu_create_payment_link_api( $order, $data ) {
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return new WP_Error( 'invalid_order', __( 'Invalid order.', 'payu-payment-links' ) );
	}
	$order_id = $order->get_id();
	$amount   = (float) $data['amount'];
	$order_total = (float) $order->get_total();
	if ( $amount <= 0 ) {
		return new WP_Error( 'invalid_amount', __( 'Payment amount must be greater than zero.', 'payu-payment-links' ) );
	}
	if ( $amount > $order_total ) {
		return new WP_Error( 'invalid_amount', __( 'Payment amount must not exceed order total.', 'payu-payment-links' ) );
	}
	$currency = $data['currency'];
	$config   = payu_get_active_config_by_currency( $currency );
	if ( ! $config ) {
		return new WP_Error( 'no_config', __( 'No active PayU configuration for the selected currency.', 'payu-payment-links' ) );
	}
	$client_secret = payu_decrypt_client_secret( $config->client_secret );
	if ( false === $client_secret || '' === $client_secret ) {
		return new WP_Error( 'decrypt_failed', __( 'Unable to use stored credentials. Please re-save the PayU configuration.', 'payu-payment-links' ) );
	}
	if ( ! class_exists( 'PayU_Token_Manager' ) ) {
		return new WP_Error( 'token_manager_missing', __( 'Token manager not available.', 'payu-payment-links' ) );
	}
	$token = PayU_Token_Manager::get_token_for_create_payment_link( $config->merchant_id, $config->client_id, $client_secret, $config->environment );
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$environment = $config->environment;
	
	$api_base    = ( 'prod' === $environment ) ? 'https://oneapi.payu.in' : 'https://uatoneapi.payu.in';
	
	// Invoice number should be alphaNumeric
	$invoice_num = 'WC' . $order_id . rand( 1000, 9999 );
	// Ensure invoice number is exactly 16 characters.
	if ( strlen( $invoice_num ) > 16 ) {
		$invoice_num = substr( $invoice_num, 0, 16 );
	}

	$payload     = payu_build_create_link_payload( $order, $data, $invoice_num );
	$response    = wp_remote_post(
		$api_base . '/payment-links',
		array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
				'merchantId'    => $config->merchant_id,
			),
			'body'    => wp_json_encode( $payload ),
		)
	);
	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$dec  = json_decode( $body, true );
	if ( 401 === $code ) {
		$scope   = PayU_Token_Manager::SCOPE_CREATE_PAYMENT_LINKS;
		$scope   = function_exists( 'payu_normalize_scope_string' ) ? payu_normalize_scope_string( $scope ) : $scope;
		$hash    = function_exists( 'payu_scope_hash' ) ? payu_scope_hash( $scope ) : hash( 'sha256', $scope );
		$env_db  = PayU_Token_Manager::normalize_environment_for_db( $environment );
		PayU_Token_Manager::invalidate_token( $config->merchant_id, $env_db, $hash );
		$token = PayU_Token_Manager::get_token_for_create_payment_link( $config->merchant_id, $config->client_id, $client_secret, $config->environment );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_post(
			$api_base . '/payment-links',
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'merchantId'    => $config->merchant_id,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$dec  = json_decode( $body, true );
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== $code && 201 !== $code ) {
		$msg = isset( $dec['message'] ) ? $dec['message'] : ( isset( $dec['error_description'] ) ? $dec['error_description'] : __( 'PayU API error.', 'payu-payment-links' ) );
		return new WP_Error( 'payu_api_error', $msg );
	}

	$status = isset( $dec['status'] ) ? (int) $dec['status'] : -1;
	if ( 0 !== $status ) {
		$msg = isset( $dec['message'] ) ? $dec['message'] : __( 'Payment link creation failed.', 'payu-payment-links' );
		return new WP_Error( 'payu_api_error', $msg );
	}
	$result = isset( $dec['result'] ) ? $dec['result'] : array();
	$link   = isset( $result['paymentLink'] ) ? $result['paymentLink'] : ( isset( $result['short_url'] ) ? $result['short_url'] : '' );
	if ( '' === $link ) {
		return new WP_Error( 'payu_api_error', __( 'Payment link URL not received from PayU.', 'payu-payment-links' ) );
	}
	$inv_num    = isset( $result['invoiceNumber'] ) ? $result['invoiceNumber'] : $invoice_num;
	$expiry_res = isset( $result['expiryDate'] ) ? sanitize_text_field( $result['expiryDate'] ) : ( isset( $data['expiry_date'] ) ? sanitize_text_field( $data['expiry_date'] ) : null );

	$extra = array(
		'config_id'                => isset( $config->id ) ? $config->id : null,
		'isPartialPaymentAllowed' => isset( $result['isPartialPaymentAllowed'] ) ? (bool) $result['isPartialPaymentAllowed'] : ! empty( $data['partial_payment'] ),
		'min_initial_payment'      => ! empty( $data['min_initial_payment'] ) ? $data['min_initial_payment'] : null,
		'max_instalments'          => ! empty( $data['num_instalments'] ) ? (int) $data['num_instalments'] : null,
		'mid'                      => isset( $config->merchant_id ) ? $config->merchant_id : null,
		'customerName'             => isset( $data['customer_name'] ) ? sanitize_text_field( $data['customer_name'] ) : null,
		'customerPhone'            => isset( $data['customer_phone'] ) ? sanitize_text_field( $data['customer_phone'] ) : null,
		'customerEmail'             => ! empty( $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : null,
		'is_email_sent'              => ! empty( $data['notify_email'] ) && ! empty( $data['customer_email'] ) ? 1 : 0,
		'is_sms_sent'                => ! empty( $data['notify_sms'] ) && ! empty( $data['customer_phone'] ) ? 1 : 0,
		'emailStatus'              => ! empty($result['emailStatus']) ? sanitize_textarea_field( $result['emailStatus'] ) : null,
		'smsStatus'                => ! empty($result['smsStatus']) ? sanitize_textarea_field( $result['smsStatus'] ) : null,
		'udf1'                     => (string) $order_id,
		'udf5'                     => 'WooCommerce_paymentlink',
	);

	payu_save_payment_link_response( $order_id, $inv_num, $link, $amount, $currency, $environment, $expiry_res, $extra );
	return $link;
}

/**
 * Build request payload for PayU Create Payment Link API.
 *
 * @param WC_Order $order      Order.
 * @param array    $data       Form data.
 * @param string   $invoice_number Invoice number to use.
 * @return array Payload for JSON body.
 */
function payu_build_create_link_payload( $order, $data, $invoice_number ) {
	$amount       = (float) $data['amount'];
	$partial      = ! empty( $data['partial_payment'] );
	$sub_amount   = (int) round( $amount);
	if ( $sub_amount < 1 ) {
		$sub_amount = 1;
	}

	$payload = array(
		'invoiceNumber'           => $invoice_number,
		'subAmount'               => $sub_amount,
		'currency'                => $data['currency'],
		'description'             => '' !== $data['description'] ? $data['description'] : sprintf( __( 'Order #%s', 'payu-payment-links' ), $order->get_id() ),
		'source'                  => 'API',
		'isPartialPaymentAllowed' => $partial,
		'customer'                => array(
			'name'  => '' !== $data['customer_name'] ? $data['customer_name'] : ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email' => ! empty( $data['notify_email'] ) && '' !== $data['customer_email'] ? $data['customer_email'] : '',
			'phone' => '' !== $data['customer_phone'] ? $data['customer_phone'] : $order->get_billing_phone(),
		),
		'viaEmail'                => ! empty( $data['notify_email'] ),
		'viaSms'                  => ! empty( $data['notify_sms'] ),
		'udf'                     => array(
			'udf1' => (string) $order->get_id(),
			'udf5' => 'WooCommerce_paymentlink',
		),
		'successURL'              => class_exists( 'PayU_Payment_Link_Status_Page' ) ? PayU_Payment_Link_Status_Page::get_status_url( $invoice_number ) : $order->get_checkout_order_received_url(),
		'failureURL'              => class_exists( 'PayU_Payment_Link_Status_Page' ) ? PayU_Payment_Link_Status_Page::get_status_url( $invoice_number ) : ( wc_get_page_permalink( 'checkout' ) ? wc_get_page_permalink( 'checkout' ) : $order->get_checkout_order_received_url() ),
	);
	if ( '' !== $data['expiry_date'] ) {
		$ts = strtotime( $data['expiry_date'] );
		$payload['expiryDate'] = gmdate( 'Y-m-d H:i:s', $ts );
	}
	if ( ! $partial ) {
		$payload['transactionId'] = $invoice_number;
	}
	if ( $partial && (float) $data['min_initial_payment'] > 0 ) {
		$payload['minAmountForCustomer'] = (int) round( (float) $data['min_initial_payment']);
	}
	if ( $partial && (int) $data['num_instalments'] > 0 ) {
		$payload['maxPaymentsAllowed'] = (int) $data['num_instalments'];
	}
	return $payload;
}

/**
 * Handle update configuration request (POST + nonce).
 * Validates input, checks uniqueness, updates DB, redirects with success/error.
 *
 * @return void
 */
function payu_handle_update_config() {

	if ( ! isset( $_POST['payu_update_config'] ) || ! isset( $_POST['payu_update_config_nonce'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$nonce = isset( $_POST['payu_update_config_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_update_config_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'payu_update_config' ) ) {
		$redirect = add_query_arg(
			'payu_config_error',
			urlencode( __( 'Security check failed. Please try again.', 'payu-payment-links' ) ),
			admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	$validated = payu_validate_edit_config_input( $_POST );
	if ( is_wp_error( $validated ) ) {
		$msg      = $validated->get_error_message();
		$edit_id  = isset( $_POST['config_id'] ) ? absint( $_POST['config_id'] ) : 0;
		$base     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' );
		$redirect = add_query_arg(
			array(
				'payu_config_error' => urlencode( $msg ? $msg : __( 'Validation failed.', 'payu-payment-links' ) ),
				'edit_payu_config'   => $edit_id,
			),
			$base
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	$result = payu_process_update_currency_config( $validated );
	$base   = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg(
			array(
				'payu_config_error' => urlencode( $result->get_error_message() ),
				'edit_payu_config'  => $validated['config_id'],
			),
			$base
		) );
	} else {
		wp_safe_redirect( add_query_arg( 'payu_config_updated', '1', $base ) );
	}
	exit;
}

/**
 * Process update currency config: token generation + update or delete+insert (reuses add flow when merchant changes).
 *
 * @param array $validated Validated edit form data (config_id, merchant_id, client_id, client_secret, environment).
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function payu_process_update_currency_config( $validated ) {
	$config = payu_get_currency_config_by_id( $validated['config_id'] );
	if ( ! $config ) {
		return new WP_Error( 'config_not_found', __( 'Configuration not found.', 'payu-payment-links' ) );
	}
	if ( ! payu_is_currency_environment_unique( $config->currency, $validated['merchant_id'], $validated['environment'], $validated['config_id'] ) ) {
		return new WP_Error( 'duplicate', __( 'This Merchant ID and Environment combination is already in use for another configuration.', 'payu-payment-links' ) );
	}
	$client_secret_plain = $validated['client_secret'];
	if ( '' === $client_secret_plain && ! empty( $config->client_secret ) ) {
		$decrypted           = payu_decrypt_client_secret( $config->client_secret );
		$client_secret_plain = ( false !== $decrypted ) ? $decrypted : '';
	}

	if ( $validated['merchant_id'] !== $config->merchant_id ) {
		// Merchant ID changed: new credentials required; cannot use old config's secret.
		if ( '' === $client_secret_plain ) {
			return new WP_Error( 'client_secret_required', __( 'Client Secret is required when changing Merchant ID.', 'payu-payment-links' ) );
		}
		// Delete current record, then generate token and insert new (same as add flow).
		if ( ! payu_soft_delete_currency_config( $validated['config_id'] ) ) {
			return new WP_Error( 'update_failed', __( 'Failed to update configuration.', 'payu-payment-links' ) );
		}
		$api_result = payu_verify_api_credentials(
			$validated['merchant_id'],
			$validated['client_id'],
			$client_secret_plain,
			$validated['environment'],
			$config->currency
		);
		if ( is_wp_error( $api_result ) ) {
			return $api_result;
		}
		$save_data = array(
			'currency'      => $config->currency,
			'merchant_id'   => $validated['merchant_id'],
			'client_id'     => $validated['client_id'],
			'client_secret' => $client_secret_plain,
			'environment'   => $validated['environment'],
		);
		$new_id = payu_save_currency_config_to_db( $save_data );
		if ( ! $new_id ) {
			return new WP_Error( 'save_failed', __( 'Failed to save new configuration.', 'payu-payment-links' ) );
		}
		return true;
	}
	
	$api_result = payu_verify_api_credentials(
		$validated['merchant_id'],
		$validated['client_id'],
		$client_secret_plain,
		$validated['environment'],
		$config->currency
	);

	if ( is_wp_error( $api_result ) ) {
		return $api_result;
	}

	$updated = payu_update_currency_config_in_db( $validated );
	if ( ! $updated ) {
		return new WP_Error( 'update_failed', __( 'Failed to update configuration.', 'payu-payment-links' ) );
	}
	return true;
}

/**
 * AJAX handler for update currency configuration.
 * Uses payu_process_update_currency_config for token + update/delete+insert (same as add flow when merchant changes).
 *
 * @return void
 */
function payu_ajax_update_currency_config() {
	if ( ! isset( $_POST['payu_update_config_nonce'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Please try again.', 'payu-payment-links' ) ) );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'payu-payment-links' ) ) );
	}
	$nonce = isset( $_POST['payu_update_config_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_update_config_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'payu_update_config' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Please try again.', 'payu-payment-links' ) ) );
	}
	$validated = payu_validate_edit_config_input( $_POST );
	if ( is_wp_error( $validated ) ) {
		wp_send_json_error( array( 'message' => $validated->get_error_message() ) );
	}
	$result = payu_process_update_currency_config( $validated );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	$base = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' );
	wp_send_json_success( array( 'redirect' => add_query_arg( 'payu_config_updated', '1', $base ) ) );
}

/**
 * Validate edit configuration form input.
 *
 * @param array $post_data POST data.
 * @return array|WP_Error Sanitized data or WP_Error.
 */
function payu_validate_edit_config_input( $post_data ) {
	
	$config_id = isset( $post_data['config_id'] ) ? absint( $post_data['config_id'] ) : 0;
	if ( ! $config_id ) {
		return new WP_Error( 'config_id', __( 'Invalid configuration.', 'payu-payment-links' ) );
	}
	$merchant_id = isset( $post_data['merchant_id'] ) ? sanitize_text_field( wp_unslash( $post_data['merchant_id'] ) ) : '';
	if ( '' === $merchant_id || strlen( $merchant_id ) > 100 ) {
		return new WP_Error( 'merchant_id', __( 'Merchant ID is required and must not exceed 100 characters.', 'payu-payment-links' ) );
	}

	$client_id = isset( $post_data['client_id'] ) ? sanitize_text_field( wp_unslash( $post_data['client_id'] ) ) : '';
	if ( '' === $client_id || strlen( $client_id ) > 255 ) {
		return new WP_Error( 'client_id', __( 'Client ID is required and must not exceed 255 characters.', 'payu-payment-links' ) );
	}

	$client_secret = isset( $post_data['payu_edit_client_secret'] ) ? sanitize_textarea_field( wp_unslash( $post_data['payu_edit_client_secret'] ) ) : '';
	if ( '' !== $client_secret && strlen( $client_secret ) > 500 ) {
		return new WP_Error( 'client_secret', __( 'Client Secret must not exceed 500 characters.', 'payu-payment-links' ) );
	}

	$environment   = isset( $post_data['environment'] ) ? sanitize_text_field( wp_unslash( $post_data['environment'] ) ) : 'uat';
	if ( ! in_array( $environment, array( 'uat', 'prod' ), true ) ) {
		$environment = 'uat';
	}
	return array(
		'config_id'     => $config_id,
		'merchant_id'   => $merchant_id,
		'client_id'     => $client_id,
		'client_secret' => $client_secret,
		'environment'   => $environment,
	);
}

/**
 * Update currency configuration in database.
 * Client secret is only updated if a non-empty value is provided.
 *
 * @param array $data Validated data (config_id, merchant_id, client_id, client_secret, environment).
 * @return bool True on success.
 */
function payu_update_currency_config_in_db( $data ) {
	global $wpdb;
	$table_name = payu_get_currency_configs_table_name();
	$update     = array(
		'merchant_id' => $data['merchant_id'],
		'client_id'   => $data['client_id'],
		'environment' => $data['environment'],
		'updated_at'  => current_time( 'mysql' ),
	);
	$format = array( '%s', '%s', '%s', '%s' );
	if ( ! empty( $data['client_secret'] ) ) {
		$encrypted = payu_encrypt_client_secret( $data['client_secret'] );
		if ( false !== $encrypted ) {
			$update['client_secret'] = $encrypted;
			$format[]                = '%s';
		}
	}
	$result = $wpdb->update(
		$table_name,
		$update,
		array( 'id' => $data['config_id'] ),
		$format,
		array( '%d' )
	);
	return false !== $result;
}

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
	} elseif ( strlen( $client_secret ) > 500 ) {
		$field_errors['payu_config_client_secret'] = __( 'Client Secret must not exceed 500 characters.', 'payu-payment-links' );
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
 * Used for both add (exclude_id = 0) and edit (exclude_id = config being updated).
 *
 * @param string $currency    Currency code.
 * @param string $merchant_id Merchant ID.
 * @param string $environment Environment (uat|prod).
 * @param int    $exclude_id  Optional. Config ID to exclude from check (for edits). Default 0 for add.
 * @return bool True if unique, false if another record exists.
 */
function payu_is_currency_environment_unique( $currency, $merchant_id, $environment, $exclude_id = 0 ) {
	global $wpdb;
	$table_name = payu_get_currency_configs_table_name();
	$exclude_id = absint( $exclude_id );
	$count      = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE currency = %s AND merchant_id = %s AND environment = %s AND id != %d AND deleted_at IS NULL",
			$currency,
			$merchant_id,
			$environment,
			$exclude_id
		)
	);
	return (int) $count === 0;
}

/**
 * Save currency configuration to database
 *
 * @param array $data Sanitized configuration data.
 * @return int|false Insert ID on success, false on failure.
 */
function payu_save_currency_config_to_db( $data ) {
	global $wpdb;

	$table_name = payu_get_currency_configs_table_name();

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
function payu_verify_api_credentials( $merchant_id, $client_id, $client_secret, $environment, $currency ) {
	// Payment Links API uses accounts.payu.in (not api.payu.in)
	$api_base_url = ( 'prod' === $environment ) 
		? 'https://accounts.payu.in' 
		: 'https://uat-accounts.payu.in';

	$auth_url = $api_base_url . '/oauth/token';

	$auth_data = array(
		'grant_type'   => 'client_credentials',
		'client_id'    => $client_id,
		'client_secret' => $client_secret,
		'scope'        => 'create_payment_links',
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
			$table_name = payu_get_currency_configs_table_name();

			$existing_merchant = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT currency FROM {$table_name}
					WHERE merchant_id = %s AND currency != %s
					AND deleted_at IS NULL
					LIMIT 1",
					sanitize_text_field( $merchant_id ),
					sanitize_text_field( $currency )
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
		$sanitized_data['environment'],
		$sanitized_data['currency']
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
	$table_name = payu_get_currency_configs_table_name();

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
