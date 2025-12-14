document.addEventListener("DOMContentLoaded", function () {
    initPayUWidget();
});

jQuery(document).on('updated_cart_totals updated_checkout wc_fragments_refreshed', function () {
    initPayUWidget();
});

document.addEventListener("DOMContentLoaded", function () {
    var targetNode = document.body;
    var observer = new MutationObserver(function(mutations, obs) {
        var cartForm = document.querySelector('.wp-block-woocommerce-cart');
        // console.log(cartForm);

        if (cartForm) {
            // console.log("cartForm found via MutationObserver:", cartForm);
            if (!document.getElementById('payuWidget')) {
                var widgetContainer = document.createElement('div');
                widgetContainer.id = 'payuWidget';
                cartForm.parentNode.insertBefore(widgetContainer, cartForm);
            }
            initPayUWidget();
            obs.disconnect();
        }
    });
    observer.observe(targetNode, {childList: true, subtree: true});
});

document.addEventListener("DOMContentLoaded", function () {
    var targetNode = document.body;
    var observer = new MutationObserver(function(mutations, obs) {
        var checkoutForm = document.querySelector('.wc-block-checkout__form');
        // console.log(checkoutForm);
        if (checkoutForm) {
            // console.log("checkoutForm found via MutationObserver:", checkoutForm);
            if (!document.getElementById('payuWidget')) {
                var widgetContainer = document.createElement('div');
                widgetContainer.id = 'payuWidget';
                checkoutForm.parentNode.insertBefore(widgetContainer, checkoutForm);
            }
            initPayUWidget();
            obs.disconnect();
        }
    });
    observer.observe(targetNode, {childList: true, subtree: true});
});


function initPayUWidget() {
    if (typeof payuData === "undefined") {
        // console.error("PayU Data is not available.");
        return;
    }

    // console.log("PayU Amount:", payuData.amount);

    const widgetConfig = {
        "key": payuData.key,
        "amount": payuData.amount,
        "skusDetail": payuData.skusDetail,
        "styleConfig": {
            "lightColor": payuData.lightColor || "#FFFCF3",
            "darkColor": payuData.darkColor || "#FFC915",
            "backgroundColor": payuData.backgroundColor || "#FFFFFF"
        },
        "userDetails": {
            "mobileNumber": payuData.mobileNumber,
            "token": payuData.token || "",
            "timeStamp": payuData.timeStamp || ""
        }
    };

    if (typeof payuAffordability !== "undefined" && document.getElementById("payuWidget")) {
        payuAffordability.init(widgetConfig);
    } else {
        // console.error("PayU Widget script not loaded properly");
    }
}
