<?php
/**
 * WooCommerce order notifications: status SMS and the shipping (cargo) SMS.
 *
 * The shipping SMS has no form of its own: tracking numbers come from cargo
 * plugins, ERPs and carrier integrations, so it is sent through an API.
 *
 *     PHP:    ss_sms_wc_send_shipping( $order, array( 'tracking_number' => 'KRG123' ) );
 *     Action: do_action( 'ss_sms_wc_shipped', $order_id, array( 'tracking_number' => 'KRG123' ) );
 *     REST:   POST /wp-json/sanalsantral-sms/v1/orders/{id}/shipping
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_WooCommerce {

	const OPTION   = 'ss_sms_wc_settings';
	const SHIPPING = 'shipping';

	const META_TRACKING = '_ss_sms_tracking_number';
	const META_CARRIER  = '_ss_sms_carrier';
	const META_URL      = '_ss_sms_tracking_url';
	/** Tracking number the last shipping SMS went out for, and when. */
	const META_SENT    = '_ss_sms_shipping_sent';
	const META_SENT_AT = '_ss_sms_shipping_sent_at';

	const REST_NAMESPACE = 'sanalsantral-sms/v1';

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'process_meta_box' ), 50 );
		add_action( 'ss_sms_wc_shipped', array( __CLASS__, 'on_shipped_action' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'         => false,
			'statuses'        => array( 'processing', 'completed' ),
			'templates'       => array(
				/* translators: SMS template; keep the {placeholders}. */
				'pending'      => __( 'Hello {first_name}, order {order_number} is awaiting payment. {site}', 'sanalsantral-sms' ),
				/* translators: SMS template; keep the {placeholders}. */
				'processing'   => __( 'Hello {first_name}, we received order {order_number} and are preparing it. {site}', 'sanalsantral-sms' ),
				/* translators: SMS template; keep the {placeholders}. */
				'on-hold'      => __( 'Order {order_number} is on hold. We will be in touch shortly. {site}', 'sanalsantral-sms' ),
				/* translators: SMS template; keep the {placeholders}. */
				'completed'    => __( 'Order {order_number} is complete. Thank you for shopping with us! {site}', 'sanalsantral-sms' ),
				/* translators: SMS template; keep the {placeholders}. */
				'cancelled'    => __( 'Order {order_number} has been cancelled. {site}', 'sanalsantral-sms' ),
				/* translators: SMS template; keep the {placeholders}. */
				'refunded'     => __( 'Order {order_number} has been refunded. {site}', 'sanalsantral-sms' ),
				/* translators: SMS template; keep the {placeholders}. */
				'failed'       => __( 'Payment for order {order_number} failed. {site}', 'sanalsantral-sms' ),
				/* translators: SMS template; keep the {placeholders}. */
				self::SHIPPING => __( 'Order {order_number} has been shipped with {carrier}. Tracking number: {tracking_number} {tracking_url}', 'sanalsantral-sms' ),
			),
			'tracking_url'    => '',
			'default_carrier' => '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function settings() {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();
		$settings = wp_parse_args( $stored, $defaults );
		// Statuses added later (or by other plugins) still get a template.
		$settings['templates'] = array_merge( $defaults['templates'], is_array( $settings['templates'] ) ? array_filter( $settings['templates'] ) : array() );
		return $settings;
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$out   = self::defaults();

		$out['enabled']  = ! empty( $input['enabled'] );
		$out['statuses'] = array();
		if ( ! empty( $input['statuses'] ) && is_array( $input['statuses'] ) ) {
			$out['statuses'] = array_values( array_map( 'sanitize_key', $input['statuses'] ) );
		}
		if ( ! empty( $input['templates'] ) && is_array( $input['templates'] ) ) {
			foreach ( $input['templates'] as $status => $template ) {
				$out['templates'][ sanitize_key( $status ) ] = sanitize_textarea_field( $template );
			}
		}
		$out['tracking_url']    = isset( $input['tracking_url'] ) ? sanitize_text_field( $input['tracking_url'] ) : '';
		$out['default_carrier'] = isset( $input['default_carrier'] ) ? sanitize_text_field( $input['default_carrier'] ) : '';
		return $out;
	}

	/**
	 * @return array<string, string> status slug (without wc-) => label.
	 */
	public static function statuses() {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$statuses[ preg_replace( '/^wc-/', '', $slug ) ] = $label;
		}
		unset( $statuses['checkout-draft'] );
		return $statuses;
	}

	/* ------------------------------------------------------------------ */
	/* Status SMS                                                           */
	/* ------------------------------------------------------------------ */

	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		$s = self::settings();
		if ( ! $s['enabled'] || ! in_array( $to, (array) $s['statuses'], true ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$template = isset( $s['templates'][ $to ] ) ? $s['templates'][ $to ] : '';
		/**
		 * Filters whether a status SMS goes out for an order.
		 *
		 * @param bool     $send  Whether to send.
		 * @param WC_Order $order Order.
		 * @param string   $to    New status.
		 */
		if ( '' === trim( $template ) || ! apply_filters( 'ss_sms_wc_send_status_sms', true, $order, $to ) ) {
			return;
		}
		self::send_to_order( $order, $template, 'status:' . $to );
	}

	/* ------------------------------------------------------------------ */
	/* Shipping SMS (API only)                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Record the shipment on the order and text the customer.
	 *
	 * Idempotent per tracking number: an integration that retries, or fires
	 * on every order save, does not text the customer twice. Pass force to
	 * resend on purpose.
	 *
	 * @param WC_Order|int         $order Order or order ID.
	 * @param array<string, mixed> $args {
	 *     @type string $tracking_number Required.
	 *     @type string $carrier         Defaults to the "Default carrier" setting.
	 *     @type string $tracking_url    Defaults to the "Tracking URL" pattern.
	 *     @type bool   $force           Send even if this tracking number was already texted.
	 * }
	 * @return true|WP_Error Error codes: ss_sms_order_not_found, ss_sms_missing_tracking,
	 *                       ss_sms_already_sent, or a gateway failure type.
	 */
	public static function send_shipping( $order, array $args = array() ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order ) {
			return new WP_Error( 'ss_sms_order_not_found', __( 'Order not found.', 'sanalsantral-sms' ), array( 'status' => 404 ) );
		}
		$tracking = isset( $args['tracking_number'] ) ? trim( sanitize_text_field( (string) $args['tracking_number'] ) ) : '';
		if ( '' === $tracking ) {
			return new WP_Error( 'ss_sms_missing_tracking', __( 'A tracking number is required for the shipping SMS.', 'sanalsantral-sms' ), array( 'status' => 400 ) );
		}
		if ( empty( $args['force'] ) && $tracking === (string) $order->get_meta( self::META_SENT ) ) {
			return new WP_Error(
				'ss_sms_already_sent',
				/* translators: %s: tracking number */
				sprintf( __( 'The shipping SMS for tracking number %s was already sent.', 'sanalsantral-sms' ), $tracking ),
				array( 'status' => 409 )
			);
		}

		$order->update_meta_data( self::META_TRACKING, $tracking );
		if ( isset( $args['carrier'] ) && '' !== trim( (string) $args['carrier'] ) ) {
			$order->update_meta_data( self::META_CARRIER, sanitize_text_field( (string) $args['carrier'] ) );
		}
		if ( isset( $args['tracking_url'] ) && '' !== trim( (string) $args['tracking_url'] ) ) {
			$order->update_meta_data( self::META_URL, esc_url_raw( (string) $args['tracking_url'] ) );
		} else {
			$order->delete_meta_data( self::META_URL );
		}
		$order->save();

		$result = self::send_to_order( $order, self::settings()['templates'][ self::SHIPPING ], self::SHIPPING );
		if ( true === $result ) {
			$order->update_meta_data( self::META_SENT, $tracking );
			$order->update_meta_data( self::META_SENT_AT, current_time( 'mysql', true ) );
			$order->save();
			/**
			 * Fires after a shipping SMS went out.
			 *
			 * @param WC_Order $order    Order.
			 * @param string   $tracking Tracking number.
			 */
			do_action( 'ss_sms_wc_shipping_sms_sent', $order, $tracking );
		}
		return $result;
	}

	/**
	 * Listener for do_action( 'ss_sms_wc_shipped', $order_id, $args ): lets
	 * cargo plugins announce a shipment without depending on this plugin.
	 */
	public static function on_shipped_action( $order_id, $args = array() ) {
		$args = is_array( $args ) ? $args : array( 'tracking_number' => (string) $args );
		self::send_shipping( $order_id, $args );
	}

	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/shipping',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_send_shipping' ),
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return current_user_can( 'edit_shop_orders' );
				},
				'args'                => array(
					'id'              => array(
						'type'     => 'integer',
						'required' => true,
					),
					'tracking_number' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Tracking number from the carrier.',
					),
					'carrier'         => array(
						'type'        => 'string',
						'description' => 'Carrier name; defaults to the "Default carrier" setting.',
					),
					'tracking_url'    => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'Tracking link; defaults to the "Tracking URL" pattern.',
					),
					'force'           => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Resend even if this tracking number was already texted.',
					),
				),
			)
		);
	}

	public static function rest_send_shipping( WP_REST_Request $request ) {
		$order_id = (int) $request['id'];
		$result   = self::send_shipping(
			$order_id,
			array(
				'tracking_number' => $request['tracking_number'],
				'carrier'         => $request['carrier'],
				'tracking_url'    => $request['tracking_url'],
				'force'           => $request['force'],
			)
		);
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				// Gateway failures: the SMS was not delivered, but the request was fine.
				$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 502 ) ) );
			}
			return $result;
		}
		$order    = wc_get_order( $order_id );
		$tracking = self::tracking( $order );
		return rest_ensure_response(
			array(
				'sent'            => true,
				'order_id'        => $order_id,
				'tracking_number' => $tracking['number'],
				'carrier'         => $tracking['carrier'],
				'tracking_url'    => $tracking['url'],
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Sending and templates                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Send $template to the order's customer and leave a note on the order.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $template Template with {placeholders}.
	 * @param string   $kind     Log label.
	 * @return true|WP_Error
	 */
	public static function send_to_order( $order, $template, $kind ) {
		$phone = $order->get_billing_phone();
		if ( ! $phone && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = $order->get_shipping_phone();
		}
		$message = self::render( $template, $order );
		$result  = ss_sms_send( $phone, $message, array( 'source' => 'order:' . $order->get_id() . ':' . $kind ) );

		if ( is_wp_error( $result ) ) {
			/* translators: 1: error, 2: phone number */
			$order->add_order_note( sprintf( __( 'SMS could not be sent to %2$s: %1$s', 'sanalsantral-sms' ), $result->get_error_message(), $phone ? $phone : __( 'no number', 'sanalsantral-sms' ) ) );
		} else {
			/* translators: 1: phone number, 2: message */
			$order->add_order_note( sprintf( __( 'SMS sent to %1$s: %2$s', 'sanalsantral-sms' ), $phone, $message ) );
		}
		return $result;
	}

	/**
	 * @param string   $template Template.
	 * @param WC_Order $order    Order.
	 * @return string
	 */
	public static function render( $template, $order ) {
		$s        = self::settings();
		$tracking = self::tracking( $order );
		$url      = $tracking['url'];
		if ( ! $url && $s['tracking_url'] && $tracking['number'] ) {
			$url = str_replace( '{tracking_number}', rawurlencode( $tracking['number'] ), $s['tracking_url'] );
		}

		$replacements = array(
			'{site}'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{first_name}'      => $order->get_billing_first_name(),
			'{last_name}'       => $order->get_billing_last_name(),
			'{order_number}'    => $order->get_order_number(),
			'{order_status}'    => wc_get_order_status_name( $order->get_status() ),
			'{order_total}'     => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			'{order_url}'       => $order->get_view_order_url(),
			'{tracking_number}' => $tracking['number'],
			'{carrier}'         => $tracking['carrier'],
			'{tracking_url}'    => $url,
		);
		/**
		 * Filters the placeholders available in order SMS templates.
		 *
		 * @param array<string, string> $replacements Placeholder => value.
		 * @param WC_Order              $order        Order.
		 */
		$replacements = apply_filters( 'ss_sms_wc_placeholders', $replacements, $order );
		return trim( preg_replace( '/[ \t]{2,}/', ' ', strtr( $template, $replacements ) ) );
	}

	/**
	 * Shipment details: what the API stored, else the WooCommerce Shipment
	 * Tracking extension.
	 *
	 * @param WC_Order $order Order.
	 * @return array{number: string, carrier: string, url: string}
	 */
	public static function tracking( $order ) {
		$s       = self::settings();
		$number  = (string) $order->get_meta( self::META_TRACKING );
		$carrier = (string) $order->get_meta( self::META_CARRIER );
		$url     = (string) $order->get_meta( self::META_URL );

		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( '' === $number && is_array( $items ) && $items ) {
			$last    = end( $items );
			$number  = isset( $last['tracking_number'] ) ? (string) $last['tracking_number'] : '';
			$carrier = ! empty( $last['custom_tracking_provider'] ) ? $last['custom_tracking_provider'] : ( isset( $last['tracking_provider'] ) ? (string) $last['tracking_provider'] : '' );
			$url     = isset( $last['custom_tracking_link'] ) ? (string) $last['custom_tracking_link'] : '';
		}
		if ( '' === $carrier ) {
			$carrier = (string) $s['default_carrier'];
		}
		$tracking = array(
			'number'  => $number,
			'carrier' => $carrier,
			'url'     => $url,
		);
		/**
		 * Filters the shipment details used by the order SMS templates, e.g.
		 * to read them from a cargo plugin's own order meta.
		 *
		 * @param array{number: string, carrier: string, url: string} $tracking Details.
		 * @param WC_Order                                             $order    Order.
		 */
		return apply_filters( 'ss_sms_wc_tracking', $tracking, $order );
	}

	/* ------------------------------------------------------------------ */
	/* Order screen                                                         */
	/* ------------------------------------------------------------------ */

	public static function add_meta_box( $post_type, $post_or_order ) {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		add_meta_box( 'ss-sms-order', __( 'SMS', 'sanalsantral-sms' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$phone   = $order->get_billing_phone();
		$sent    = (string) $order->get_meta( self::META_SENT );
		$sent_at = (string) $order->get_meta( self::META_SENT_AT );
		wp_nonce_field( 'ss_sms_order_' . $order->get_id(), 'ss_sms_order_nonce' );
		?>
		<p>
			<?php esc_html_e( 'Customer phone:', 'sanalsantral-sms' ); ?>
			<strong><?php echo esc_html( $phone ? $phone : __( 'not set', 'sanalsantral-sms' ) ); ?></strong>
			<?php if ( $phone && ! ss_sms_normalize_phone( $phone ) ) : ?>
				<br /><span style="color:#b32d2e"><?php esc_html_e( 'Not a Turkish mobile number; SMS cannot be sent.', 'sanalsantral-sms' ); ?></span>
			<?php endif; ?>
		</p>
		<?php if ( '' !== $sent ) : ?>
			<p>
				<?php
				$tracking = self::tracking( $order );
				printf(
					/* translators: 1: carrier, 2: tracking number, 3: date */
					esc_html__( 'Shipping SMS sent: %1$s, %2$s (%3$s)', 'sanalsantral-sms' ),
					esc_html( $tracking['carrier'] ),
					'<code>' . esc_html( $sent ) . '</code>',
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $sent_at . ' UTC' ) ) )
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<label for="ss_sms_custom"><strong><?php esc_html_e( 'Custom SMS', 'sanalsantral-sms' ); ?></strong></label>
			<textarea class="widefat" rows="3" id="ss_sms_custom" name="ss_sms_custom" data-ss-sms-counter="1"></textarea>
		</p>
		<p>
			<button type="submit" class="button" name="ss_sms_action" value="custom"><?php esc_html_e( 'Send SMS', 'sanalsantral-sms' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'Placeholders: {first_name}, {order_number}, {order_status}, {order_total}, {carrier}, {tracking_number}, {tracking_url}, {site}', 'sanalsantral-sms' ); ?></p>
		<?php
	}

	public static function process_meta_box( $order_id ) {
		if ( ! isset( $_POST['ss_sms_order_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ss_sms_order_nonce'] ) ), 'ss_sms_order_' . $order_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$action = isset( $_POST['ss_sms_action'] ) ? sanitize_key( $_POST['ss_sms_action'] ) : '';
		$text   = isset( $_POST['ss_sms_custom'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ss_sms_custom'] ) ) : '';
		if ( 'custom' !== $action || '' === trim( $text ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$result = self::send_to_order( $order, $text, 'custom' );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error */
			SS_SMS_Admin::notice( 'error', sprintf( __( 'SMS could not be sent: %s', 'sanalsantral-sms' ), $result->get_error_message() ) );
		} else {
			SS_SMS_Admin::notice( 'success', __( 'SMS sent.', 'sanalsantral-sms' ) );
		}
	}
}
