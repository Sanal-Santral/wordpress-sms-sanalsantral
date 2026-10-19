<?php
/**
 * Plugin Name:       Sanal Santral SMS OTP
 * Plugin URI:        https://www.sanalsantral.com.tr
 * Description:       One-time codes and SMS two-step login for WordPress and WooCommerce customers, with an opt-in, lock-out-safe mode for administrators.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Requires Plugins:  sanalsantral-sms
 * Author:            Sanal Santral Telekomünikasyon Ticaret A.Ş.
 * Author URI:        https://www.sanalsantral.com.tr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sanalsantral-sms-otp
 * Domain Path:       /languages
 * Update URI:        https://github.com/Sanal-Santral/wordpress-sms-sanalsantral
 */

defined( 'ABSPATH' ) || exit;

define( 'SS_SMS_OTP_VERSION', '1.0.0' );
define( 'SS_SMS_OTP_FILE', __FILE__ );
define( 'SS_SMS_OTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SS_SMS_OTP_URL', plugin_dir_url( __FILE__ ) );

// "sanalsantral-sms-otp" sorts before "sanalsantral-sms", so the gateway
// plugin is not loaded yet at this point: wait for it.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'ss_sms_send' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Sanal Santral SMS OTP needs the Sanal Santral SMS plugin to be active.', 'sanalsantral-sms-otp' ) . '</p></div>';
				}
			);
			return;
		}

		require_once SS_SMS_OTP_DIR . 'includes/class-ss-sms-otp-settings.php';
		require_once SS_SMS_OTP_DIR . 'includes/class-ss-sms-otp.php';
		require_once SS_SMS_OTP_DIR . 'includes/class-ss-sms-2fa.php';
		require_once SS_SMS_OTP_DIR . 'includes/functions.php';

		SS_SMS_2FA::init();

		if ( is_admin() ) {
			require_once SS_SMS_OTP_DIR . 'includes/class-ss-sms-otp-admin.php';
			SS_SMS_OTP_Admin::init();
		}
	}
);

add_action(
	'init',
	static function () {
		// Bundled Turkish translation; translate.wordpress.org packs take precedence when present.
		load_plugin_textdomain( 'sanalsantral-sms-otp', false, dirname( plugin_basename( SS_SMS_OTP_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);
