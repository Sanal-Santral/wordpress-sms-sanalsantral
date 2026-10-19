<?php
/**
 * Gateway exceptions.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * The gateway refused the request; nothing was handed over for delivery.
 *
 * `failure_type` is one of the plugin's failure types (see SS_SMS_Phone and
 * SS_SMS_Client::failure_type()) so callers can react to the reason.
 */
class SS_SMS_Gateway_Error extends Exception {

	/** @var string */
	public $failure_type;

	/** @var string|null Gateway error code, e.g. ERR_SMS_PKG_DUPLICATION. */
	public $gateway_code;

	public function __construct( $message, $failure_type = 'sms_server', $gateway_code = null, $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->failure_type = $failure_type;
		$this->gateway_code = $gateway_code;
	}

	/**
	 * The message as plain text. Messages are HTML-escaped when thrown, as
	 * WordPress coding standards require; everything downstream (WP_Error,
	 * log, notices) stores plain text and escapes on output.
	 *
	 * @return string
	 */
	public function text() {
		return wp_specialchars_decode( $this->getMessage(), ENT_QUOTES );
	}
}

/**
 * The request left WordPress but its outcome could not be read.
 *
 * The messages may or may not be on their way, so they must never be replayed
 * automatically: doing so risks billing and delivering the whole batch twice.
 */
class SS_SMS_Gateway_Unknown_State extends SS_SMS_Gateway_Error {
}
