<?php
/**
 * PayU Configuration List Table
 *
 * Extends WP_List_Table to provide pagination, search, and filtering
 * for PayU currency configurations.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

// Ensure WP_List_Table is available
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * PayU Configuration List Table Class
 */
class PayU_Config_List_Table extends WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Configuration', 'payu-payment-links' ),
				'plural'   => __( 'Configurations', 'payu-payment-links' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get table columns
	 *
	 * @return array Column definitions
	 */
	public function get_columns() {
		$columns = array(
			'currency'    => __( 'Currency', 'payu-payment-links' ),
			'merchant_id' => __( 'Merchant ID', 'payu-payment-links' ),
			'client_id'   => __( 'Client ID', 'payu-payment-links' ),
			'environment' => __( 'Environment', 'payu-payment-links' ),
			'status'      => __( 'Status', 'payu-payment-links' ),
			'created_at'  => __( 'Created', 'payu-payment-links' ),
			'actions'     => __( 'Actions', 'payu-payment-links' ),
		);

		return $columns;
	}

	/**
	 * Get sortable columns
	 *
	 * @return array Sortable column definitions
	 */
	protected function get_sortable_columns() {
		$sortable_columns = array(
			'currency'    => array( 'currency', false ),
			'merchant_id' => array( 'merchant_id', false ),
			'environment' => array( 'environment', false ),
			'status'      => array( 'status', false ),
			'created_at'  => array( 'created_at', true ),
		);

		return $sortable_columns;
	}

	/**
	 * Get views for filtering - Disabled (status filter not needed)
	 *
	 * @return array Empty array
	 */
	protected function get_views() {
		// Status filter tabs removed as per user requirement
		return array();
	}

	/**
	 * Render search box - WordPress built-in search functionality
	 *
	 * @param string $text     Button text.
	 * @param string $input_id Input ID.
	 * @return void
	 */
	public function search_box( $text, $input_id ) {
		$input_id = $input_id . '-search-input';

		// Preserve current filters in hidden fields
		if ( ! empty( $_REQUEST['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="orderby" value="' . esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_REQUEST['order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="order" value="' . esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_REQUEST['environment_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="environment_filter" value="' . esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['environment_filter'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" class="payu-search-input" />
			<?php submit_button( $text, '', '', false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
	}

	/**
	 * Override display_tablenav to hide pagination on top and filter dropdown (already shown in custom header)
	 *
	 * @param string $which Position (top or bottom).
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			// Hide top tablenav completely - we show filter and search in custom header
			return;
		} else {
			// Show pagination on bottom
			parent::display_tablenav( $which );
		}
	}

	/**
	 * Override display method to include search box and filter in same row
	 *
	 * @return void
	 */
	public function display() {
		?>
		<div class="payu-table-header">
			<div class="payu-table-header-left">
				<?php
				// Display filter dropdown
				$this->extra_tablenav( 'top' );
				?>
			</div>
			<div class="payu-table-header-right">
				<?php
				// Display search box
				$this->search_box( __( 'Search Configurations', 'payu-payment-links' ), 'payu-config' );
				?>
			</div>
		</div>
		<?php
		// Call parent display method (which will call display_tablenav for bottom pagination only)
		parent::display();
	}

	/**
	 * Prepare items for display
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		// Set column headers (required for WP_List_Table) - must be set before early returns
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$table_name = $wpdb->prefix . 'payu_currency_configs';

		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			$this->items = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => 10,
					'total_pages' => 0,
				)
			);
			return;
		}

		// Get pagination parameters
		$per_page     = $this->get_items_per_page( 'payu_configs_per_page', 5 );
		$current_page = $this->get_pagenum();

		// Calculate offset
		$offset = ( $current_page - 1 ) * $per_page;

		// Get orderby and order
		$orderby = 'created_at';
		$order   = 'DESC';

		if ( ! empty( $_REQUEST['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$orderby_input = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sortable      = $this->get_sortable_columns();
			if ( isset( $sortable[ $orderby_input ] ) ) {
				$orderby = $orderby_input;
			}
		}

		if ( ! empty( $_REQUEST['order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_input = strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $order_input, array( 'ASC', 'DESC' ), true ) ) {
				$order = $order_input;
			}
		}

		// Sanitize orderby to prevent SQL injection
		$allowed_orderby = array( 'id', 'currency', 'merchant_id', 'client_id', 'environment', 'status' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'id';
		}

		// Sanitize order (already validated above, but ensure consistency)
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC'; // Match default
		}

		// Build WHERE clause parts
		$where_parts = array( "deleted_at IS NULL" );

		// Add search filter (search by currency)
		if ( ! empty( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search_term = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $search_term ) ) {
				$search_like = '%' . $wpdb->esc_like( $search_term ) . '%';
				$where_parts[] = $wpdb->prepare( 'currency LIKE %s', $search_like );
			}
		}

		// Add environment filter
		if ( ! empty( $_REQUEST['environment_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$environment_filter = sanitize_text_field( wp_unslash( $_REQUEST['environment_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $environment_filter, array( 'uat', 'prod' ), true ) ) {
				$where_parts[] = $wpdb->prepare( 'environment = %s', $environment_filter );
			}
		}

		// Build WHERE clause
		$where_clause = 'WHERE ' . implode( ' AND ', $where_parts );

		// Get total count for pagination
		$total_items = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_name} {$where_clause}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Get items with pagination
		// Note: $where_clause already contains prepared SQL, so we prepare only LIMIT/OFFSET
		$limit_offset = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		$items = $wpdb->get_results(
			"SELECT * FROM {$table_name} {$where_clause} ORDER BY `{$orderby}` {$order} {$limit_offset}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->items = $items ? $items : array();

		// Set pagination arguments
		$this->set_pagination_args(
			array(
				'total_items' => (int) $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}


	/**
	 * Render currency column
	 *
	 * @param object $item Configuration item.
	 * @return string
	 */
	protected function column_currency( $item ) {
		return '<strong>' . esc_html( $item->currency ) . '</strong>';
	}

	/**
	 * Render actions column (Edit, Delete with icons)
	 *
	 * @param object $item Configuration item.
	 * @return string
	 */
	protected function column_actions( $item ) {
		$config_id = absint( $item->id );
		$base_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' );

		$edit_url = add_query_arg( array( 'edit_payu_config' => $config_id ), $base_url );
		$delete_url = wp_nonce_url(
			add_query_arg( array( 'payu_delete_config' => $config_id ), $base_url ),
			'payu_delete_config_' . $config_id,
			'_wpnonce'
		);

		$edit_link = sprintf(
			'<a href="%1$s" class="payu-action-link payu-action-edit" title="%2$s" aria-label="%2$s">%3$s</a>',
			esc_url( $edit_url ),
			esc_attr__( 'Edit', 'payu-payment-links' ),
			'<span class="dashicons dashicons-edit" aria-hidden="true"></span>'
		);
		$delete_link = sprintf(
			'<a href="%1$s" class="payu-action-link payu-action-delete" title="%2$s" aria-label="%2$s" onclick="return confirm(%3$s);">%4$s</a>',
			esc_url( $delete_url ),
			esc_attr__( 'Delete', 'payu-payment-links' ),
			esc_attr( "'" . esc_js( __( 'Are you sure you want to delete this configuration?', 'payu-payment-links' ) ) . "'" ),
			'<span class="dashicons dashicons-trash" aria-hidden="true"></span>'
		);

		return '<span class="payu-row-actions">' . $edit_link . ' ' . $delete_link . '</span>';
	}

	/**
	 * Render merchant_id column
	 *
	 * @param object $item Configuration item.
	 * @return string
	 */
	protected function column_merchant_id( $item ) {
		return esc_html( $item->merchant_id );
	}

	/**
	 * Render client_id column
	 *
	 * @param object $item Configuration item.
	 * @return string
	 */
	protected function column_client_id( $item ) {
		return esc_html( $item->client_id );
	}

	/**
	 * Render environment column
	 *
	 * @param object $item Configuration item.
	 * @return string
	 */
	protected function column_environment( $item ) {
		return esc_html( strtoupper( $item->environment ) );
	}

	/**
	 * Render status column
	 *
	 * @param object $item Configuration item.
	 * @return string
	 */
	protected function column_status( $item ) {
		$is_active = ( 'active' === $item->status );
		$config_id = absint( $item->id );
		$currency = esc_attr( $item->currency );
		
		$toggle_class = 'payu-status-toggle';
		if ( $is_active ) {
			$toggle_class .= ' payu-status-toggle-active';
		}
		
		$toggle_id = 'payu-toggle-' . $config_id;
		
		$toggle_html = sprintf(
			'<label class="%s" for="%s" data-config-id="%d" data-currency="%s">
				<input type="checkbox" id="%s" class="payu-status-toggle-input" %s />
				<span class="payu-status-toggle-slider"></span>
			</label>',
			esc_attr( $toggle_class ),
			esc_attr( $toggle_id ),
			$config_id,
			$currency,
			esc_attr( $toggle_id ),
			checked( $is_active, true, false )
		);

		// Wrapper with loading spinner and message (shown when toggle is loading)
		return sprintf(
			'<div class="payu-status-cell">
				%s
				<span class="payu-status-loading-wrap" aria-live="polite">
					<span class="payu-status-spinner"></span>
				</span>
			</div>',
			$toggle_html
		);
	}

	/**
	 * Render default column
	 *
	 * @param object $item        Configuration item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '&mdash;';
	}

	/**
	 * Render extra table controls (filters)
	 *
	 * @param string $which Position (top or bottom).
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// Get environment filter from REQUEST (works for both GET and AJAX POST)
		$environment_filter = '';
		if ( isset( $_REQUEST['environment_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$environment_filter = sanitize_text_field( wp_unslash( $_REQUEST['environment_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<div class="alignleft actions">
			<label for="payu-filter-by-environment" class="screen-reader-text"><?php esc_html_e( 'Filter by environment', 'payu-payment-links' ); ?></label>
			<select name="environment_filter" id="payu-filter-by-environment" class="payu-ajax-filter">
				<option value=""><?php esc_html_e( 'All environments', 'payu-payment-links' ); ?></option>
				<option value="uat" <?php selected( $environment_filter, 'uat' ); ?>><?php esc_html_e( 'UAT', 'payu-payment-links' ); ?></option>
				<option value="prod" <?php selected( $environment_filter, 'prod' ); ?>><?php esc_html_e( 'Production', 'payu-payment-links' ); ?></option>
			</select>
		</div>
		<?php
	}

	/**
	 * Message to display when no items are found
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No currency configurations found.', 'payu-payment-links' );
	}
}
