<?php
defined( 'ABSPATH' ) || exit;

/**
 * Payment Links Repository – Chunked data fetching for listing.
 *
 * Never fetches all records at once. Uses LIMIT + OFFSET and SQL_CALC_FOUND_ROWS
 * for server-side pagination. Database is the single source of truth.
 *
 * @package PayU_Payment_Links
 */
class Payment_Links_Repository {

	private $wpdb;
	private $table;

	/** Maximum records per page (chunk size). Never exceed. */
	const MAX_PER_PAGE = 100;

	/** Columns needed for list table + row actions (avoids SELECT *). */
	const LIST_COLUMNS = 'id, order_id, config_id, payu_invoice_number, payment_link_url, currency, amount, status, payment_link_status, utr_number, environment, expiry_date, updated_at, customerEmail, customerPhone';

	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'payu_payment_links';
	}

	/**
	 * Fetch payment links in a single chunk (server-side pagination).
	 *
	 * Contract:
	 * - Accepts: [ 'filters' => [], 'limit' => int, 'offset' => int ]
	 * - Returns: [ 'data' => array, 'total' => int ]
	 *
	 * Filters are applied before pagination. Uses indexed columns (created_at, status, order_id, environment).
	 *
	 * @param array $args 'filters' (date_from, date_to, status, currency, order_id, environment), 'limit' (max 100), 'offset'.
	 * @return array{ data: array, total: int }
	 */
	public function get_links( array $args ) {
		$defaults = [
			'filters' => [],
			'limit'   => 20,
			'offset'  => 0,
		];

		$args    = wp_parse_args( $args, $defaults );
		$filters = $args['filters'];

		$limit  = absint( $args['limit'] );
		$offset = absint( $args['offset'] );
		if ( $limit > self::MAX_PER_PAGE ) {
			$limit = self::MAX_PER_PAGE;
		}

		$where  = [ '( is_deleted = 0 OR is_deleted IS NULL )' ];
		$params = [];

		// Mandatory date range (uses index created_at)
		if ( ! empty( $filters['date_from'] ) && ! empty( $filters['date_to'] ) ) {
			$from = $filters['date_from'] . ' 00:00:00';
			$to   = $filters['date_to'] . ' 23:59:59';
			$where[]  = 'created_at BETWEEN %s AND %s';
			$params[] = $from;
			$params[] = $to;
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		if ( ! empty( $filters['currency'] ) ) {
			$where[]  = 'currency = %s';
			$params[] = $filters['currency'];
		}

		if ( ! empty( $filters['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = absint( $filters['order_id'] );
		}

		if ( ! empty( $filters['environment'] ) ) {
			$where[]  = 'environment = %s';
			$params[] = $filters['environment'];
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = "
			SELECT SQL_CALC_FOUND_ROWS " . self::LIST_COLUMNS . "
			FROM {$this->table}
			{$where_sql}
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d
		";

		$params[] = $limit;
		$params[] = $offset;

		$data = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $params ),
			OBJECT
		);

		$total = (int) $this->wpdb->get_var( 'SELECT FOUND_ROWS()' );

		return [
			'data'  => is_array( $data ) ? $data : [],
			'total' => $total,
		];
	}

	/**
	 * Chunked fetch for CSV export. No PayU API; database only.
	 * Same filters as get_links(); date range is required by caller.
	 *
	 * @param array $args 'filters' => array (date_from, date_to, status, currency, order_id, environment), 'limit' (500-1000), 'offset'.
	 * @return array{ data: array }
	 */
	public function get_links_for_export( array $args ) {
		$defaults = [
			'filters' => [],
			'limit'   => 500,
			'offset'  => 0,
		];
		$args    = wp_parse_args( $args, $defaults );
		$filters = $args['filters'];
		$limit   = absint( $args['limit'] );
		$offset  = absint( $args['offset'] );
		if ( $limit < 1 ) {
			$limit = 500;
		}
		if ( $limit > 1000 ) {
			$limit = 1000;
		}

		$where  = [ '( is_deleted = 0 OR is_deleted IS NULL )' ];
		$params = [];

		if ( ! empty( $filters['date_from'] ) && ! empty( $filters['date_to'] ) ) {
			$from   = $filters['date_from'] . ' 00:00:00';
			$to     = $filters['date_to'] . ' 23:59:59';
			$where[]  = 'created_at BETWEEN %s AND %s';
			$params[] = $from;
			$params[] = $to;
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['currency'] ) ) {
			$where[]  = 'currency = %s';
			$params[] = $filters['currency'];
		}
		if ( ! empty( $filters['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = absint( $filters['order_id'] );
		}
		if ( ! empty( $filters['environment'] ) ) {
			$where[]  = 'environment = %s';
			$params[] = $filters['environment'];
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$cols      = 'payu_invoice_number, order_id, payment_link_url, amount, currency, payment_link_status, status, utr_number, paid_amount, environment, expiry_date, created_at';

		$sql = "SELECT {$cols} FROM {$this->table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$data = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $params ),
			OBJECT
		);

		return [
			'data' => is_array( $data ) ? $data : [],
		];
	}

	/**
	 * Get a single payment link by ID.
	 *
	 * @param int $id Payment link row ID.
	 * @return object|null Row object or null.
	 */
	public function get_link_by_id( $id ) {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND ( is_deleted = 0 OR is_deleted IS NULL ) LIMIT 1",
				$id
			),
			OBJECT
		);
	}
}