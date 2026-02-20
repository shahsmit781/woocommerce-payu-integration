<?php
/**
 * PayU Payment Link Status – Shell only. All result content is injected by JS from AJAX response.
 *
 * No static status text. Loader shown until JS receives response; then header, details, and actions
 * are built from response.data (display_status, amount_paid, remaining, total, currency, order_ref, invoice).
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

status_header( 200 );
header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( __( 'Payment link status', 'payu-payment-links' ) ); ?></title>
	<style>
		* { box-sizing: border-box; }
		.payu-status-body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, sans-serif; margin: 0; padding: 1rem; min-height: 100vh; background: linear-gradient(160deg, #f5f7fa 0%, #e8ecf1 100%); display: flex; align-items: center; justify-content: center; }
		.payu-status-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); max-width: 440px; width: 100%; overflow: hidden; }
		.payu-status-header { padding: 1.75rem 1.5rem; text-align: center; }
		.payu-status-icon { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700; line-height: 1; }
		.payu-status--success .payu-status-icon { background: #d4edda; color: #155724; }
		.payu-status--failure .payu-status-icon { background: #f8d7da; color: #721c24; }
		.payu-status--pending .payu-status-icon { background: #fff3cd; color: #856404; }
		.payu-status--active .payu-status-icon { background: #cce5ff; color: #004085; }
		.payu-status--expired .payu-status-icon { background: #e2e3e5; color: #383d41; }
		.payu-status--partial .payu-status-icon { background: #d1ecf1; color: #0c5460; }
		.payu-status-title { font-size: 1.35rem; font-weight: 600; margin: 0 0 0.25rem; color: #1e1e1e; }
		.payu-status-sub { font-size: 0.875rem; color: #666; margin: 0; }
		.payu-status-body-section { padding: 0 1.5rem 1.5rem; }
		.payu-status-row { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #eee; }
		.payu-status-row:last-child { border-bottom: none; }
		.payu-status-label { font-size: 0.875rem; color: #666; }
		.payu-status-value { font-size: 1rem; font-weight: 600; color: #1e1e1e; }
		.payu-status-value.highlight { font-size: 1.1rem; }
		.payu-status-badge { display: inline-block; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
		.payu-status--success .payu-status-badge { background: #d4edda; color: #155724; }
		.payu-status--failure .payu-status-badge { background: #f8d7da; color: #721c24; }
		.payu-status--pending .payu-status-badge { background: #fff3cd; color: #856404; }
		.payu-status--active .payu-status-badge { background: #cce5ff; color: #004085; }
		.payu-status--expired .payu-status-badge { background: #e2e3e5; color: #383d41; }
		.payu-status--partial .payu-status-badge { background: #d1ecf1; color: #0c5460; }
		.payu-status-actions { padding: 1rem 1.5rem 1.5rem; border-top: 1px solid #eee; }
		.payu-status-btn { display: inline-block; padding: 0.6rem 1.25rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; transition: opacity 0.2s; }
		.payu-status-btn:hover { opacity: 0.9; }
		.payu-status-btn-primary { background: #2271b1; color: #fff; border: none; }
		.payu-status-btn-secondary { background: #f0f0f1; color: #1e1e1e; margin-left: 0.5rem; }
		.payu-status-loader { padding: 2rem 1.5rem; text-align: center; }
		.payu-status-loader-spinner { display: inline-block; width: 40px; height: 40px; border: 3px solid #e8ecf1; border-top-color: #2271b1; border-radius: 50%; animation: payu-spin 0.8s linear infinite; }
		.payu-status-loader-text { margin-top: 1rem; font-size: 0.95rem; color: #666; }
		@keyframes payu-spin { to { transform: rotate(360deg); } }
		.payu-status-result { display: none; }
		.payu-status-result.is-visible { display: block; }
		.payu-status-loader.is-hidden { display: none; }
		.payu-status-error { display: none; padding: 0; }
		.payu-status-error.is-visible { display: block; }
		.payu-status-error-card { padding: 2rem 1.5rem; text-align: center; background: #fef9f9; border-radius: 0 0 12px 12px; }
		.payu-status-error-icon { width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 1rem; background: #fef3f2; color: #b42318; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; font-weight: 600; line-height: 1; }
		.payu-status-error-title { font-size: 1.25rem; font-weight: 600; margin: 0 0 0.5rem; color: #1e1e1e; }
		.payu-status-error-message { font-size: 0.9375rem; color: #50575e; margin: 0 0 1.5rem; line-height: 1.55; max-width: 320px; margin-left: auto; margin-right: auto; }
		.payu-status-error-actions { margin-top: 0.5rem; }
		.payu-status-error-actions .payu-status-btn { margin: 0 0.35rem; }
	</style>
</head>
<body class="payu-status-body">
	<div class="payu-status-card">
		<div class="payu-status-loader" id="payu-status-loader" aria-live="polite">
			<div class="payu-status-loader-spinner" aria-hidden="true"></div>
			<p class="payu-status-loader-text" id="payu-status-loader-text"><?php esc_html_e( 'Fetching payment status…', 'payu-payment-links' ); ?></p>
		</div>
		<div class="payu-status-error" id="payu-status-error" role="alert">
			<div class="payu-status-error-card">
				<div class="payu-status-error-icon" id="payu-status-error-icon" aria-hidden="true">!</div>
				<h2 class="payu-status-error-title" id="payu-status-error-title"></h2>
				<p class="payu-status-error-message" id="payu-status-error-message"></p>
				<div class="payu-status-error-actions" id="payu-status-error-actions"></div>
			</div>
		</div>
		<div class="payu-status-result" id="payu-status-result">
			<div class="payu-status-header" id="payu-status-header">
				<div class="payu-status-icon" id="payu-status-icon" aria-hidden="true"></div>
				<h1 class="payu-status-title" id="payu-status-title" aria-live="polite"></h1>
				<p class="payu-status-sub" id="payu-status-sub"><?php esc_html_e( 'Payment link status', 'payu-payment-links' ); ?></p>
			</div>
			<div class="payu-status-body-section" id="payu-status-details"></div>
			<div class="payu-status-actions" id="payu-status-actions"></div>
		</div>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
