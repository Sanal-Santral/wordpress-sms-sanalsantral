=== Sanal Santral SMS ===
Contributors: sanalsantral
Tags: sms, woocommerce, order notification, shipping, bulk sms
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce order and shipping SMS, consent-based campaign SMS and an SMS API for WordPress, through the Sanal Santral SMS gateway.

== Description ==

Sanal Santral SMS connects your WordPress site to the [Sanal Santral](https://www.sanalsantral.com.tr) SMS gateway, so order updates, shipping notices and campaigns reach your customers under your own approved sender name.

= WooCommerce order SMS =

* A separate, editable template for every order status (pending, processing, on hold, completed, cancelled, refunded, failed).
* Placeholders: `{first_name}`, `{last_name}`, `{order_number}`, `{order_status}`, `{order_total}`, `{order_url}`, `{carrier}`, `{tracking_number}`, `{tracking_url}`, `{site}`.
* Every SMS, sent or failed, is added to the order as a note.
* Send a one-off SMS to the customer from the order screen.
* HPOS (High-Performance Order Storage) and the block checkout are supported.

= Shipping SMS for cargo integrations =

Tracking numbers come from your cargo plugin, ERP or carrier, so the shipping SMS is sent through an API instead of a form:

* PHP: `ss_sms_wc_send_shipping( $order_id, array( 'tracking_number' => 'KRG123' ) )`
* Action, with no hard dependency on this plugin: `do_action( 'ss_sms_wc_shipped', $order_id, array( 'tracking_number' => 'KRG123' ) )`
* REST: `POST /wp-json/sanalsantral-sms/v1/orders/{id}/shipping`, authenticated with an application password.

The same tracking number is never texted twice, so integrations may call it on every sync.

= Campaign SMS with consent =

* Consent checkbox on the checkout (block and classic), in My Account and on the user profile; the time of consent is stored.
* Send to everyone who agreed (optionally one role only, guest orders included) or to a pasted list of numbers.
* Personalised with `{first_name}`, `{last_name}`, `{site}`; duplicate numbers are texted once.

= Reliable delivery =

* Turkish mobile numbers are recognised in any notation (`+90 532…`, `0532…`, `0090…`, with spaces or brackets); landlines and foreign numbers are rejected with a reason.
* Bulk sends go out in packages of 500 through a single gateway call; one bad number never sinks the package.
* A package the gateway refuses is retried one message at a time, but never after a credit or sender error, and never when the outcome of a request is unknown, so no customer is texted or billed twice.

= SMS log =

Every message with its number, status, reason of failure, source and gateway package ID. One-time codes are never stored. Old entries are removed after a configurable number of days.

= For developers =

`ss_sms_send()`, `ss_sms_send_bulk()`, `ss_sms_normalize_phone()` and the hooks `ss_sms_sent`, `ss_sms_failed`, `ss_sms_wc_placeholders`, `ss_sms_wc_tracking` and more. See the [installation and usage guide](https://github.com/Sanal-Santral/wordpress-sms-sanalsantral).

The companion plugin **Sanal Santral SMS OTP** adds one-time codes and SMS two-step login on top of this plugin.

== External services ==

This plugin sends SMS through the Sanal Santral SMS gateway (`https://sms.sanalsantral.com.tr:9588`), operated by Sanal Santral Telekomünikasyon Ticaret A.Ş. A Sanal Santral account is required; nothing is sent until you enter your API username and key.

What is sent, and when:

* **When an SMS is sent** (order status change, shipping SMS, campaign, test SMS, or a call from another plugin): the recipient's mobile number, the message text, the selected sender name and your API credentials.
* **When you press "Sync senders"**: your API credentials, to fetch the sender names approved on your account.

No other data about your site or its visitors is sent.

* Terms of service: https://www.sanalsantral.com.tr/dosyalar/kurumsal-abonelik-sozlesmesi.pdf
* Privacy policy: https://www.sanalsantral.com.tr/gizlilik-politikasi
* Personal data (KVKK): https://www.sanalsantral.com.tr/kisisel-verilerin-korunmasi

== Installation ==

1. Install the plugin from Plugins > Add New Plugin, or upload `sanalsantral-sms.zip` there, and activate it.
2. Open **Sanal Santral SMS > Settings**, enter the API username and API key of your Sanal Santral account and save.
3. Press **Sync senders** and choose the sender name your messages go out with.
4. Send yourself a test SMS from the same page.
5. With WooCommerce, enable the order statuses to text under **Sanal Santral SMS > Order SMS**.

With WP-CLI: `wp plugin install sanalsantral-sms --activate`

== Frequently Asked Questions ==

= Which numbers can receive SMS? =

Turkish mobile numbers (5XX). Landlines and foreign numbers are skipped and logged with the reason.

= Do I need consent to send order and shipping SMS? =

No. Order and shipping updates are informational messages. Campaign (commercial) SMS need the recipient's consent and the sender has to be registered with İYS (İleti Yönetim Sistemi); the bulk sender therefore only targets people who opted in, unless you paste numbers yourself.

= The gateway answers ERR_UNAUTHORIZED_REQUEST =

The API username or key is wrong, API access is not enabled on the account, or the gateway only accepts requests from allowed IP addresses. Contact Sanal Santral support to check.

= A message says "delivery state unknown" =

The request reached the gateway but its answer could not be read, so the message may have gone out. Check the Sanal Santral SMS panel before resending; the plugin never resends such messages by itself.

= How do I connect my cargo plugin? =

Call `ss_sms_wc_send_shipping()` or fire the `ss_sms_wc_shipped` action where the tracking number is created, or POST it to the REST endpoint. Examples are on the Order SMS settings page.

== Screenshots ==

1. Gateway credentials, sender name and sending options.
2. SMS templates per WooCommerce order status.
3. The SMS box on the order screen.
4. Bulk SMS to customers who gave consent.
5. The SMS log with status, reason and source.
6. Campaign consent on the block checkout.
7. Shipping SMS integration examples for developers.

== Changelog ==

= 1.0.1 =
* Maintenance release, no functional changes. Demo environment now runs on patched PHP 8.3.36 and binds to localhost.

= 1.0.0 =
* First release: gateway settings and sender sync, WooCommerce order and shipping SMS, consent-based bulk SMS, SMS log, developer API, Turkish translation.

== Upgrade Notice ==

= 1.0.1 =
Maintenance release, no functional changes.

= 1.0.0 =
First release.
