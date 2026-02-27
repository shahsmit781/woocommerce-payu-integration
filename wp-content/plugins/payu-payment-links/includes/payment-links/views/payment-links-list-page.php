<?php
defined( 'ABSPATH' ) || exit;

require_once PAYU_PAYMENT_LINKS_PLUGIN_DIR . 'includes/payment-links/admin/class-payu-payment-links-list-table.php';

$table = new PayU_Payment_Links_List_Table();
$table->prepare_items();
?>

<div class="wrap woocommerce">
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
</div>