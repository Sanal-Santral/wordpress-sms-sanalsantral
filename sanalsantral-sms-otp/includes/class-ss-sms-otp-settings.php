<?php
/**
 * OTP and two-step login settings.
 *
 * @package SanalSantralSMSOTP
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_OTP_Settings {

	const OPTION = 'ss_sms_otp_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			/* translators: Default OTP SMS. Keep the {code} placeholder. */
			'otp_template'        => __( '{site} verification code: {code}', 'sanalsantral-sms-otp' ),
			'otp_length'          => 6,
			'otp_ttl'             => 300,
			'otp_max_attempts'    => 5,
			'otp_resend_cooldown' => 60,
			'otp_hourly_limit'    => 5,
			'twofa_customers'     => false,
			// Off by default: an administrator who cannot receive the code is
			// locked out of the site, so this is opted into deliberately.
			'twofa_admins'        => false,
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

	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? wp_unslash( $input ) : array();
		$current = self::all();
		$out     = array();

		$template = isset( $input['otp_template'] ) ? sanitize_textarea_field( $input['otp_template'] ) : '';
		if ( false === strpos( $template, '{code}' ) ) {
			add_settings_error( self::OPTION, 'otp_template', __( 'The OTP message must contain {code}; the previous message was kept.', 'sanalsantral-sms-otp' ) );
			$template = $current['otp_template'];
		}
		$out['otp_template']        = $template;
		$out['otp_length']          = SS_SMS_Settings::int_in_range( $input, 'otp_length', 4, 8, 6 );
		$out['otp_ttl']             = SS_SMS_Settings::int_in_range( $input, 'otp_ttl', 60, 3600, 300 );
		$out['otp_max_attempts']    = SS_SMS_Settings::int_in_range( $input, 'otp_max_attempts', 1, 20, 5 );
		$out['otp_resend_cooldown'] = SS_SMS_Settings::int_in_range( $input, 'otp_resend_cooldown', 0, 3600, 60 );
		$out['otp_hourly_limit']    = SS_SMS_Settings::int_in_range( $input, 'otp_hourly_limit', 0, 100, 5 );
		$out['twofa_customers']     = ! empty( $input['twofa_customers'] );
		$out['twofa_admins']        = ! empty( $input['twofa_admins'] );
		return $out;
	}
}
