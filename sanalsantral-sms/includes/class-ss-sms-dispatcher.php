<?php
/**
 * Sends SMS through the client, logs every outcome and turns gateway
 * exceptions into WP_Error for the public API.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Dispatcher {

	/**
	 * Package rejections a per-message retry can still get past. An
	 * account-level problem (no credit, unknown sender header) fails
	 * identically for every message, so replaying it 500 times only wastes
	 * time and quota.
	 */
	const RETRYABLE_BULK_FAILURES = array( 'sms_duplicate', 'sms_server', 'unknown' );

	/** @var SS_SMS_Client */
	private $client;

	public function __construct( $client = null ) {
		$this->client = $client ? $client : new SS_SMS_Client();
	}

	/**
	 * @param string               $phone   Raw number.
	 * @param string               $message Body.
	 * @param array<string, mixed> $args    sender, is_otp, source.
	 * @return true|WP_Error
	 */
	public function send( $phone, $message, array $args = array() ) {
		$args = self::parse_args( $args );
		$row  = array(
			'number' => (string) $phone,
			'body'   => $message,
			'is_otp' => $args['is_otp'],
			'source' => $args['source'],
		);

		try {
			$row['sender'] = $this->client->sender( $args['sender'] );
			$sent          = $this->client->send( $phone, $message, $args['sender'] );
		} catch ( SS_SMS_Gateway_Error $e ) {
			$error = self::to_wp_error( $e );
			self::log_error( array( $row ), $error );
			return $error;
		}

		$row['number'] = $sent['number'];
		$row['pkg_id'] = $sent['pkg_id'];
		$row['state']  = 'sent';
		SS_SMS_Log::insert_many( array( $row ) );
		do_action( 'ss_sms_sent', $sent['number'], $args );
		return true;
	}

	/**
	 * Send many messages, in packages of the configured bulk size.
	 *
	 * @param array<int|string, array{0: string, 1: string}> $items [phone, message] pairs.
	 * @param array<string, mixed>                           $args  sender, source.
	 * @return array<int|string, true|WP_Error> Outcome per item, keys preserved.
	 */
	public function send_bulk( array $items, array $args = array() ) {
		$args    = self::parse_args( $args );
		$results = array();
		$keys    = array_keys( $items );
		$size    = $this->client->bulk_size();

		for ( $offset = 0; $offset < count( $keys ); $offset += $size ) {
			$batch = array();
			foreach ( array_slice( $keys, $offset, $size ) as $key ) {
				$batch[ $key ] = array_values( (array) $items[ $key ] ) + array( '', '' );
			}
			$results += $this->send_batch( $batch, $args );
		}
		return $results;
	}

	/**
	 * @param array<int|string, array{0: string, 1: string}> $batch Items.
	 * @param array<string, mixed>                           $args  Parsed args.
	 * @return array<int|string, true|WP_Error>
	 */
	private function send_batch( array $batch, array $args ) {
		$keys = array_keys( $batch );
		$rows = array();
		foreach ( $batch as $key => $item ) {
			$rows[ $key ] = array(
				'number' => (string) $item[0],
				'body'   => $item[1],
				'source' => $args['source'],
			);
		}

		try {
			$sender = $this->client->sender( $args['sender'] );
			$sent   = $this->client->send_bulk( array_values( $batch ), $args['sender'] );
		} catch ( SS_SMS_Gateway_Unknown_State $e ) {
			// The package may already be on its way. Say so, so nobody resends
			// the batch and bills the customer twice.
			return $this->fail_all( $rows, self::to_wp_error( $e ) );
		} catch ( SS_SMS_Gateway_Error $e ) {
			if ( $this->client->bulk_fallback_single() && in_array( $e->failure_type, self::RETRYABLE_BULK_FAILURES, true ) && count( $batch ) > 1 ) {
				// Nothing in the package was accepted, so replaying it one
				// message at a time cannot double-send, and it pins the
				// failure on the message that actually caused it.
				$results = array();
				foreach ( $batch as $key => $item ) {
					$results[ $key ] = $this->send( $item[0], $item[1], $args );
				}
				return $results;
			}
			return $this->fail_all( $rows, self::to_wp_error( $e ) );
		}

		$results = array();
		$ok_rows = array();
		foreach ( $keys as $index => $key ) {
			$row           = $rows[ $key ];
			$row['sender'] = $sender;
			if ( isset( $sent['invalid'][ $index ] ) ) {
				// Reported against its own message: a batch-wide error would
				// blame 500 recipients for one contact's typo.
				$error = new WP_Error(
					$sent['invalid'][ $index ],
					/* translators: %s: phone number */
					sprintf( __( 'Invalid phone number for SMS: %s', 'sanalsantral-sms' ), $row['number'] ),
					array( 'failure_type' => $sent['invalid'][ $index ] )
				);
				self::log_error( array( $row ), $error );
				$results[ $key ] = $error;
				continue;
			}
			$row['number']   = $sent['numbers'][ $index ];
			$row['pkg_id']   = $sent['pkg_id'];
			$row['state']    = 'sent';
			$ok_rows[]       = $row;
			$results[ $key ] = true;
		}
		SS_SMS_Log::insert_many( $ok_rows );
		return $results;
	}

	private function fail_all( array $rows, WP_Error $error ) {
		self::log_error( array_values( $rows ), $error );
		return array_fill_keys( array_keys( $rows ), $error );
	}

	private static function parse_args( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'sender' => null,
				'is_otp' => false,
				'source' => '',
			)
		);
		$args['is_otp'] = (bool) $args['is_otp'];
		return $args;
	}

	private static function to_wp_error( SS_SMS_Gateway_Error $e ) {
		$unknown = $e instanceof SS_SMS_Gateway_Unknown_State;
		$message = $e->text();
		if ( $unknown ) {
			$message = rtrim( $message, '. ' ) . '. ' . __( 'Delivery state unknown, check the gateway before resending.', 'sanalsantral-sms' );
		}
		return new WP_Error(
			$e->failure_type,
			$message,
			array(
				'failure_type'  => $e->failure_type,
				'unknown_state' => $unknown,
				'gateway_code'  => $e->gateway_code,
			)
		);
	}

	private static function log_error( array $rows, WP_Error $error ) {
		foreach ( $rows as &$row ) {
			$row['state']          = 'error';
			$row['failure_type']   = $error->get_error_code();
			$row['failure_reason'] = $error->get_error_message();
		}
		unset( $row );
		SS_SMS_Log::insert_many( $rows );
		foreach ( $rows as $row ) {
			do_action( 'ss_sms_failed', $row['number'], $error );
		}
	}
}
