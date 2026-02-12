<?php
/**
 * PayU Payment Links Template Functions
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render Add Configuration form template.
 *
 * @return void
 */
function payu_render_configuration_form_template() {
	$currencies = get_woocommerce_currencies();
	?>
	<div id="payu-add-configuration-form" class="payu-add-configuration-form" role="region" aria-labelledby="payu-add-config-heading">
		<h3 id="payu-add-config-heading"><?php esc_html_e( 'Add New Currency Configuration', 'payu-payment-links' ); ?></h3>
		
		<!-- AJAX Messages Container -->
		<div id="payu-ajax-messages" class="payu-ajax-messages" style="display: none;">
		</div>

		
		<form class="payu-config-form" method="post">
			<!-- AJAX Data is set via wp_localize_script in gateway class -->
			<!-- No need for inline script - wp_localize_script handles it -->
			
			<table class="form-table" role="presentation">
				<tbody>
					<!-- Currency Field -->
					<tr>
						<th scope="row" class="titledesc">
							<label for="payu_config_currency">
								<?php esc_html_e( 'Currency', 'payu-payment-links' ); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip( __( 'Select the currency for this configuration.', 'payu-payment-links' ) ); ?>
							</label>
						</th>
						<td class="forminp forminp-select">
							<select name="payu_config_currency" id="payu_config_currency" class="select" required aria-required="true">
								<option value=""><?php esc_html_e( 'Select...', 'payu-payment-links' ); ?></option>
								<?php foreach ( $currencies as $currency_code => $currency_name ) : ?>
									<option value="<?php echo esc_attr( $currency_code ); ?>">
										<?php echo esc_html( $currency_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span id="payu_config_currency_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<!-- Merchant ID Field -->
					<tr>
						<th scope="row" class="titledesc">
							<label for="payu_config_merchant_id">
								<?php esc_html_e( 'Merchant ID', 'payu-payment-links' ); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip( __( 'Your PayU Merchant ID from the PayU dashboard.', 'payu-payment-links' ) ); ?>
							</label>
						</th>
						<td class="forminp forminp-text">
							<input type="text" name="payu_config_merchant_id" id="payu_config_merchant_id" class="regular-text" value="" required aria-required="true" maxlength="100">
							<span id="payu_config_merchant_id_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<!-- Client ID Field -->
					<tr>
						<th scope="row" class="titledesc">
							<label for="payu_config_client_id">
								<?php esc_html_e( 'Client ID', 'payu-payment-links' ); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip( __( 'Your PayU Client ID from the PayU dashboard.', 'payu-payment-links' ) ); ?>
							</label>
						</th>
						<td class="forminp forminp-text">
							<input type="text" name="payu_config_client_id" id="payu_config_client_id" class="regular-text" value="" required aria-required="true" maxlength="255">
							<span id="payu_config_client_id_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<!-- Client Secret Field -->
					<tr>
						<th scope="row" class="titledesc">
							<label for="payu_config_client_secret">
								<?php esc_html_e( 'Client Secret', 'payu-payment-links' ); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip( __( 'Your PayU Client Secret from the PayU dashboard.', 'payu-payment-links' ) ); ?>
							</label>
						</th>
						<td class="forminp forminp-password">
							<input type="password" name="payu_config_client_secret" id="payu_config_client_secret" class="regular-text" value="" required aria-required="true" autocomplete="new-password">
							<span id="payu_config_client_secret_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<!-- Environment Field -->
					<tr>
						<th scope="row" class="titledesc">
							<label for="payu_config_environment">
								<?php esc_html_e( 'Environment', 'payu-payment-links' ); ?>
								<?php echo wc_help_tip( __( 'Select UAT for testing or Production for live transactions.', 'payu-payment-links' ) ); ?>
							</label>
						</th>
						<td class="forminp forminp-select">
							<select name="payu_config_environment" id="payu_config_environment" class="select">
								<option value="uat" selected><?php esc_html_e( 'UAT (Sandbox)', 'payu-payment-links' ); ?></option>
								<option value="prod"><?php esc_html_e( 'Production', 'payu-payment-links' ); ?></option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			
			<p class="submit">
				<button type="submit" class="button button-primary payu-submit-button" name="payu_save_config" value="1">
					<?php esc_html_e( 'Save Configuration', 'payu-payment-links' ); ?>
				</button>
				<button type="button" id="payu-cancel-configuration" class="button">
					<?php esc_html_e( 'Cancel', 'payu-payment-links' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}
