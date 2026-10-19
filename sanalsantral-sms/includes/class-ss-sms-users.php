<?php
/**
 * Per-user mobile number and marketing consent.
 *
 * Commercial SMS in Turkey needs the recipient's consent (and an İYS
 * registration on the sender side); the bulk sender therefore only targets
 * people who ticked the opt-in box, unless the operator pastes numbers in.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Users {

	const PHONE_META     = 'ss_sms_phone';
	const OPTIN_META     = 'ss_sms_marketing_optin';
	const ORDER_OPTIN    = '_ss_sms_marketing_optin';
	const CHECKOUT_FIELD = 'sanalsantral/sms-marketing';

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ), 5 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate_profile' ), 10, 3 );

		// WooCommerce: My Account and checkout.
		add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'account_fields' ) );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'save_account' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'classic_checkout_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_checkout_save' ), 10, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_checkout_field' ) );
		add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'block_checkout_save' ), 10, 4 );
	}

	public static function profile_fields( $user ) {
		$phone = (string) get_user_meta( $user->ID, self::PHONE_META, true );
		$optin = '1' === get_user_meta( $user->ID, self::OPTIN_META, true );
		?>
		<h2 id="ss-sms"><?php esc_html_e( 'SMS', 'sanalsantral-sms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ss_sms_phone"><?php esc_html_e( 'Mobile phone', 'sanalsantral-sms' ); ?></label></th>
				<td>
					<input type="tel" name="ss_sms_phone" id="ss_sms_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" placeholder="05XX XXX XX XX" autocomplete="tel" />
					<p class="description"><?php esc_html_e( 'Turkish mobile number used for SMS. When empty, the WooCommerce billing phone is used.', 'sanalsantral-sms' ); ?></p>
					<?php do_action( 'ss_sms_profile_phone_after', $user ); ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Campaign SMS', 'sanalsantral-sms' ); ?></th>
				<td>
					<label><input type="checkbox" name="ss_sms_marketing_optin" value="1" <?php checked( $optin ); ?> /> <?php esc_html_e( 'I agree to receive campaign and promotion messages by SMS.', 'sanalsantral-sms' ); ?></label>
				</td>
			</tr>
			<?php do_action( 'ss_sms_profile_rows', $user ); ?>
		</table>
		<?php
	}

	public static function validate_profile( $errors, $update, $user ) {
		if ( isset( $_POST['ss_sms_phone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verified the profile nonce.
			$phone = trim( sanitize_text_field( wp_unslash( $_POST['ss_sms_phone'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' !== $phone && ! ss_sms_normalize_phone( $phone ) ) {
				$errors->add( 'ss_sms_phone', __( '<strong>Error:</strong> The mobile phone must be a Turkish mobile number (05XX XXX XX XX).', 'sanalsantral-sms' ) );
			}
		}
	}

	public static function save_profile( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core verified the profile nonce.
		if ( isset( $_POST['ss_sms_phone'] ) ) {
			self::set_phone( $user_id, sanitize_text_field( wp_unslash( $_POST['ss_sms_phone'] ) ) );
		}
		self::set_optin( $user_id, ! empty( $_POST['ss_sms_marketing_optin'] ) );
		// phpcs:enable
	}

	/**
	 * Store a user's phone. Unusable numbers are refused, not stored.
	 *
	 * @return bool Whether the stored value changed.
	 */
	public static function set_phone( $user_id, $phone ) {
		$phone = trim( (string) $phone );
		if ( '' !== $phone ) {
			$e164 = ss_sms_to_e164( $phone );
			if ( ! $e164 ) {
				return false;
			}
			$phone = $e164;
		}
		$old = (string) get_user_meta( $user_id, self::PHONE_META, true );
		if ( $old === $phone ) {
			return false;
		}
		if ( '' === $phone ) {
			delete_user_meta( $user_id, self::PHONE_META );
		} else {
			update_user_meta( $user_id, self::PHONE_META, $phone );
		}
		do_action( 'ss_sms_user_phone_changed', $user_id, $phone, $old );
		return true;
	}

	public static function set_optin( $user_id, $optin ) {
		$was = '1' === get_user_meta( $user_id, self::OPTIN_META, true );
		if ( $was === (bool) $optin ) {
			return;
		}
		if ( $optin ) {
			update_user_meta( $user_id, self::OPTIN_META, '1' );
			// Proof of consent: when it was given.
			update_user_meta( $user_id, self::OPTIN_META . '_at', current_time( 'mysql', true ) );
		} else {
			delete_user_meta( $user_id, self::OPTIN_META );
			delete_user_meta( $user_id, self::OPTIN_META . '_at' );
		}
	}

	public static function account_fields() {
		$user_id = get_current_user_id();
		$optin   = '1' === get_user_meta( $user_id, self::OPTIN_META, true );
		?>
		<fieldset>
			<legend><?php esc_html_e( 'SMS', 'sanalsantral-sms' ); ?></legend>
			<p class="woocommerce-form-row form-row form-row-wide">
				<label for="ss_sms_phone"><?php esc_html_e( 'Mobile phone', 'sanalsantral-sms' ); ?></label>
				<input type="tel" class="woocommerce-Input input-text" name="ss_sms_phone" id="ss_sms_phone" value="<?php echo esc_attr( ss_sms_user_phone( $user_id ) ); ?>" autocomplete="tel" />
			</p>
			<p class="woocommerce-form-row form-row form-row-wide">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox">
					<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="ss_sms_marketing_optin" value="1" <?php checked( $optin ); ?> />
					<span><?php esc_html_e( 'I agree to receive campaign and promotion messages by SMS.', 'sanalsantral-sms' ); ?></span>
				</label>
			</p>
		</fieldset>
		<?php
	}

	public static function save_account( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form nonce.
		if ( isset( $_POST['ss_sms_phone'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['ss_sms_phone'] ) );
			if ( '' !== trim( $phone ) && ! ss_sms_normalize_phone( $phone ) ) {
				wc_add_notice( __( 'The mobile phone must be a Turkish mobile number (05XX XXX XX XX).', 'sanalsantral-sms' ), 'error' );
			} else {
				self::set_phone( $user_id, $phone );
			}
		}
		self::set_optin( $user_id, ! empty( $_POST['ss_sms_marketing_optin'] ) );
		// phpcs:enable
	}

	public static function classic_checkout_field() {
		if ( is_user_logged_in() && '1' === get_user_meta( get_current_user_id(), self::OPTIN_META, true ) ) {
			return;
		}
		?>
		<p class="form-row ss-sms-optin">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="ss_sms_marketing_optin" value="1" />
				<span><?php esc_html_e( 'I agree to receive campaign and promotion messages by SMS.', 'sanalsantral-sms' ); ?></span>
			</label>
		</p>
		<?php
	}

	public static function classic_checkout_save( $order, $data ) {
		if ( empty( $_POST['ss_sms_marketing_optin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
			return;
		}
		self::record_order_optin( $order );
	}

	public static function register_block_checkout_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::CHECKOUT_FIELD,
				'label'    => __( 'I agree to receive campaign and promotion messages by SMS.', 'sanalsantral-sms' ),
				'location' => 'order',
				'type'     => 'checkbox',
			)
		);
	}

	public static function block_checkout_save( $key, $value, $group, $wc_object ) {
		if ( self::CHECKOUT_FIELD !== $key || ! $value || ! $wc_object instanceof WC_Order ) {
			return;
		}
		self::record_order_optin( $wc_object );
	}

	/**
	 * @param WC_Order $order Order being placed.
	 */
	private static function record_order_optin( $order ) {
		$order->update_meta_data( self::ORDER_OPTIN, current_time( 'mysql', true ) );
		if ( $order->get_customer_id() ) {
			self::set_optin( $order->get_customer_id(), true );
		}
	}

	/**
	 * Everyone who agreed to campaign SMS: registered users and guest orders.
	 *
	 * @param string $role Limit registered users to a role ('' = any).
	 * @return array<string, array{phone: string, first_name: string, last_name: string}>
	 *         Keyed by national number, so nobody receives the same campaign twice.
	 */
	public static function marketing_audience( $role = '' ) {
		$audience = array();
		$query    = array(
			'meta_key'   => self::OPTIN_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'     => array( 'ID' ),
			'number'     => -1,
		);
		if ( $role ) {
			$query['role'] = $role;
		}
		foreach ( get_users( $query ) as $u ) {
			$national = ss_sms_normalize_phone( ss_sms_user_phone( $u->ID ) );
			if ( $national && ! isset( $audience[ $national ] ) ) {
				$audience[ $national ] = array(
					'phone'      => $national,
					'first_name' => (string) get_user_meta( $u->ID, 'first_name', true ),
					'last_name'  => (string) get_user_meta( $u->ID, 'last_name', true ),
				);
			}
		}

		if ( ! $role && function_exists( 'wc_get_orders' ) ) {
			// meta_key/meta_compare, not meta_query: the legacy (CPT) order
			// store silently ignores meta_query and would return every order.
			$orders = wc_get_orders(
				array(
					'customer_id'  => 0,
					'limit'        => -1,
					'meta_key'     => self::ORDER_OPTIN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => 'EXISTS',
				)
			);
			foreach ( $orders as $order ) {
				// Consent is checked per order as well: a query arg some store
				// does not understand must never widen a campaign.
				if ( ! $order->get_meta( self::ORDER_OPTIN ) ) {
					continue;
				}
				$national = ss_sms_normalize_phone( $order->get_billing_phone() );
				if ( $national && ! isset( $audience[ $national ] ) ) {
					$audience[ $national ] = array(
						'phone'      => $national,
						'first_name' => $order->get_billing_first_name(),
						'last_name'  => $order->get_billing_last_name(),
					);
				}
			}
		}
		return $audience;
	}
}
