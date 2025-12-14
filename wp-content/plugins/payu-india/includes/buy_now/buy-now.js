//Buy Now functionality Js Code File Added By (SM)
jQuery(document).ready(function($) {
    // Log AJAX URL for debugging.
    console.log(cbn_ajax_object.ajax_url);
    
    $('.buy-now-btn').on('click', function(e) {
        e.preventDefault();
        // Get product ID from data attribute.
        var productId = $(this).data('product-id');
        var productQuantity = jQuery('form.cart').find('input.qty').val() || 1;
        var loader = $(this).siblings('.buy-now-loader');
        // For variable products, gather variation attributes.
        var variationData = {};
        // Assume variation dropdown fields are within .variations_form and have name attribute like attribute_pa_color.
        $('.variations_form').find('select[name^="attribute_"]').each(function(){
            var attributeName = $(this).attr('name');
            var attributeValue = $(this).val();
            if ( attributeValue ) {
                variationData[attributeName] = attributeValue;
            }
        });
        
        // Variation ID: it can be stored in a hidden input or data attribute. 
        // Example: if your variations form has an input field with name "variation_id":
        var variationId = $('input[name="variation_id"]').val() || 0;
        // Show loader
        loader.show();
        // Prepare data object
        var dataToSend = {
            action: 'custom_buy_now',
            product_id: productId,
            product_quantity: productQuantity
        };
        
        // If variation data exists, add it.
        if( ! $.isEmptyObject(variationData) ) {
            dataToSend.variation = variationData;
            dataToSend.variation_id = variationId;
        }
        
        $.ajax({
            type: 'POST',
            url: cbn_ajax_object.ajax_url, // Localized AJAX URL.
            data: dataToSend,
            success: function(response) {
                console.log('Full Response:', response);
                // Hide loader on success
                loader.hide();
                if (response.success) {
                    console.log('Checkout Data:', response.data.checkout_data);
                    console.log('Redirect URL:', response.data.redirect_url);
                    window.location.href = response.data.redirect_url;
                } else {
                    // alert(response.message);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                loader.hide();
                // alert('Something went wrong. Please try again.');
            }
        });
    });
});