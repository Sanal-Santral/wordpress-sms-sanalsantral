<?php
/**
 * One-time codes over SMS.
 *
 * Codes are random, single-use and stored only as an HMAC, keyed by context
 * and number, so the same number can hold a login code and a phone
 * verification code at once without one invalidating the other.
 *
 * @package SanalSantralSMSOTP
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_OTP {

	const PREFIX = 'ss_sms_otp_';

	/**
	 * Generate a code and send it.
	 *
	 * @param string               $phone   Raw number.
	 * @param string               $context What the code unlocks, e.g. "login:12".
	 * @param array<string, mixed> $args    sender, source.
	 * @return true|WP_Error WP_Error data carries retry_after (seconds) on rate limits.
	 */
	public static function send( $phone, $context, array $args = array() ) {
		$s = SS_SMS_OTP_Settings::all();
		list( $national, $failure_type ) = SS_SMS_Phone::normalize( $phone );
		if ( ! $national ) {
			return new WP_Error( $failure_type, __( 'This is not a valid Turkish mobile number.', 'sanalsantral-sms-otp' ), array( 'failure_type' => $failure_type ) );
		}

		$key      = self::key( $context, $national );
		$existing = get_transient( $key );
		$now      = time();
		if ( is_array( $existing ) && $now - $existing['sent_at'] < (int) $s['otp_resend_cooldown'] ) {
			$wait = (int) $s['otp_resend_cooldown'] - ( $now - $existing['sent_at'] );
			return new WP_Error(
				'otp_cooldown',
				/* translators: %d: seconds */
				sprintf( _n( 'Please wait %d second before requesting a new code.', 'Please wait %d seconds before requesting a new code.', $wait, 'sanalsantral-sms-otp' ), $wait ),
				array( 'retry_after' => $wait )
			);
		}

		// Caps how many codes one number receives per hour, whatever the
		// context: a public login form must not become an SMS cannon.
		$limit = (int) $s['otp_hourly_limit'];
		$rate  = get_transient( self::PREFIX . 'rate_' . md5( $national ) );
		if ( ! is_array( $rate ) || $now - $rate['start'] >= HOUR_IN_SECONDS ) {
			$rate = array(
				'start' => $now,
				'count' => 0,
			);
		}
		if ( $limit > 0 && $rate['count'] >= $limit ) {
			$wait = HOUR_IN_SECONDS - ( $now - $rate['start'] );
			return new WP_Error(
				'otp_rate_limited',
				__( 'Too many codes were requested for this number. Please try again later.', 'sanalsantral-sms-otp' ),
				array( 'retry_after' => $wait )
			);
		}

		$length = (int) $s['otp_length'];
		$code   = str_pad( (string) random_int( 0, (int) pow( 10, $length ) - 1 ), $length, '0', STR_PAD_LEFT );

		$message = strtr(
			$s['otp_template'],
			array(
				'{code}'    => $code,
				'{site}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{minutes}' => (string) max( 1, (int) round( $s['otp_ttl'] / 60 ) ),
			)
		);
		/**
		 * Filters the OTP message body. Must keep the code in it.
		 *
		 * @param string $message Body.
		 * @param string $code    The code.
		 * @param string $context Context.
		 */
		$message = apply_filters( 'ss_sms_otp_message', $message, $code, $context );

		$result = ( new SS_SMS_Dispatcher() )->send(
			$national,
			$message,
			array(
				'is_otp' => true,
				'sender' => isset( $args['sender'] ) ? $args['sender'] : null,
				'source' => isset( $args['source'] ) ? $args['source'] : 'otp',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		++$rate['count'];
		set_transient( self::PREFIX . 'rate_' . md5( $national ), $rate, HOUR_IN_SECONDS - ( $now - $rate['start'] ) );

		set_transient(
			$key,
			array(
				'hash'     => self::hash( $code, $context, $national ),
				'expires'  => $now + (int) $s['otp_ttl'],
				'sent_at'  => $now,
				'attempts' => 0,
			),
			(int) $s['otp_ttl']
		);
		return true;
	}

	/**
	 * Check a code. A correct code is consumed; a wrong one costs an attempt.
	 *
	 * @param string $phone   Raw number.
	 * @param string $code    Code the user typed.
	 * @param string $context Context passed to send().
	 * @return true|WP_Error otp_invalid, otp_expired or otp_too_many_attempts.
	 */
	public static function verify( $phone, $code, $context ) {
		list( $national ) = SS_SMS_Phone::normalize( $phone );
		$code             = preg_replace( '/\s+/', '', (string) $code );
		if ( ! $national ) {
			return new WP_Error( 'otp_expired', __( 'The code has expired. Please request a new one.', 'sanalsantral-sms-otp' ) );
		}

		$key    = self::key( $context, $national );
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || time() > $stored['expires'] ) {
			delete_transient( $key );
			return new WP_Error( 'otp_expired', __( 'The code has expired. Please request a new one.', 'sanalsantral-sms-otp' ) );
		}

		if ( '' !== $code && hash_equals( $stored['hash'], self::hash( $code, $context, $national ) ) ) {
			delete_transient( $key );
			return true;
		}

		++$stored['attempts'];
		if ( $stored['attempts'] >= (int) SS_SMS_OTP_Settings::get( 'otp_max_attempts' ) ) {
			// Burn the code: the attacker now needs a fresh one, which the
			// cooldown and hourly cap make slow.
			delete_transient( $key );
			return new WP_Error( 'otp_too_many_attempts', __( 'Too many wrong codes. Please request a new one.', 'sanalsantral-sms-otp' ) );
		}
		set_transient( $key, $stored, max( 1, $stored['expires'] - time() ) );
		return new WP_Error( 'otp_invalid', __( 'The code is not correct.', 'sanalsantral-sms-otp' ) );
	}

	/**
	 * Seconds until a new code may be requested, 0 when allowed now.
	 */
	public static function cooldown_remaining( $phone, $context ) {
		list( $national ) = SS_SMS_Phone::normalize( $phone );
		if ( ! $national ) {
			return 0;
		}
		$stored = get_transient( self::key( $context, $national ) );
		if ( ! is_array( $stored ) ) {
			return 0;
		}
		return max( 0, (int) SS_SMS_OTP_Settings::get( 'otp_resend_cooldown' ) - ( time() - $stored['sent_at'] ) );
	}

	public static function forget( $phone, $context ) {
		list( $national ) = SS_SMS_Phone::normalize( $phone );
		if ( $national ) {
			delete_transient( self::key( $context, $national ) );
		}
	}

	private static function key( $context, $national ) {
		// Transient names are capped at 172 characters; hash the variable part.
		return self::PREFIX . md5( $context . '|' . $national );
	}

	private static function hash( $code, $context, $national ) {
		return hash_hmac( 'sha256', $code . '|' . $context . '|' . $national, wp_salt( 'auth' ) );
	}
}
