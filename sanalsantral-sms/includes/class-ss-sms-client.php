<?php
/**
 * Low-level client for the Sanal Santral SMS gateway.
 *
 * Talks HTTP only; logging what was sent and to whom is the dispatcher's job.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Client {

	/** Single sends sit on the login path, so they never wait for the bulk timeout. */
	const SINGLE_TIMEOUT = 15;

	/**
	 * Gateway error codes we know how to translate into a failure type.
	 * Anything unlisted falls through to the keyword heuristics below.
	 */
	const API_FAILURE_TYPES = array(
		'ERR_SMS_PKG_DUPLICATION' => 'sms_duplicate',
	);

	/**
	 * Best-effort matching on the error code, so a gateway-side rename does
	 * not silently degrade every failure back to a generic server error.
	 */
	const API_FAILURE_KEYWORDS = array(
		array( 'DUPLICAT', 'sms_duplicate' ),
		array( 'BALANCE', 'sms_credit' ),
		array( 'CREDIT', 'sms_credit' ),
		array( 'KREDI', 'sms_credit' ),
		array( 'SENDER', 'sms_acc' ),
		array( 'BASLIK', 'sms_acc' ),
		array( 'AUTH', 'sms_acc' ),
		array( 'PERMISSION', 'sms_acc' ),
		array( 'FORBIDDEN', 'sms_acc' ),
		array( 'NUMBER', 'sms_number_format' ),
		array( 'NUMARA', 'sms_number_format' ),
	);

	/** @var array<string, mixed> */
	private $settings;

	/**
	 * @param array<string, mixed>|null $settings Defaults to the saved settings.
	 */
	public function __construct( $settings = null ) {
		$this->settings = null === $settings ? SS_SMS_Settings::all() : wp_parse_args( $settings, SS_SMS_Settings::defaults() );
	}

	/**
	 * @param string|null $code        Gateway error code.
	 * @param int|null    $http_status HTTP status code.
	 * @return string Failure type.
	 */
	public static function failure_type( $code, $http_status = null ) {
		$code = strtoupper( (string) $code );
		if ( isset( self::API_FAILURE_TYPES[ $code ] ) ) {
			return self::API_FAILURE_TYPES[ $code ];
		}
		foreach ( self::API_FAILURE_KEYWORDS as $pair ) {
			if ( '' !== $code && false !== strpos( $code, $pair[0] ) ) {
				return $pair[1];
			}
		}
		if ( in_array( $http_status, array( 401, 403 ), true ) ) {
			return 'sms_acc';
		}
		if ( 402 === $http_status ) {
			return 'sms_credit';
		}
		return 'sms_server';
	}

	/**
	 * @param string|null $override Sender header to use instead of the default.
	 * @return string
	 * @throws SS_SMS_Gateway_Error When no sender is configured.
	 */
	public function sender( $override = null ) {
		$sender = $override ? $override : $this->settings['sender'];
		if ( ! $sender ) {
			throw new SS_SMS_Gateway_Error(
				esc_html( __( 'No SMS sender header is configured. Sync the senders and select one.', 'sanalsantral-sms' ) ),
				'sms_acc'
			);
		}
		return $sender;
	}

	public function bulk_size() {
		return max( 1, (int) $this->settings['bulk_size'] );
	}

	public function bulk_fallback_single() {
		return (bool) $this->settings['bulk_fallback_single'];
	}

	/**
	 * POST $payload to $path and return the gateway's `data` block.
	 *
	 * Every failure mode is funnelled into one of two exceptions so callers
	 * can tell apart "definitely not sent" from "we have no idea", which is
	 * the difference between a safe retry and a double send.
	 *
	 * @param string               $path    API path, e.g. /sms/create.
	 * @param array<string, mixed> $payload Request body.
	 * @param int|null             $timeout Seconds.
	 * @return array<string, mixed>
	 * @throws SS_SMS_Gateway_Unknown_State The outcome could not be determined.
	 * @throws SS_SMS_Gateway_Error         The gateway rejected the request outright.
	 */
	public function call_api( $path, array $payload, $timeout = null ) {
		$s = $this->settings;
		if ( '' === $s['username'] || '' === $s['api_key'] ) {
			throw new SS_SMS_Gateway_Error( esc_html( __( 'The SMS gateway credentials are not configured.', 'sanalsantral-sms' ) ), 'sms_acc' );
		}
		$timeout = $timeout ? $timeout : (int) $s['api_timeout'];
		$url     = untrailingslashit( $s['api_endpoint'] ) . $path;

		// Not wp_safe_remote_post(): it refuses the gateway's non-standard port.
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $s['username'] . ':' . $s['api_key'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( preg_match( '/cURL error 28|timed out/i', $message ) ) {
				// The request may have gone out; we just never saw the answer.
				throw new SS_SMS_Gateway_Unknown_State(
					/* translators: 1: seconds, 2: transport error */
					esc_html( sprintf( __( 'The SMS gateway did not answer within %1$ds: %2$s', 'sanalsantral-sms' ), $timeout, $message ) )
				);
			}
			/* translators: %s: transport error */
			throw new SS_SMS_Gateway_Error( esc_html( sprintf( __( 'Could not reach the SMS gateway: %s', 'sanalsantral-sms' ), $message ) ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$body   = substr( $raw, 0, 500 );

		$decoded = json_decode( $raw );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			if ( $status >= 400 ) {
				/* translators: 1: HTTP status, 2: response body */
				throw new SS_SMS_Gateway_Error( esc_html( sprintf( __( 'The SMS gateway returned HTTP %1$d: %2$s', 'sanalsantral-sms' ), $status, $body ) ), esc_html( self::failure_type( null, $status ) ) );
			}
			// A 2xx we cannot parse: the send may have gone through.
			/* translators: 1: HTTP status, 2: response body */
			throw new SS_SMS_Gateway_Unknown_State( esc_html( sprintf( __( 'The SMS gateway returned a non-JSON response (HTTP %1$d): %2$s', 'sanalsantral-sms' ), $status, $body ) ) );
		}
		if ( ! is_object( $decoded ) ) {
			/* translators: 1: HTTP status, 2: response body */
			throw new SS_SMS_Gateway_Unknown_State( esc_html( sprintf( __( 'The SMS gateway returned an unexpected payload (HTTP %1$d): %2$s', 'sanalsantral-sms' ), $status, $body ) ) );
		}
		$data = json_decode( $raw, true );

		if ( ! empty( $data['err'] ) ) {
			$error = $data['err'];
			// 'err' is documented as an object, but a bare string is cheap to support.
			if ( is_array( $error ) ) {
				$code    = isset( $error['code'] ) ? (string) $error['code'] : null;
				$e_state = isset( $error['status'] ) ? $error['status'] : null;
				$message = isset( $error['message'] ) ? $error['message'] : '';
			} else {
				$code    = null;
				$e_state = null;
				$message = (string) $error;
			}
			throw new SS_SMS_Gateway_Error(
				esc_html( sprintf( 'SMS API Error: Code=%s, Status=%s, Message=%s', $code, $e_state, is_scalar( $message ) ? $message : wp_json_encode( $message ) ) ),
				esc_html( self::failure_type( $code, $status ) ),
				esc_html( (string) $code )
			);
		}

		if ( $status >= 400 ) {
			/* translators: 1: HTTP status, 2: response body */
			throw new SS_SMS_Gateway_Error( esc_html( sprintf( __( 'The SMS gateway returned HTTP %1$d: %2$s', 'sanalsantral-sms' ), $status, $body ) ), esc_html( self::failure_type( null, $status ) ) );
		}

		return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
	}

	/**
	 * Send one SMS.
	 *
	 * @param string      $phone_number Raw number.
	 * @param string      $message      Body.
	 * @param string|null $sender       Sender header override.
	 * @return array{number: string, pkg_id: mixed}
	 * @throws SS_SMS_Gateway_Error On any problem.
	 */
	public function send( $phone_number, $message, $sender = null ) {
		$sender = $this->sender( $sender );
		list( $national, $failure_type ) = SS_SMS_Phone::normalize( $phone_number );
		if ( ! $national ) {
			/* translators: %s: phone number */
			throw new SS_SMS_Gateway_Error( esc_html( sprintf( __( 'Invalid phone number for SMS: %s', 'sanalsantral-sms' ), (string) $phone_number ) ), esc_html( $failure_type ) );
		}

		$data = $this->call_api(
			'/sms/create',
			array(
				'type'             => 1,
				'sendingType'      => 0,
				'title'            => 'SMS Notification',
				'content'          => (string) $message,
				'number'           => (int) $national,
				'encoding'         => (int) $this->settings['encoding'],
				'sender'           => $sender,
				'periodicSettings' => null,
				'sendingDate'      => null,
				'validity'         => 60,
				'pushSettings'     => null,
			),
			self::SINGLE_TIMEOUT
		);

		return array(
			'number' => $national,
			'pkg_id' => isset( $data['pkgID'] ) ? $data['pkgID'] : null,
		);
	}

	/**
	 * Send several SMS through a single N-to-N gateway call.
	 *
	 * Numbers that cannot be reduced to the gateway's format are reported back
	 * rather than aborting the call: one malformed contact must never sink an
	 * entire campaign.
	 *
	 * @param array<int, array{0: string, 1: string}> $items  [phone, message] pairs.
	 * @param string|null                             $sender Sender header override.
	 * @return array{invalid: array<int, string>, numbers: array<int, string>, pkg_id: mixed}
	 *         invalid maps item index to failure type; everything else was
	 *         accepted by the gateway. numbers holds the normalized numbers.
	 * @throws SS_SMS_Gateway_Error         The gateway rejected the whole package.
	 * @throws SS_SMS_Gateway_Unknown_State The fate of the package is unknown.
	 */
	public function send_bulk( array $items, $sender = null ) {
		$items  = array_values( $items );
		$result = array(
			'invalid' => array(),
			'numbers' => array(),
			'pkg_id'  => null,
		);
		if ( ! $items ) {
			return $result;
		}

		$sender  = $this->sender( $sender );
		$numbers = array();
		foreach ( $items as $index => $item ) {
			list( $national, $failure_type ) = SS_SMS_Phone::normalize( $item[0] );
			if ( ! $national ) {
				$result['invalid'][ $index ] = $failure_type;
				continue;
			}
			$result['numbers'][ $index ] = $national;
			// The API expects a numeric 'nr' (see the postman N-to-N example).
			$numbers[] = array(
				'nr'  => (int) $national,
				'msg' => (string) $item[1],
			);
		}
		if ( ! $numbers ) {
			return $result;
		}

		$data = $this->call_api(
			'/sms/create',
			array(
				'type'             => 1,
				'sendingType'      => 2,
				'title'            => 'WordPress SMS',
				'content'          => '',
				'numbers'          => $numbers,
				'encoding'         => (int) $this->settings['encoding'],
				'sender'           => $sender,
				'periodicSettings' => null,
				'sendingDate'      => null,
				'validity'         => 60,
				'pushSettings'     => null,
			)
		);

		$result['pkg_id'] = isset( $data['pkgID'] ) ? $data['pkgID'] : null;
		return $result;
	}

	/**
	 * Fetch every sender header the account owns.
	 *
	 * @return array<int, array{uuid: string, title: string, status: mixed}>
	 * @throws SS_SMS_Gateway_Error On any problem.
	 */
	public function fetch_senders() {
		$page_index = 0;
		$page_size  = 100;
		// A gateway that keeps answering with a full page would otherwise spin
		// here forever; 100 pages is far past any real sender list.
		$max_pages    = 100;
		$aggregated   = array();
		$total_record = null;

		while ( $page_index < $max_pages ) {
			$data = $this->call_api(
				'/sms/list-sender',
				array(
					'pageIndex' => $page_index,
					'pageSize'  => $page_size,
				),
				self::SINGLE_TIMEOUT
			);
			$page_list = isset( $data['list'] ) && is_array( $data['list'] ) ? $data['list'] : array();
			if ( null === $total_record && isset( $data['stats']['totalRecord'] ) && is_int( $data['stats']['totalRecord'] ) ) {
				$total_record = $data['stats']['totalRecord'];
			}
			$aggregated = array_merge( $aggregated, $page_list );

			if ( ( null !== $total_record && count( $aggregated ) >= $total_record ) || count( $page_list ) < $page_size ) {
				break;
			}
			++$page_index;
		}

		$senders = array();
		foreach ( $aggregated as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$uuid  = isset( $s['uuid'] ) ? $s['uuid'] : ( isset( $s['id'] ) ? $s['id'] : ( isset( $s['name'] ) ? $s['name'] : '' ) );
			$title = isset( $s['title'] ) ? $s['title'] : ( isset( $s['name'] ) ? $s['name'] : $uuid );
			if ( '' === (string) $title ) {
				continue;
			}
			// Keyed by uuid (or title) so a repeated page cannot duplicate a sender.
			$senders[ (string) ( $uuid ? $uuid : $title ) ] = array(
				'uuid'   => (string) $uuid,
				'title'  => (string) $title,
				'status' => isset( $s['status'] ) ? $s['status'] : null,
			);
		}
		return array_values( $senders );
	}
}
