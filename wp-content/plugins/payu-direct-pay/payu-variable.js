jQuery(document).ready(function ($) {

    let button = $(".payu-direct-btn");

    // Hide initially for variable products
    if ($(".variations_form").length) {
        button.hide();
    }

    // When user selects a variation
    $("form.variations_form").on("found_variation", function (event, variation) {

        let finalPrice = variation.display_price;

        let newUrl =
            button.data("base") +
            "&variation_id=" + variation.variation_id +
            "&amount=" + finalPrice;

        button.attr("href", newUrl);
        button.show();
    });

    // When variation resets
    $("form.variations_form").on("reset_data", function () {
        button.hide();
    });

});
