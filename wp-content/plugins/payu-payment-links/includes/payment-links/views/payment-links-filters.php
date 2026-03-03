<?php
defined( 'ABSPATH' ) || exit;

$today = current_time( 'Y-m-d' );

/**
 * Quick date logic (WordPress-style)
 */
$quick = sanitize_text_field( $_GET['quick'] ?? '' );

switch ( $quick ) {
	case 'today':
		$date_from = $today;
		$date_to   = $today;
		break;

	case 'yesterday':
		$date_from = date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );
		$date_to   = $date_from;
		break;

	case 'last7':
		$date_from = date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) );
		$date_to   = $today;
		break;

	case 'month':
		$date_from = date( 'Y-m-01', strtotime( $today ) );
		$date_to   = $today;
		break;

	default:
		$date_from = sanitize_text_field( $_GET['date_from'] ?? $today );
		$date_to   = sanitize_text_field( $_GET['date_to'] ?? $today );
		break;
}

$status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
$currency    = isset( $_GET['currency'] ) ? sanitize_text_field( wp_unslash( $_GET['currency'] ) ) : '';
$order_id    = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : '';
$environment = isset( $_GET['environment'] ) ? sanitize_text_field( wp_unslash( $_GET['environment'] ) ) : '';

$has_filters = ! empty( $_GET['status'] )
	|| ! empty( $_GET['currency'] )
	|| ! empty( $_GET['order_id'] )
	|| ! empty( $_GET['environment'] )
	|| ! empty( $_GET['date_from'] )
	|| ! empty( $_GET['date_to'] );
?>

<ul class="subsubsub">
<?php
$today = current_time( 'Y-m-d' );
$base  = admin_url( 'admin.php?page=payu-payment-links' );

$quick_ranges = [
	'today' => [
		'label' => __( 'Today', 'payu-payment-links' ),
		'from'  => $today,
		'to'    => $today,
	],
	'yesterday' => [
		'label' => __( 'Yesterday', 'payu-payment-links' ),
		'from'  => date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) ),
		'to'    => date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) ),
	],
	'last7' => [
		'label' => __( 'Last 7 Days', 'payu-payment-links' ),
		'from'  => date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ),
		'to'    => $today,
	],
	'month' => [
		'label' => __( 'This Month', 'payu-payment-links' ),
		'from'  => date( 'Y-m-01', strtotime( $today )  ),
		'to'    => $today,
	],
];

$current_from = $_GET['date_from'] ?? $today;
$current_to   = $_GET['date_to'] ?? $today;

$index = 0;
$total = count( $quick_ranges );

foreach ( $quick_ranges as $range ) {

	$url = add_query_arg(
		[
			'date_from' => $range['from'],
			'date_to'   => $range['to'],
		],
		$base
	);

	$class = (
		$current_from === $range['from'] &&
		$current_to === $range['to']
	) ? 'current' : '';

	echo '<li><a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' .
		esc_html( $range['label'] ) .
	'</a></li>';

	// Print separator ONLY if not last item
	if ( ++$index < $total ) {
		echo '<li class="separator"> | </li>';
	}
}
?>
</ul>

<div class="clear"></div>

<!-- Filter controls (same layout as Orders / Posts) -->
<div class="tablenav top">
	<div class="alignleft actions">

		<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />

		<select name="status" id="payu-filter-status" aria-label="<?php esc_attr_e( 'Filter by payment status', 'payu-payment-links' ); ?>">
			<option value=""><?php esc_html_e( 'All statuses', 'payu-payment-links' ); ?></option>
			<?php
			$status_options = [
				'PENDING'        => __( 'Pending', 'payu-payment-links' ),
				'PARTIALLY_PAID' => __( 'Partial paid', 'payu-payment-links' ),
				'PAID'           => __( 'Paid', 'payu-payment-links' )
			];
			foreach ( $status_options as $value => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( $status, $value, false ),
					esc_html( $label )
				);
			}
			?>
		</select>

		<input
			type="text"
			name="currency"
			value="<?php echo esc_attr( $currency ); ?>"
			placeholder="<?php esc_attr_e( 'Currency', 'payu-payment-links' ); ?>"
			style="width:90px;"
		/>

		<input
			type="number"
			name="order_id"
			value="<?php echo esc_attr( $order_id ); ?>"
			placeholder="<?php esc_attr_e( 'Order ID', 'payu-payment-links' ); ?>"
			style="width:120px;"
		/>

		<select name="environment">
			<option value=""><?php esc_html_e( 'All environments', 'payu-payment-links' ); ?></option>
			<option value="uat" <?php selected( $environment, 'uat' ); ?>><?php esc_html_e( 'UAT', 'payu-payment-links' ); ?></option>
			<option value="prod" <?php selected( $environment, 'prod' ); ?>><?php esc_html_e( 'Production', 'payu-payment-links' ); ?></option>
		</select>

		<?php submit_button( __( 'Filter', 'payu-payment-links' ), 'button', 'filter_action', false ); ?>

		<?php if ( $has_filters ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=payu-payment-links' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Reset', 'payu-payment-links' ); ?></a>
		<?php endif; ?>

		<?php
		$export_date_valid = ! empty( $date_from ) && ! empty( $date_to ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to );
		if ( $export_date_valid && class_exists( 'PayU_Payment_Links_Export' ) ) :
			$export_args = array(
				'page'      => 'payu-payment-links',
				'export'    => 'csv',
				'_wpnonce'  => wp_create_nonce( PayU_Payment_Links_Export::get_nonce_action() ),
				'date_from' => $date_from,
				'date_to'   => $date_to,
			);
			if ( '' !== $status ) {
				$export_args['status'] = $status;
			}
			if ( '' !== $currency ) {
				$export_args['currency'] = $currency;
			}
			if ( '' !== $order_id ) {
				$export_args['order_id'] = $order_id;
			}
			if ( '' !== $environment ) {
				$export_args['environment'] = $environment;
			}
			$export_url = add_query_arg( $export_args, admin_url( 'admin.php' ) );
			?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button payu-export-csv-btn"><?php esc_html_e( 'Export CSV', 'payu-payment-links' ); ?></a>
		<?php endif; ?>

	</div>
</div>

<div class="clear"></div>