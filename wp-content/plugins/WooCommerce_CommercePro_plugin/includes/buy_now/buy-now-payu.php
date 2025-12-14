<?php
/**
* Buy Now functionality Code File Added By (SM)
*/

if (!defined('ABSPATH')) {
    exit;//Exit if accessed directly
}

// Fetch PayU settings
$payu_settings = get_option('woocommerce_payubiz_settings');
$enable_buy_now = isset($payu_settings['enable_buy_now']) && sanitize_text_field($payu_settings['enable_buy_now']) === 'yes';
$enable_buy_now_on_product_page = isset($payu_settings['enable_buy_now_on_product_page']) && sanitize_text_field($payu_settings['enable_buy_now_on_product_page']) === 'yes';
$enable_buy_now_on_shop_page = isset($payu_settings['enable_buy_now_on_shop_page']) && sanitize_text_field($payu_settings['enable_buy_now_on_shop_page']) === 'yes';
$button_bg_color   = isset( $payu_settings['button_background_color'] ) ? sanitize_hex_color($payu_settings['button_background_color']) : '#007BFF';
$button_text_color = isset( $payu_settings['button_text_color'] ) ? sanitize_hex_color($payu_settings['button_text_color']) : '#FFFFFF';
$button_border_radius = isset($payu_settings['button_border_radius']) ? intval($payu_settings['button_border_radius']) : 6;
$button_hover_color = isset( $payu_settings['button_hover_color'] ) ? sanitize_hex_color($payu_settings['button_hover_color']) : '#0056b3';

add_action('admin_footer', function() {
    if (isset($_GET['page']) && sanitize_text_field($_GET['page']) === 'wc-settings' && isset($_GET['tab']) && sanitize_text_field($_GET['tab']) === 'checkout' && isset($_GET['section']) && sanitize_text_field($_GET['section']) === 'payubiz') {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function toggleBuyNowCheckbox() {
                    var checkoutExperience = $('select[name="woocommerce_payubiz_checkout_express"]');
                    var buyNowRow = $('#woocommerce_payubiz_enable_buy_now').closest('tr');
                    var buyNowSettings = $('.payu-buy-now-settings').closest('tr');

                    if (checkoutExperience.val() === 'checkout_express') {
                        buyNowRow.show();
                        toggleBuyNowSettings(); // Buy Now ka checkbox dikhega to settings bhi dikhao/hide karo
                    } else {
                        buyNowRow.hide();
                        buyNowSettings.hide(); // Buy Now UI settings bhi hide ho jaye
                    }
                }

                function toggleBuyNowSettings() {
                    var buyNowSettings = $('.payu-buy-now-settings').closest('tr');

                    if ($('#woocommerce_payubiz_enable_buy_now').is(':checked')) {
                        buyNowSettings.show();
                    } else {
                        buyNowSettings.hide();
                    }
                }

                // Initial Checks on Page Load
                toggleBuyNowCheckbox();

                // Event Listeners
                $('select[name="woocommerce_payubiz_checkout_express"]').change(toggleBuyNowCheckbox);
                $('#woocommerce_payubiz_enable_buy_now').change(toggleBuyNowSettings);
            });
        </script>
        <?php
    }
});

if ($enable_buy_now &&
isset($payu_settings['checkout_express']) && sanitize_text_field($payu_settings['checkout_express'])==="checkout_express") {
    // Get current user billing address dynamically.
    function get_current_user_billing_address() {
        $current_user = wp_get_current_user();
        if ( $current_user->ID ) {
            $address = array(
                'first_name' => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_first_name', true )),
                'last_name'  => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_last_name', true )),
                'company'    => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_company', true )),
                'email'      => sanitize_email($current_user->user_email),
                'phone'      => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_phone', true )),
                'address_1'  => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_address_1', true )),
                'address_2'  => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_address_2', true )),
                'city'       => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_city', true )),
                'state'      => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_state', true )),
                'postcode'   => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_postcode', true )),
                'country'    => sanitize_text_field(get_user_meta( $current_user->ID, 'billing_country', true )),
            );
            return $address;
        } else {
            // Default values for guest user.
            return array(
                'first_name' => sanitize_text_field('Test'),
                'last_name'  => sanitize_text_field('Test'),
                'company'    => '',
                'email'      => sanitize_email('test@payu.in'),
                'phone'      => sanitize_text_field('9876543210'),
                'address_1'  => sanitize_text_field('address'),
                'address_2'  => '',
                'city'       => sanitize_text_field('Noida'),
                'state'      => sanitize_text_field('UP'),
                'postcode'   => sanitize_text_field('201301'),
                'country'    => sanitize_text_field('IN'),
            );
        }
    }

    add_action('woocommerce_after_add_to_cart_button', 'custom_button_after_add_to_cart');
    function custom_button_after_add_to_cart() {

        global $product,$button_bg_color, $button_text_color, $button_border_radius, $button_hover_color;
        // echo '<pre>';
        // print_r($product);
        if ( ! $product ) {
            return;
        }
        ?>
        <style>
            .buy-now-btn {
                background: <?php echo esc_attr( $button_bg_color ); ?>;
                color: <?php echo esc_attr( $button_text_color ); ?>;
                border: none;
                padding: 12px 24px;
                border-radius: <?php echo esc_attr( $button_border_radius ); ?>px;
                text-decoration: none;
                transition: background 0.3s ease;
                width: 35%;
                margin: 5px 0 0 0;
            }
            .buy-now-btn:hover {
                background: <?php echo esc_attr( $button_hover_color ); ?>;
            }
        </style>
        <a href="javascript:void(0);" class="buy-now-btn button" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">Buy Now with PayU</a>
        <div class="buy-now-loader" style="display:none;">
            <img src="<?php echo esc_url( plugins_url( 'images/Loading.gif', __FILE__ )); ?>" alt="Loading..." />
        </div>
        <?php
        
    }

    // Enqueue Script for Buy Now button.
    function enqueue_buy_now_script() {
        wp_enqueue_script(
            'buy-now-js',
            plugin_dir_url(__FILE__) . 'buy-now.js',
            array('jquery'),
            null,
            true
        );
        wp_localize_script('buy-now-js', 'cbn_ajax_object', array(
            'ajax_url'       => WC()->ajax_url(),  // WooCommerce AJAX URL.
            'checkout_nonce' => wp_create_nonce('woocommerce-process_checkout')
        ));
    }
    add_action('wp_enqueue_scripts', 'enqueue_buy_now_script');

    // AJAX handler for Buy Now functionality (Product add & Cart restore logic)
    add_action('wp_ajax_custom_buy_now', 'custom_buy_now_function');
    add_action('wp_ajax_nopriv_custom_buy_now', 'custom_buy_now_function');
    function custom_buy_now_function() {
        if ( ! isset($_POST['product_id']) ) {
            wp_send_json_error(array('message' => 'Invalid Product'));
        }
        $product_id = intval($_POST['product_id']);
        $quantity   = isset( $_POST['product_quantity'] ) ? intval( $_POST['product_quantity'] ) : 1; // Default quantity.

        // Save current cart items in session if not already saved.
        if ( ! WC()->session->get('previous_cart') ) {
            WC()->session->set('previous_cart', WC()->cart->get_cart());
        }

        // Create a new order.
        $order = wc_create_order();

        // Clear current cart.
        WC()->cart->empty_cart();

        // Get product object.
        $product = wc_get_product($product_id);
        if ( ! $product ) {
            wp_send_json_error(array('message' => 'Product not found.'));
        }

        // Check product type and add accordingly.
        $product_type = $product->get_type();

        if ( $product_type === 'variable' ) {
            if ( ! isset($_POST['variation_id']) ) {
                wp_send_json_error(array('message' => 'Variation not selected.'));
            }
            $variation_id = intval($_POST['variation_id']);
            $variation  = wc_get_product($variation_id);
            if ( ! $variation ) {
                wp_send_json_error(array('message' => 'Variation not found.'));
            }
            
            // $variation_data = isset($_POST['variation']) ? (array) $_POST['variation'] : array();
            // Sanitize variation attributes
            $variation_data = array();
            if ( ! empty( $_POST['variation'] ) && is_array( $_POST['variation'] ) ) {
                foreach ( $_POST['variation'] as $attr => $val ) {
                    $variation_data[ sanitize_text_field( $attr ) ] = sanitize_text_field( $val );
                }
            }
            $added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_data);
            if ( ! $added ) {
                wp_send_json_error(array('message' => 'Variation could not be added to cart.'));
            }
            $order->add_product($variation, $quantity, array('variation' => $variation_data));
        } elseif ( $product_type === 'grouped' ) {
            // For grouped products, add each child product.
            $child_ids = $product->get_children();
            if ( empty($child_ids) ) {
                wp_send_json_error(array('message' => 'No child products found for grouped product.'));
            }
            foreach ( $child_ids as $child_id ) {
                // Here, quantity for each child can be provided individually via POST data
                // Example: $_POST['grouped_quantities'][child_id]
                $child_quantity = isset($_POST['grouped_quantities'][$child_id]) ? intval($_POST['grouped_quantities'][$child_id]) : $quantity;
                if ( $child_quantity > 0 ) {
                    $child_product = wc_get_product($child_id);
                    if ( $child_product ) {
                        $added = WC()->cart->add_to_cart($child_id, $child_quantity);
                        if ( $added ) {
                            $order->add_product($child_product, $child_quantity);
                        }
                    }
                }
            }
        } else {
            // For simple and other product types.
            $added = WC()->cart->add_to_cart($product_id, $quantity);
            if ( ! $added ) {
                wp_send_json_error(array('message' => 'Product could not be added to cart.'));
            }
            $order->add_product($product, $quantity);
        }
        
        // Set dynamic billing & shipping details.
        $address = get_current_user_billing_address();
        $order->set_address( $address, 'billing' );
        $order->set_address( $address, 'shipping' );
        
        // Set payment method (ensure yeh aapke gateway ID se match karta hai)
        $order->set_payment_method('payubiz');
        
        // Calculate totals & update order status if required.
        $order->calculate_totals();
        $order->update_status('pending');

        // Get order details.
        $order_id  = $order->get_id();
        $order_key = $order->get_order_key();

        // Get checkout base URL from WooCommerce.
        $checkout_url = wc_get_checkout_url(); // e.g. https://example.com/checkout/
        $checkout_url = untrailingslashit($checkout_url);

        // Generate direct order pay URL.
        // $redirect_url = $checkout_url . '/order-pay/' . $order_id . '/?key=' . $order_key . '&order=' . $order_id;
        $redirect_url = "{$checkout_url}/order-pay/{$order_id}/?key={$order_key}&order={$order_id}";

        // Prepare checkout data for PayU (example hard-coded data; adjust as needed).
        $checkout_data = array(
            'billing_alt'                 => 0,
            'billing_first_name'          => isset($address['first_name']) ? sanitize_text_field($address['first_name']) : 'test',
            'billing_last_name'           => isset($address['last_name']) ? sanitize_text_field($address['last_name']) : 'test',
            'billing_company'             => isset($address['company']) ? sanitize_text_field($address['company']) : 'Example Company',
            'billing_country'             => isset($address['country']) ? sanitize_text_field($address['country']) : 'IN',
            'billing_address_1'           => isset($address['address_1']) ? sanitize_text_field($address['address_1']) : '123 Example Street',
            'billing_address_2'           => isset($address['address_2']) ? sanitize_text_field($address['address_2']) : '',
            'billing_city'                => isset($address['city']) ? sanitize_text_field($address['city']) : 'Noida',
            'billing_state'               => isset($address['state']) ? sanitize_text_field($address['state']) : 'UP',
            'billing_postcode'            => isset($address['postcode']) ? sanitize_text_field($address['postcode']) : '201301',
            'billing_phone'               => isset($address['phone']) ? sanitize_text_field($address['phone']) : '9876543210',
            'billing_email'               => isset($address['email']) ? sanitize_email($address['email']) : 'test@example.com',
            // Shipping data.
            'shipping_first_name'         => isset($address['first_name']) ? sanitize_text_field($address['first_name']) : 'test',
            'shipping_last_name'          => isset($address['last_name']) ? sanitize_text_field($address['last_name']) : 'test',
            'shipping_company'            => isset($address['company']) ? sanitize_text_field($address['company']) : 'Example Company',
            'shipping_country'            => isset($address['country']) ? sanitize_text_field($address['country']) : 'IN',
            'shipping_address_1'          => isset($address['address_1']) ? sanitize_text_field($address['address_1']) : '123 Example Street',
            'shipping_address_2'          => isset($address['address_2']) ? sanitize_text_field($address['address_2']) : '',
            'shipping_phone'              => isset($address['phone']) ? sanitize_text_field($address['phone']) : '9876543210',
            'shipping_city'               => isset($address['city']) ? sanitize_text_field($address['city']) : 'Noida',
            'shipping_state'              => isset($address['state']) ? sanitize_text_field($address['state']) : 'UP',
            'shipping_postcode'           => isset($address['postcode']) ? sanitize_text_field($address['postcode']) : '201301',
            'shipping_email'              => isset($address['email']) ? sanitize_email($address['email']) : 'test@example.com',
            'ship_to_order_comments'      => '',
            'ship_to_different_address'   => 1,
            'order_comments'              => '',
            'payment_method'              => 'payubiz',
            '_wp_http_referer'            => esc_url_raw('/?wc-ajax=update_order_review'),
            'woocommerce-process-checkout-nonce' => wp_create_nonce('woocommerce-process-checkout'),
        );

        // Debugging logs (optional)
        error_log('Cart Contents: ' . print_r(WC()->cart->get_cart(), true));
        error_log('Redirect URL: ' . $redirect_url);
        error_log('Checkout Data: ' . print_r($checkout_data, true));

        // Response: checkout URL and checkout data.
        $response = array(
            'redirect_url'  => esc_url($redirect_url),
            'checkout_data' => $checkout_data
        );
        wp_send_json_success($response);
    }


    // Restore previous cart if checkout is abandoned
    add_action('wp', 'restore_previous_cart_if_needed');
    function restore_previous_cart_if_needed() {

        // Ensure WooCommerce functions are available
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return; // Exit early if WooCommerce or session isn't available
        }
        if ( is_cart() || is_checkout() ) {
            return; // Do not restore on Cart or Checkout page.
        }
        $previous_cart = WC()->session->get('previous_cart');
        if ( $previous_cart ) {
            WC()->cart->empty_cart();
            foreach ( $previous_cart as $cart_item_key => $values ) {
                WC()->cart->add_to_cart($values['product_id'], $values['quantity']);
            }
            WC()->session->__unset('previous_cart');
        }
    }
}