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
		
		// Initialize settings
		$this->init_settings();
		
		// Enqueue admin scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Display admin settings.
	 * Always shows "Create PayU Account" CTA and "Add Configuration" button.
	 */
	public function admin_options() {
		// Hide default WooCommerce "Save Changes" button since we use custom form
		global $hide_save_button;
		$hide_save_button = true;

		?>
		<div class="payu-payment-links-admin-wrapper">
			<h2><?php esc_html_e( 'PayU Payment Links', 'payu-payment-links' ); ?></h2>

			<?php
			// Display admin notices if any
			$this->display_admin_notices();

			// Show PayU Account CTA - Register or Login
			$signup_url = 'https://onboarding.payu.in/app/account/signup?partner_name=WooCommerce&partner_source=Affiliate+Links&partner_uuid=11eb-3a29-70592552-8c2b-0a696b110fde&source=Partner';
			$login_url  = 'https://onboarding.payu.in/app/account/login';
			?>
				<p>
					<?php
					printf(
						/* translators: %1$s: Sign up link, %2$s: Login link */
						esc_html__( '%1$s for a PayU merchant account to get started or %2$s to your existing account.', 'payu-payment-links' ),
						'<a href="' . esc_url( $signup_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sign up', 'payu-payment-links' ) . '</a>',
						'<a href="' . esc_url( $login_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'login', 'payu-payment-links' ) . '</a>'
					);
					?>
				</p>


			<!-- Close WooCommerce form wrapper to allow our custom form -->
			</form>

			<!-- Add Configuration Button - always visible -->
			<div class="payu-button-container">
				<button type="button" id="payu-add-configuration-button" class="button button-primary" aria-expanded="false" aria-controls="payu-add-configuration-form">
					<?php esc_html_e( 'Add Configuration', 'payu-payment-links' ); ?>
				</button>
			</div>

			<!-- Add Configuration Form (initially hidden) -->
			<?php $this->render_add_configuration_form(); ?>

			<!-- Configuration List -->
			<?php $this->render_configuration_list(); ?>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts and styles for PayU Payment Links admin page
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on WooCommerce settings pages
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// Check if we're on the PayU Payment Links settings page
		if ( isset( $_GET['section'] ) && 'payu_payment_links' === $_GET['section'] ) {
			$plugin_url = plugin_dir_url( dirname( __FILE__ ) );
			$version    = defined( 'PAYU_PAYMENT_LINKS_VERSION' ) ? PAYU_PAYMENT_LINKS_VERSION : '1.0.0';

			// Enqueue CSS file
			wp_enqueue_style(
				'payu-payment-links-admin',
				$plugin_url . 'assets/css/admin.css',
				array( 'woocommerce_admin_styles' ),
				$version
			);

			// Enqueue JavaScript file
			wp_enqueue_script(
				'payu-payment-links-admin',
				$plugin_url . 'assets/js/admin.js',
				array( 'jquery' ),
				$version,
				true
			);

			// Localize script with AJAX data
			wp_localize_script(
				'payu-payment-links-admin',
				'payuAjaxData',
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'payu_save_currency_config' ),
					'filterNonce' => wp_create_nonce( 'payu_filter_configs' ),
					'toggleNonce' => wp_create_nonce( 'payu_toggle_status' ),
				)
			);
		}
	}

	/**
	 * Render Add Configuration form template.
	 * Template function for form HTML rendering - reusable wherever needed.
	 *
	 * @return void
	 */
	private function render_add_configuration_form() {
		payu_render_configuration_form_template();
	}

	/**
	 * Render the configuration list table using WP_List_Table
	 * Template function for configuration listing HTML with pagination, search, and filtering.
	 *
	 * @return void
	 */
	private function render_configuration_list() {
		// Load the list table class
		require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/class-payu-config-list-table.php';

		// Create an instance of our custom list table
		$list_table = new PayU_Config_List_Table();

		// Prepare items (this handles pagination, search, filtering)
		$list_table->prepare_items();

		?>
		<div class="payu-config-list" id="payu-config-list-container">
			<div class="payu-config-list-header">
				<h3 class="payu-config-list-title"><?php esc_html_e( 'Currency Configurations', 'payu-payment-links' ); ?></h3>
				<div class="payu-config-list-actions">
					<button type="button" id="payu-add-configuration-button" class="button button-primary">
						<?php esc_html_e( 'Add Configuration', 'payu-payment-links' ); ?>
					</button>
				</div>
			</div>
			
			<div id="payu-config-list-wrapper">
				<?php
				// Display the table (display() automatically calls search_box and extra_tablenav internally)
				$list_table->display();
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Display admin notices
	 *
	 * @return void
	 */
	private function display_admin_notices() {
		// Check for success notice
		if ( isset( $_GET['payu_config_saved'] ) && '1' === $_GET['payu_config_saved'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Currency configuration saved successfully.', 'payu-payment-links' ); ?></p>
			</div>
			<?php
		}

		// Check for error notice
		if ( isset( $_GET['payu_config_error'] ) ) {
			$error_message = sanitize_text_field( $_GET['payu_config_error'] );
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $error_message ); ?></p>
			</div>
			<?php
		}
	}


}