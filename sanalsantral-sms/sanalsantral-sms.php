<?php
/**
 * Plugin Name:       Sanal Santral SMS
 * Plugin URI:        https://www.sanalsantral.com.tr
 * Description:       Sanal Santral SMS gateway for WordPress: order and shipping SMS for WooCommerce, bulk campaign SMS and an SMS API for other plugins.
 * Version:           1.0.1
 * Requires at least: 7.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Sanal Santral Telekomünikasyon Ticaret A.Ş.
 * Author URI:        https://www.sanalsantral.com.tr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sanalsantral-sms
 * Domain Path:       /languages
 * Update URI:        https://github.com/Sanal-Santral/wordpress-sms-sanalsantral
 */

defined( 'ABSPATH' ) || exit;

define( 'SS_SMS_VERSION', '1.0.1' );
define( 'SS_SMS_FILE', __FILE__ );
define( 'SS_SMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SS_SMS_URL', plugin_dir_url( __FILE__ ) );

require_once SS_SMS_DIR . 'includes/class-ss-sms-exceptions.php';
require_once SS_SMS_DIR . 'includes/class-ss-sms-phone.php';
require_once SS_SMS_DIR . 'includes/class-ss-sms-settings.php';
require_once SS_SMS_DIR . 'includes/class-ss-sms-client.php';
require_once SS_SMS_DIR . 'includes/class-ss-sms-log.php';
require_once SS_SMS_DIR . 'includes/class-ss-sms-dispatcher.php';
require_once SS_SMS_DIR . 'includes/class-ss-sms-users.php';
require_once SS_SMS_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'SS_SMS_Log', 'install' ) );
register_deactivation_hook( __FILE__, array( 'SS_SMS_Log', 'unschedule_purge' ) );

add_action(
	'plugins_loaded',
	static function () {
		// Activation hooks do not fire on updates, nor for every site of a
		// network activation, so the table is (re)checked lazily as well.
		SS_SMS_Log::maybe_upgrade();
	}
);

add_action(
	'init',
	static function () {
		// Bundled Turkish translation; translate.wordpress.org packs take precedence when present.
		load_plugin_textdomain( 'sanalsantral-sms', false, dirname( plugin_basename( SS_SMS_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action( SS_SMS_Log::PURGE_HOOK, array( 'SS_SMS_Log', 'purge' ) );

SS_SMS_Users::init();

// GitHub builds update themselves from GitHub Releases; the WordPress.org
// build ships without this file and is updated by WordPress.org.
if ( file_exists( SS_SMS_DIR . 'includes/class-ss-sms-updater.php' ) ) {
	require_once SS_SMS_DIR . 'includes/class-ss-sms-updater.php';
	SS_SMS_Updater::init();
}

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			require_once SS_SMS_DIR . 'includes/class-ss-sms-woocommerce.php';
			SS_SMS_WooCommerce::init();
		}
	}
);

// Order meta is read through the CRUD API only, so HPOS is safe.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SS_SMS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SS_SMS_FILE, true );
		}
	}
);

if ( is_admin() ) {
	require_once SS_SMS_DIR . 'admin/class-ss-sms-admin.php';
	SS_SMS_Admin::init();
}
