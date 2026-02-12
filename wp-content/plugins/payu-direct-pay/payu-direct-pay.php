<?php
/*
Plugin Name: PayU Direct Pay + Payment Link (OneAPI) - Clickable Fix
Description: PayU "Pay Now" (Hosted) + OneAPI Payment Link. Enqueues script correctly so buttons are clickable across themes.
Author: Smit | Lucent
Version: 2.1.2
*/

if (! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------
   CONFIG (only change if needed)
--------------------------*/
if ( ! defined( 'PAYU_DIRECT_KEY' ) ) define( 'PAYU_DIRECT_KEY',  'ltxgea' );
if ( ! defined( 'PAYU_DIRECT_SALT' ) ) define( 'PAYU_DIRECT_SALT', 'UiWf8CgzRTetgBrjQ8qcob83uPLJCsGB' );
if ( ! defined( 'PAYU_DIRECT_MODE' ) ) define( 'PAYU_DIRECT_MODE', 'test' );

if ( ! defined( 'PAYU_CLIENT_ID2' ) ) define( 'PAYU_CLIENT_ID2', '04117d50d5ac8e103843dfcefa9de1aa54305ef94e25acc04058b06328e95317' );
if ( ! defined( 'PAYU_CLIENT_SECRET2' ) ) define( 'PAYU_CLIENT_SECRET2', 'f37ec35e438fd284ac699beb2a2c91965f1681ecfb324ccee95fe6967b2bdee0' );
if ( ! defined( 'PAYU_MERCHANT_ID' ) ) define( 'PAYU_MERCHANT_ID', '9121334' );

if ( ! defined( 'PAYU_TOKEN_URL' ) ) define( 'PAYU_TOKEN_URL', 'https://uat-accounts.payu.in/oauth/token' );
if ( ! defined( 'PAYU_PAYMENTLINKS_URL' ) ) define( 'PAYU_PAYMENTLINKS_URL', 'https://uatoneapi.payu.in/payment-links' );

if( ! defined( 'PAYU_RETURN_URL' ) ) define( 'PAYU_RETURN_URL', 'https://gerald-unwelded-griffin.ngrok-free.dev/woocommerce-payu-integration//index.php/payu-success/' );
if( ! defined( 'PAYU_FAILURE_URL' ) ) define( 'PAYU_FAILURE_URL', 'https://gerald-unwelded-griffin.ngrok-free.dev/woocommerce-payu-integration//index.php/payu-failed/' );

/* -------------------------
   Helpers
--------------------------*/
function payu_hosted_action_url() {
    return PAYU_DIRECT_MODE === 'live'
        ? 'https://secure.payu.in/_payment'
        : 'https://test.payu.in/_payment';
}

/* -------------------------
   Token cache
--------------------------*/
function payu_get_access_token() {
    $opt_name = 'payu_oneapi_token_v2';
    $opt = get_option( $opt_name, array() );

    if ( ! empty( $opt['token'] ) && ! empty( $opt['expires_at'] ) && time() < intval( $opt['expires_at'] ) ) {
        return $opt['token'];
    }

    $body = http_build_query( array(
        'grant_type'    => 'client_credentials',
        'client_id'     => PAYU_CLIENT_ID2,
        'client_secret' => PAYU_CLIENT_SECRET2,
        'scope'         => 'create_payment_links',
    ) );

    $args = array(
        'headers' => array(
            'Accept'       => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'body'    => $body,
        'timeout' => 30,
    );

    $resp = wp_remote_post( PAYU_TOKEN_URL, $args );

    if ( is_wp_error( $resp ) ) {
        error_log( '[PayU] Token fetch error: ' . $resp->get_error_message() );
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );

    if ( $code >= 200 && $code < 300 && ! empty( $json['access_token'] ) && ! empty( $json['expires_in'] ) ) {
        $token      = $json['access_token'];
        $expires_in = intval( $json['expires_in'] );
        $expires_at = time() + $expires_in - 60;

        update_option( $opt_name, array(
            'token'      => $token,
            'expires_at' => $expires_at,
            'fetched_at' => time(),
        ), true );

        return $token;
    }

    error_log( '[PayU] Token fetch failed. code=' . $code . ' body=' . $body );
    return false;
}

/* -------------------------
   Enqueue frontend script properly
--------------------------*/
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_product() ) return;

    // register (no file, we'll add inline script); use a handle so we can localize
    wp_register_script( 'payu-direct-pay-script', '', array( 'jquery' ), '2.1.2', true );

    // localize data for script
    global $product;
    $product_price = $product && is_object( $product ) ? floatval( $product->get_price() ) : 0;
    $data = array(
        'rest_url' => esc_url_raw( rest_url( 'payu/v1/create-link' ) ),
        'product_id' => intval( $product ? $product->get_id() : 0 ),
        'product_price' => $product_price,
        'timeout' => 30
    );
    wp_localize_script( 'payu-direct-pay-script', 'PayUDirectData', $data );

    // actual JS — delegated handler and fallback to plain DOM if needed
    $inline_js = <<<JS
(function($){
    // ensure $ is jQuery
    function init() {
        // delegated event so it works on dynamic DOM
        $(document).on('click', '.payu-generate-link-btn', function(e){
            e.preventDefault();
            var \$btn = $(this);
            var restUrl = PayUDirectData.rest_url;
            var productId = \$btn.data('product') || PayUDirectData.product_id;

            // find visible price (some themes show HTML with currency symbol)
            var raw = $('.summary .price, .product .price').first().text() || '';
            var amount = raw.replace(/[^0-9.]/g, '');
            amount = parseFloat(amount);
            if ( isNaN(amount) || amount <= 0 ) {
                amount = parseFloat(PayUDirectData.product_price) || 0;
            }
            if ( amount <= 0 ) {
                alert('Invalid amount - cannot create payment link.');
                return;
            }

            \$btn.prop('disabled', true).attr('aria-busy','true').text('Creating...');
            $.ajax({
                url: restUrl,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    product_id: productId,
                    amount: amount
                }),
                success: function(res) {
                    \$btn.prop('disabled', false).removeAttr('aria-busy').text('Generate Payment Link');
                    if ( res && res.success && res.shorturl ) {
                        window.location.href = res.shorturl;
                    } else {
                        var msg = (res && res.message) ? res.message : 'Failed to generate payment link';
                        if (res && res.raw) console.log('PayU raw:', res.raw);
                        alert(msg);
                    }
                },
                error: function(xhr) {
                    \$btn.prop('disabled', false).removeAttr('aria-busy').text('Generate Payment Link');
                    var err = 'Server error';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) err = xhr.responseJSON.message;
                    alert(err);
                },
                timeout: PayUDirectData.timeout * 1000
            });
        });
    }

    // if jQuery ready, init; else fallback
    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(init);
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})(jQuery);
JS;

    wp_enqueue_script( 'payu-direct-pay-script' );
    wp_add_inline_script( 'payu-direct-pay-script', $inline_js );
}, 11 );

/* -------------------------
  Render buttons (clean markup)
--------------------------*/
add_action( 'woocommerce_after_add_to_cart_button', function() {
    if ( ! is_product() ) return;
    global $product;
    if ( ! $product instanceof WC_Product ) return;

    $product_id = $product->get_id();
    $hosted_url = add_query_arg( array(
        'payu_direct_pay' => 1,
        'product_id' => $product_id
    ), site_url( '/' ) );

    // Buttons - ensure type=button on generated link
    echo '<div class="payu-direct-container" style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;">';
    echo '<a class="payu-hosted-btn" href="' . esc_url( $hosted_url ) . '" style="background:#2e7d32;color:#fff;padding:12px 20px;border-radius:6px;font-weight:700;text-decoration:none;min-width:200px;display:inline-block;text-align:center;">Pay Now with PayU</a>';
    echo '<button type="button" class="payu-generate-link-btn" data-product="' . esc_attr( $product_id ) . '" style="background:#1976D2;color:#fff;padding:12px 20px;border-radius:6px;border:none;font-weight:700;min-width:200px;cursor:pointer;">Generate Payment Link</button>';
    echo '</div>';
});

/* -------------------------
  Hosted checkout redirect handler
--------------------------*/
add_action( 'template_redirect', function() {
    if ( ! isset( $_GET['payu_direct_pay'] ) ) return;

    $product_id   = intval( $_GET['product_id'] ?? 0 );
    $variation_id = intval( $_GET['variation_id'] ?? 0 );
    $custom_amount= isset( $_GET['amount'] ) ? floatval( $_GET['amount'] ) : null;

    $product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
    if ( ! $product ) wp_die( 'Product not found' );

    $amount = $custom_amount ?: $product->get_price();
    $product_name = $product->get_name();

    $txnid = 'TXN' . time() . rand(100,999);
    $surl  = site_url( '/payu-success/' );
    $furl  = site_url( '/payu-failed/' );

    $posted = array(
        'key' => PAYU_DIRECT_KEY,
        'txnid' => $txnid,
        'amount' => $amount,
        'productinfo' => $product_name,
        'firstname' => '',
        'email' => '',
        'phone' => '',
        'surl' => $surl,
        'furl' => $furl,
    );

    $hash_string = PAYU_DIRECT_KEY . '|' . $txnid . '|' . $amount . '|' . $product_name . '|' . '' . '|' . '' . '|||||||||||' . PAYU_DIRECT_SALT;
    $hash = strtolower( hash( 'sha512', $hash_string ) );

    $action = payu_hosted_action_url();

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Redirecting to PayU</title></head><body onload="document.getElementById(\'payuForm\').submit();">';
    echo '<form method="post" action="' . esc_url( $action ) . '" id="payuForm">';
    foreach ( $posted as $k => $v ) {
        echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
    }
    echo '<input type="hidden" name="hash" value="' . esc_attr( $hash ) . '">';
    echo '</form></body></html>';
    exit;
});

/* -------------------------
  Register REST routes
--------------------------*/
add_action( 'rest_api_init', function() {
    register_rest_route( 'payu/v1', '/create-link', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'payu_create_payment_link',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'payu/v1', '/webhook', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'payu_handle_webhook',
        'permission_callback' => '__return_true',
    ) );
} );

/* -------------------------
  Create Payment Link (OneAPI)
--------------------------*/
function payu_create_payment_link( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    $product_id = isset( $params['product_id'] ) ? intval( $params['product_id'] ) : 0;
    $amount     = isset( $params['amount'] ) ? floatval( $params['amount'] ) : 0.0;

    if ( $amount < 1 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Amount must be >= 1' ), 400 );
    }

    $cust_name  = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
    $cust_email = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
    $cust_phone = isset( $params['phone'] ) ? preg_replace('/[^0-9\+]/', '', $params['phone']) : '';

    $description = isset( $params['description'] ) ? sanitize_text_field( $params['description'] ) : '';

    try {
        $order = wc_create_order( array( 'status' => 'pending' ) );
        if ( is_wp_error( $order ) || ! $order ) {
            error_log( '[PayU] Failed creating WC order: ' . print_r( $order, true ) );
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to create WooCommerce order' ), 500 );
        }

        // add product line (if product exists)
        if ( $product_id > 0 ) {
            $prod = wc_get_product( $product_id );
            if ( $prod ) {
                // quantity default to 1 — you can extend to pass quantity from frontend
                $order->add_product( $prod, 1 );
            }
        }

        // set totals and minimal billing (so order is consistent)
        $order->set_total( round( $amount, 2 ) );

        // optionally set minimal billing info if provided
        if ( $cust_name ) {
            // try split first/last
            $parts = explode( ' ', $cust_name, 2 );
            $order->set_billing_first_name( $parts[0] ?? '' );
            $order->set_billing_last_name( $parts[1] ?? '' );
        }
        if ( $cust_email ) {
            $order->set_billing_email( $cust_email );
        }
        if ( $cust_phone ) {
            $order->set_billing_phone( $cust_phone );
        }

        $order->set_currency( 'INR' );
        $order->save();
        $order_id = $order->get_id();

    } catch ( Exception $e ) {
        error_log( '[PayU] Exception creating order: ' . $e->getMessage() );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to create order' ), 500 );
    }

    // --- 3) Prepare PayU Payment Link payload
    // invoiceNumber must be unique - use order ID + timestamp
    $invoiceNumber = 'INV' . $order_id;

    $product_name = '';
    if ( $product_id > 0 ) {
        $pobj = wc_get_product( $product_id );
        $product_name = $pobj ? $pobj->get_name() : '';
    }

    // Use integer for subAmount as per PayU requirement
    $payload = array(
        'merchantId'      => PAYU_MERCHANT_ID,
        'invoiceNumber'   => $invoiceNumber,                     
        'isAmountFilledByCustomer' => false,
        'subAmount'       => intval( round( $amount ) ),         // integer
        'currency' => 'INR',
        'description'     => $description ?: ( $product_name ? 'Payment for '.$product_name : 'WooCommerce Payment' ),
        'source'          => 'API',
        'viaEmail'        => false,
        'viaSms'          => false,
        'isPartialPaymentAllowed' => false,
        'expiryDate'      => '2026-12-12 19:35:08',               
        'successURL'       => PAYU_RETURN_URL,
        'failureURL'      => PAYU_FAILURE_URL,
        'customAttributes'=> array(
            array(
                'attributeType'       => 'input',
                'checked'             => true,
                'required'            => true,
                'customAttributeName' => 'Customer Name',
                'options'             => array()
            ),
            array(
                'attributeType'       => 'input',
                'checked'             => true,
                'required'            => true,
                'customAttributeName' => 'Customer Address',
                'options'             => array()
            ),
            array(
                'attributeType'       => 'input',
                'checked'             => true,
                'required'            => true,
                'customAttributeName' => 'Customer Email',
                'options'             => array()
            ),
            array(
                'attributeType'       => 'input',
                'checked'             => true,
                'required'            => true,
                'customAttributeName' => 'Customer Phone',
                'options'             => array()
            ),
        ),
        "udf" => array(
            'udf1' => 'order_' . $order_id
        ),
        'metadata' => array(
            'order_id'   => strval( $order_id ),
            'product_id' => strval( $product_id ),
        ),
    );

    if ( $cust_name ) {
        // try split
        $names = explode( ' ', $cust_name, 2 );
        $payload['customer']['firstName'] = $names[0] ?? $cust_name;
        $payload['customer']['lastName']  = $names[1] ?? '';
    }
    if ( $cust_email ) {
        $payload['customer']['email'] = $cust_email;
    }
    if ( $cust_phone ) {
        $payload['customer']['phone'] = $cust_phone;
    }

    // optionally send viaEmail or viaSms if email/phone present
    if ( ! empty( $cust_email ) ) {
        $payload['viaEmail'] = true;
        $payload['emailTemplateName'] = ''; // optional
    }
    if ( ! empty( $cust_phone ) ) {
        $payload['viaSms'] = true;
        $payload['smsTemplateName'] = ''; // optional
    }

    // --- 4) Call PayU OneAPI payment-links endpoint
    $token = payu_get_access_token();
    if ( ! $token ) {
        // leave the order as pending, but return error so merchant can retry
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to obtain PayU access token' ), 500 );
    }

    $args = array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'merchantId'    => PAYU_MERCHANT_ID,
            'mid'           => PAYU_MERCHANT_ID // some examples use mid header
        ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 30,
    );

    $resp = wp_remote_post( PAYU_PAYMENTLINKS_URL, $args );

    if ( is_wp_error( $resp ) ) {
        error_log( '[PayU] create link HTTP error: ' . $resp->get_error_message() );
        $order->add_order_note( 'PayU create-link HTTP error: ' . $resp->get_error_message() );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'HTTP Request Failed: ' . $resp->get_error_message() ), 500 );
    }

    $status_code = wp_remote_retrieve_response_code( $resp );
    $resp_body   = wp_remote_retrieve_body( $resp );
    error_log( '[PayU] create link response (' . $status_code . '): ' . $resp_body );

    // decode response safely
    $json = json_decode( $resp_body, true );

    if ( ! is_array( $json ) ) {
        $order->add_order_note( 'PayU create-link: invalid JSON response' );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid response from PayU', 'raw' => $resp_body ), 500 );
    }

    $payu_status  = isset( $json['status'] ) ? intval( $json['status'] ) : null;
    $payu_message = isset( $json['message'] ) ? sanitize_text_field( $json['message'] ) : '';

    if ( $payu_status === 0 && ! empty( $json['result']['paymentLink'] ) ) {
        $paymentLink = $json['result']['paymentLink'];
        $invoice     = $json['result']['invoiceNumber'] ?? $invoiceNumber;

        update_post_meta( $order_id, '_payu_payment_link', $paymentLink );
        update_post_meta( $order_id, '_payu_invoice_number', $invoice );
        update_post_meta( $order_id, '_payu_payload', $json ); 

        $order->add_order_note( 'PayU payment link generated: ' . $paymentLink . ' (invoice: ' . $invoice . ')' );

        update_post_meta( $order_id, '_payu_link_status', 'active' );
        
        return new WP_REST_Response( array(
            'success'  => true,
            'shorturl' => $paymentLink,
            'order_id' => $order_id,
            'invoice'  => $invoice,
            'raw'      => $json
        ), 200 );

    }

    // Failure scenario (status !== 0) — use message if present
    $error_message = $payu_message ? $payu_message : 'Unknown error from PayU';
    // Log and add order note
    $order->add_order_note( 'PayU create link FAILED. Status: ' . var_export( $payu_status, true ) . ' Message: ' . $error_message );
    error_log( '[PayU] create link failed: ' . print_r( $json, true ) );

    // Map known error codes or send payu message directly
    return new WP_REST_Response( array(
        'success' => false,
        'message' => 'PayU error: ' . $error_message,
        'raw'     => $json
    ), 400 );
}


/* -------------------------
  Webhook handler (placeholder)
--------------------------*/
function payu_handle_webhook( WP_REST_Request $request ) {

    // ------------------------------
    // 1. Capture incoming payload
    // ------------------------------
    $data = $request->get_params();
    error_log('[PayU Webhook] Received: ' . json_encode($data));

    $order_id = 0;

    if (!empty($data['udf1']) && strpos($data['udf1'], 'order_') === 0) {
        $order_id = intval(str_replace('order_', '', $data['udf1']));
    }

    if (!$order_id) {
        error_log('[PayU] Missing order ID. udf1=' . ($data['udf1'] ?? 'null'));
        return new WP_REST_Response([
            'ok' => false,
            'msg' => 'Order ID missing'
        ], 200);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('[PayU] Order not found: #' . $order_id);
        return new WP_REST_Response(['ok' => false], 200);
    }

    // ------------------------------
    // 4. Validation (amount & PayU status)
    // ------------------------------
    $order_total = floatval($order->get_total());
    $payu_amount = floatval($data['amount']);
    $status      = strtolower($data['status']);
    $unmapped    = strtolower($data['unmappedstatus'] ?? '');

    if ($order_total != $payu_amount || $status !== 'success' || $unmapped !== 'captured') {

        $order->add_order_note(
            'PayU Webhook mismatch: amount/status invalid. ' .
            "Order Total: $order_total, PayU: $payu_amount, Status: $status, Unmapped: $unmapped"
        );

        error_log("[PayU] Mismatch for Order #$order_id : total/status mismatch");

        return new WP_REST_Response(['ok' => true], 200); // We still acknowledge
    }

    // ------------------------------
    // 5. Mark order as PAID
    // ------------------------------
    if (!in_array($order->get_status(), ['processing', 'completed'])) {

        $order->payment_complete($data['mihpayid']);

        $order->add_order_note(
            'PayU Payment Success:' .
            "\nMihpayid: {$data['mihpayid']}" .
            "\nTxnid: {$data['txnid']}" .
            "\nBank Ref: {$data['bank_ref_no']}"
        );
    }

    // ------------------------------
    // 6. Save metadata for admin panel
    // ------------------------------
    update_post_meta($order_id, '_payu_mihpayid', $data['mihpayid']);
    update_post_meta($order_id, '_payu_txnid',    $data['txnid']);
    update_post_meta($order_id, '_payu_status',   $data['status']);
    update_post_meta($order_id, '_payu_unmapped', $data['unmappedstatus']);
    update_post_meta($order_id, '_payu_amount',   $data['amount']);
    update_post_meta($order_id, '_payu_bankref',  $data['bank_ref_no']);
    update_post_meta($order_id, '_payu_udf1',     $data['udf1']);

    // ------------------------------
    // 7. Send OK response to PayU
    // ------------------------------
    return new WP_REST_Response(['ok' => true], 200);
}

