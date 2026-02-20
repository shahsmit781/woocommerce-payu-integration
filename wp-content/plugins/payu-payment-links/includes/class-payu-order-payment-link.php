<?php
/**
 * PayU Payment Link – Order Admin Meta Box
 *
 * Adds a "Create Payment Link" button to the WooCommerce order edit screen
 * using add_meta_boxes. Visible only to users with manage_woocommerce.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Order_Payment_Link
 */
class PayU_Order_Payment_Link {

	/**
	 * Meta box ID.
	 *
	 * @var string
	 */
	const META_BOX_ID = 'payu_order_payment_link';

	/**
	 * Page slug for the dedicated Create Payment Link screen.
	 *
	 * @var string
	 */
	const CREATE_LINK_PAGE_SLUG = 'payu-create-payment-link';

	/**
	 * Query arg for success redirect (payment link URL).
	 */
	const QUERY_ARG_CREATED = 'payu_payment_link_created';

	/**
	 * Transient key prefix for one-time success notice (order_id appended).
	 */
	const TRANSIENT_NOTICE_PREFIX = 'payu_payment_link_notice_';

	/**
	 * Constructor. Hooks into add_meta_boxes, admin_init, and admin_notices.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'redirect_after_payment_link_created' ), 5 );
		add_action( 'admin_notices', array( $this, 'maybe_show_payment_link_created_notice' ), 10 );
	}

	/**
	 * If order edit URL has payu_payment_link_created, store link in transient and redirect to clean URL so message does not show on refresh.
	 */
	public function redirect_after_payment_link_created() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$link = isset( $_GET[ self::QUERY_ARG_CREATED ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG_CREATED ] ) ) : '';
		if ( '' === $link ) {
			return;
		}
		$order_id = self::get_order_id_from_request();
		if ( ! $order_id ) {
			return;
		}
		$link_decoded = rawurldecode( $link );
		if ( ! esc_url_raw( $link_decoded ) ) {
			return;
		}
		set_transient( self::TRANSIENT_NOTICE_PREFIX . $order_id, $link_decoded, 60 );
		$clean_url = remove_query_arg( self::QUERY_ARG_CREATED );
		wp_safe_redirect( $clean_url );
		exit;
	}

	/**
	 * Show admin success notice from transient (one-time), then delete transient.
	 */
	public function maybe_show_payment_link_created_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::is_order_edit_screen( $screen->id ) ) {
			return;
		}
		$order_id = self::get_order_id_from_request();
		if ( ! $order_id ) {
			return;
		}
		$link = get_transient( self::TRANSIENT_NOTICE_PREFIX . $order_id );
		if ( false === $link || '' === $link ) {
			return;
		}
		delete_transient( self::TRANSIENT_NOTICE_PREFIX . $order_id );
		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: %s: payment link URL */
			__( 'PayU payment link created: <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', 'payu-payment-links' ),
			esc_url( $link ),
			esc_html( $link )
		);
		echo '</p></div>';
	}

	/**
	 * Get order ID from current request (HPOS or legacy).
	 *
	 * @return int 0 if not on order edit.
	 */
	private static function get_order_id_from_request() {
		if ( isset( $_GET['id'] ) && 'edit' === ( isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '' ) ) {
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			if ( 'wc-orders' === $page ) {
				return absint( $_GET['id'] );
			}
		}
		if ( isset( $_GET['post'] ) && 'edit' === ( isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '' ) ) {
			return absint( $_GET['post'] );
		}
		return 0;
	}

	/**
	 * Check if current screen is order edit (legacy shop_order or HPOS).
	 *
	 * @param string $screen_id Screen ID from add_meta_boxes.
	 * @return bool
	 */
	private static function is_order_edit_screen( $screen_id ) {
		if ( 'shop_order' === $screen_id ) {
			return true;
		}
		if ( function_exists( 'wc_get_page_screen_id' ) && wc_get_page_screen_id( 'shop-order' ) === $screen_id ) {
			return true;
		}
		return false;
	}

	/**
	 * Register the meta box on order edit screens (legacy and HPOS).
	 *
	 * @param string   $screen_id Screen ID (shop_order or woocommerce_page_wc-orders).
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 */
	public function register_meta_box( $screen_id, $post_or_order = null ) {
		if ( ! self::is_order_edit_screen( $screen_id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );
		if ( ! $order || ! $order->get_id() ) {
			return;
		}
		add_meta_box(
			self::META_BOX_ID,
			__( 'PayU Payment Link', 'payu-payment-links' ),
			array( $this, 'render_meta_box' ),
			$screen_id,
			'side',
			'high',
			array( 'order' => $order )
		);
	}

	
	/**
	 * Render the meta box: button and result container.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 * @param array            $metabox       Metabox array; callback args in $metabox['args'].
	 */
	public function render_meta_box( $post_or_order, $metabox = array() ) {
		$order = isset( $metabox['args']['order'] ) ? $metabox['args']['order'] : null;
		if ( ! $order ) {
			$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );
		}
		if ( ! $order || ! $order->get_id() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order_id = $order->get_id();
		$url      = admin_url( 'admin.php?page=' . self::CREATE_LINK_PAGE_SLUG . '&order_id=' . $order_id );
		?>
		<div class="payu-order-payment-link-box">
			<p>
				<a href="<?php echo esc_url( $url ); ?>"
					class="button button-secondary"
					aria-label="<?php esc_attr_e( 'Create PayU payment link for this order', 'payu-payment-links' ); ?>">
					<?php esc_html_e( 'Create Payment Link', 'payu-payment-links' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
