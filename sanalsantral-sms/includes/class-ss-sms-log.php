<?php
/**
 * Outgoing SMS log: one row per message, sent or failed.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Log {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'ss_sms_db_version';
	const PURGE_HOOK        = 'ss_sms_purge_logs';
	const OTP_PLACEHOLDER   = '[OTP]';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ss_sms_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				number varchar(32) NOT NULL DEFAULT '',
				sender varchar(64) NOT NULL DEFAULT '',
				body text NULL,
				is_otp tinyint(1) NOT NULL DEFAULT 0,
				state varchar(16) NOT NULL DEFAULT '',
				failure_type varchar(40) NULL,
				failure_reason text NULL,
				pkg_id varchar(64) NULL,
				source varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY state (state)
			) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function unschedule_purge() {
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}

	public static function purge() {
		global $wpdb;
		$days = (int) SS_SMS_Settings::get( 'log_retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; name from $wpdb->prefix, values prepared.
	}

	/**
	 * Insert log rows in one statement.
	 *
	 * Each row: number, sender, body, is_otp, state, failure_type,
	 * failure_reason, pkg_id, source. OTP bodies carry live codes and are
	 * never stored.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 */
	public static function insert_many( array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return;
		}
		$now          = current_time( 'mysql', true );
		$placeholders = array();
		$values       = array();
		foreach ( $rows as $row ) {
			$is_otp         = ! empty( $row['is_otp'] );
			$placeholders[] = '(%s, %s, %s, %s, %d, %s, %s, %s, %s, %s)';
			array_push(
				$values,
				$now,
				substr( (string) ( isset( $row['number'] ) ? $row['number'] : '' ), 0, 32 ),
				substr( (string) ( isset( $row['sender'] ) ? $row['sender'] : '' ), 0, 64 ),
				$is_otp ? self::OTP_PLACEHOLDER : (string) ( isset( $row['body'] ) ? $row['body'] : '' ),
				$is_otp ? 1 : 0,
				(string) $row['state'],
				isset( $row['failure_type'] ) ? (string) $row['failure_type'] : '',
				isset( $row['failure_reason'] ) ? (string) $row['failure_reason'] : '',
				isset( $row['pkg_id'] ) ? (string) $row['pkg_id'] : '',
				substr( (string) ( isset( $row['source'] ) ? $row['source'] : '' ), 0, 64 )
			);
		}
		$table = self::table();
		// Bulk sends write up to bulk_size rows at once; one INSERT instead of 500.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (created_at, number, sender, body, is_otp, state, failure_type, failure_reason, pkg_id, source) VALUES " . implode( ', ', $placeholders ), $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; name from $wpdb->prefix, values prepared.
	}

	/**
	 * @param array{state?: string, search?: string, page?: int, per_page?: int} $args Query.
	 * @return array{rows: array<int, object>, total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table    = self::table();
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$where    = array( '1=1' );
		$params   = array();

		if ( ! empty( $args['state'] ) ) {
			$where[]  = 'state = %s';
			$params[] = $args['state'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(number LIKE %s OR failure_reason LIKE %s OR source LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; name from $wpdb->prefix, values prepared.

		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; name from $wpdb->prefix, values prepared.

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}
