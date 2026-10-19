<?php
/**
 * Removes everything the plugin stored.
 *
 * @package SanalSantralSMSOTP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'ss_sms_otp_settings' );
foreach ( array( '_ss_sms_2fa_pending', 'ss_sms_phone_verified', 'ss_sms_2fa_exempt', '_ss_sms_otp_test_phone' ) as $ss_sms_otp_meta_key ) {
	delete_metadata( 'user', 0, $ss_sms_otp_meta_key, '', true );
}
// Outstanding codes and rate-limit counters.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ss\\_sms\\_otp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ss\\_sms\\_otp\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
