<?php
/**
 * PayU Database Schema – All plugin tables in one place
 *
 * Defines: wp_payu_currency_configs, wp_payu_api_tokens, wp_payu_payment_links, wp_payu_payment_transactions.
 * Use dbDelta on activation or when DB version is behind.
 *
 * Terminology:
 * - Payment Status (money outcome): PENDING, PAID, PARTIALLY_PAID. Whether and how much was paid.
 * - Payment Link Status (link lifecycle): active, expired, deactivated. PayU link state, independent of payment result.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the table name for PayU currency configs (with current prefix).
 *
 * @return string
 */
function payu_get_currency_configs_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'payu_currency_configs';
}

/**
 * Get the SQL definition for the PayU currency configs table.
 * dbDelta-compatible.
 *
 * @return string Full CREATE TABLE statement (no charset; caller appends).
 */
function payu_get_currency_configs_schema_sql() {
	$table_name = payu_get_currency_configs_table_name();
	return "CREATE TABLE $table_name (
	id bigint(20) NOT NULL AUTO_INCREMENT,
	currency varchar(10) NOT NULL,
	merchant_id varchar(100) NOT NULL,
	client_id varchar(255) NOT NULL,
	client_secret text NOT NULL,
	environment enum('uat','prod') DEFAULT 'uat',
	status enum('active','invalid','inactive') DEFAULT 'active',
	deleted_at datetime DEFAULT NULL,
	created_at datetime DEFAULT CURRENT_TIMESTAMP,
	updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY  (id)
)";
}

/**
 * Create or update the PayU currency configs table (dbDelta).
 *
 * @return void
 */
function payu_create_currency_configs_table() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$sql = payu_get_currency_configs_schema_sql() . " $charset_collate;";
	dbDelta( $sql );
}

/**
 * Normalize a scope string: sort space-separated scopes alphabetically.
 * Used to ensure "create_payment_links read_payment_links" and "read_payment_links create_payment_links" map to the same value.
 *
 * @param string $scope Space-separated scope string (e.g. from PayU token response).
 * @return string Normalized scope string (alphabetically sorted, trimmed).
 */
function payu_normalize_scope_string( $scope ) {
	$scope = sanitize_text_field( $scope );
	if ( '' === $scope ) {
		return '';
	}
	$parts = array_map( 'trim', explode( ' ', $scope ) );
	$parts = array_filter( $parts );
	sort( $parts, SORT_STRING );
	return implode( ' ', $parts );
}

/**
 * Compute SHA-256 hash of the normalized scope string for use as scope_hash.
 *
 * @param string $scope Normalized scope string (use payu_normalize_scope_string first).
 * @return string 64-character hex hash.
 */
function payu_scope_hash( $scope ) {
	$scope = is_string( $scope ) ? $scope : '';
	if ( '' === $scope ) {
		return '';
	}
	return hash( 'sha256', $scope );
}

/**
 * Return the table name for PayU API tokens (with current prefix).
 *
 * @return string
 */
function payu_get_api_tokens_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'payu_api_tokens';
}

/**
 * Get the SQL definition for the PayU API tokens table.
 * Uses dbDelta-compatible formatting (two spaces before PRIMARY KEY, etc.).
 *
 * @return string Full CREATE TABLE statement (no charset; caller appends).
 */
function payu_get_api_tokens_schema_sql() {
	$table_name = payu_get_api_tokens_table_name();
	return "CREATE TABLE $table_name (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	merchant_id varchar(100) NOT NULL,
	environment enum('uat','production') NOT NULL DEFAULT 'uat',
	scope varchar(255) NOT NULL,
	scope_hash char(64) NOT NULL,
	access_token text NOT NULL,
	expires_at datetime NOT NULL,
	status enum('active','expired','revoked') NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY  (id),
	UNIQUE KEY uk_merchant_env_scope (merchant_id, environment, scope_hash),
	KEY ix_expires_at (expires_at),
	KEY ix_status (status)
)";
}

/**
 * Create or update the PayU API tokens table (dbDelta).
 *
 * @return void
 */
function payu_create_api_tokens_table() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$sql = payu_get_api_tokens_schema_sql() . " $charset_collate;";
	dbDelta( $sql );
}

/**
 * Return the table name for PayU payment links (with current prefix).
 *
 * @return string
 */
function payu_get_payment_links_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'payu_payment_links';
}

/**
 * Get the SQL definition for the PayU payment links table.
 *
 * Column notes:
 * - status: Payment outcome only. Allowed: PENDING, PAID, PARTIALLY_PAID. Not link lifecycle.
 * - payment_link_status: PayU link lifecycle only. Allowed: active, expired, deactivated. Independent of payment result.
 * - created_at / updated_at: Set explicitly in PHP via current_time( 'mysql' ) so both use WordPress timezone. No ON UPDATE CURRENT_TIMESTAMP.
 *
 * @return string Full CREATE TABLE statement (no charset; caller appends).
 */
function payu_get_payment_links_schema_sql() {
	$table_name = payu_get_payment_links_table_name();
	return "CREATE TABLE $table_name (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	order_id bigint(20) unsigned NOT NULL,
	config_id bigint(20) unsigned DEFAULT NULL,
	payu_invoice_number varchar(100) NOT NULL,
	payment_link_url varchar(255) NOT NULL,
	currency varchar(10) NOT NULL,
	amount decimal(18,2) NOT NULL DEFAULT 0.00,
	paid_amount decimal(18,2) NOT NULL DEFAULT 0.00,
	remaining_amount decimal(18,2) NOT NULL DEFAULT 0.00,
	status varchar(20) NOT NULL DEFAULT 'PENDING',
	payment_link_status varchar(20) DEFAULT NULL,
	expiry_date datetime DEFAULT NULL,
	isPartialPaymentAllowed tinyint(1) NOT NULL DEFAULT 0,
	min_initial_payment decimal(18,2) DEFAULT NULL,
	max_instalments int(11) DEFAULT NULL,
	utr_number varchar(100) DEFAULT NULL,
	mid varchar(100) DEFAULT NULL,
	environment varchar(20) NOT NULL DEFAULT 'uat',
	customerName varchar(255) DEFAULT NULL,
	customerPhone varchar(50) DEFAULT NULL,
	customerEmail varchar(255) DEFAULT NULL,
	is_email_sent tinyint(1) NOT NULL DEFAULT 0,
	is_sms_sent tinyint(1) NOT NULL DEFAULT 0,
	emailStatus text DEFAULT NULL,
	smsStatus text DEFAULT NULL,
	udf1 varchar(100) DEFAULT NULL,
	udf5 varchar(50) DEFAULT NULL,
	transaction_summary text DEFAULT NULL,
	payu_api_response_json longtext DEFAULT NULL,
	created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	is_deleted tinyint(1) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY payu_invoice_number (payu_invoice_number),
	KEY order_id (order_id),
	KEY status (status),
	KEY payment_link_status (payment_link_status),
	KEY currency (currency),
	KEY environment (environment),
	KEY created_at (created_at),
	KEY config_id (config_id),
	KEY utr_number (utr_number)
)";
}

/**
 * Create or update the PayU payment links table (dbDelta).
 * Migration: adds payment_link_status if missing; status default is PENDING (canonical payment outcome).
 *
 * @return void
 */
function payu_create_payment_links_table() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$sql = payu_get_payment_links_schema_sql() . " $charset_collate;";
	dbDelta( $sql );
}

/**
 * Return the table name for PayU payment transactions (with current prefix).
 *
 * @return string
 */
function payu_get_payment_transactions_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'payu_payment_transactions';
}

/**
 * Get the SQL definition for the PayU payment transactions table.
 * dbDelta-compatible.
 *
 * Column notes:
 * - merchantReferenceId: Merchant-side reference (e.g. invoice number / order reference). Used for reconciliation and idempotency.
 * - status: Transaction-level payment outcome. Aligns with canonical statuses: PENDING, PAID, PARTIALLY_PAID, FAILED.
 *
 * @return string Full CREATE TABLE statement (no charset; caller appends).
 */
function payu_get_payment_transactions_schema_sql() {
	$table_name = payu_get_payment_transactions_table_name();
	return "CREATE TABLE $table_name (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	payment_link_id bigint(20) unsigned NOT NULL,
	transaction_id varchar(100) DEFAULT NULL,
	merchantReferenceId varchar(100) DEFAULT NULL,
	invoice_number varchar(100) DEFAULT NULL,
	amount decimal(18,2) NOT NULL DEFAULT 0.00,
	payment_mode varchar(50) DEFAULT NULL,
	bankCode varchar(50) DEFAULT NULL,
	card_num varchar(50) DEFAULT NULL,
	bank_reference varchar(100) DEFAULT NULL,
	status varchar(20) NOT NULL DEFAULT 'PENDING',
	payer_name varchar(255) DEFAULT NULL,
	payer_phone varchar(50) DEFAULT NULL,
	payer_email varchar(255) DEFAULT NULL,
	webhook_payload_json longtext DEFAULT NULL,
	created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY  (id),
	UNIQUE KEY uk_transaction_id (transaction_id),
	KEY payment_link_id (payment_link_id),
	KEY merchantReferenceId (merchantReferenceId),
	KEY status (status)
)";
}

/**
 * Create or update the PayU payment transactions table (dbDelta).
 *
 * @return void
 */
function payu_create_payment_transactions_table() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$sql = payu_get_payment_transactions_schema_sql() . " $charset_collate;";
	dbDelta( $sql );
}
