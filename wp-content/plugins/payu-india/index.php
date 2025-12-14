<?php
/*
Plugin Name: PayU India
Plugin URI: https://payu.in/
Description: Seamlessly integrate PayU with WooCommerce for secure and reliable payment processing.
Version: 3.8.8
Author: Team PayU
Author URI: https://payu.in/
Tags: payment, gateway, payu
Requires at least: 5.3
Tested up to: 6.8
Stable tag: 3.8.8
Requires PHP: 7.4
License: GPLv2 or later
Woo: 7310302:82f4a3fafb07f086f3ebac34a6a03729
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Copyright: © 2024, PayU. All rights reserved.
*/
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/**
 * The code that runs during payu activation.
 * This action is documented in includes/class-payu-activator.php
 */

function activatePayu()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-payu-activator.php';
	PayuActivator::activate();
}

register_activation_hook(__FILE__, 'activatePayu');

require_once plugin_dir_path(__FILE__) . 'includes/constant.php';

require_once plugin_dir_path(__FILE__) . 'includes/helper.php';

require_once plugin_dir_path(__FILE__) . 'includes/payu-payment-gateway-api.php';

require_once plugin_dir_path(__FILE__) . 'includes/payu-refund-process.php';

require_once plugin_dir_path(__FILE__) . 'includes/payu-cart-express-checkout.php';

/**
* Added File For Buy Now
* Added by SM
*/
require_once plugin_dir_path(__FILE__) . 'includes/buy_now/buy-now-payu.php';

/**
 * Added File For Affordability Widget
 * Added by SM
*/
require_once plugin_dir_path(__FILE__) . 'includes/payu_affordability_widget/payu-affordability-widget.php';

add_action('plugins_loaded', 'woocommercePayubizInit', 0);

function woocommercePayubizInit()
{

	if (!class_exists('WC_Payment_Gateway')) {
		return null;
	}

	/**
	 * Localisation
	 */

	if (isset($_GET['msg']) && sanitize_text_field($_GET['msg']) != '') {
		add_action('the_content', 'showpayubizMessage');
	}

	function showpayubizMessage($content)
	{
		return '<div class="box ' . sanitize_text_field($_GET['type']) . '-box">' .
			esc_html__(sanitize_text_field($_GET['msg']), 'payubiz') .
			'</div>' . $content;
	}
	static $plugin;

	if (!isset($plugin)) {

		class WcPayu
		{

			/**
			 * The *Singleton* instance of this class
			 *
			 * @var WcPayu
			 */

			public function __construct()
			{

				$this->init();
			}

			private static $instance;

			/**
			 * Returns the *Singleton* instance of this class.
			 *
			 * @return WcPayu The *Singleton* instance.
			 */
			public static function getInstance()
			{
				if (null === self::$instance) {
					self::$instance = new self();
				}
				return self::$instance;
			}


			/**
			 * Init the plugin after plugins_loaded so environment variables are set.
			 *
			 */
			public function init()
			{
				require_once dirname(__FILE__) . '/includes/class-payu-payment-validation.php';
				require_once dirname(__FILE__) . '/includes/class-wc-gateway-payu.php';
				add_filter('woocommerce_payment_gateways', [$this, 'addGateways']);
				require_once dirname(__FILE__) . '/includes/class-payu-shipping-tax-api-calculation.php';
				require_once plugin_dir_path(__FILE__) . 'includes/class-payu-verify-payment.php';
				require_once plugin_dir_path(__FILE__) . 'includes/class-payu-account-address-sync.php';
				require_once plugin_dir_path(__FILE__) . 'includes/admin/payu-webhook-calls.php';
			}

			/**
			 * Add the gateways to WooCommerce.
			 */

			public function addGateways($methods)
			{
				$methods[] = WcPayubiz::class;

				return $methods;
			}
		}

		$plugin = WcPayu::getInstance();
	}

	return $plugin;
}

/*=========================================================================================
------------------- Payu Support Block Based Cart ------------------------------- 
         ------ Only working in a Commerce pro mode ----------------------
========================================================================================= */
function is_commercepro_enabled() {
    $payu_settings = get_option('woocommerce_payubiz_settings');
    $selected_mode = isset($payu_settings['checkout_express']) ? $payu_settings['checkout_express'] : 'redirect';
 
    // echo "</pre>";
    // print_r($payu_settings);
    // echo "</pre>";
     //echo $selected_mode;
    // exit;
    return ($selected_mode == 'checkout_express');
}
/* ==================================================================================
         -------------- Enqueu Js Script ----------------------
================================================================================== */

function enqueue_custom_block_cart_script() {
    if (is_commercepro_enabled()) {
        wp_enqueue_script(
            'custom-block-cart-script',
            plugin_dir_url(__FILE__) . 'assets/js/custom-block-cart.js',
            array('jquery'),
            null,
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_custom_block_cart_script');


/*=========================================================================================
------------------- Payu Support Block Based Checkout -------------------------------
========================================================================================= */

add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil'))
    {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

add_action('woocommerce_blocks_loaded', 'payu_woocommerce_block_support');

function payu_woocommerce_block_support()
{
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType'))
    {
       //require_once dirname( __FILE__ ) . 'includes/checkout_block/checkout-block.php';
	   require_once plugin_dir_path(__FILE__) . 'includes/checkout_block/checkout_block.php';

        add_action(
          'woocommerce_blocks_payment_method_type_registration',
          function(PaymentMethodRegistry $payment_method_registry) {
            $container = Automattic\WooCommerce\Blocks\Package::container();
            $container->register(
                WC_Payu_Blocks::class,
                function() {
					// call this wc_payu_block in includes>checkoutblock>checkout_block.php file
                    return new WC_Payu_Blocks();
                }
            );
            $payment_method_registry->register($container->get(WC_Payu_Blocks::class));
          },
        );
    }
}

/*=========================================================================================
------------------- Plugin Activation Show Menu in plugin page ---------------------------
========================================================================================= */

add_filter('plugin_action_links', 'custom_plugin_action_links_all', 10, 2);

function custom_plugin_action_links_all($links, $file) {
    if ($file === plugin_basename(__FILE__)) {

        $textdomain = basename(dirname(__FILE__));

        $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=payubiz');
        $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', $textdomain) . '</a>';

        $support_url = 'https://help.payu.in/search-query'; 
        $support_link = '<a href="' . esc_url($support_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Support', $textdomain) . '</a>';

        $documentation_url = 'https://docs.payu.in/docs/woocommerce'; 
        $documentation_link = '<a href="' . esc_url($documentation_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Docs', $textdomain) . '</a>';

        array_unshift($links, $settings_link, $support_link, $documentation_link);
    }
    return $links;
}
