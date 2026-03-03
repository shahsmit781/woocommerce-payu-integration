<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/repository/class-payment-links-repository.php';
require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/class-payu-status-labels.php';

class PayU_Payment_Links_List_Table extends WP_List_Table {

	private $repository;

	public function __construct() {
		parent::__construct( [
			'singular' => 'payment_link',
			'plural'   => 'payment_links',
			'ajax'     => false,
		] );

		$this->repository = new Payment_Links_Repository();
	}

	public function get_columns() {
        return [
            'payu_invoice_number' => __( 'Invoice No', 'payu-payment-links' ),
            'order_id'           => __( 'Order', 'payu-payment-links' ),
            'payment_link_url'   => __( 'Payment Link', 'payu-payment-links' ),
            'amount'             => __( 'Amount', 'payu-payment-links' ),
            'payment_link_status'=> __( 'Payment link status', 'payu-payment-links' ),
            'status'             => __( 'Payment Status', 'payu-payment-links' ),
            'utr_number'         => __( 'UTR Number', 'payu-payment-links' ),
            'environment'        => __( 'Environment', 'payu-payment-links' ),
            'expiry_date'        => __( 'Expiry', 'payu-payment-links' ),
            'actions'            => __( 'Actions', 'payu-payment-links' ),
        ];
    }

	protected function column_order_id( $item ) {
		if ( empty( $item->order_id ) ) {
			return '—';
		}

		$url = function_exists( 'payu_get_order_edit_url' ) ? payu_get_order_edit_url( (int) $item->order_id ) : admin_url( 'post.php?post=' . absint( $item->order_id ) . '&action=edit' );

		return sprintf(
			'<a href="%s">#%d</a>',
			esc_url( $url ),
			absint( $item->order_id )
		);
	}

    protected function column_payment_link_url( $item ) {

        if ( empty( $item->payment_link_url ) ) {
            return '—';
        }

        $short = preg_replace( '#^https?://#', '', $item->payment_link_url );
        $short = strlen( $short ) > 40 ? substr( $short, 0, 40 ) . '…' : $short;

        ob_start();
        ?>
        <div class="payu-payment-link">
            <a href="<?php echo esc_url( $item->payment_link_url ); ?>"
            target="_blank"
            class="payu-link-text">
                <?php echo esc_html( $short ); ?>
            </a>

            <button
                type="button"
                class="payu-copy-btn"
                data-url="<?php echo esc_attr( $item->payment_link_url ); ?>"
                aria-label="<?php esc_attr_e( 'Copy payment link', 'payu-payment-links' ); ?>">
                <span class="dashicons dashicons-admin-links"></span>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

	protected function column_amount( $item ) {
		$currency = isset( $item->currency ) ? $item->currency : '';
		$amount   = isset( $item->amount ) ? $item->amount : '';
		return esc_html( $currency . ( $currency && $amount !== '' ? ' ' : '' ) . $amount );
	}

	protected function column_environment( $item ) {
		$env = isset( $item->environment ) ? trim( (string) $item->environment ) : '';
		return esc_html( function_exists( 'payu_payment_links_environment_label' ) ? payu_payment_links_environment_label( $env ) : ( $env !== '' ? strtoupper( $env ) : '—' ) );
	}

	/**
	 * Payment link status column: link lifecycle (active, expired, deactivated) – display label.
	 *
	 * @param object $item Payment link row.
	 * @return string
	 */
	protected function column_payment_link_status( $item ) {
		$link_status = isset( $item->payment_link_status ) ? trim( (string) $item->payment_link_status ) : '';
		return esc_html( function_exists( 'payu_payment_links_link_status_label' ) ? payu_payment_links_link_status_label( $link_status ) : ( $link_status !== '' ? $link_status : '—' ) );
	}

	/**
	 * Payment Status column: display label (Pending, Partial paid, Paid).
	 *
	 * @param object $item Payment link row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = isset( $item->status ) ? trim( (string) $item->status ) : '';
		return esc_html( function_exists( 'payu_payment_links_payment_status_label' ) ? payu_payment_links_payment_status_label( $status ) : ( $status !== '' ? $status : '—' ) );
	}

	/**
	 * UTR Number column: show value or — when not set.
	 *
	 * @param object $item Payment link row.
	 * @return string
	 */
	protected function column_utr_number( $item ) {
		$utr = isset( $item->utr_number ) ? trim( (string) $item->utr_number ) : '';
		return esc_html( $utr !== '' ? $utr : '—' );
	}

	/**
	 * Expiry column: formatted date and edit icon only when Payment link status is expired AND Payment status is PENDING.
	 *
	 * @param object $item Payment link row.
	 * @return string
	 */
	protected function column_expiry_date( $item ) {
		$expiry = isset( $item->expiry_date ) ? trim( (string) $item->expiry_date ) : '';
		$payment_link_status = isset( $item->payment_link_status ) ? trim( strtolower( (string) $item->payment_link_status ) ) : '';
		$payment_status     = isset( $item->status ) ? trim( (string) $item->status ) : '';

		$display = $expiry ? gmdate( 'Y-m-d H:i', strtotime( $expiry ) ) : '—';
		$html    = '<span class="payu-expiry-display">' . esc_html( $display ) . '</span>';
        
		if ( $payment_link_status != 'expired' && ($payment_status === 'PENDING' || $payment_status === 'PARTIALLY_PAID') ) {
			$expiry_attr = $expiry ? gmdate( 'Y-m-d\TH:i', strtotime( $expiry ) ) : '';
			$invoice     = isset( $item->payu_invoice_number ) ? $item->payu_invoice_number : '';
			$link_id     = isset( $item->id ) ? (int) $item->id : 0;
			$html       .= ' <button type="button" class="payu-expiry-edit-btn payu-expire-btn button-link" '
				. 'data-link-id="' . esc_attr( (string) $link_id ) . '" '
				. 'data-invoice="' . esc_attr( $invoice ) . '" '
				. 'data-expiry-date="' . esc_attr( $expiry_attr ) . '" '
				. 'title="' . esc_attr__( 'Edit expiry', 'payu-payment-links' ) . '" '
				. 'aria-label="' . esc_attr__( 'Edit expiry date', 'payu-payment-links' ) . '">'
				. '<span class="dashicons dashicons-edit"></span></button>';
		}

		return $html;
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( $item->$column_name ?? '' );
	}

    protected function column_actions( $item ) {

        $actions = [
            [
                'title' => __( 'View', 'payu-payment-links' ),
                'icon'  => 'dashicons-visibility',
                'class' => 'view',
                'url'   => ! empty( $item->payment_link_url ) ? $item->payment_link_url : '#',
                'target' => '_blank',
                'rel'   => 'noopener noreferrer',
            ],
            [
                'title' => __( 'Resend', 'payu-payment-links' ),
                'icon'  => 'dashicons-email',
                'class' => 'resend payu-action payu-resend-btn',
                'data'  => [
                    'action'  => 'resend',
                    'id'      => $item->id,
                    'invoice' => isset( $item->payu_invoice_number ) ? $item->payu_invoice_number : '',
                    'email'   => isset( $item->customerEmail ) ? $item->customerEmail : '',
                    'phone'   => isset( $item->customerPhone ) ? $item->customerPhone : '',
                ],
            ],
            [
                'title' => __( 'Refresh', 'payu-payment-links' ),
                'icon'  => 'dashicons-update',
                'class' => 'refresh payu-action payu-refresh-btn',
                'data'  => [ 'action' => 'refresh', 'id' => $item->id ],
            ],
        ];

        ob_start();
        ?>
        <div class="payu-actions">
            <?php foreach ( $actions as $action ) : ?>
                <a
                    href="<?php echo esc_url( $action['url'] ?? '#' ); ?>"
                    class="payu-action-btn <?php echo esc_attr( $action['class'] ); ?>"
                    title="<?php echo esc_attr( $action['title'] ); ?>"
                    <?php
                    if ( ! empty( $action['target'] ) ) {
                        echo ' target="' . esc_attr( $action['target'] ) . '"';
                    }
                    if ( ! empty( $action['rel'] ) ) {
                        echo ' rel="' . esc_attr( $action['rel'] ) . '"';
                    }
                    if ( ! empty( $action['data'] ) ) {
                        foreach ( $action['data'] as $k => $v ) {
                            echo ' data-' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
                        }
                    }
                    ?>
                >
                    <span class="dashicons <?php echo esc_attr( $action['icon'] ); ?>"></span>
                    <span class="label"><?php echo esc_html( $action['title'] ); ?></span>
                </a>
            <?php endforeach; ?>

            <button type="button" class="payu-actions-toggle">
                <span class="dashicons dashicons-ellipsis"></span>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

	public function prepare_items() {

		$today = current_time( 'Y-m-d' );

		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : $today;
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : $today;

		$default_per_page = 10;
		$max_per_page     = 100;
		$user_per_page    = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 0;
		$per_page         = $user_per_page > 0 ? min( $user_per_page, $max_per_page ) : $default_per_page;

		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Preserve all filters for pagination (date range, status, currency, order_id, environment).
		$filters = [
			'date_from'    => $date_from,
			'date_to'      => $date_to,
			'status'       => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
			'currency'     => isset( $_GET['currency'] ) ? sanitize_text_field( wp_unslash( $_GET['currency'] ) ) : '',
			'order_id'     => isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0,
			'environment'  => isset( $_GET['environment'] ) ? sanitize_text_field( wp_unslash( $_GET['environment'] ) ) : '',
		];

		$result = $this->repository->get_links( [
			'filters' => $filters,
			'limit'   => $per_page,
			'offset'  => $offset,
		] );

		$this->items = $result['data'];

		// Base URL for pagination links: preserve filters so changing page keeps current filters.
		$base = remove_query_arg( 'paged' );
		$base = add_query_arg( 'paged', '%#%', $base );

		$this->set_pagination_args( [
			'total_items' => $result['total'],
			'per_page'    => $per_page,
			'total_pages' => $per_page > 0 ? ceil( $result['total'] / $per_page ) : 0,
			'base'        => $base,
		] );

		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}

	public function no_items() {
		esc_html_e( 'No payment links found.', 'payu-payment-links' );
	}
}