<?php
defined( 'ABSPATH' ) || exit;

require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/admin/class-payu-payment-links-list-table.php';

$table = new PayU_Payment_Links_List_Table();
$table->prepare_items();
?>

<div class="wrap woocommerce">
    <?php
    $export_notice = get_transient( 'payu_export_notice' );
    if ( is_array( $export_notice ) && ! empty( $export_notice['message'] ) ) {
        $notice_type = ( isset( $export_notice['type'] ) && 'success' === $export_notice['type'] ) ? 'success' : 'error';
        echo '<div class="notice notice-' . esc_attr( $notice_type ) . ' is-dismissible"><p>' . esc_html( $export_notice['message'] ) . '</p></div>';
        delete_transient( 'payu_export_notice' );
    }
    ?>
    <h1 class="wp-heading-inline">
        <?php esc_html_e( 'Payment Links', 'payu-payment-links' ); ?>
    </h1>

    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="payu-payment-links" />

        <?php
        // Filters UI (inputs MUST be inside form)
        require PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/views/payment-links-filters.php';

        // Table
        $table->display();
        ?>
    </form>

    <!-- Expiry date modal (Expire / Set Expiry action) -->
    <div id="payu-expiry-modal" class="payu-modal" role="dialog" aria-labelledby="payu-expiry-modal-title" aria-modal="true" hidden>
        <div class="payu-modal-backdrop"></div>
        <div class="payu-modal-content">
            <h2 id="payu-expiry-modal-title" class="payu-modal-title"><?php esc_html_e( 'Set payment link expiry', 'payu-payment-links' ); ?></h2>
            <p class="payu-modal-description"><?php esc_html_e( 'Choose the new expiry date and time (24-hour format). Past date-time is not allowed.', 'payu-payment-links' ); ?></p>
            <p class="payu-expiry-modal-error payu-modal-error" role="alert" aria-live="polite" style="display:none;"></p>
            <p class="payu-modal-field">
                <label for="payu-expiry-datetime"><?php esc_html_e( 'Expiry date & time', 'payu-payment-links' ); ?></label>
                <input type="datetime-local" id="payu-expiry-datetime" step="60" min="" class="regular-text" required />
            </p>
            <p class="payu-modal-actions">
                <button type="button" id="payu-expiry-confirm" class="button button-primary"><?php esc_html_e( 'Confirm', 'payu-payment-links' ); ?></button>
                <button type="button" id="payu-expiry-cancel" class="button"><?php esc_html_e( 'Cancel', 'payu-payment-links' ); ?></button>
                <span class="payu-expiry-spinner is-hidden" aria-hidden="true"></span>
            </p>
        </div>
    </div>

    <?php
    if ( class_exists( 'PayU_Payment_Links_Modals' ) ) {
        PayU_Payment_Links_Modals::render_resend_modal();
    }
    ?>
</div>