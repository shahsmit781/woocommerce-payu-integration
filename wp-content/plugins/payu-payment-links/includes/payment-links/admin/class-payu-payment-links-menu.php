<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers WooCommerce → Payment Links admin menu
 *
 * @package PayU_Payment_Links
 */
class PayU_Payment_Links_Menu {

    /**
     * Constructor
     */
    public function __construct() {
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this, 'register_menu' ], 99 );
        }
    }

    /**
     * Register submenu under WooCommerce
     */
    public function register_menu() {

        add_submenu_page(
            'woocommerce',
            __( 'Payment Links', 'payu-payment-links' ),
            __( 'Payment Links', 'payu-payment-links' ),
            'manage_woocommerce',
            'payu-payment-links',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Render Payment Links admin page
     */
    public function render_page() {
        require PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/views/payment-links-list-page.php';
    }
}