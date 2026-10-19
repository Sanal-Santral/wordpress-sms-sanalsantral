<?php
/**
 * OTP settings page, OTP test tool, and the profile section where users
 * verify their number and see whether SMS two-step login protects them.
 *
 * @package SanalSantralSMSOTP
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_OTP_Admin {

	const PAGE = 'ss-sms-otp';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_ss_sms_otp_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'unverified_admin_notice' ) );
		add_action( 'ss_sms_profile_phone_after', array( __CLASS__, 'profile_verify_ui' ) );
		add_action( 'ss_sms_profile_rows', array( __CLASS__, 'profile_rows' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'wp_ajax_ss_sms_otp_phone_send', array( __CLASS__, 'ajax_phone_send' ) );
		add_action( 'wp_ajax_ss_sms_otp_phone_check', array( __CLASS__, 'ajax_phone_check' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SS_SMS_OTP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_submenu_page( SS_SMS_Admin::MENU, __( 'OTP & two-step login', 'sanalsantral-sms-otp' ), __( 'OTP & 2FA', 'sanalsantral-sms-otp' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'sanalsantral-sms-otp' ) . '</a>' );
		return $links;
	}

	public static function register_settings() {
		register_setting( 'ss_sms_otp_settings', SS_SMS_OTP_Settings::OPTION, array( 'sanitize_callback' => array( 'SS_SMS_OTP_Settings', 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'ss-sms-otp-profile', SS_SMS_OTP_URL . 'assets/profile.js', array(), SS_SMS_OTP_VERSION, true );
		wp_localize_script(
			'ss-sms-otp-profile',
			'ssSmsOtp',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ss_sms_otp_phone' ),
				'sending'  => __( 'Sending…', 'sanalsantral-sms-otp' ),
				'checking' => __( 'Checking…', 'sanalsantral-sms-otp' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings page                                                        */
	/* ------------------------------------------------------------------ */

	public static function page() {
		$s   = SS_SMS_OTP_Settings::all();
		$opt = SS_SMS_OTP_Settings::OPTION;
		$me  = wp_get_current_user();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OTP & two-step login', 'sanalsantral-sms-otp' ); ?></h1>
			<?php settings_errors( $opt ); ?>
			<?php if ( ! SS_SMS_Settings::is_configured() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: link to the gateway settings */
						esc_html__( 'Enter the gateway credentials on the %s page first. Two-step login stays off until the gateway is configured.', 'sanalsantral-sms-otp' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . SS_SMS_Admin::MENU ) ) . '">' . esc_html__( 'Settings', 'sanalsantral-sms-otp' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( defined( 'SS_SMS_2FA_DISABLED' ) && SS_SMS_2FA_DISABLED ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'SS_SMS_2FA_DISABLED is set in wp-config.php: two-step login is switched off for everyone.', 'sanalsantral-sms-otp' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ss_sms_otp_settings' ); ?>
				<h2><?php esc_html_e( 'Two-step login', 'sanalsantral-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Customers', 'sanalsantral-sms-otp' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[twofa_customers]" value="1" <?php checked( $s['twofa_customers'] ); ?> /> <?php esc_html_e( 'Ask customers for an SMS code after their password', 'sanalsantral-sms-otp' ); ?></label>
							<p class="description"><?php esc_html_e( 'Applies to every non-administrator with a Turkish mobile number on their profile or WooCommerce billing address, on wp-login.php and the My Account login. Users without a mobile number log in with their password only.', 'sanalsantral-sms-otp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Administrators', 'sanalsantral-sms-otp' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[twofa_admins]" value="1" <?php checked( $s['twofa_admins'] ); ?> /> <?php esc_html_e( 'Ask administrators for an SMS code after their password', 'sanalsantral-sms-otp' ); ?></label>
							<div class="notice notice-warning inline" style="margin:.6em 0 0">
								<p><strong><?php esc_html_e( 'Lock-out risk.', 'sanalsantral-sms-otp' ); ?></strong>
								<?php esc_html_e( 'An administrator who cannot receive the SMS (wrong number, no credit on the gateway, gateway outage) cannot log in. To limit this, the code is only asked from administrators who verified their number by SMS on their profile.', 'sanalsantral-sms-otp' ); ?></p>
								<p><?php esc_html_e( 'Emergency switch: add this to wp-config.php:', 'sanalsantral-sms-otp' ); ?> <code>define( 'SS_SMS_2FA_DISABLED', true );</code></p>
							</div>
							<p class="description">
								<?php
								if ( SS_SMS_2FA::verified_phone( $me->ID ) ) {
									/* translators: %s: masked phone */
									printf( esc_html__( 'Your verified number: %s', 'sanalsantral-sms-otp' ), esc_html( SS_SMS_2FA::mask( SS_SMS_2FA::verified_phone( $me->ID ) ) ) );
								} else {
									printf(
										/* translators: %s: link to the profile */
										esc_html__( 'Your number is not verified yet, so this will not affect your own login. Verify it on %s.', 'sanalsantral-sms-otp' ),
										'<a href="' . esc_url( admin_url( 'profile.php#ss-sms' ) ) . '">' . esc_html__( 'your profile', 'sanalsantral-sms-otp' ) . '</a>'
									);
								}
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Codes', 'sanalsantral-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ss_otp_template"><?php esc_html_e( 'Message', 'sanalsantral-sms-otp' ); ?></label></th>
						<td>
							<textarea id="ss_otp_template" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[otp_template]"><?php echo esc_textarea( $s['otp_template'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Placeholders: {code} (required), {site}, {minutes}.', 'sanalsantral-sms-otp' ); ?></p>
						</td>
					</tr>
					<?php
					$numbers = array(
						'otp_length'          => array( __( 'Code length (digits)', 'sanalsantral-sms-otp' ), 4, 8, '' ),
						'otp_ttl'             => array( __( 'Code valid for (seconds)', 'sanalsantral-sms-otp' ), 60, 3600, '' ),
						'otp_max_attempts'    => array( __( 'Wrong attempts allowed', 'sanalsantral-sms-otp' ), 1, 20, __( 'After this many wrong codes the code is burnt and the login must start over.', 'sanalsantral-sms-otp' ) ),
						'otp_resend_cooldown' => array( __( 'Wait before a new code (seconds)', 'sanalsantral-sms-otp' ), 0, 3600, '' ),
						'otp_hourly_limit'    => array( __( 'Codes per number per hour', 'sanalsantral-sms-otp' ), 0, 100, __( 'Protects your SMS credit against abuse. 0 = unlimited.', 'sanalsantral-sms-otp' ) ),
					);
					foreach ( $numbers as $key => $field ) :
						?>
						<tr>
							<th><label for="ss_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
							<td>
								<input type="number" class="small-text" id="ss_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $opt . '[' . $key . ']' ); ?>" min="<?php echo esc_attr( $field[1] ); ?>" max="<?php echo esc_attr( $field[2] ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" />
								<?php if ( $field[3] ) : ?>
									<p class="description"><?php echo esc_html( $field[3] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Try it', 'sanalsantral-sms-otp' ); ?></h2>
			<p><?php esc_html_e( 'Send yourself a code, then type it in to check the whole round trip.', 'sanalsantral-sms-otp' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ss_sms_otp_test" />
				<?php wp_nonce_field( 'ss_sms_otp_test' ); ?>
				<?php $last = get_user_meta( $me->ID, '_ss_sms_otp_test_phone', true ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ss_otp_test_phone"><?php esc_html_e( 'Mobile phone', 'sanalsantral-sms-otp' ); ?></label></th>
						<td>
							<input type="tel" class="regular-text" id="ss_otp_test_phone" name="phone" value="<?php echo esc_attr( $last ); ?>" placeholder="05XX XXX XX XX" />
							<?php submit_button( __( 'Send code', 'sanalsantral-sms-otp' ), 'secondary', 'send', false ); ?>
						</td>
					</tr>
					<tr>
						<th><label for="ss_otp_test_code"><?php esc_html_e( 'Code', 'sanalsantral-sms-otp' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ss_otp_test_code" name="code" inputmode="numeric" autocomplete="one-time-code" />
							<?php submit_button( __( 'Verify code', 'sanalsantral-sms-otp' ), 'secondary', 'verify', false ); ?>
						</td>
					</tr>
				</table>
			</form>
		</div>
		<?php
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sanalsantral-sms-otp' ), 403 );
		}
		check_admin_referer( 'ss_sms_otp_test' );
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$context = 'test:' . get_current_user_id();
		update_user_meta( get_current_user_id(), '_ss_sms_otp_test_phone', $phone );

		if ( isset( $_POST['verify'] ) ) {
			$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			$result = SS_SMS_OTP::verify( $phone, $code, $context );
			if ( true === $result ) {
				SS_SMS_Admin::notice( 'success', __( 'Code is correct. OTP works end to end.', 'sanalsantral-sms-otp' ) );
			} else {
				SS_SMS_Admin::notice( 'error', $result->get_error_message() );
			}
		} else {
			$result = SS_SMS_OTP::send( $phone, $context, array( 'source' => 'otp-test' ) );
			if ( true === $result ) {
				/* translators: %s: phone */
				SS_SMS_Admin::notice( 'success', sprintf( __( 'Code sent to %s.', 'sanalsantral-sms-otp' ), ss_sms_to_e164( $phone ) ) );
			} else {
				SS_SMS_Admin::notice( 'error', $result->get_error_message() );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function unverified_admin_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'profile' ), true ) ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! SS_SMS_OTP_Settings::get( 'twofa_admins' ) || ! SS_SMS_2FA::is_admin_user( $user ) || SS_SMS_2FA::globally_disabled() ) {
			return;
		}
		if ( SS_SMS_2FA::verified_phone( $user->ID ) || '1' === get_user_meta( $user->ID, SS_SMS_2FA::EXEMPT_META, true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'SMS two-step login is on for administrators, but your number is not verified, so your account is not protected yet.', 'sanalsantral-sms-otp' ),
			esc_url( admin_url( 'profile.php#ss-sms' ) ),
			esc_html__( 'Verify your number', 'sanalsantral-sms-otp' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Profile                                                              */
	/* ------------------------------------------------------------------ */

	public static function profile_verify_ui( $user ) {
		$verified = SS_SMS_2FA::verified_phone( $user->ID );
		$own      = get_current_user_id() === $user->ID;
		?>
		<div id="ss-sms-verify" style="margin-top:.6em">
			<?php if ( $verified ) : ?>
				<span class="ss-sms-verified" style="color:#008a20">&#10003; <?php esc_html_e( 'Verified by SMS', 'sanalsantral-sms-otp' ); ?></span>
			<?php else : ?>
				<span style="color:#996800"><?php esc_html_e( 'Not verified', 'sanalsantral-sms-otp' ); ?></span>
			<?php endif; ?>
			<?php if ( $own ) : ?>
				<p class="ss-sms-verify-step1" style="margin:.5em 0 0">
					<button type="button" class="button" id="ss-sms-verify-send"><?php echo $verified ? esc_html__( 'Verify again', 'sanalsantral-sms-otp' ) : esc_html__( 'Send verification code', 'sanalsantral-sms-otp' ); ?></button>
				</p>
				<p class="ss-sms-verify-step2" style="margin:.5em 0 0;display:none">
					<input type="text" id="ss-sms-verify-code" class="small-text" style="width:8em" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />
					<button type="button" class="button button-primary" id="ss-sms-verify-check"><?php esc_html_e( 'Verify', 'sanalsantral-sms-otp' ); ?></button>
				</p>
				<p class="ss-sms-verify-result description" style="margin:.5em 0 0" aria-live="polite"></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function profile_rows( $user ) {
		$phone = SS_SMS_2FA::phone_for( $user );
		?>
		<tr>
			<th><?php esc_html_e( 'SMS two-step login', 'sanalsantral-sms-otp' ); ?></th>
			<td>
				<?php
				if ( '' !== $phone ) {
					/* translators: %s: masked phone */
					printf( '<span style="color:#008a20">&#10003; %s</span>', esc_html( sprintf( __( 'Active. Login codes go to %s.', 'sanalsantral-sms-otp' ), SS_SMS_2FA::mask( $phone ) ) ) );
				} else {
					echo '<span>' . esc_html( self::inactive_reason( $user ) ) . '</span>';
				}
				?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<p style="margin-top:.6em">
						<label><input type="checkbox" name="ss_sms_2fa_exempt" value="1" <?php checked( '1' === get_user_meta( $user->ID, SS_SMS_2FA::EXEMPT_META, true ) ); ?> /> <?php esc_html_e( 'Exempt this user from SMS two-step login', 'sanalsantral-sms-otp' ); ?></label>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function inactive_reason( $user ) {
		if ( SS_SMS_2FA::globally_disabled() ) {
			return __( 'Off. Two-step login is not available on this site.', 'sanalsantral-sms-otp' );
		}
		if ( '1' === get_user_meta( $user->ID, SS_SMS_2FA::EXEMPT_META, true ) ) {
			return __( 'Off. This user is exempt.', 'sanalsantral-sms-otp' );
		}
		$s = SS_SMS_OTP_Settings::all();
		if ( SS_SMS_2FA::is_admin_user( $user ) ) {
			if ( ! $s['twofa_admins'] ) {
				return __( 'Off. Not enabled for administrators.', 'sanalsantral-sms-otp' );
			}
			return __( 'Off. Verify the mobile number above by SMS to turn it on.', 'sanalsantral-sms-otp' );
		}
		if ( ! $s['twofa_customers'] ) {
			return __( 'Off. Not enabled for customers.', 'sanalsantral-sms-otp' );
		}
		return __( 'Off. No Turkish mobile number on file.', 'sanalsantral-sms-otp' );
	}

	public static function save_profile( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! empty( $_POST['ss_sms_2fa_exempt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verified the profile nonce.
			update_user_meta( $user_id, SS_SMS_2FA::EXEMPT_META, '1' );
		} else {
			delete_user_meta( $user_id, SS_SMS_2FA::EXEMPT_META );
		}
	}

	public static function ajax_phone_send() {
		check_ajax_referer( 'ss_sms_otp_phone' );
		$user_id = get_current_user_id();
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'sanalsantral-sms-otp' ) ), 403 );
		}
		$result = SS_SMS_OTP::send( $phone, 'verify:' . $user_id, array( 'source' => 'verify:' . $user_id ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		/* translators: %s: masked phone */
		wp_send_json_success( array( 'message' => sprintf( __( 'Code sent to %s.', 'sanalsantral-sms-otp' ), SS_SMS_2FA::mask( $phone ) ) ) );
	}

	public static function ajax_phone_check() {
		check_ajax_referer( 'ss_sms_otp_phone' );
		$user_id = get_current_user_id();
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'sanalsantral-sms-otp' ) ), 403 );
		}
		$result = SS_SMS_OTP::verify( $phone, $code, 'verify:' . $user_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		// The proven number becomes the profile number.
		SS_SMS_Users::set_phone( $user_id, $phone );
		update_user_meta( $user_id, SS_SMS_2FA::VERIFIED_META, ss_sms_normalize_phone( $phone ) );
		wp_send_json_success( array( 'message' => __( 'Number verified.', 'sanalsantral-sms-otp' ) ) );
	}
}
