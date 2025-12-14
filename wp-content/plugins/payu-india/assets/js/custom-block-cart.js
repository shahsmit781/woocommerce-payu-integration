jQuery(document).ready(function ($) {
    function replaceCartButton() {
        let checkoutButton = $(".wc-block-cart__submit-button");
        // console.log(checkoutButton);
        // console.log("================================");
        // if(checkoutButton.length){
        //     alert('yes length is founded');
        // }

        if (checkoutButton.length && !$(".payu-checkout").length) {
            checkoutButton.hide(); 
 
            // Add new Buy Now with payu button
            checkoutButton.after(
                '<a href="javascript:void(0);" class="wc-block-components-button wp-element-button checkout-button payu-checkout button alt wc-forward" style="width: 82%;">Buy Now with PayU</a>'
            );
 
            // PayU checkout function call
            triggerPayUCheckout();
        }
    }
 
    function triggerPayUCheckout() {
        jQuery(document).on('click', '.payu-checkout', function () {
            var data = {
                billing_alt: 0,
                payment_method: 'payubiz',
                _wp_http_referer: '/?wc-ajax=update_order_review',
                'woocommerce-process-checkout-nonce': wc_checkout_params.checkout_nonce,
            };
 
            console.log(data);
            jQuery.ajax({
                type: 'POST',
                url: '?wc-ajax=checkout',
                data: data,
                success: function (response) {
                    console.log(response);
                    if (response.result == 'success') {
                        window.location = response.redirect;
                    }
                },
            });
        });
    }
 
    // MutationObserver  page change detect 
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            replaceCartButton();
        });
    });
 
    // Cart container observe
    const cartContainer = document.querySelector(".wc-block-cart");
    if (cartContainer) {
        observer.observe(cartContainer, { childList: true, subtree: true });
    }
 
    
    // replaceCartButton();
    setTimeout(replaceCartButton, 2000);
});
 