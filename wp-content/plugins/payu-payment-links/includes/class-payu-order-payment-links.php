<?php
/**
 * PayU Payment Links – Order edit page section (read-only table).
 *
 * Registers a meta box on WooCommerce Edit Order. Displays payment links from DB only.
 * No redirects, no API calls, no webhook handling.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Order_Payment_Links
 */
class PayU_Order_Payment_Links {

	const META_BOX_ID = 'payu_order_payment_links';

	/**
	 * Constructor. Registers meta box on order edit screens.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 2 );
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
	 * Register meta box on order edit screens (legacy and HPOS).
	 *
	 * @param string        $screen_id   Screen ID (shop_order or woocommerce_page_wc-orders).
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
			__( 'PayU Payment Links', 'payu-payment-links' ),
			array( $this, 'render_meta_box' ),
			$screen_id,
			'normal',
			'default',
			array( 'order' => $order )
		);
	}

	/**
	 * Render meta box: fetch links from DB, pass to view.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 * @param array            $metabox       Metabox args; $metabox['args']['order'].
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
		$links    = class_exists( 'PayU_Payment_Links_Repository' ) ? PayU_Payment_Links_Repository::get_payment_links_by_order_id( $order_id ) : array();
		$empty_message = __( 'No PayU payment links created for this order.', 'payu-payment-links' );

		include PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/views/order-payment-links-table.php';
	}
}
