<?php
defined( 'ABSPATH' ) || exit;

class Payment_Links_Repository {

	private $wpdb;
	private $table;

	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'payu_payment_links';
	}

	/**
	 * Fetch payment links with filters & pagination
	 */
	public function get_links( array $args ) {
		$defaults = [
			'filters' => [],
			'limit'   => 20,
			'offset'  => 0,
		];

		$args    = wp_parse_args( $args, $defaults );
		$filters = $args['filters'];

		$where  = [ 'is_deleted = 0' ];
		$params = [];

		// Mandatory date range
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
			SELECT SQL_CALC_FOUND_ROWS *
			FROM {$this->table}
			{$where_sql}
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d
		";
        
		$params[] = absint( $args['limit'] );
		$params[] = absint( $args['offset'] );

		$data = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $params )
            
		);

		$total = (int) $this->wpdb->get_var( 'SELECT FOUND_ROWS()' );

		return [
			'data'  => $data,
			'total' => $total,
		];
	}
}