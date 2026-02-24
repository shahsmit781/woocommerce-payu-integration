<?php
/**
 * PayU Payment Link Status – Custom public page for success/failure redirects
 *
 * Registers /payment-link/status (query param: invoice). Shows amount paid, remaining, payment status.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Payment_Link_Status_Page
 */
class PayU_Payment_Link_Status_Page {

	const QUERY_VAR = 'payu_payment_link_status';
	const REWRITE_PATH = 'payment-link/status';

	/**
	 * Constructor. Registers rewrite, query var, and template redirect.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rule' ), 10 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ), 10, 1 );
		add_action( 'template_redirect', array( $this, 'maybe_render_status_page' ), 5 );
	}

	/**
	 * Register rewrite rule for /payment-link/status.
	 * WordPress strips the home path before matching, so the pattern is always relative (no subdir in pattern).
	 */
	public function register_rewrite_rule() {
		add_rewrite_rule(
			'^payment-link/status/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Expose query var for the status page.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * If current request is the status page, render template and exit.
	 */
	public function maybe_render_status_page() {
		$is_status_page = get_query_var( self::QUERY_VAR );
		if ( ! $is_status_page && $this->is_status_page_request() ) {
			$is_status_page = true;
		}
		if ( ! $is_status_page ) {
			return;
		}
		$invoice = isset( $_GET['invoice'] ) ? sanitize_text_field( wp_unslash( $_GET['invoice'] ) ) : '';
		$this->render_status_page( $invoice );
		exit;
	}

	/**
	 * Check if the current request path is /payment-link/status (after stripping home path and query string).
	 * Ensures the page works when rewrite rules were not flushed or permalinks are plain.
	 *
	 * @return bool
	 */
	private function is_status_page_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$req_uri = trim( (string) $_SERVER['REQUEST_URI'], '/' );
		list( $req_uri ) = explode( '?', $req_uri );
		$req_uri = trim( $req_uri, '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( $home_path !== '' ) {
			$home_path_regex = '#^' . preg_quote( $home_path, '#' ) . '/#';
			$req_uri = preg_replace( $home_path_regex, '', $req_uri );
			$req_uri = trim( $req_uri, '/' );
		}
		return $req_uri === self::REWRITE_PATH || $req_uri === self::REWRITE_PATH . '/';
	}

	/**
	 * Output interactive status page. Always show loader until response: JS fetches status via AJAX, then displays result or error.
	 * When invoice is missing, show error immediately (no fetch). Try again uses AJAX.
	 *
	 * @param string $invoice Invoice identifier from query param.
	 */
	private function render_status_page( $invoice ) {
		$invoice_safe   = sanitize_text_field( $invoice );
		$status_display = null;
		$status_error   = null;

		if ( $invoice_safe === '' ) {
			$status_error = array(
				'code'    => 'invalid_invoice',
				'message' => __( 'The invoice or link is invalid. Please check and try again.', 'payu-payment-links' ),
			);
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$ajax_action = class_exists( 'PayU_Payment_Link_Status_Ajax' ) ? PayU_Payment_Link_Status_Ajax::AJAX_ACTION : 'payu_fetch_payment_link_status';
		$ajax_url    = admin_url( 'admin-ajax.php' );

		$handle = 'payu-payment-link-status';
		$src    = PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'assets/js/payment-link-status.js';
		$url    = plugin_dir_url( PAYU_PAYMENT_LINKS_PLUGIN_FILE ) . 'assets/js/payment-link-status.js';
		if ( ! file_exists( $src ) ) {
			$url = '';
		}
		if ( $url ) {
			wp_enqueue_script( $handle, $url, array( 'jquery' ), PAYU_PAYMENT_LINKS_VERSION, true );
			wp_localize_script( $handle, 'payuStatusPage', array(
				'ajaxUrl'    => $ajax_url,
				'action'     => $ajax_action,
				'invoice'    => $invoice_safe,
				'preloaded'  => (bool) $status_display,
				'data'       => $status_display,
				'error'      => $status_error,
				'i18n'       => array(
					'fetching'       => __( 'Fetching payment status…', 'payu-payment-links' ),
					'error'          => __( 'Unable to fetch payment status. Please try again.', 'payu-payment-links' ),
					'errorNoLink'    => __( 'Payment link not found', 'payu-payment-links' ),
					'errorNoLinkMsg' => __( 'This link may be invalid or expired. Please check your link or contact the store.', 'payu-payment-links' ),
					'errorNoConfig'  => __( 'Payment setup incomplete', 'payu-payment-links' ),
					'errorNoConfigMsg' => __( 'The store has not completed PayU setup. Please contact the store.', 'payu-payment-links' ),
					'errorInvalid'   => __( 'Invalid link', 'payu-payment-links' ),
					'errorInvalidMsg'=> __( 'The invoice or link is invalid. Please check and try again.', 'payu-payment-links' ),
					'errorNoTransactions' => __( 'Payment details not available', 'payu-payment-links' ),
					'errorNoTransactionsMsg' => __( 'Payment details are not available yet. Please try again in a moment or contact support with your invoice number.', 'payu-payment-links' ),
					'errorGeneric'   => __( 'Something went wrong', 'payu-payment-links' ),
					'errorGenericMsg'=> __( 'We couldn’t load the payment status. Please try again or contact the store.', 'payu-payment-links' ),
					'errorVerifying' => __( "We're verifying your payment. Please refresh in a moment.", 'payu-payment-links' ),
					'paid'           => __( 'Full payment completed', 'payu-payment-links' ),
					'partial'        => __( 'Partial payment received', 'payu-payment-links' ),
					'failed'         => __( 'Payment failed', 'payu-payment-links' ),
					'active'         => __( 'Payment pending', 'payu-payment-links' ),
					'expired'        => __( 'Payment link expired', 'payu-payment-links' ),
					'invoice'        => __( 'Invoice ID', 'payu-payment-links' ),
					'orderRef'       => __( 'Order reference', 'payu-payment-links' ),
					'status'         => __( 'Payment status', 'payu-payment-links' ),
					'invoiceStatus'  => __( 'Invoice status', 'payu-payment-links' ),
					'txnStatus'      => __( 'Transaction status', 'payu-payment-links' ),
					'print'          => __( 'Print', 'payu-payment-links' ),
					'total'          => __( 'Total amount', 'payu-payment-links' ),
					'amountPaid'     => __( 'Amount paid', 'payu-payment-links' ),
					'remaining'      => __( 'Remaining', 'payu-payment-links' ),
					'transactionNo'  => __( 'Transaction No.', 'payu-payment-links' ),
					'transactionId'  => __( 'Transaction ID', 'payu-payment-links' ),
					'paymentTxns'    => __( 'Payment transactions', 'payu-payment-links' ),
					'date'           => __( 'Date', 'payu-payment-links' ),
					'dateTime'       => __( 'Date & time', 'payu-payment-links' ),
					'amount'         => __( 'Amount', 'payu-payment-links' ),
					'txnSuccess'     => __( 'Success', 'payu-payment-links' ),
					'txnPartiallyPaid' => __( 'Partially paid', 'payu-payment-links' ),
					'txnInProgress'  => __( 'In Progress', 'payu-payment-links' ),
					'txnFailed'      => __( 'Failed', 'payu-payment-links' ),
					'txnUserCancelled' => __( 'User Cancelled', 'payu-payment-links' ),
					'viewOrder'      => __( 'View order', 'payu-payment-links' ),
					'backToShop'     => __( 'Back to shop', 'payu-payment-links' ),
					'tryAgain'       => __( 'Try again', 'payu-payment-links' ),
				),
			) );
		}

		include PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/views/payment-link-status.php';
	}

	/**
	 * Full URL for the status page with invoice query param (for successURL / failureURL).
	 *
	 * @param string $invoice_number Invoice number (e.g. WC-119-20250212120000).
	 * @return string
	 */
	public static function get_status_url( $invoice_number ) {
		$path = '/' . self::REWRITE_PATH . '/';
		$url  = home_url( $path );
		$url  = add_query_arg( 'invoice', rawurlencode( $invoice_number ), $url );
		return $url;
	}

	/**
	 * Register rewrite rule (for use on plugin activation to flush).
	 */
	public static function register_rewrite_rule_for_activation() {
		add_rewrite_rule(
			'^payment-link/status/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}
}
