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
		$date_from = esc_attr( $_GET['date_from'] ?? $today );
		$date_to   = esc_attr( $_GET['date_to'] ?? $today );
}

$status      = esc_attr( $_GET['status'] ?? '' );
$currency    = esc_attr( $_GET['currency'] ?? '' );
$order_id    = esc_attr( $_GET['order_id'] ?? '' );
$environment = esc_attr( $_GET['environment'] ?? '' );
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

		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'payu-payment-links' ); ?></option>
			<option value="PENDING" <?php selected( $status, 'PENDING' ); ?>>PENDING</option>
			<option value="PAID" <?php selected( $status, 'PAID' ); ?>>PAID</option>
			<option value="FAILED" <?php selected( $status, 'FAILED' ); ?>>FAILED</option>
			<option value="EXPIRED" <?php selected( $status, 'EXPIRED' ); ?>>EXPIRED</option>
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
			<option value="UAT" <?php selected( $environment, 'UAT' ); ?>>UAT</option>
			<option value="PRODUCTION" <?php selected( $environment, 'PRODUCTION' ); ?>>Production</option>
		</select>

		<?php submit_button( __( 'Filter', 'payu-payment-links' ), 'button', 'filter_action', false ); ?>

	</div>
</div>

<div class="clear"></div>