<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/repository/class-payment-links-repository.php';

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
            'payment_link_url'       => __( 'Payment Link', 'payu-payment-links' ),
            'amount'             => __( 'Amount', 'payu-payment-links' ),
            'status'             => __( 'Status', 'payu-payment-links' ),
            'environment'        => __( 'Environment', 'payu-payment-links' ),
            'expiry_date'        => __( 'Expiry', 'payu-payment-links' ),
            'actions'            => __( 'Actions', 'payu-payment-links' ), 
        ];
    }

	protected function column_order_id( $item ) {
		if ( empty( $item->order_id ) ) {
			return '—';
		}

		return sprintf(
			'<a href="%s">#%d</a>',
			esc_url( admin_url( 'post.php?post=' . absint( $item->order_id ) . '&action=edit' ) ),
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
		return esc_html($item->currency . " " . $item->amount ?? '' );
	}

    protected function column_environment( $item ) {
        return esc_html(strtoupper($item->environment) ?? ''); 
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
                'url'   => admin_url( 'admin.php?page=payu-payment-link-view&id=' . $item->id ),
            ],
            [
                'title' => __( 'Resend', 'payu-payment-links' ),
                'icon'  => 'dashicons-email',
                'class' => 'resend payu-action',
                'data'  => [ 'action' => 'resend', 'id' => $item->id ],
            ],
            [
                'title' => __( 'Refresh', 'payu-payment-links' ),
                'icon'  => 'dashicons-update',
                'class' => 'refresh payu-action',
                'data'  => [ 'action' => 'refresh', 'id' => $item->id ],
            ],
            [
                'title' => __( 'Expire', 'payu-payment-links' ),
                'icon'  => 'dashicons-no',
                'class' => 'expire payu-action',
                'data'  => [ 'action' => 'expire', 'id' => $item->id ],
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

        $date_from = sanitize_text_field( $_GET['date_from'] ?? $today );
        $date_to   = sanitize_text_field( $_GET['date_to'] ?? $today );

        $per_page     = 10;
        $current_page = $this->get_pagenum();

        // Ensure filters always have date range    
        $filters              = $_GET;
        $filters['date_from'] = $date_from;
        $filters['date_to']   = $date_to;

        $result = $this->repository->get_links( [
            'filters' => $filters,
            'limit'   => $per_page,
            'offset'  => ( $current_page - 1 ) * $per_page,
        ] );

        $this->items = $result['data'];

        $this->set_pagination_args( [
            'total_items' => $result['total'],
            'per_page'    => $per_page,
        ] );

        $this->_column_headers = [ $this->get_columns(), [], [] ];
    }

	public function no_items() {
		esc_html_e( 'No payment links found.', 'payu-payment-links' );
	}
}