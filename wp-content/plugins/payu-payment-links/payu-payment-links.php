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
 * Load schema file and create/update wp_payu_api_tokens, wp_payu_payment_links, wp_payu_payment_transactions.
 * Safe to call on every activation or when DB version is behind.
 *
 * @return void
 */
function payu_payment_links_run_table_schema() {
	$schema_file = dirname( PAYU_PAYMENT_LINKS_PLUGIN_FILE ) . '/includes/schema-payu-api-tokens.php';
	if ( ! is_readable( $schema_file ) ) {
		$schema_file = PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/schema-payu-api-tokens.php';
	}
	require_once $schema_file;
	payu_create_currency_configs_table();
	payu_create_api_tokens_table();
	payu_create_payment_links_table();
	payu_create_payment_transactions_table();
}

/**
 * Run on plugin activation: create/update all tables from schema, set DB version, flush rewrites.
 *
 * @return void
 */
function payu_payment_links_create_db() {
	payu_payment_links_run_table_schema();
	update_option( 'payu_payment_links_db_version', PAYU_PAYMENT_LINKS_VERSION );
	if ( file_exists( PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-payment-link-status-page.php' ) ) {
		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-payment-link-status-page.php';
		PayU_Payment_Link_Status_Page::register_rewrite_rule_for_activation();
		flush_rewrite_rules( true );
	}
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

	// Load template functions
	require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payu-template-functions.php';

	// Load form handler (must be loaded before registering AJAX hooks)
	require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payu-form-handler.php';

	// Schema and helpers for PayU API tokens table
	if ( file_exists( PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/schema-payu-api-tokens.php' ) ) {
		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/schema-payu-api-tokens.php';
	}
	// Token manager (DB-backed, scope-aware)
	if ( file_exists( PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-token-manager.php' ) ) {
		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-token-manager.php';
	}

	// Register AJAX handler for AJAX form submission
	add_action( 'wp_ajax_payu_save_currency_config', 'payu_ajax_save_currency_config' );

	// Register AJAX handler for edit configuration (client-side first, then server via AJAX)
	add_action( 'wp_ajax_payu_update_currency_config', 'payu_ajax_update_currency_config' );

	// Register AJAX handler for filtering configurations
	// Note: Function must be defined before this hook (loaded via require_once above)
	if ( function_exists( 'payu_ajax_filter_configs' ) ) {
		add_action( 'wp_ajax_payu_filter_configs', 'payu_ajax_filter_configs' );
	} else {
		// Log error if function not found
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PayU Payment Links: payu_ajax_filter_configs function not found!' );
		}
	}

	// Register AJAX handler for toggling configuration status
	if ( function_exists( 'payu_ajax_toggle_status' ) ) {
		add_action( 'wp_ajax_payu_toggle_status', 'payu_ajax_toggle_status' );
	} else {
		// Log error if function not found
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PayU Payment Links: payu_ajax_toggle_status function not found!' );
		}
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

	// Order admin: "Create Payment Link" meta box (links to dedicated page)
	$order_payment_link_file = PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-order-payment-link.php';
	if ( file_exists( $order_payment_link_file ) && class_exists( 'WC_Order' ) ) {
		require_once $order_payment_link_file;
		new PayU_Order_Payment_Link();
	}

	// Dedicated admin screen: Create Payment Link form (admin.php?page=payu-create-payment-link&order_id=…)
	$create_link_page_file = PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-create-payment-link-page.php';
	if ( file_exists( $create_link_page_file ) && class_exists( 'WC_Order' ) ) {
		require_once $create_link_page_file;
		new PayU_Create_Payment_Link_Page();
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

// Ensure PayU tables exist/are updated when DB version is behind (e.g. reactivation didn't run)
add_action( 'plugins_loaded', 'payu_payment_links_maybe_upgrade_db', 1 );

/**
 * If stored DB version is older than plugin version (or missing), run table schema and update option.
 * Ensures tables are created/updated even when activation hook doesn't run (e.g. bulk activate).
 */
function payu_payment_links_maybe_upgrade_db() {
	$saved = get_option( 'payu_payment_links_db_version', '' );
	if ( $saved === PAYU_PAYMENT_LINKS_VERSION ) {
		return;
	}
	payu_payment_links_run_table_schema();
	update_option( 'payu_payment_links_db_version', PAYU_PAYMENT_LINKS_VERSION );
}

// Payment Link Status page (public /payment-link/status) – load early so rewrite and URL helper are available
add_action( 'plugins_loaded', 'payu_payment_links_load_status_page', 5 );

/**
 * Load the Payment Link Status page class (rewrite rule + template).
 */
function payu_payment_links_load_status_page() {
	$file = PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-payment-link-status-page.php';
	if ( file_exists( $file ) ) {
		require_once $file;
		new PayU_Payment_Link_Status_Page();
	}
	$ajax_file = PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-payment-link-status-ajax.php';
	if ( file_exists( $ajax_file ) ) {
		require_once $ajax_file;
	}
}

// Initialize the gateway after all plugins are loaded
// Priority 99 ensures WooCommerce is fully loaded before we initialize
add_action( 'plugins_loaded', 'payu_payment_links_load_gateway', 99 );

// Add gateway to WooCommerce payment gateways list
// Priority 100 ensures gateway class is loaded before this filter runs
add_filter( 'woocommerce_payment_gateways', 'payu_payment_links_add_gateway', 100 );
