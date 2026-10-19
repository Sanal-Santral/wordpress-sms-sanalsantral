<?php
/**
 * Public OTP API.
 *
 *     $sent = ss_sms_otp_send( $phone, 'newsletter-signup' );
 *     ...
 *     $ok = ss_sms_otp_verify( $phone, $_POST['code'], 'newsletter-signup' );
 *
 * @package SanalSantralSMSOTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send a one-time code by SMS.
 *
 * @param string               $phone   Turkish mobile number in any common notation.
 * @param string               $context What the code is for; verify with the same value.
 * @param array<string, mixed> $args    sender, source.
 * @return true|WP_Error otp_cooldown / otp_rate_limited carry retry_after in the error data.
 */
function ss_sms_otp_send( $phone, $context = 'default', array $args = array() ) {
	return SS_SMS_OTP::send( $phone, 'api:' . $context, $args );
}

/**
 * Check a code sent with ss_sms_otp_send(). A correct code is consumed.
 *
 * @return true|WP_Error otp_invalid, otp_expired or otp_too_many_attempts.
 */
function ss_sms_otp_verify( $phone, $code, $context = 'default' ) {
	return SS_SMS_OTP::verify( $phone, $code, 'api:' . $context );
}
