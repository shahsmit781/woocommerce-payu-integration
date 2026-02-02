<?php
/**
 * PayU Payment Links Gateway
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

// Ensure WooCommerce is loaded
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

class WC_Gateway_Payu_Payment_Links extends WC_Payment_Gateway {
	
	public function __construct() {
		$this->id                 = 'payu_payment_links';
		$this->icon               = 'https://devguide.payu.in/website-assets/uploads/2021/12/new-payu-logo.svg';
		$this->method_title       = __( 'PayU Payment Links', 'payu-payment-links' );
		$this->method_description = __( 'Generate PayU payment links via OneAPI for WooCommerce orders.', 'payu-payment-links' );
		$this->has_fields         = false;
		
		// Initialize form fields and settings
		$this->init_form_fields();
		$this->init_settings();
		
		// Get settings
		// $this->title       = $this->get_option( 'title', __( 'PayU Payment Links', 'payu-payment-links' ) );
		// $this->description = $this->get_option( 'description', __( 'Pay via PayU payment link', 'payu-payment-links' ) );
		// $this->enabled     = $this->get_option( 'enabled', 'no' );
		
		// Save settings
		// add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}
	
	/**
	 * Check if gateway needs setup
	 *
	 * @return bool
	 */
	public function needs_setup() {
		// $client_id     = $this->get_option( 'client_id' );
		// $client_secret = $this->get_option( 'client_secret' );
		// $merchant_id   = $this->get_option( 'merchant_id' );
		
		// $is_missing = empty( $client_id ) || empty( $client_secret ) || empty( $merchant_id );
		
		// if ( $is_missing ) {
		// 	error_log("PayU Setup Error: ID: $client_id, Secret: $client_secret, Merchant: $merchant_id");
		// }

		// return $is_missing;
	}
	
	/**
	 * Display admin settings
	 */
	public function admin_options() {
		?>
		<h2><?php esc_html_e( 'PayU Payment Links', 'payu-payment-links' ); ?></h2>
		
		<?php if ( $this->needs_setup() ) : ?>
			<div class="notice notice-info inline" style="margin: 20px 0; padding: 15px;">
				<p style="margin: 0 0 15px 0; font-size: 14px;">
					<strong><?php esc_html_e( 'PayU credentials are required to use this payment gateway.', 'payu-payment-links' ); ?></strong>
				</p>
				<p style="margin: 0 0 15px 0;">
					<?php esc_html_e( 'Please configure your PayU credentials below or create a new PayU account to get started.', 'payu-payment-links' ); ?>
				</p>
				<p style="margin: 0;">
					<a href="https://onboarding.payu.in/app/account/signup?partner_name=WooCommerce&partner_source=Affiliate+Links&partner_uuid=11eb-3a29-70592552-8c2b-0a696b110fde&source=Partner" 
					   target="_blank" 
					   class="button button-primary" 
					   style="text-decoration: none;">
						<?php esc_html_e( 'Create PayU Account', 'payu-payment-links' ); ?>
					</a>
					<span style="margin-left: 10px;">
						<?php esc_html_e( 'or', 'payu-payment-links' ); ?>
						<a href="https://onboarding.payu.in/app/account/login?partner_name=WooCommerce&partner_source=Affiliate+Links&partner_uuid=11eb-3a29-70592552-8c2b-0a696b110fde&source=Partner" 
						   target="_blank" 
						   style="margin-left: 5px;">
							<?php esc_html_e( 'login to your existing account', 'payu-payment-links' ); ?>
						</a>
					</span>
				</p>
			</div>
		<?php endif; ?>
		
		<p><?php esc_html_e( 'Settings for PayU Payment Links gateway.', 'payu-payment-links' ); ?></p>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php
	}

	/**
	 * Initialize form fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Enable/Disable', 'payu-payment-links' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayU Payment Links', 'payu-payment-links' ),
				'default' => 'no',
			),
			'title'         => array(
				'title'       => __( 'Title', 'payu-payment-links' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'payu-payment-links' ),
				'default'     => __( 'PayU Payment Links', 'payu-payment-links' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Description', 'payu-payment-links' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'payu-payment-links' ),
				'default'     => __( 'Pay via PayU payment link', 'payu-payment-links' ),
				'desc_tip'    => true,
			),
			'client_id'     => array(
				'title'       => __( 'Client ID', 'payu-payment-links' ),
				'type'        => 'text',
				'description' => __( 'Your PayU Client ID from the PayU dashboard.', 'payu-payment-links' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'client_secret' => array(
				'title'       => __( 'Client Secret', 'payu-payment-links' ),
				'type'        => 'password',
				'description' => __( 'Your PayU Client Secret from the PayU dashboard.', 'payu-payment-links' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'merchant_id'  => array(
				'title'       => __( 'Merchant ID', 'payu-payment-links' ),
				'type'        => 'text',
				'description' => __( 'Your PayU Merchant ID from the PayU dashboard.', 'payu-payment-links' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'mode'          => array(
				'title'       => __( 'Mode', 'payu-payment-links' ),
				'type'        => 'select',
				'description' => __( 'Select test mode for testing or live mode for production.', 'payu-payment-links' ),
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Sandbox', 'payu-payment-links' ),
					'live' => __( 'Production', 'payu-payment-links' ),
				),
				'desc_tip'    => true,
			),
		);
	}
	
	/**
	 * Process payment
	 */
	// public function process_payment( $order_id ) {
	// 	$order = wc_get_order( $order_id );
		
	// 	// Mark as pending payment
	// 	$order->update_status( 'pending', __( 'Awaiting PayU payment link payment', 'payu-payment-links' ) );
		
	// 	// Reduce stock levels
	// 	wc_reduce_stock_levels( $order_id );
		
	// 	// Remove cart
	// 	WC()->cart->empty_cart();
		
	// 	// Return thankyou redirect
	// 	return array(
	// 		'result'   => 'success',
	// 		'redirect' => $this->get_return_url( $order ),
	// 	);
	// }
}