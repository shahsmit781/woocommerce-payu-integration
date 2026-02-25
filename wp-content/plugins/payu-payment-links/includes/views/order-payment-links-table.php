<?php
/**
 * PayU Payment Links – Order edit page table view (read-only).
 *
 * Renders HTML table only. Expects $links (array of objects from repository) and $empty_message (string).
 * No business logic. No API calls.
 *
 * @package PayU_Payment_Links
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $links ) || ! is_array( $links ) ) {
	$links = array();
}
if ( ! isset( $empty_message ) || ! is_string( $empty_message ) ) {
	$empty_message = __( 'No PayU payment links created for this order.', 'payu-payment-links' );
}

$format_amount = function ( $amount, $currency = '' ) {
	if ( $amount === null || $amount === '' ) {
		return '—';
	}
	$num = (float) $amount;
	return number_format( $num, 2 );
};

if ( empty( $links ) ) {
	echo '<p class="payu-order-links-empty">' . esc_html( $empty_message ) . '</p>';
	return;
}

?>
<table class="widefat striped payu-order-links-table" cellspacing="0">
	<thead>
		<tr>
			<th class="column-invoice" scope="col"><?php esc_html_e( 'Invoice Number', 'payu-payment-links' ); ?></th>
			<th class="column-payment-link" scope="col"><?php esc_html_e( 'PayU Payment Link', 'payu-payment-links' ); ?></th>
			<th class="column-link-status" scope="col"><?php esc_html_e( 'Payment Link Status', 'payu-payment-links' ); ?></th>
			<th class="column-payment-status" scope="col"><?php esc_html_e( 'Payment Status', 'payu-payment-links' ); ?></th>
			<th class="column-currency" scope="col"><?php esc_html_e( 'Currency', 'payu-payment-links' ); ?></th>
			<th class="column-total" scope="col"><?php esc_html_e( 'Total Amount', 'payu-payment-links' ); ?></th>
			<th class="column-paid" scope="col"><?php esc_html_e( 'Paid Amount', 'payu-payment-links' ); ?></th>
			<th class="column-remaining" scope="col"><?php esc_html_e( 'Remaining Amount', 'payu-payment-links' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $links as $link ) : ?>
			<?php
			$currency = isset( $link->currency ) ? $link->currency : '';
			$invoice_number = isset( $link->payu_invoice_number ) ? $link->payu_invoice_number : '';
			$invoice_display = $invoice_number ? $invoice_number : '—';
			$invoice_cell = esc_html( $invoice_display );
			if ( $invoice_number && class_exists( 'PayU_Payment_Link_Status_Page' ) ) {
				$status_url = PayU_Payment_Link_Status_Page::get_status_url( $invoice_number );
				$invoice_cell = '<a href="' . esc_url( $status_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $invoice_display ) . '</a>';
			}
			?>
			<tr>
				<td class="column-invoice"><?php echo wp_kses_post( $invoice_cell ); ?></td>
				<td class="column-payment-link"><?php
					$payment_link_url = isset( $link->payment_link_url ) ? trim( (string) $link->payment_link_url ) : '';
					if ( $payment_link_url !== '' ) {
						$safe_url = esc_url( $payment_link_url );
						echo $safe_url !== '' ? '<a href="' . $safe_url . '" target="_blank" rel="noopener noreferrer" class="payu-payment-link-url">' . esc_html( $payment_link_url ) . '</a>' : esc_html( $payment_link_url );
					} else {
						echo '—';
					}
				?></td>
				<td class="column-link-status"><?php echo esc_html( isset( $link->payment_link_status ) && $link->payment_link_status !== '' ? $link->payment_link_status : '—' ); ?></td>
				<td class="column-payment-status"><?php echo esc_html( isset( $link->status ) ? $link->status : '—' ); ?></td>
				<td class="column-currency"><?php echo esc_html( $currency ? $currency : '—' ); ?></td>
				<td class="column-total"><?php echo wp_kses_post( $format_amount( isset( $link->amount ) ? $link->amount : null, $currency ) ); ?></td>
				<td class="column-paid"><?php echo wp_kses_post( $format_amount( isset( $link->paid_amount ) ? $link->paid_amount : null, $currency ) ); ?></td>
				<td class="column-remaining"><?php echo wp_kses_post( $format_amount( isset( $link->remaining_amount ) ? $link->remaining_amount : null, $currency ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
