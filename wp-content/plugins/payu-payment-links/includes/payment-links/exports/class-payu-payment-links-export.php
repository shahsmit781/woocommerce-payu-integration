<?php
/**
 * PayU Payment Links – CSV Export (streamed, chunked).
 *
 * Database only; no PayU API. Respects filters. Requires date range.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Payment_Links_Export
 */
class PayU_Payment_Links_Export {

	const EXPORT_CHUNK_SIZE = 500;
	const EXPORT_NONCE_ACTION = 'payu_export_payment_links_csv';

	/**
	 * Register hook to handle export before page render.
	 */
	public function __construct() {
		add_action( 'load-woocommerce_page_payu-payment-links', array( $this, 'maybe_stream_csv' ), 1 );
	}

	/**
	 * If export=csv and valid, stream CSV and exit. Otherwise do nothing.
	 */
	public function maybe_stream_csv() {
		if ( ! isset( $_GET['export'] ) || 'csv' !== sanitize_text_field( wp_unslash( $_GET['export'] ) ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::EXPORT_NONCE_ACTION ) ) {
			$this->redirect_with_notice( 'error', __( 'Security check failed. Please try again.', 'payu-payment-links' ) );
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->redirect_with_notice( 'error', __( 'You do not have permission to export payment links.', 'payu-payment-links' ) );
			return;
		}

		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';

		if ( '' === $date_from || '' === $date_to ) {
			$this->redirect_with_notice( 'error', __( 'Date range is required for export. Please set From and To dates and try again.', 'payu-payment-links' ) );
			return;
		}

		// Validate date format (Y-m-d).
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid date format. Use YYYY-MM-DD.', 'payu-payment-links' ) );
			return;
		}

		$filters = array(
			'date_from'   => $date_from,
			'date_to'     => $date_to,
			'status'      => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
			'currency'    => isset( $_GET['currency'] ) ? sanitize_text_field( wp_unslash( $_GET['currency'] ) ) : '',
			'order_id'    => isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0,
			'environment' => isset( $_GET['environment'] ) ? sanitize_text_field( wp_unslash( $_GET['environment'] ) ) : '',
		);

		$this->stream_csv( $filters );
		exit;
	}

	/**
	 * Redirect back to listing with an admin notice.
	 *
	 * @param string $type 'error' or 'success'.
	 * @param string $message Message text.
	 */
	private function redirect_with_notice( $type, $message ) {
		set_transient( 'payu_export_notice', array(
			'type'    => $type,
			'message' => $message,
		), 30 );

		$url = add_query_arg( array( 'page' => 'payu-payment-links' ), admin_url( 'admin.php' ) );
		$keep = array( 'date_from', 'date_to', 'status', 'currency', 'order_id', 'environment' );
		foreach ( $keep as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] ) {
				$val = 'order_id' === $key ? absint( $_GET[ $key ] ) : sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				if ( '' !== $val || 'order_id' !== $key ) {
					$url = add_query_arg( $key, $val, $url );
				}
			}
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Stream CSV to output. Uses chunked fetch; no full dataset in memory.
	 *
	 * @param array $filters Sanitized filters (date_from, date_to, status, currency, order_id, environment).
	 */
	private function stream_csv( array $filters ) {
		// Disable output buffering so stream flushes.
		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}
		while ( ob_get_level() ) {
			ob_end_flush();
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="payu-payment-links-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			return;
		}

		// UTF-8 BOM for Excel.
		fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

		$headers = array(
			__( 'PayU Invoice Number', 'payu-payment-links' ),
			__( 'WooCommerce Order ID', 'payu-payment-links' ),
			__( 'Payment Link URL', 'payu-payment-links' ),
			__( 'Amount', 'payu-payment-links' ),
			__( 'Currency', 'payu-payment-links' ),
			__( 'Payment link status', 'payu-payment-links' ),
			__( 'Payment Status', 'payu-payment-links' ),
			__( 'UTR Number', 'payu-payment-links' ),
			__( 'Paid Amount', 'payu-payment-links' ),
			__( 'Environment', 'payu-payment-links' ),
			__( 'Expiry Date', 'payu-payment-links' ),
			__( 'Created Date', 'payu-payment-links' ),
		);
		fputcsv( $output, $headers );

		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/repository/class-payment-links-repository.php';
		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/class-payu-status-labels.php';
		$repo   = new Payment_Links_Repository();
		$offset = 0;
		$chunk  = self::EXPORT_CHUNK_SIZE;

		do {
			$result = $repo->get_links_for_export( array(
				'filters' => $filters,
				'limit'   => $chunk,
				'offset'  => $offset,
			) );
			$rows   = isset( $result['data'] ) ? $result['data'] : array();

			foreach ( $rows as $row ) {
				$expiry   = isset( $row->expiry_date ) && $row->expiry_date ? gmdate( 'Y-m-d H:i', strtotime( $row->expiry_date ) ) : '';
				$created  = isset( $row->created_at ) && $row->created_at ? gmdate( 'Y-m-d H:i', strtotime( $row->created_at ) ) : '';
				$utr      = isset( $row->utr_number ) && trim( (string) $row->utr_number ) !== '' ? trim( (string) $row->utr_number ) : '-';
				$link_st  = isset( $row->payment_link_status ) ? trim( (string) $row->payment_link_status ) : '';
				$pay_st   = isset( $row->status ) ? trim( (string) $row->status ) : '';
				$env      = isset( $row->environment ) ? trim( (string) $row->environment ) : '';
				fputcsv( $output, array(
					isset( $row->payu_invoice_number ) ? $row->payu_invoice_number : '',
					isset( $row->order_id ) ? $row->order_id : '',
					isset( $row->payment_link_url ) ? $row->payment_link_url : '',
					isset( $row->amount ) ? $row->amount : '',
					isset( $row->currency ) ? $row->currency : '',
					function_exists( 'payu_payment_links_link_status_label' ) ? payu_payment_links_link_status_label( $link_st ) : $link_st,
					function_exists( 'payu_payment_links_payment_status_label' ) ? payu_payment_links_payment_status_label( $pay_st ) : $pay_st,
					$utr,
					isset( $row->paid_amount ) ? $row->paid_amount : '',
					function_exists( 'payu_payment_links_environment_label' ) ? payu_payment_links_environment_label( $env ) : $env,
					$expiry,
					$created,
				) );
			}

			if ( function_exists( 'flush' ) ) {
				flush();
			}
			$offset += $chunk;
		} while ( count( $rows ) === $chunk );

		fclose( $output );
	}

	/**
	 * Get nonce action name for URL generation.
	 *
	 * @return string
	 */
	public static function get_nonce_action() {
		return self::EXPORT_NONCE_ACTION;
	}
}
