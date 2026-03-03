<?php
/**
 * PayU Payment Links – Admin modals (resend, etc.)
 *
 * Renders modal HTML only. No business logic. Views are HTML-only.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayU_Payment_Links_Modals
 */
class PayU_Payment_Links_Modals {

	/**
	 * Output the Resend payment link modal markup.
	 */
	public static function render_resend_modal() {
		include PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/views/modal-resend-payment-link.php';
	}
}
