<?php

/**
 * PayU Payment Links Template Functions
 *
 * @package PayU_Payment_Links
 */

defined('ABSPATH') || exit;

/**
 * Render Add Configuration form template.
 *
 * @return void
 */
function payu_render_configuration_form_template()
{
	$currencies = function_exists( 'payu_get_supported_currencies' ) ? payu_get_supported_currencies() : array();
?>
	<div id="payu-add-configuration-form" class="payu-add-configuration-form" role="region" aria-labelledby="payu-add-config-heading">
		<h3 id="payu-add-config-heading"><?php esc_html_e('Add New Currency Configuration', 'payu-payment-links'); ?></h3>

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
								<?php esc_html_e('Currency', 'payu-payment-links'); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip(__('Select the currency for this configuration.', 'payu-payment-links')); ?>
							</label>
						</th>
						<td class="forminp forminp-select">
							<select name="payu_config_currency" id="payu_config_currency" class="select" required aria-required="true">
								<option value=""><?php esc_html_e('Select...', 'payu-payment-links'); ?></option>
								<?php foreach ($currencies as $currency_code => $currency_name) : ?>
									<option value="<?php echo esc_attr($currency_code); ?>">
										<?php echo esc_html($currency_name); ?>
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
								<?php esc_html_e('Merchant ID', 'payu-payment-links'); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip(__('Your PayU Merchant ID from the PayU dashboard.', 'payu-payment-links')); ?>
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
								<?php esc_html_e('Client ID', 'payu-payment-links'); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip(__('Your PayU Client ID from the PayU dashboard.', 'payu-payment-links')); ?>
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
								<?php esc_html_e('Client Secret', 'payu-payment-links'); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip(__('Your PayU Client Secret from the PayU dashboard.', 'payu-payment-links')); ?>
							</label>
						</th>
						<td class="forminp forminp-password">
							<input type="password" name="payu_config_client_secret" id="payu_config_client_secret" class="regular-text" value="" required aria-required="true" maxlength="500" autocomplete="new-password">
							<span id="payu_config_client_secret_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<!-- Environment Field -->
					<tr>
						<th scope="row" class="titledesc">
							<label for="payu_config_environment">
								<?php esc_html_e('Environment', 'payu-payment-links'); ?>
								<?php echo wc_help_tip(__('Select UAT for testing or Production for live transactions.', 'payu-payment-links')); ?>
							</label>
						</th>
						<td class="forminp forminp-select">
							<select name="payu_config_environment" id="payu_config_environment" class="select">
								<option value="uat" selected><?php esc_html_e('UAT (Sandbox)', 'payu-payment-links'); ?></option>
								<option value="prod"><?php esc_html_e('Production', 'payu-payment-links'); ?></option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary payu-submit-button" name="payu_save_config" value="1">
					<?php esc_html_e('Save Configuration', 'payu-payment-links'); ?>
				</button>
				<button type="button" id="payu-cancel-configuration" class="button">
					<?php esc_html_e('Cancel', 'payu-payment-links'); ?>
				</button>
			</p>
		</form>
	</div>
<?php
}

function payu_render_edit_configuration_form_template( $config_id ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$config_id = absint( $config_id );
	$list_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' );
	$error_msg = __( 'Configuration not found.', 'payu-payment-links' );

	if ( ! $config_id ) {
		wp_safe_redirect( add_query_arg( 'payu_config_error', urlencode( $error_msg ), $list_url ) );
		exit;
	}

	$config = payu_get_currency_config_by_id( $config_id );
	if ( ! $config ) {
		wp_safe_redirect( add_query_arg( 'payu_config_error', urlencode( $error_msg ), $list_url ) );
		exit;
	}

	$back_url        = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payu_payment_links' );
	$edit_page_error = isset( $_GET['payu_config_error'] ) ? urldecode( sanitize_text_field( wp_unslash( $_GET['payu_config_error'] ) ) ) : '';
	?>
	<div class="payu-edit-wrapper">
		<?php if ( $edit_page_error !== '' ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $edit_page_error ); ?></p>
			</div>
			<?php endif; ?>
			
			<a href="<?php echo esc_url( $back_url ); ?>" class="button">← <?php esc_html_e( 'Back to Configurations', 'payu-payment-links' ); ?></a>
			
		<h2 class="payu-edit-title"><?php esc_html_e( 'Edit Currency Configuration', 'payu-payment-links' ); ?></h2>
		<form method="post" id="payu-edit-config-form" class="payu-edit-config-form">
			<div id="payu-edit-ajax-error" class="payu-edit-ajax-message" role="alert" style="display:none;" aria-live="polite"><p></p></div>
			<?php wp_nonce_field( 'payu_update_config', 'payu_update_config_nonce' ); ?>
			<input type="hidden" name="config_id" value="<?php echo esc_attr( (string) $config->id ); ?>">

			<table class="form-table">
				<tbody>

					<tr>
						<th scope="row">
							<label for="payu_edit_currency">
								<?php esc_html_e('Currency', 'payu-payment-links'); ?>
							</label>
						</th>
						<td>
							<input type="text" class="regular-text" name="payu_edit_currency" id="payu_edit_currency" 
							value="<?php echo esc_attr($config->currency); ?>" disabled>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="payu_edit_merchant_id">
								<?php esc_html_e('Merchant ID', 'payu-payment-links'); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip(__('Your PayU Merchant ID from the PayU dashboard.', 'payu-payment-links')); ?>
							</label>
						</th>
						<td>
							<input type="text" name="merchant_id" id="payu_edit_merchant_id" class="regular-text" value="<?php echo esc_attr($config->merchant_id); ?>" >
							<span id="payu_edit_merchant_id_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="payu_edit_client_id">
								<?php esc_html_e('Client ID', 'payu-payment-links'); ?>
								<span class="required">*</span>
								<?php echo wc_help_tip(__('Your PayU Client ID from the PayU dashboard.', 'payu-payment-links')); ?>
							</label>
						</th>
						<td>
							<input type="text" name="client_id" id="payu_edit_client_id" class="regular-text"
								value="<?php echo esc_attr($config->client_id); ?>" >
							<span id="payu_edit_client_id_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<tr>
						<th scope="row" class="titledesc">
							<label for="payu_edit_client_secret">
								<?php esc_html_e( 'Client Secret', 'payu-payment-links' ); ?>
								<?php echo wc_help_tip( __( 'Leave blank to keep current, or enter a new secret.', 'payu-payment-links' ) ); ?>
							</label>
						</th>
						<td class="forminp forminp-password">
							<input
								type="password"
								name="payu_edit_client_secret"
								id="payu_edit_client_secret"
								class="regular-text"
								value=""
								placeholder="<?php echo esc_attr__( 'Leave blank to keep current', 'payu-payment-links' ); ?>"
								maxlength="500"
								autocomplete="new-password"
							>
							<p class="description">
								<?php esc_html_e( 'Leave blank to keep current, or enter a new secret.', 'payu-payment-links' ); ?>
							</p>
							<span id="payu_edit_client_secret_error" class="payu-field-error-container"></span>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="payu_edit_environment">
								<?php esc_html_e( 'Environment', 'payu-payment-links' ); ?>
								<?php echo wc_help_tip( __( 'Select UAT for testing or Production for live transactions.', 'payu-payment-links' ) ); ?>
							</label>
						</th>
						<td>
							<select name="environment" id="payu_edit_environment">
								<option value="uat" <?php selected( $config->environment, 'uat' ); ?>><?php esc_html_e( 'UAT (Sandbox)', 'payu-payment-links' ); ?></option>
								<option value="prod" <?php selected( $config->environment, 'prod' ); ?>><?php esc_html_e( 'Production', 'payu-payment-links' ); ?></option>
							</select>
						</td>
					</tr>

				</tbody>
			</table>

			<p class="submit">
				<button type="submit" name="payu_update_config" class="button button-primary">
					<?php esc_html_e('Update Configuration', 'payu-payment-links'); ?>
				</button>
				<a href="<?php echo esc_url($back_url); ?>" class="button">
					<?php esc_html_e('Cancel', 'payu-payment-links'); ?>
				</a>
			</p>
		</form>
	</div>
<?php
}
