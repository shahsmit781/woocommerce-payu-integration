<?php
/**
 * Added by SM
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Fetch PayU settings
$payu_settings = get_option('woocommerce_payubiz_settings');
$enable_affordability_widget = isset($payu_settings['enable_affordability_widget']) && sanitize_text_field($payu_settings['enable_affordability_widget']) === 'yes';
$enable_affordability_widget_on_product_page = isset($payu_settings['enable_affordability_widget_on_product_page']) && sanitize_text_field($payu_settings['enable_affordability_widget_on_product_page']) === 'yes';
$enable_affordability_widget_on_cart_page = isset($payu_settings['enable_affordability_widget_on_cart_page']) && sanitize_text_field($payu_settings['enable_affordability_widget_on_cart_page']) === 'yes';
$enable_affordability_widget_on_checkout_page = isset($payu_settings['enable_affordability_widget_on_checkout_page']) && sanitize_text_field($payu_settings['enable_affordability_widget_on_checkout_page']) === 'yes';

add_action('admin_footer', function() {
    if (isset($_GET['page']) && sanitize_text_field($_GET['page']) === 'wc-settings' && isset($_GET['tab']) && sanitize_text_field($_GET['tab']) === 'checkout' && isset($_GET['section']) && sanitize_text_field($_GET['section']) === 'payubiz') {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function toggleAffordabilitySettings() {
                    if ($('#woocommerce_payubiz_enable_affordability_widget').is(':checked')) {
                        $('.payu-affordability-settings').closest('tr').show();
                    } else {
                        $('.payu-affordability-settings').closest('tr').hide();
                    }
                }
                
                // Initial Check on Load
                toggleAffordabilitySettings();
                
                // Toggle on Checkbox Change
                $('#woocommerce_payubiz_enable_affordability_widget').change(function() {
                    toggleAffordabilitySettings();
                });
            });
        </script>
        <?php
    }
});


if ($enable_affordability_widget) {
    function custom_scripts()
    {
        global $product, $woocommerce;

        error_log("Debug: Entered custom_scripts function");

        // Initialize variables
        $amount = 0;
        $skusDetail = [];
        $user_mobile = '';

        // Get user mobile if logged in
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $user_mobile_raw = get_user_meta($current_user->ID, 'billing_phone', true);
            $user_mobile = sanitize_text_field($user_mobile_raw);
            error_log("Debug: User mobile: " . $user_mobile);
        }

        // Fetch PayU settings
        $payu_settings = get_option('woocommerce_payubiz_settings', []);
        $lightColor = isset($payu_settings['lightColor']) ? sanitize_hex_color($payu_settings['lightColor']) : '#FFFCF3';
        $darkColor = isset($payu_settings['darkColor']) ? sanitize_hex_color($payu_settings['darkColor']) : '#FFC915';
        $backgroundColor = isset($payu_settings['backgroundColor']) ? sanitize_hex_color($payu_settings['backgroundColor']) : '#FFFFFF';
        $payu_key = isset($payu_settings['currency1_payu_key']) ? sanitize_text_field($payu_settings['currency1_payu_key']) : '';
        error_log("Debug: PayU key: " . $payu_key);

        // Check if the widget should be enabled
        $enable_widget_product = isset($GLOBALS['enable_affordability_widget_on_product_page']) ? (bool)$GLOBALS['enable_affordability_widget_on_product_page'] : false;
        $enable_widget_cart = isset($GLOBALS['enable_affordability_widget_on_cart_page']) ? (bool)$GLOBALS['enable_affordability_widget_on_cart_page'] : false;
        $enable_widget_checkout = isset($GLOBALS['enable_affordability_widget_on_checkout_page']) ? (bool)$GLOBALS['enable_affordability_widget_on_checkout_page'] : false;

        // Check if we are on a product page
        if (is_product() && $enable_widget_product) {
            // Ensure $product is a WC_Product object
            if (!($product instanceof WC_Product)) {
                $product_id = get_the_ID(); // Get the current product ID
                $product = wc_get_product($product_id); // Retrieve the product object
                error_log("Debug: Fetched product using wc_get_product, ID " . $product_id);
            }

            // Check if $product is a valid WC_Product
            if ($product instanceof WC_Product) {
                $amount = floatval($product->get_price());
                $skusDetail[] = [
                    'skuId' => strval(absint($product->get_id())),
                    'skuAmount' => $amount,
                    'quantity' => 1
                ];
                error_log("Debug: Product price set: " . $amount);
            } else {
                error_log("Error: Product is not an instance of WC_Product");
            }
        } elseif ((is_cart() && $enable_widget_cart) || (is_checkout() && $enable_widget_checkout)) {
            // Calculate total amount for cart or checkout
            if (isset($woocommerce->cart)) {
                foreach ($woocommerce->cart->get_cart() as $cart_item) {
                    if (isset($cart_item['data'])) {
                        $product_data = $cart_item['data'];
                        $product_price = floatval($product_data->get_price());
                        $quantity = intval($cart_item['quantity']);
                        $amount += $product_price * $quantity;
                        $skusDetail[] = [
                            'skuId' => strval($product_data->get_id()),
                            'skuAmount' => $product_price,
                            'quantity' => $quantity
                        ];
                    }
                }
                error_log("Debug: Calculated cart amount: " . $amount);
            }
        }

        // Enqueue PayU SDK
        wp_enqueue_script('payu-affordability-widget', 'https://jssdk.payu.in/widget/affordability-widget.min.js', array('jquery'), '1.0', true);
        wp_enqueue_script('custom-payu-widget', plugin_dir_url(__FILE__) . 'payu-affordability-widget.js', array('jquery'), '1.0', true);

        // Prepare product info for JavaScript
        if (is_product()) {
            $productinfo = ($product instanceof WC_Product) ? wp_strip_all_tags($product->get_name()) : 'WooCommerce Product';
        } else {
            $productinfo = 'WooCommerce Order';
        }

        // Pass PHP data to JavaScript
        wp_localize_script('custom-payu-widget', 'payuData', array(
            'amount'      => $amount,
            'key'         => $payu_key,
            'productinfo' => $productinfo,
            'skusDetail'  => json_decode(json_encode($skusDetail), true),
            'lightColor'  => $lightColor,
            'darkColor'   => $darkColor,
            'backgroundColor' => $backgroundColor,
            'mobileNumber' => $user_mobile,
            'token'        => '',
            'timeStamp'    => time()
        ));
    }
    
    add_action('wp_enqueue_scripts', 'custom_scripts');

    function display_payu_affordability_widget() {
        if ((is_product() && !empty($GLOBALS['enable_affordability_widget_on_product_page'])) ||
            (is_cart() && !empty($GLOBALS['enable_affordability_widget_on_cart_page'])) ||
            (is_checkout() && !empty($GLOBALS['enable_affordability_widget_on_checkout_page']))) {
            echo '<div id="payuWidget"></div>';
        }
    }

    add_action('woocommerce_before_add_to_cart_form', 'display_payu_affordability_widget');
    add_action('woocommerce_before_cart_table', 'display_payu_affordability_widget');
    add_action('woocommerce_review_order_before_payment', 'display_payu_affordability_widget');
    add_action('woocommerce_blocks_loaded', 'display_payu_affordability_widget');

}
