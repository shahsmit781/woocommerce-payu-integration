<?php
/**
 * PayU API Tokens – Database Schema
 *
 * Production-ready schema for persisting PayU access tokens with scope awareness,
 * lifecycle tracking, and concurrency safety. Tokens are stored in a dedicated table
 * (not options, order meta, or user meta) for multi-admin, multi-currency safety.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

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
 * @return string Full CREATE TABLE statement (no charset; caller appends).
 */
function payu_get_payment_links_schema_sql() {
	$table_name = payu_get_payment_links_table_name();
	return "CREATE TABLE $table_name (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	order_id bigint(20) unsigned NOT NULL,
	invoice_number varchar(100) NOT NULL,
	payment_link_url text NOT NULL,
	amount decimal(18,4) NOT NULL,
	currency varchar(10) NOT NULL,
	environment varchar(20) NOT NULL,
	expiry_date datetime DEFAULT NULL,
	status varchar(20) NOT NULL DEFAULT 'active',
	raw_response longtext DEFAULT NULL,
	created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY  (id),
	KEY ix_order_id (order_id),
	KEY ix_invoice_number (invoice_number)
)";
}

/**
 * Create or update the PayU payment links table (dbDelta).
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
