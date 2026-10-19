<?php
/**
 * Removes everything the plugin stored.
 *
 * @package SanalSantralSMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'ss_sms_settings', 'ss_sms_senders', 'ss_sms_wc_settings', 'ss_sms_db_version' ) as $ss_sms_option ) {
	delete_option( $ss_sms_option );
}
wp_clear_scheduled_hook( 'ss_sms_purge_logs' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ss_sms_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
// Phone numbers and marketing consent stay on the user profiles on purpose:
// they belong to the customers, not to this plugin's configuration.
