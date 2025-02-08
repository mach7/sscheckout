=== Simple Stripe Checkout ===
Contributors: Tyson Brooks
Tags: stripe, checkout, ecommerce, payment, cart
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Simple Stripe Checkout is a lightweight plugin that enables a quick and easy shopping cart experience with embedded Stripe payments. Users can add items to a cart, adjust quantities dynamically, and complete purchases without leaving the page.

== Features ==
* Simple "Add to Cart" shortcode
* AJAX-powered cart updates
* Embedded Stripe checkout (no redirects)
* Admin order history management
* Supports logged-in and guest users

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/sscheckout` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Ensure your site has SSL enabled.
4. Use the `[add_to_cart price="" name=""]` and `[Checkout]` shortcodes to display cart functionality.

== Changelog ==
= 1.0.0 =
* Initial release with core checkout functionality.

== Frequently Asked Questions ==
= Does this plugin require an SSL certificate? =
Yes, an SSL certificate is required for security and Stripe integration.

= Can I customize the checkout button? =
Yes, styling can be modified via CSS.

= Does this plugin support tax calculations? =
Not in the initial release, but it may be added in a future update.

== Support ==
For support, please contact Tyson Brooks at [Frost Line Works](https://frostlineworks.com).
