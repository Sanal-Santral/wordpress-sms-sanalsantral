=== Sanal Santral SMS OTP ===
Contributors: sanalsantral
Tags: otp, two-factor, 2fa, sms, login
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: sanalsantral-sms
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-time SMS codes and SMS two-step login for WordPress and WooCommerce customers, with a lock-out-safe opt-in for administrators.

== Description ==

After the password, users type a one-time code sent to their phone by SMS. Built on the **Sanal Santral SMS** plugin and its gateway account.

= Customer login =

* Works on `wp-login.php` and the WooCommerce My Account login.
* The code goes to the mobile number on the profile, else the WooCommerce billing phone.
* Users without a Turkish mobile number keep logging in with their password.
* After the code the user lands where they were heading.

= Administrator login, without locking yourself out =

* A separate setting, off by default.
* Codes only go to administrators who verified their number by SMS on their profile; changing the number drops the verification.
* Any user can be exempted from their profile.
* Nothing is enforced while the SMS gateway is not configured.
* Emergency switch in `wp-config.php`: `define( 'SS_SMS_2FA_DISABLED', true );`

= Abuse limits =

* Waiting time before a new code, codes per number per hour, wrong attempts per code; after too many wrong codes the password step starts over.
* Codes are random, single-use and stored only as a keyed hash; the SMS log hides their content.
* Accounts under SMS two-step login cannot log in to XML-RPC or REST with their password; application passwords keep working for integrations.

= OTP API for your own forms =

    $sent = ss_sms_otp_send( $phone, 'newsletter' );
    $ok   = ss_sms_otp_verify( $phone, $_POST['code'], 'newsletter' );

Hooks: `ss_sms_otp_message`, `ss_sms_2fa_phone`, `ss_sms_2fa_is_admin`, `ss_sms_2fa_authenticated`.

== External services ==

This plugin does not contact any service itself. One-time codes are sent through the **Sanal Santral SMS** plugin and the Sanal Santral SMS gateway (`https://sms.sanalsantral.com.tr:9588`): the recipient's mobile number and the code message are sent each time a code is requested. See that plugin's description for the data it sends and the service's terms and privacy policy:

* Terms of service: https://www.sanalsantral.com.tr/dosyalar/kurumsal-abonelik-sozlesmesi.pdf
* Privacy policy: https://www.sanalsantral.com.tr/gizlilik-politikasi

== Installation ==

1. Install and set up the **Sanal Santral SMS** plugin first.
2. Install this plugin from Plugins > Add New Plugin, or upload `sanalsantral-sms-otp.zip` there, and activate it.
3. Open **Sanal Santral SMS > OTP & 2FA** and use **Try it** to send yourself a code.
4. Tick "Ask customers for an SMS code after their password".
5. For administrators: tick the administrator setting, then verify your number on your profile.

With WP-CLI: `wp plugin install sanalsantral-sms sanalsantral-sms-otp --activate`

== Frequently Asked Questions ==

= I am locked out of wp-admin =

Add `define( 'SS_SMS_2FA_DISABLED', true );` to `wp-config.php` through FTP or your hosting panel, log in, fix the cause (number, SMS credit) and remove the line again.

= An administrator logs in without a code =

Codes are only asked from administrators whose number is verified by SMS on their profile. The "SMS two-step login" row on the profile shows why it is off.

= My mobile app can no longer log in =

Accounts under SMS two-step login cannot use their password for XML-RPC or REST. Create an application password on the profile and use it in the app.

== Screenshots ==

1. The SMS step after the password.
2. Two-step login and code settings.
3. Verified number and two-step login status on the profile.

== Changelog ==

= 1.0.0 =
* First release: one-time codes, SMS two-step login for customers and administrators, profile number verification, OTP API, Turkish translation.

== Upgrade Notice ==

= 1.0.0 =
First release.
