=== PayU CommercePro Plugin ===
Contributors:
Donate Link:
Tags: payment, gateway, payu
Requires at least: 5.3
Tested up to: 6.8
Stable tag: 3.8.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

CommercePro payment plugin by PayU Payment Gateway (India) for WooCommerce (tested from 5.3 to 9.8.1).

== Description ==

Caution: Always keep backup of your existing WooCommerce installation including Mysql Database, before installing a new module.

The plugin zip can be easily installed using WordPress's upload plugin feature.

== Frequently Asked Questions ==

= Is there any dependency on other specific plugin apart from WooCommerce? =

No. This plugin needs WooCommerce, as that is the sole purpose of the plugin to facilitate payment. Apart from that, the plugin does not depend directly on any other plugin.

== Screenshots ==

screenshot-1: Upload and install/activate PayU payment plugin to WordPress.

screenshot-2: Configure PayU payment plugin under WooCommerce - Settings - Payments tab.

screenshot-3: Enable/Disable plugin, plugin description to display in checkout, Gateway Mode (Sandbox/Production), Currency 1 (Name, Key, and Salt).

screenshot-4: Return Page in case of error occurred.

screenshot-5: Checkout page showing PayU as payment option.

screenshot-6: Billing/Shipping details validation error.

screenshot-7: PayU payment page for making payment.

screenshot-8: Payment error posted back by PayU payment gateway.

screenshot-9: After successful payment, control redirected to WooCommerce order success page.

== Changelog ==

= 3.8.8 = 

Remove user token api

Added hash validation for shipping cost api

= 3.8.7 =

Fixing user session

Skipping order update for shipping api

= 3.8.6 =

Fixed:

User session issue

= 3.8.5 =

Added:

Warnings handling

Block-based checkout support

BuyNow feature

Affordability Widget feature

= 3.8.4 =

Resolved security issues.

= 3.8.3 =

Improved session handling.

= 3.8.2 =

Adhering to latest WordPress and WooCommerce technologies.

Fixing WordPress coding standards.

= 3.8.1 =

Adhering to latest WordPress and WooCommerce technologies.

Introduced inquiry API to doubly verify payment apart from previously coded signature validations.

'samesite' cookie parameter management introduced to take care of latest browser security.

= 3.0 =

Custom order success page introduced.

= 2.0 =

Request and response signature validations introduced.

= 1.0 =

New plugin developed for WooCommerce v3.3.4.

== Upgrade Notice ==

= 3.8.0 =
Upgrade to the latest version v3.8.1 to install multi-currency key, salt feature.