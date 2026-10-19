<?php
/**
 * Public API for themes and other plugins.
 *
 *     if ( function_exists( 'ss_sms_send' ) ) {
 *         $result = ss_sms_send( '0532 123 45 67', 'Siparişiniz kargoya verildi.' );
 *         if ( is_wp_error( $result ) ) { ... }
 *     }
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send one SMS.
 *
 * @param string               $phone   Any common Turkish mobile notation.
 * @param string               $message Body.
 * @param array<string, mixed> $args {
 *     @type string $sender Sender header; defaults to the configured one.
 *     @type bool   $is_otp Hide the body in the log.
 *     @type string $source Free label stored in the log, e.g. "order:123".
 * }
 * @return true|WP_Error The error code is the failure type (sms_number_format,
 *                       sms_credit, sms_acc, sms_server, ...). When
 *                       $error->get_error_data()['unknown_state'] is true the
 *                       message may still be delivered: do not resend blindly.
 */
function ss_sms_send( $phone, $message, array $args = array() ) {
	return ( new SS_SMS_Dispatcher() )->send( $phone, $message, $args );
}

/**
 * Send many SMS through the gateway's bulk endpoint.
 *
 * @param array<int|string, array{0: string, 1: string}> $items [phone, message] pairs.
 * @param array<string, mixed>                           $args  sender, source.
 * @return array<int|string, true|WP_Error> Outcome per item, keys preserved.
 */
function ss_sms_send_bulk( array $items, array $args = array() ) {
	return ( new SS_SMS_Dispatcher() )->send_bulk( $items, $args );
}

/**
 * @param mixed $phone Raw number.
 * @return string|null The 10-digit national mobile number (5321234567) or null.
 */
function ss_sms_normalize_phone( $phone ) {
	list( $national ) = SS_SMS_Phone::normalize( $phone );
	return $national;
}

/**
 * @param mixed $phone Raw number.
 * @return string|null +905321234567, or null when not a Turkish mobile.
 */
function ss_sms_to_e164( $phone ) {
	return SS_SMS_Phone::to_e164( $phone );
}

/**
 * The mobile number SMS for a user go to: the profile number, else the
 * WooCommerce billing phone.
 *
 * @param int|WP_User $user User.
 * @return string Raw stored number, '' when none.
 */
function ss_sms_user_phone( $user ) {
	$user_id = $user instanceof WP_User ? $user->ID : (int) $user;
	$phone   = (string) get_user_meta( $user_id, 'ss_sms_phone', true );
	if ( '' === $phone ) {
		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
	}
	/**
	 * Filters the phone number used for a user.
	 *
	 * @param string $phone   Raw number.
	 * @param int    $user_id User ID.
	 */
	return (string) apply_filters( 'ss_sms_user_phone', $phone, $user_id );
}

/**
 * Human-readable label for a failure type.
 *
 * @param string $failure_type Failure type.
 * @return string
 */
function ss_sms_failure_label( $failure_type ) {
	$labels = array(
		'sms_number_missing'        => __( 'Missing number', 'sanalsantral-sms' ),
		'sms_number_format'         => __( 'Wrong number format', 'sanalsantral-sms' ),
		'sms_country_not_supported' => __( 'Country not supported', 'sanalsantral-sms' ),
		'sms_credit'                => __( 'Insufficient credit', 'sanalsantral-sms' ),
		'sms_acc'                   => __( 'Account or sender problem', 'sanalsantral-sms' ),
		'sms_duplicate'             => __( 'Duplicate message', 'sanalsantral-sms' ),
		'sms_server'                => __( 'Gateway error', 'sanalsantral-sms' ),
		'unknown'                   => __( 'Unknown error', 'sanalsantral-sms' ),
	);
	return isset( $labels[ $failure_type ] ) ? $labels[ $failure_type ] : (string) $failure_type;
}

/**
 * GSM 03.38 (with the Turkish national single-shift table) vs UCS-2 part
 * count for a message, mirroring what the gateway bills.
 *
 * @param string $message  Body.
 * @param int    $encoding 0 = GSM, 1 = Unicode.
 * @return array{chars: int, parts: int}
 */
function ss_sms_message_parts( $message, $encoding ) {
	$chars = function_exists( 'mb_strlen' ) ? mb_strlen( $message, 'UTF-8' ) : strlen( $message );
	list( $single, $multi ) = 1 === (int) $encoding ? array( 70, 67 ) : array( 160, 153 );
	$parts = $chars <= $single ? 1 : (int) ceil( $chars / $multi );
	return array(
		'chars' => $chars,
		'parts' => max( 1, $parts ),
	);
}

/**
 * Text the customer that the order has shipped (WooCommerce).
 *
 * Idempotent per tracking number: calling it again with the same number does
 * nothing unless force is set, so integrations can call it on every sync.
 *
 *     ss_sms_wc_send_shipping( $order_id, array(
 *         'tracking_number' => 'KRG1234567890',
 *         'carrier'         => 'Örnek Kargo',                  // optional
 *         'tracking_url'    => 'https://...?no=KRG1234567890',    // optional
 *     ) );
 *
 * @param WC_Order|int         $order Order or order ID.
 * @param array<string, mixed> $args  tracking_number (required), carrier, tracking_url, force.
 * @return true|WP_Error
 */
function ss_sms_wc_send_shipping( $order, array $args = array() ) {
	if ( ! class_exists( 'SS_SMS_WooCommerce' ) ) {
		return new WP_Error( 'ss_sms_no_woocommerce', __( 'WooCommerce is not active.', 'sanalsantral-sms' ) );
	}
	return SS_SMS_WooCommerce::send_shipping( $order, $args );
}
