<?php
/**
 * Create PayU Payment Link – Admin form view
 *
 * Uses WooCommerce admin markup. Variables: $order, $back_url, $currencies, $order_id, $form_url, $nonce_name, $nonce_action.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

$customer_name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
$customer_email = $order->get_billing_email();
$customer_phone  = $order->get_billing_phone();
$order_total    = $order->get_total();
$order_currency = $order->get_currency();
$currency_keys   = array_keys( $currencies );
$default_currency = ! empty( $currencies[ $order_currency ] ) ? $order_currency : ( ! empty( $currency_keys ) ? $currency_keys[0] : '' );
?>
<div class="wrap woocommerce payu-create-payment-link-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Create PayU Payment Link', 'payu-payment-links' ); ?></h1>
	<p>
		<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to order', 'payu-payment-links' ); ?></a>
	</p>
	<hr class="wp-header-end">

	<form method="post" action="<?php echo esc_url( $form_url ); ?>" id="payu-create-payment-link-form" class="payu-create-payment-link-form">
		<?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
		<input type="hidden" name="payu_order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">

		<div class="payu-form-panel" id="payu-form-panel-order">
			<h2 class="payu-form-section-title"><?php esc_html_e( 'Order', 'payu-payment-links' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="payu_order_id_display"><?php esc_html_e( 'Order ID', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<input type="text" id="payu_order_id_display" class="regular-text" value="<?php echo esc_attr( (string) $order_id ); ?>" readonly disabled>
							<p class="description"><?php esc_html_e( 'WooCommerce order reference.', 'payu-payment-links' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="payu-form-panel" id="payu-form-panel-customer">
			<h2 class="payu-form-section-title"><?php esc_html_e( 'Customer details', 'payu-payment-links' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="payu_customer_name"><?php esc_html_e( 'Customer name', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<input type="text" name="payu_customer_name" id="payu_customer_name" class="regular-text" value="<?php echo esc_attr( $customer_name ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="payu_customer_email"><?php esc_html_e( 'Customer email', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<input type="email" name="payu_customer_email" id="payu_customer_email" class="regular-text" value="<?php echo esc_attr( $customer_email ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="payu_customer_phone"><?php esc_html_e( 'Phone number', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<input type="text" name="payu_customer_phone" id="payu_customer_phone" class="regular-text" value="<?php echo esc_attr( $customer_phone ); ?>">
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="payu-form-panel" id="payu-form-panel-payment">
			<h2 class="payu-form-section-title"><?php esc_html_e( 'Payment', 'payu-payment-links' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="payu_amount"><?php esc_html_e( 'Payment amount', 'payu-payment-links' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<input type="number" name="payu_amount" id="payu_amount" class="regular-text" step="<?php echo esc_attr( pow( 10, -wc_get_price_decimals() ) ); ?>" min="0.01" value="<?php echo esc_attr( $order_total ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="payu_currency"><?php esc_html_e( 'Currency', 'payu-payment-links' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<select name="payu_currency" id="payu_currency" required>
								<option value=""><?php esc_html_e( 'Select currency…', 'payu-payment-links' ); ?></option>
								<?php foreach ( $currencies as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default_currency, $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Only currencies with an active PayU configuration are listed.', 'payu-payment-links' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="payu_expiry_date"><?php esc_html_e( 'Payment link expiry date', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<input type="date" name="payu_expiry_date" id="payu_expiry_date" class="regular-text" value="">
							<p class="description"><?php esc_html_e( 'Leave empty for no expiry.', 'payu-payment-links' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="payu_description"><?php esc_html_e( 'Description / reference', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<textarea name="payu_description" id="payu_description" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Invoice or PO reference…', 'payu-payment-links' ); ?>"></textarea>
							<p class="description"><?php esc_html_e( 'Optional description or reference for the payment link.', 'payu-payment-links' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="payu-form-panel" id="payu-form-panel-partial">
			<h2 class="payu-form-section-title"><?php esc_html_e( 'Partial payment', 'payu-payment-links' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Enable partial payment', 'payu-payment-links' ); ?>
						</th>
						<td>
							<label for="payu_partial_payment">
								<input type="checkbox" name="payu_partial_payment" id="payu_partial_payment" value="1">
								<?php esc_html_e( 'Enable partial payment', 'payu-payment-links' ); ?>
							</label>
						</td>
					</tr>
					<tr class="payu-partial-fields" id="payu-row-partial-fields" style="display:none;">
						<th scope="row">
							<label for="payu_min_initial_payment"><?php esc_html_e( 'Minimum initial payment', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<input type="number" name="payu_min_initial_payment" id="payu_min_initial_payment" class="regular-text" step="<?php echo esc_attr( pow( 10, -wc_get_price_decimals() ) ); ?>" min="0" value="">
						</td>
					</tr>
					<tr class="payu-partial-fields" id="payu-row-num-instalments" style="display:none;">
						<th scope="row">
							<label for="payu_num_instalments"><?php esc_html_e( 'Number of instalments', 'payu-payment-links' ); ?></label>
						</th>
						<td>
							<input type="number" name="payu_num_instalments" id="payu_num_instalments" class="small-text" min="1" max="99" value="">
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="payu-form-panel" id="payu-form-panel-communication">
			<h2 class="payu-form-section-title"><?php esc_html_e( 'Communication channels', 'payu-payment-links' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Notify via', 'payu-payment-links' ); ?>
						</th>
						<td>
							<label for="payu_notify_email">
								<input type="checkbox" name="payu_notify_email" id="payu_notify_email" value="1">
								<?php esc_html_e( 'Email', 'payu-payment-links' ); ?>
							</label>
							<br>
							<label for="payu_notify_sms">
								<input type="checkbox" name="payu_notify_sms" id="payu_notify_sms" value="1">
								<?php esc_html_e( 'SMS', 'payu-payment-links' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<p class="submit">
			<button type="submit" name="payu_submit_create_link" class="button button-primary" value="1"><?php esc_html_e( 'Create Payment Link', 'payu-payment-links' ); ?></button>
			<a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'payu-payment-links' ); ?></a>
		</p>
	</form>
</div>
