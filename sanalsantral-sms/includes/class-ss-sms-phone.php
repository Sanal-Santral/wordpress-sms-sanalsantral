<?php
/**
 * Phone number handling for the Sanal Santral gateway.
 *
 * The gateway accepts exactly one shape: the Turkish national mobile number as
 * a 10-digit integer (5321234567). Everything a site stores (E.164, an
 * international prefix, a trunk zero, the bare national number, with or
 * without separators) has to converge to that before it reaches the API.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Phone {

	const TR_COUNTRY_CODE = '90';

	/**
	 * Reduce $phone_number to the gateway's canonical 10-digit form.
	 *
	 * Accepted inputs, separators and surrounding whitespace being irrelevant:
	 *
	 *     +905321234567   00905321234567   905321234567   05321234567   5321234567
	 *
	 * @param mixed $phone_number Raw number.
	 * @return array{0: string|null, 1: string|null} [national, failure_type].
	 *         national is the canonical 10-digit string, or null when the number
	 *         cannot be used, and then failure_type says why.
	 */
	public static function normalize( $phone_number ) {
		$raw    = is_scalar( $phone_number ) && false !== $phone_number ? trim( (string) $phone_number ) : '';
		$digits = preg_replace( '/\D/', '', $raw );
		if ( '' === $digits ) {
			return array( null, 'sms_number_missing' );
		}

		$national = $digits;
		// Peel the country code off, in whichever notation it arrived.
		if ( 0 === strpos( $national, '00' ) ) {
			$national = substr( $national, 2 );
		}
		if ( 0 === strpos( $national, self::TR_COUNTRY_CODE ) && in_array( strlen( $national ), array( 12, 13 ), true ) ) {
			$national = substr( $national, strlen( self::TR_COUNTRY_CODE ) );
		}
		if ( 0 === strpos( $national, '0' ) && 11 === strlen( $national ) ) {
			$national = substr( $national, 1 );
		}

		// Turkish mobile numbers are 10 digits and always start with 5.
		if ( preg_match( '/^5\d{9}$/', $national ) ) {
			return array( $national, null );
		}

		// Not reducible to a Turkish mobile number. An explicit international
		// prefix means the number belongs to another country, which this
		// gateway never sends to; anything else is simply malformed.
		if ( 0 === strpos( $raw, '+' ) || 0 === strpos( $digits, '00' ) ) {
			return array( null, 'sms_country_not_supported' );
		}
		return array( null, 'sms_number_format' );
	}

	/**
	 * Return the E.164 form (+905321234567) of a Turkish mobile number.
	 *
	 * @param mixed $phone_number Raw number.
	 * @return string|null Null when the number is not a Turkish mobile.
	 */
	public static function to_e164( $phone_number ) {
		list( $national ) = self::normalize( $phone_number );
		return $national ? '+' . self::TR_COUNTRY_CODE . $national : null;
	}
}
