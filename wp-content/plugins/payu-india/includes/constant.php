<?php

define('PAYU_POSTSERVICE_FORM_2_URL_PRODUCTION', 'https://info.payu.in/merchant/postservice.php?form=2');
define('PAYU_POSTSERVICE_FORM_2_URL_UAT', 'https://apitest.payu.in/merchant/postservice.php?form=2');
define('PAYU_HOSTED_PAYMENT_URL_PRODUCTION', 'https://secure.payu.in/_payment');
define('PAYU_HOSTED_PAYMENT_URL_UAT', 'https://test.payu.in/_payment');
define('PAYU_ADDRESS_API_URL', 'https://api.payu.in/user/address/v3');
define('PAYU_ADDRESS_API_URL_UAT', 'https://apitest.payu.in/user/address/v3');
define('PAYU_GENERATE_API_URL', 'https://accounts.payu.in/oauth/token');
define('PAYU_GENERATE_API_URL_UAT', 'https://uat-accounts.payu.in/oauth/token');
define('PAYU_CLIENT_ID', '36d0fdc212a5ed316720e4f3c9c4c7c74cbc06a6e7bf069e6e4e2ce4b697734e');
define('PAYU_CLIENT_SECRET_ID', '12aee3ac14ced779330816b33d16d47f9db61295bbca7989613278077629db92');
define('PAYU_CLIENT_ID_UAT', '68b9ee63744ba405dc4a013ec9a80d4f9eef4a859c9f04468ddec5c1a35f33f3');
define('PAYU_CLIENT_SECRET_ID_UAT', '98930aa1b9e717009d51b3492bf635659d3bd95b08e0d243aae23b2df5efa7e2');
define('PAYU_REFUND_PROCESS_TIME_TEXT', "Refunds take 5-6 business working days to appear on your bank");
define('MERCHANT_HOSTED_PAYMENT_JS_LINK_PRODUCTION', 'https://jssdk.payu.in/bolt/bolt.min.js');
define('MERCHANT_HOSTED_PAYMENT_JS_LINK_UAT', 'https://jssdk-uat.payu.in/bolt/bolt.min.js');
define('PAYU_ORDER_DETAIL_API_UAT','https://apitest.payu.in/cart/order/');
define('PAYU_ORDER_DETAIL_API','https://info.payu.in/cart/order/');
define('PAYU_USER_TOKEN_EMAIL','commerce.pro@payu.in');
define('CURL_CONTENT_TYPE','application/x-www-form-urlencoded');
define('CURL_CONTENT_TYPE_JSON','application/json');
$woocommerce_version = get_option('woocommerce_version');
define('WOOCOMMERCE_CURRENT_VERSION',$woocommerce_version);
define('COMMERCEPRO_APP_VERSION' , '3.8');