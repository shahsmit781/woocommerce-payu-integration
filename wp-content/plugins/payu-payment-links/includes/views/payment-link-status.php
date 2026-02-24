<?php
/**
 * PayU Payment Result – Page template. Loads with loader; JS fetches status via AJAX and renders result.
 *
 * When $status_error is set (e.g. missing invoice): show error card. Otherwise: show loader; JS fetches
 * via PayU API, then shows result or friendly error.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

$has_error   = ! empty( $status_error );
$show_loader = ! $has_error;

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
		.payu-status-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); max-width: 560px; width: 100%; overflow: hidden; }
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
		/* Transaction No. — copy-friendly, platform-style */
		.payu-status-row-txn .payu-status-value { word-break: break-all; }
		.payu-status-txn-id { font-family: ui-monospace, "Cascadia Code", "SF Mono", Monaco, Consolas, monospace; font-size: 0.9rem; background: #f6f8fa; padding: 0.5rem 0.75rem; border-radius: 6px; display: inline-block; border: 1px solid #e1e4e8; }
		.payu-status-txn-block { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; }
		.payu-status-txn-heading { font-size: 0.875rem; font-weight: 600; color: #444; margin-bottom: 0.5rem; }
		.payu-status-txn-list { list-style: none; margin: 0; padding: 0; }
		.payu-status-txn-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.75rem; background: #f6f8fa; border-radius: 6px; margin-bottom: 0.35rem; border: 1px solid #e1e4e8; }
		.payu-status-txn-item:last-child { margin-bottom: 0; }
		.payu-status-txn-item .payu-status-txn-id { background: none; padding: 0; border: none; font-size: 0.85rem; }
		.payu-status-txn-item .payu-status-txn-amount { font-weight: 600; color: #1e1e1e; font-size: 0.9rem; }
		/* Partial payment: record-based table — broad layout so records display properly */
		.payu-status-txn-table { width: 100%; min-width: 320px; border-collapse: collapse; font-size: 0.875rem; margin-top: 0.5rem; table-layout: fixed; }
		.payu-status-txn-table th { text-align: left; padding: 0.6rem 0.5rem; font-weight: 600; color: #444; border-bottom: 1px solid #e1e4e8; white-space: nowrap; }
		.payu-status-txn-table td { padding: 0.6rem 0.5rem; border-bottom: 1px solid #eee; vertical-align: top; overflow-wrap: break-word; }
		.payu-status-txn-table tbody tr:last-child td { border-bottom: none; }
		.payu-status-txn-table .payu-status-txn-id { font-family: ui-monospace, "Cascadia Code", "SF Mono", Monaco, Consolas, monospace; font-size: 0.8rem; word-break: break-all; }
		.payu-status-txn-table th:nth-child(1) { width: 28%; }
		.payu-status-txn-table th:nth-child(2) { width: 22%; }
		.payu-status-txn-table th:nth-child(3) { width: 18%; }
		.payu-status-txn-table th:nth-child(4) { width: 32%; }
		.payu-status-datetime { display: flex; flex-direction: column; gap: 0.15rem; }
		.payu-status-date-line { display: block; font-weight: 500; }
		.payu-status-time-line { display: block; font-size: 0.85rem; color: #666; }
		.payu-status-datetime-cell .payu-status-date-line { display: block; }
		.payu-status-datetime-cell .payu-status-time-line { display: block; font-size: 0.8rem; color: #666; }
		.payu-status-txn-table .payu-status-badge { font-size: 0.75rem; padding: 0.25rem 0.5rem; }
		.payu-status-badge--failed { background: #f8d7da; color: #721c24; }
		.payu-status-badge--success { background: #d4edda; color: #155724; }
		.payu-status-badge--in-progress { background: #fff3cd; color: #856404; }
		.payu-status-badge--user-cancelled { background: #e2e3e5; color: #383d41; }
		@media print {
			.payu-status-loader { display: none !important; }
			.payu-status-error { display: none !important; }
			.payu-status-actions { display: none !important; }
			body.payu-status-body { background: #fff; }
		}
	</style>
</head>
<body class="payu-status-body">
	<div class="payu-status-card">
		<?php if ( $show_loader ) : ?>
		<div class="payu-status-loader" id="payu-status-loader" aria-live="polite">
			<div class="payu-status-loader-spinner" aria-hidden="true"></div>
			<p class="payu-status-loader-text" id="payu-status-loader-text"><?php esc_html_e( 'Fetching payment status…', 'payu-payment-links' ); ?></p>
		</div>
		<?php else : ?>
		<div class="payu-status-loader is-hidden" id="payu-status-loader" aria-live="polite">
			<div class="payu-status-loader-spinner" aria-hidden="true"></div>
			<p class="payu-status-loader-text" id="payu-status-loader-text"><?php esc_html_e( 'Fetching payment status…', 'payu-payment-links' ); ?></p>
		</div>
		<?php endif; ?>

		<?php if ( $has_error ) : ?>
		<div class="payu-status-error is-visible" id="payu-status-error" role="alert">
			<div class="payu-status-error-card">
				<div class="payu-status-error-icon" id="payu-status-error-icon" aria-hidden="true">!</div>
				<h2 class="payu-status-error-title" id="payu-status-error-title"><?php
	$err_code = isset( $status_error['code'] ) ? $status_error['code'] : '';
	if ( $err_code === 'no_link' ) {
		echo esc_html( __( 'Payment link not found', 'payu-payment-links' ) );
	} elseif ( $err_code === 'invalid_invoice' ) {
		echo esc_html( __( 'Invalid link', 'payu-payment-links' ) );
	} elseif ( $err_code === 'payu_no_transactions' ) {
		echo esc_html( __( 'Payment details not available', 'payu-payment-links' ) );
	} else {
		echo esc_html( __( 'Something went wrong', 'payu-payment-links' ) );
	}
?></h2>
				<p class="payu-status-error-message" id="payu-status-error-message"><?php echo esc_html( isset( $status_error['message'] ) ? $status_error['message'] : __( 'We couldn’t load the payment status. Please try again or contact the store.', 'payu-payment-links' ) ); ?></p>
				<div class="payu-status-error-actions" id="payu-status-error-actions">
					<button type="button" class="payu-status-btn payu-status-btn-secondary" id="payu-status-try-again"><?php esc_html_e( 'Try again', 'payu-payment-links' ); ?></button>
				</div>
			</div>
		</div>
		<?php else : ?>
		<div class="payu-status-error" id="payu-status-error" role="alert">
			<div class="payu-status-error-card">
				<div class="payu-status-error-icon" id="payu-status-error-icon" aria-hidden="true">!</div>
				<h2 class="payu-status-error-title" id="payu-status-error-title"></h2>
				<p class="payu-status-error-message" id="payu-status-error-message"></p>
				<div class="payu-status-error-actions" id="payu-status-error-actions"></div>
			</div>
		</div>
		<?php endif; ?>

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
	<?php
	// Avoid deprecated the_block_template_skip_link (WP 6.4+) on this minimal status page.
	if ( function_exists( 'the_block_template_skip_link' ) ) {
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
	}
	wp_footer();
	?>
</body>
</html>
