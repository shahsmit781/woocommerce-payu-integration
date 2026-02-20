<?php
/**
 * PayU Create Payment Link – Dedicated Admin Screen
 *
 * Renders the Create Payment Link form at admin.php?page=payu-create-payment-link&order_id={ORDER_ID}.
 * Uses WC_Order APIs, HPOS-compatible. No inline JS/CSS.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Create_Payment_Link_Page
 */
class PayU_Create_Payment_Link_Page {

	const PAGE_SLUG   = 'payu-create-payment-link';
	const NONCE_ACTION = 'payu_create_payment_link_form';
	const NONCE_FIELD  = 'payu_create_payment_link_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_init', array( $this, 'handle_form_submit' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 1 );
	}

	/**
	 * Register the admin page (no menu item; accessible via URL only).
	 */
	public function register_page() {
		add_submenu_page(
			null,
			__( 'Create PayU Payment Link', 'payu-payment-links' ),
			__( 'Create Payment Link', 'payu-payment-links' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle form POST: nonce, capability, sanitize, validate, then process or set notices.
	 */
	public function handle_form_submit() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order_id = isset( $_POST['payu_order_id'] ) ? absint( $_POST['payu_order_id'] ) : 0;
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_id() ) {
			return;
		}
		$errors = $this->validate_form( $order );
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $msg ) {
				add_settings_error(
					'payu_create_payment_link',
					'validation',
					$msg,
					'error'
				);
			}
			return;
		}
		$data = $this->sanitize_form_input();
		$this->process_create_link( $order, $data );
	}

	/**
	 * Sanitize form input for processing.
	 *
	 * @return array
	 */
	private function sanitize_form_input() {
		return array(
			'customer_name'       => isset( $_POST['payu_customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_customer_name'] ) ) : '',
			'customer_email'     => isset( $_POST['payu_customer_email'] ) ? sanitize_email( wp_unslash( $_POST['payu_customer_email'] ) ) : '',
			'customer_phone'     => isset( $_POST['payu_customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_customer_phone'] ) ) : '',
			'amount'              => isset( $_POST['payu_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['payu_amount'] ), wc_get_price_decimals() ) : '0',
			'currency'            => isset( $_POST['payu_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_currency'] ) ) : '',
			'expiry_date'         => isset( $_POST['payu_expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_expiry_date'] ) ) : '',
			'description'         => isset( $_POST['payu_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payu_description'] ) ) : '',
			'partial_payment'     => isset( $_POST['payu_partial_payment'] ) && '1' === $_POST['payu_partial_payment'],
			'min_initial_payment' => isset( $_POST['payu_min_initial_payment'] ) ? wc_format_decimal( wp_unslash( $_POST['payu_min_initial_payment'] ), wc_get_price_decimals() ) : '',
			'num_instalments'     => isset( $_POST['payu_num_instalments'] ) ? absint( $_POST['payu_num_instalments'] ) : 0,
			'notify_email'        => isset( $_POST['payu_notify_email'] ) && '1' === $_POST['payu_notify_email'],
			'notify_sms'           => isset( $_POST['payu_notify_sms'] ) && '1' === $_POST['payu_notify_sms']
		);
	}

	/**
	 * Validate form server-side.
	 *
	 * @param WC_Order $order Order.
	 * @return array List of error messages.
	 */
	private function validate_form( $order ) {
		$errors = array();
		$amount = isset( $_POST['payu_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['payu_amount'] ), wc_get_price_decimals() ) : 0;
		if ( $amount <= 0 ) {
			$errors[] = __( 'Payment amount must be greater than zero.', 'payu-payment-links' );
		}
		$order_total = (float) $order->get_total();
		if ( $amount > $order_total ) {
			$errors[] = __( 'Payment amount must not exceed order total.', 'payu-payment-links' );
		}
		$expiry = isset( $_POST['payu_expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_expiry_date'] ) ) : '';
		if ( '' !== $expiry ) {
			$expiry_ts = strtotime( $expiry );
			if ( false === $expiry_ts || $expiry_ts <= time() ) {
				$errors[] = __( 'Expiry date must be in the future.', 'payu-payment-links' );
			}
		}
		$currency = isset( $_POST['payu_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_currency'] ) ) : '';
		$allowed  = function_exists( 'payu_get_active_payu_currencies' ) ? array_keys( payu_get_active_payu_currencies() ) : array();
		if ( '' === $currency || ( ! empty( $allowed ) && ! in_array( $currency, $allowed, true ) ) ) {
			$errors[] = __( 'Please select a valid currency from PayU configurations.', 'payu-payment-links' );
		}
		$notify_email = isset( $_POST['payu_notify_email'] ) && '1' === $_POST['payu_notify_email'];
		if ( $notify_email ) {
			$notify_addr = isset( $_POST['payu_customer_email'] ) ? sanitize_email( wp_unslash( $_POST['payu_customer_email'] ) ) : '';
			if ( '' === $notify_addr || ! is_email( $notify_addr ) ) {
				$errors[] = __( 'A valid customer email is required..', 'payu-payment-links' );
			}
		}
		$notify_sms = isset( $_POST['payu_notify_sms'] ) && '1' === $_POST['payu_notify_sms'];
		if ( $notify_sms ) {
			$sms = isset( $_POST['payu_customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['payu_customer_phone'] ) ) : '';
			if ( '' === $sms ) {
				$errors[] = __( 'Please enter a mobile number when SMS notification is enabled.', 'payu-payment-links' );
			}
		}
		$partial = isset( $_POST['payu_partial_payment'] ) && '1' === $_POST['payu_partial_payment'];
		if ( $partial ) {
			$min_raw = isset( $_POST['payu_min_initial_payment'] ) ? trim( (string) wp_unslash( $_POST['payu_min_initial_payment'] ) ) : '';
			$num_raw = isset( $_POST['payu_num_instalments'] ) ? trim( (string) wp_unslash( $_POST['payu_num_instalments'] ) ) : '';
			if ( '' === $min_raw ) {
				$errors[] = __( 'Minimum initial payment is required when partial payment is enabled.', 'payu-payment-links' );
			}
			if ( '' === $num_raw ) {
				$errors[] = __( 'Number of instalments is required when partial payment is enabled.', 'payu-payment-links' );
			}
			$min = '' !== $min_raw ? wc_format_decimal( $min_raw, wc_get_price_decimals() ) : 0;
			if ( (float) $min > 0 && (float) $min > (float) $amount ) {
				$errors[] = __( 'Minimum initial payment cannot be more than the payment amount.', 'payu-payment-links' );
			}
		}
		return $errors;
	}

	/**
	 * Process create link: call PayU API, persist response, redirect to order with notice.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data Sanitized form data.
	 */
	private function process_create_link( $order, $data ) {
		if ( ! function_exists( 'payu_create_payment_link_api' ) ) {
			add_settings_error(
				'payu_create_payment_link',
				'not_configured',
				__( 'Payment link API is not available.', 'payu-payment-links' ),
				'error'
			);
			return;
		}
		$link = payu_create_payment_link_api( $order, $data );
		$order_id  = $order->get_id();
		$back_url  = $this->get_order_edit_url( $order_id );
		if ( is_wp_error( $link ) ) {
			add_settings_error(
				'payu_create_payment_link',
				'api_error',
				$link->get_error_message(),
				'error'
			);
			return;
		}
		$redirect = add_query_arg( 'payu_payment_link_created', rawurlencode( $link ), $back_url );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the admin page (form).
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'payu-payment-links' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Create PayU Payment Link', 'payu-payment-links' ) . '</h1><p class="notice notice-error">' . esc_html__( 'Invalid or missing order ID.', 'payu-payment-links' ) . '</p></div>';
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_id() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Create PayU Payment Link', 'payu-payment-links' ) . '</h1><p class="notice notice-error">' . esc_html__( 'Order not found.', 'payu-payment-links' ) . '</p></div>';
			return;
		}
		$back_url = $this->get_order_edit_url( $order_id );
		$currencies = function_exists( 'payu_get_active_payu_currencies' ) ? payu_get_active_payu_currencies() : array();

		if ( empty( $currencies ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Create PayU Payment Link', 'payu-payment-links' ) . '</h1>';
			echo '<p class="notice notice-warning">' . esc_html__( 'No active PayU currency configurations found. Please add at least one in PayU Payment Links settings.', 'payu-payment-links' ) . '</p>';
			echo '<p><a href="' . esc_url( $back_url ) . '" class="button">' . esc_html__( 'Back to order', 'payu-payment-links' ) . '</a></p></div>';
			return;
		}
		settings_errors( 'payu_create_payment_link' );
		$order_id   = $order->get_id();
		$form_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&order_id=' . $order_id );
		$nonce_name = self::NONCE_FIELD;
		$nonce_action = self::NONCE_ACTION;
		include PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/views/create-payment-link-form.php';
	}

	/**
	 * Get order edit URL (HPOS or legacy).
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private function get_order_edit_url( $order_id ) {
		if ( function_exists( 'wc_get_container' ) && class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Enqueue scripts and styles only on this page.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );
		wp_enqueue_style(
			'payu-create-payment-link',
			$plugin_url . 'assets/css/create-payment-link.css',
			array( 'woocommerce_admin_styles' ),
			PAYU_PAYMENT_LINKS_VERSION
		);
		wp_enqueue_script(
			'payu-create-payment-link',
			$plugin_url . 'assets/js/create-payment-link.js',
			array( 'jquery' ),
			PAYU_PAYMENT_LINKS_VERSION,
			true
		);
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_total = 0;
		$allowed_currencies = array();
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && $order->get_id() ) {
				$order_total = (float) $order->get_total();
				$allowed_currencies = function_exists( 'payu_get_active_payu_currencies' ) ? array_keys( payu_get_active_payu_currencies() ) : array();
			}
		}
		wp_localize_script(
			'payu-create-payment-link',
			'payuCreatePaymentLink',
			array(
				'orderTotal'        => $order_total,
				'allowedCurrencies' => $allowed_currencies,
				'i18n'              => array(
					'amountGreaterThanZero'   => __( 'Payment amount must be greater than zero.', 'payu-payment-links' ),
					'amountExceedOrderTotal'  => __( 'Payment amount must not exceed order total.', 'payu-payment-links' ),
					'expiryMustBeFuture'      => __( 'Expiry date must be in the future.', 'payu-payment-links' ),
					'selectValidCurrency'     => __( 'Please select a valid currency from PayU configurations.', 'payu-payment-links' ),
					'customerEmailRequired'   => __( 'A valid customer email is required.', 'payu-payment-links' ),
					'emailRequired'           => __( 'Please enter an email address when Email is selected.', 'payu-payment-links' ),
					'smsRequired'             => __( 'Please enter a mobile number when SMS is selected.', 'payu-payment-links' ),
					'minInitialRequired'     => __( 'Minimum initial payment is required when partial payment is enabled.', 'payu-payment-links' ),
					'numInstalmentsRequired'  => __( 'Number of instalments is required when partial payment is enabled.', 'payu-payment-links' ),
					'minAmountError'          => __( 'Minimum initial payment cannot be more than the payment amount.', 'payu-payment-links' ),
				),
			)
		);
	}
}
