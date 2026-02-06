=== Zaver Checkout for WooCommerce ===
Contributors: krokedil
Tags: woocommerce, zaver, checkout, payment, refund, swish
Stable tag: 2.1.0
Requires at least: 4.7
Tested up to: 6.9
Requires PHP: 7.4
WC tested up to: 10.5.0
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Fight cart abandonment rates by reducing friction at the point-of-sale, with probably the world’s first size-agnostic checkout.

== Description ==

*Fight cart abandonment rates by reducing friction at the point-of-sale, with probably the world’s first size-agnostic checkout.*

Zaver Checkout is about taking frictionless shopping experiences to the next level - regardless of what items the cart contains. It’s about making it as easy to buy a jacuzzi online, as it is to purchase a pair of shoes. If you’re looking for a Checkout with incredible high conversion rates at both microscopically small, and astronomically large purchases in life, look no further.

Our WooCommerce plugin is easy to install, fully responsive and fits seamlessly on your website, without occupying your beloved checkout. 🦄

= With this plugin you will: =

- Be able to achieve high conversion rates for transactions in all sizes, microscopically small, to astronomically large.
- Get instant callbacks and follow payments and refunds in real-time.
- Have access to our Merchant Portal and be able to track your business performance with a widescreen-like overview and collect reports of your sales.
- Offer frictionless shopping experiences with the most popular payment methods included in the package.
- Increase your liquidity with super-duper fast settlements.
- Always be up to date with a checkout that constantly improves and optimises.
- Have access to our dedicated and friendly support.

= Increase sales with tailor-made payment methods. =

Unlock your customers' purchasing power and help them get what they came for. Our tailor-made payment methods have probably the highest conversion rates in the market.

**Pay now** - Instant payments either via Swish or direct bank transfer. Swoosh!

**Pay later** - Let your customers buy now and pay later. No fees. No Hassle.

**Pay over time** - Gives your customers the flexibility to spread the cost with monthly payments.

= How to Get Started: =

To get started with Zaver you need to sign up for an account. Once you have gone through the onboarding process and the account has been approved, you’ll be up and running in no time.

1. [Sign up for an account](https://zaver.com/woocommerce).
2. Wait for the account to be approved.
3. [Install the plugin](https://wordpress.org/plugins/zaver-checkout-for-woocommerce/)​ on your site.
4. Go to the payment method settings and add your credentials received from Zaver.
5. Get paid. ✌️

== Changelog ==
= 2025.11.10    - version 2.1.0 =
* Feature       - Added support for updating Zaver orders with a reduced price for PAY_LATER and INSTALLMENTS payment types, before the payment is captured or cancelled.

= 2025.10.16    - version 2.0.1 =
* Fix           - Resolved an issue where the WooCommerce order status was not consistently updated after capturing a payment.

= 2025.09.03    - version 2.0.0 =
* Feature       - Added support for separate payment methods. This allows you to conditionally show the individual payment methods in the checkout.
* Feature       - Added support for WooCommerce High-Performance Storage (HPOS).
* Feature       - Added support for the support package to facilitate troubleshooting and support.
* Feature       - Added support for a PENDING_CONFIRMATION status.
* Feature       - You can now capture and cancel, in addition to refunding, a Zaver order directly from the WooCommerce order admin page, by enabling the Order management setting.
* Feature       - Added a setting to enable or disable logging.
* Tweak         - The order number will now be used as the purchase title. Previously, the product name was used, or the order number if it had more than one product.
* Tweak         - The refund button will now be hidden unless the order can be refunded.
* Tweak         - Added check for whether the order status can be transitioned in callback before further processing.
* Fix           - Fixed logic for checking whether an order can be refunded.
* Fix           - Fixed incorrect use of metadata when processing a refund in callback.

= 1.0.16 - 1.0.18 =
* Updated plugin description.
* Changed author domain to zaver.com.

= 1.0.14 - 1.0.15 =
* Adjusted CI/CD with WordPress SVN repo.

= 1.0.13 =
* Code clean-up.

= 1.0.12 =
* Updated Swedish translations.
* Added hooks.

= 1.0.2 - 1.0.11 =
* CI/CD with WordPress SVN repo.

= 1.0.1 =
* Minor adjustments.

= 1.0.0 =
* Added Zaver Checkout and support for refunds.
