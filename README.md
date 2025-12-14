# WooCommerce PayU Integration

This project is a WordPress WooCommerce setup integrated with the PayU payment gateway. It includes custom functionality for PayU Hosted Checkout and OneAPI Payment Links.

## Prerequisites

-   WordPress 6.x
-   WooCommerce 5.x+
-   PHP 7.4+
-   PayU Merchant Credentials (Key, Salt, Client ID, Client Secret)

## Installation

1.  **Clone the repository** to your web server's public directory (e.g., `htdocs` or `www`).
2.  **Configure Database**:
    -   Create a MySQL database.
    -   Copy `wp-config-sample.php` to `wp-config.php` (if not already present/configured).
    -   Update `wp-config.php` with your database credentials (`DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`).
3.  **Install Plugins**:
    -   Go to **Plugins > Installed Plugins**.
    -   Activate **WooCommerce**.
    -   Activate **PayU CommercePro Plugin**.
    -   Activate **PayU Direct Pay + Payment Link (OneAPI) - Clickable Fix**.

## Configuration

### PayU CommercePro
Configure the official PayU plugin via **WooCommerce > Settings > Payments > PayU**.

### PayU Direct Pay (Custom Plugin)
The custom plugin `payu-direct-pay` handles direct hosted checkout and payment links.
**Configuration is currently located in the plugin file:**
`wp-content/plugins/payu-direct-pay/payu-direct-pay.php`

**Important:** You must update the following constants in `payu-direct-pay.php` with your actual PayU credentials:

```php
// In wp-content/plugins/payu-direct-pay/payu-direct-pay.php

define( 'PAYU_DIRECT_KEY',  'your_key_here' );
define( 'PAYU_DIRECT_SALT', 'your_salt_here' );
define( 'PAYU_DIRECT_MODE', 'test' ); // Set to 'live' for production
define( 'PAYU_CLIENT_ID2', 'your_client_id_here' );
define( 'PAYU_CLIENT_SECRET2', 'your_client_secret_here' );
define( 'PAYU_MERCHANT_ID', 'your_merchant_id' );
```

## Features

-   **WooCommerce Checkout**: Standard checkout via PayU.
-   **Direct Pay Button**: Adds a "Pay Now with PayU" button on product pages.
-   **Payment Links**: Admin/API capability to generate payment links via PayU OneAPI.