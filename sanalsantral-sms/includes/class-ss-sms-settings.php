<?php
/**
 * Plugin settings, stored as a single option.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Settings {

	const OPTION         = 'ss_sms_settings';
	const SENDERS_OPTION = 'ss_sms_senders';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'api_endpoint'         => 'https://sms.sanalsantral.com.tr:9588',
			'username'             => '',
			'api_key'              => '',
			'sender'               => '',
			// GSM fits 160 characters per part, Unicode only 70, so Unicode
			// roughly doubles the cost of a long Turkish message.
			'encoding'             => 0,
			'bulk_size'            => 500,
			'bulk_fallback_single' => true,
			'api_timeout'          => 30,
			'log_retention_days'   => 30,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function is_configured() {
		$s = self::all();
		return '' !== $s['api_endpoint'] && '' !== $s['username'] && '' !== $s['api_key'];
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? wp_unslash( $input ) : array();
		$current = self::all();
		$out     = $current;

		$endpoint = isset( $input['api_endpoint'] ) ? esc_url_raw( trim( $input['api_endpoint'] ), array( 'https', 'http' ) ) : '';
		$out['api_endpoint'] = '' !== $endpoint ? untrailingslashit( $endpoint ) : self::defaults()['api_endpoint'];

		$out['username'] = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';
		// The key is never echoed back into the form; an empty field keeps it.
		if ( isset( $input['api_key'] ) && '' !== trim( $input['api_key'] ) ) {
			$out['api_key'] = trim( $input['api_key'] );
		}
		$out['sender']   = isset( $input['sender'] ) ? sanitize_text_field( $input['sender'] ) : '';
		$out['encoding'] = isset( $input['encoding'] ) && '1' === (string) $input['encoding'] ? 1 : 0;

		$out['bulk_size']            = self::int_in_range( $input, 'bulk_size', 1, 5000, 500 );
		$out['bulk_fallback_single'] = ! empty( $input['bulk_fallback_single'] );
		$out['api_timeout']          = self::int_in_range( $input, 'api_timeout', 5, 120, 30 );
		$out['log_retention_days']   = self::int_in_range( $input, 'log_retention_days', 0, 3650, 30 );

		// Senders belong to an account: switching accounts must not leave the
		// old account's header selectable.
		if ( $out['username'] !== $current['username'] || $out['api_endpoint'] !== $current['api_endpoint'] ) {
			delete_option( self::SENDERS_OPTION );
		}

		return $out;
	}

	public static function int_in_range( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === trim( (string) $input[ $key ] ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $input[ $key ] ) );
	}

	/**
	 * Senders fetched from the gateway by the last sync.
	 *
	 * @return array<int, array{uuid: string, title: string, status: mixed}>
	 */
	public static function senders() {
		$senders = get_option( self::SENDERS_OPTION, array() );
		return is_array( $senders ) ? $senders : array();
	}

	public static function save_senders( array $senders ) {
		update_option( self::SENDERS_OPTION, array_values( $senders ), false );
	}
}
