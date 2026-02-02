<?php
/**
 * Plugin Name: PayU Payment Links
 * Plugin URI: https://example.com/payu-payment-links
 * Description: Generate PayU payment links via OneAPI for WooCommerce orders.
 * Version: 1.0.0
 * Author: Lucent Innovations
 * Author URI: https://example.com
 * Text Domain: payu-payment-links
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.3
 *
 * @package PayU_Payment_Links
 */

// Prevent direct access to this file
defined( 'ABSPATH' ) || exit;

// Define plugin constants
if ( ! defined( 'PAYU_PAYMENT_LINKS_VERSION' ) ) {
	define( 'PAYU_PAYMENT_LINKS_VERSION', '1.0.0' );
}

if ( ! defined( 'PAYU_PAYMENT_LINKS_PLUGIN_FILE' ) ) {
	define( 'PAYU_PAYMENT_LINKS_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'PAYU_PAYMENT_LINKS_PLUGIN_DIR' ) ) {
	define( 'PAYU_PAYMENT_LINKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'PAYU_PAYMENT_LINKS_MIN_PHP_VERSION' ) ) {
	define( 'PAYU_PAYMENT_LINKS_MIN_PHP_VERSION', '7.4' );
}

if ( ! defined( 'PAYU_PAYMENT_LINKS_MIN_WC_VERSION' ) ) {
	define( 'PAYU_PAYMENT_LINKS_MIN_WC_VERSION', '5.0' );
}

define('WP_DEBUG', true); 

/**
 * Check PHP version compatibility
 *
 * @return bool
 */
function payu_payment_links_check_php_version() {
	if ( version_compare( PHP_VERSION, PAYU_PAYMENT_LINKS_MIN_PHP_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'payu_payment_links_php_version_notice' );
		return false;
	}
	return true;
}

/**
 * Display PHP version incompatibility notice
 *
 * @return void
 */
function payu_payment_links_php_version_notice() {
	?>
	<div class="error">
		<p>
			<strong><?php esc_html_e( 'PayU Payment Links', 'payu-payment-links' ); ?></strong>: 
			<?php
			printf(
				/* translators: %s: Minimum PHP version */
				esc_html__( 'This plugin requires PHP version %s or higher. You are running PHP %s.', 'payu-payment-links' ),
				esc_html( PAYU_PAYMENT_LINKS_MIN_PHP_VERSION ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Check if WooCommerce is installed and active
 *
 * @return bool True if WooCommerce is active, false otherwise
 */
function payu_payment_links_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Check WooCommerce version compatibility
 *
 * @return bool
 */
function payu_payment_links_check_woocommerce_version() {
	if ( ! payu_payment_links_is_woocommerce_active() ) {
		return false;
	}

	if ( ! defined( 'WC_VERSION' ) ) {
		return false;
	}

	return version_compare( WC_VERSION, PAYU_PAYMENT_LINKS_MIN_WC_VERSION, '>=' );
}

/**
 * Display WooCommerce missing notice
 *
 * @return void
 */
function payu_payment_links_woocommerce_missing_notice() {
	?>
	<div class="error">
		<p>
			<strong><?php esc_html_e( 'PayU Payment Links', 'payu-payment-links' ); ?></strong>: 
			<?php esc_html_e( 'This plugin requires WooCommerce to be installed and active.', 'payu-payment-links' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Display WooCommerce version incompatibility notice
 *
 * @return void
 */
function payu_payment_links_woocommerce_version_notice() {
	?>
	<div class="error">
		<p>
			<strong><?php esc_html_e( 'PayU Payment Links', 'payu-payment-links' ); ?></strong>: 
			<?php
			printf(
				/* translators: %s: Minimum WooCommerce version */
				esc_html__( 'This plugin requires WooCommerce version %s or higher. You are running WooCommerce %s.', 'payu-payment-links' ),
				esc_html( PAYU_PAYMENT_LINKS_MIN_WC_VERSION ),
				defined( 'WC_VERSION' ) ? esc_html( WC_VERSION ) : esc_html__( 'unknown', 'payu-payment-links' )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Create database table for storing PayU currency configurations
 * This table stores merchant credentials per currency for multi-currency support
 *
 * @return void
 */
function payu_payment_links_create_db() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'payu_currency_configs';

	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	if ( $table_exists ) {
		// Table exists, use dbDelta to update if needed
		$charset_collate = $wpdb->get_charset_collate();

		/**
		 * dbDelta is very picky about SQL formatting:
		 */
		$sql = "CREATE TABLE $table_name (
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
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	} else {
		// Table doesn't exist, create it
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
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
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// Log database version for future migrations
	update_option( 'payu_payment_links_db_version', PAYU_PAYMENT_LINKS_VERSION );
}

/**
 * Load plugin text domain for translations
 *
 * @return void
 */
function payu_payment_links_load_textdomain() {
	load_plugin_textdomain(
		'payu-payment-links',
		false,
		dirname( plugin_basename( PAYU_PAYMENT_LINKS_PLUGIN_FILE ) ) . '/languages'
	);
}

/**
 * Load the payment gateway class
 * Only loads if all dependencies are met
 *
 * @return void
 */
function payu_payment_links_load_gateway() {
	// Check PHP version first
	if ( ! payu_payment_links_check_php_version() ) {
		return;
	}

	// Check if WooCommerce is active
	if ( ! payu_payment_links_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'payu_payment_links_woocommerce_missing_notice' );
		return;
	}

	// Check WooCommerce version
	if ( ! payu_payment_links_check_woocommerce_version() ) {
		add_action( 'admin_notices', 'payu_payment_links_woocommerce_version_notice' );
		return;
	}

	// Check if WC_Payment_Gateway class exists
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	// Load the payment gateway class file
	$gateway_file = PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-wc-gateway-payu-links.php';

	if ( file_exists( $gateway_file ) ) {
		require_once $gateway_file;
	} else {
		// Log error if file doesn't exist
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PayU Payment Links: Gateway class file not found at ' . $gateway_file );
		}
	}
}

/**
 * Add PayU Payment Links gateway to WooCommerce payment gateways list
 * This filter hook registers our custom gateway with WooCommerce
 *
 * @param array $methods Existing payment gateway classes.
 * @return array Modified array with our gateway added.
 */
function payu_payment_links_add_gateway( $methods ) {
	// Only add gateway if the class exists (safety check)
	if ( class_exists( 'WC_Gateway_Payu_Payment_Links' ) ) {
		$methods[] = 'WC_Gateway_Payu_Payment_Links';
	}
	return $methods;
}

/**
 * Declare compatibility with High-Performance Order Storage (HPOS)
 * This ensures the plugin works with WooCommerce's new order storage system
 *
 * @return void
 */
function payu_payment_links_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAYU_PAYMENT_LINKS_PLUGIN_FILE, true );
	}
}

// Register activation hook to create database table on plugin activation
register_activation_hook( PAYU_PAYMENT_LINKS_PLUGIN_FILE, 'payu_payment_links_create_db' );

// Declare HPOS compatibility (must be before WooCommerce init)
add_action( 'before_woocommerce_init', 'payu_payment_links_declare_hpos_compatibility' );

// Load text domain
add_action( 'plugins_loaded', 'payu_payment_links_load_textdomain' );

// Initialize the gateway after all plugins are loaded
// Priority 99 ensures WooCommerce is fully loaded before we initialize
add_action( 'plugins_loaded', 'payu_payment_links_load_gateway', 99 );

// Add gateway to WooCommerce payment gateways list
// Priority 100 ensures gateway class is loaded before this filter runs
add_filter( 'woocommerce_payment_gateways', 'payu_payment_links_add_gateway', 100 );
