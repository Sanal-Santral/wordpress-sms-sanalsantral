<?php
/**
 * SMS two-step login for wp-login.php and the WooCommerce My Account form.
 *
 * Flow: the password is checked as usual; for a user under SMS 2FA the auth
 * cookies are withheld, the session WordPress just created is destroyed, a
 * code goes out by SMS and the browser is sent to wp-login.php?action=ss_sms_2fa.
 * Only a correct code there sets the real auth cookies.
 *
 * Lock-out safety:
 * - Administrators are only covered when "SMS 2FA for administrators" is on
 *   (off by default) AND they verified their number by SMS on their profile.
 * - define( 'SS_SMS_2FA_DISABLED', true ); in wp-config.php switches the
 *   second step off entirely.
 * - Nothing is enforced while the SMS gateway is not configured.
 *
 * @package SanalSantralSMSOTP
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_2FA {

	const ACTION        = 'ss_sms_2fa';
	const COOKIE        = 'ss_sms_2fa';
	const PENDING_META  = '_ss_sms_2fa_pending';
	const VERIFIED_META = 'ss_sms_phone_verified';
	const EXEMPT_META   = 'ss_sms_2fa_exempt';
	/** How long the password step stays valid while waiting for the code. */
	const PENDING_TTL = 900;

	/** @var int User whose login this request is holding back. */
	private static $intercept = 0;

	/** @var string Session token WordPress created for the held-back login. */
	private static $session_token = '';

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), 50, 3 );
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'capture_session_token' ), 10, 6 );
		// Last, so every other wp_login handler has run before we stop the request.
		add_action( 'wp_login', array( __CLASS__, 'on_wp_login' ), PHP_INT_MAX, 2 );
		add_action( 'login_form_' . self::ACTION, array( __CLASS__, 'login_page' ) );
		add_filter( 'wp_login_errors', array( __CLASS__, 'login_errors' ) );
		add_action( 'ss_sms_user_phone_changed', array( __CLASS__, 'on_phone_changed' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Who is covered                                                       */
	/* ------------------------------------------------------------------ */

	public static function is_admin_user( $user ) {
		/**
		 * Filters whether a user counts as an administrator for SMS 2FA.
		 *
		 * @param bool    $is_admin Default: can manage_options.
		 * @param WP_User $user     User.
		 */
		return (bool) apply_filters( 'ss_sms_2fa_is_admin', user_can( $user, 'manage_options' ), $user );
	}

	public static function globally_disabled() {
		return ( defined( 'SS_SMS_2FA_DISABLED' ) && SS_SMS_2FA_DISABLED ) || ! SS_SMS_Settings::is_configured();
	}

	/**
	 * The number the login code goes to, or '' when SMS 2FA does not apply.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	public static function phone_for( $user ) {
		$phone = '';
		if ( ! self::globally_disabled() && '1' !== get_user_meta( $user->ID, self::EXEMPT_META, true ) ) {
			$s = SS_SMS_OTP_Settings::all();
			if ( self::is_admin_user( $user ) ) {
				// Only a number proven by SMS: a typo here would lock the site's
				// administrator out.
				if ( $s['twofa_admins'] && self::verified_phone( $user->ID ) ) {
					$phone = self::verified_phone( $user->ID );
				}
			} elseif ( $s['twofa_customers'] ) {
				$candidate = ss_sms_user_phone( $user->ID );
				if ( ss_sms_normalize_phone( $candidate ) ) {
					$phone = $candidate;
				}
			}
		}
		/**
		 * Filters the number SMS 2FA sends the login code to; '' disables it
		 * for this user.
		 *
		 * @param string  $phone Raw number or ''.
		 * @param WP_User $user  User.
		 */
		return (string) apply_filters( 'ss_sms_2fa_phone', $phone, $user );
	}

	/**
	 * The profile number, if the user proved by SMS that it reaches them.
	 */
	public static function verified_phone( $user_id ) {
		$verified = (string) get_user_meta( $user_id, self::VERIFIED_META, true );
		$current  = ss_sms_normalize_phone( get_user_meta( $user_id, SS_SMS_Users::PHONE_META, true ) );
		return ( '' !== $verified && $verified === $current ) ? $current : '';
	}

	public static function on_phone_changed( $user_id, $phone ) {
		if ( ss_sms_normalize_phone( $phone ) !== (string) get_user_meta( $user_id, self::VERIFIED_META, true ) ) {
			delete_user_meta( $user_id, self::VERIFIED_META );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Holding the login back                                               */
	/* ------------------------------------------------------------------ */

	public static function filter_authenticate( $user, $username, $password ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}
		// Application passwords are separate, revocable credentials meant for
		// machines; they cannot answer an SMS.
		if ( did_action( 'application_password_did_authenticate' ) ) {
			return $user;
		}
		if ( '' === self::phone_for( $user ) ) {
			return $user;
		}
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return new WP_Error( 'ss_sms_2fa_required', __( 'This account uses SMS two-step login. Use an application password for API access.', 'sanalsantral-sms-otp' ) );
		}
		self::$intercept = $user->ID;
		add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		return $user;
	}

	public static function capture_session_token( $cookie, $expire, $expiration, $user_id, $scheme, $token = '' ) {
		if ( self::$intercept && (int) $user_id === self::$intercept ) {
			self::$session_token = (string) $token;
		}
	}

	public static function on_wp_login( $login, $user ) {
		if ( ! self::$intercept || ! $user instanceof WP_User || $user->ID !== self::$intercept ) {
			return;
		}
		if ( self::$session_token ) {
			WP_Session_Tokens::get_instance( $user->ID )->destroy( self::$session_token );
		}
		wp_set_current_user( 0 );

		// phpcs:disable WordPress.Security.NonceVerification -- the login form was already validated by core / WooCommerce.
		$redirect = '';
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			$redirect = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
		} elseif ( ! empty( $_POST['redirect'] ) ) {
			$redirect = esc_url_raw( wp_unslash( $_POST['redirect'] ) );
		}
		$remember = ! empty( $_POST['rememberme'] );
		// phpcs:enable

		$token   = wp_generate_password( 43, false, false );
		$pending = array(
			'hash'     => self::token_hash( $token ),
			'expires'  => time() + self::PENDING_TTL,
			'remember' => $remember,
			'redirect' => $redirect,
			'tries'    => 0,
			'notice'   => null,
		);
		$sent = SS_SMS_OTP::send( self::phone_for( $user ), self::context( $user->ID ), array( 'source' => '2fa:' . $user->ID ) );
		if ( is_wp_error( $sent ) ) {
			$pending['notice'] = array( 'error', $sent->get_error_message() );
		}
		update_user_meta( $user->ID, self::PENDING_META, $pending );
		self::set_cookie( $user->ID . '|' . $token, time() + self::PENDING_TTL );

		wp_safe_redirect( self::url() );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* The code page                                                        */
	/* ------------------------------------------------------------------ */

	public static function login_page() {
		nocache_headers();
		list( $user, $pending ) = self::pending_from_cookie();
		if ( ! $user ) {
			self::clear_cookie();
			wp_safe_redirect( add_query_arg( 'ss_sms_2fa', 'expired', wp_login_url() ) );
			exit;
		}

		$phone = self::phone_for( $user );
		if ( '' === $phone ) {
			// 2FA was switched off for this user while they were typing: the
			// password step already succeeded, so let them in.
			self::complete( $user, $pending );
		}

		$errors  = new WP_Error();
		$context = self::context( $user->ID );
		if ( $pending['notice'] ) {
			$errors->add( 'ss_sms_notice', esc_html( $pending['notice'][1] ), 'error' === $pending['notice'][0] ? 'error' : 'message' );
			$pending['notice'] = null;
			update_user_meta( $user->ID, self::PENDING_META, $pending );
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			check_admin_referer( 'ss_sms_2fa_' . $user->ID );

			if ( isset( $_POST['ss_sms_cancel'] ) ) {
				self::abort( $user->ID );
				wp_safe_redirect( wp_login_url() );
				exit;
			}

			if ( isset( $_POST['ss_sms_resend'] ) ) {
				$sent = SS_SMS_OTP::send( $phone, $context, array( 'source' => '2fa:' . $user->ID ) );
				if ( is_wp_error( $sent ) ) {
					$errors->add( 'ss_sms_resend', esc_html( $sent->get_error_message() ) );
				} else {
					$errors->add( 'ss_sms_resend', esc_html__( 'A new code has been sent.', 'sanalsantral-sms-otp' ), 'message' );
				}
			} else {
				$code = isset( $_POST['ss_sms_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ss_sms_code'] ) ) : '';
				++$pending['tries'];
				update_user_meta( $user->ID, self::PENDING_META, $pending );

				$valid = SS_SMS_OTP::verify( $phone, $code, $context );
				if ( true === $valid ) {
					self::complete( $user, $pending );
				}
				if ( $pending['tries'] >= (int) SS_SMS_OTP_Settings::get( 'otp_max_attempts' ) ) {
					// Same as the Odoo module: too many misses and the password
					// step has to be redone.
					self::abort( $user->ID );
					wp_safe_redirect( add_query_arg( 'ss_sms_2fa', 'locked', wp_login_url() ) );
					exit;
				}
				$errors->add( 'ss_sms_code', esc_html( $valid->get_error_message() ) );
			}
		}

		self::render( $user, $phone, $errors );
		exit;
	}

	private static function render( $user, $phone, WP_Error $errors ) {
		$wait = SS_SMS_OTP::cooldown_remaining( $phone, self::context( $user->ID ) );
		login_header( __( 'SMS verification', 'sanalsantral-sms-otp' ), '', $errors );
		?>
		<form name="ss_sms_2fa_form" id="loginform" action="<?php echo esc_url( self::url() ); ?>" method="post" autocomplete="off">
			<?php wp_nonce_field( 'ss_sms_2fa_' . $user->ID ); ?>
			<p class="message" style="margin-bottom:16px">
				<?php
				/* translators: %s: masked phone number */
				printf( esc_html__( 'Enter the code we sent by SMS to %s.', 'sanalsantral-sms-otp' ), '<strong>' . esc_html( self::mask( $phone ) ) . '</strong>' );
				?>
			</p>
			<p>
				<label for="ss_sms_code"><?php esc_html_e( 'Verification code', 'sanalsantral-sms-otp' ); ?></label>
				<input type="text" name="ss_sms_code" id="ss_sms_code" class="input" value="" size="20" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" autofocus required style="font-size:24px;letter-spacing:.3em;text-align:center" />
			</p>
			<p class="submit">
				<input type="submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify and log in', 'sanalsantral-sms-otp' ); ?>" />
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( self::url() ); ?>" style="margin-top:8px;padding:12px 24px;text-align:center">
			<?php wp_nonce_field( 'ss_sms_2fa_' . $user->ID ); ?>
			<button type="submit" name="ss_sms_resend" value="1" class="button-link" id="ss-sms-resend" <?php disabled( $wait > 0 ); ?> data-wait="<?php echo esc_attr( $wait ); ?>"><?php esc_html_e( 'Send a new code', 'sanalsantral-sms-otp' ); ?></button>
			&nbsp;·&nbsp;
			<button type="submit" name="ss_sms_cancel" value="1" class="button-link" formnovalidate><?php esc_html_e( 'Cancel', 'sanalsantral-sms-otp' ); ?></button>
		</form>
		<script>
		( function () {
			var b = document.getElementById( 'ss-sms-resend' ), w = parseInt( b.getAttribute( 'data-wait' ), 10 ), label = b.textContent;
			if ( ! w ) { return; }
			var t = setInterval( function () {
				w--;
				b.textContent = w > 0 ? label + ' (' + w + ')' : label;
				if ( w <= 0 ) { b.disabled = false; clearInterval( t ); }
			}, 1000 );
		}() );
		</script>
		<?php
		login_footer( 'ss_sms_code' );
	}

	public static function login_errors( $errors ) {
		$state = isset( $_GET['ss_sms_2fa'] ) ? sanitize_key( $_GET['ss_sms_2fa'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'expired' === $state ) {
			$errors->add( 'ss_sms_2fa', esc_html__( 'The SMS verification step expired. Please log in again.', 'sanalsantral-sms-otp' ) );
		} elseif ( 'locked' === $state ) {
			$errors->add( 'ss_sms_2fa', esc_html__( 'Too many wrong codes. Please log in again.', 'sanalsantral-sms-otp' ) );
		}
		return $errors;
	}

	/**
	 * Correct code: set the real auth cookies and go where the user was headed.
	 *
	 * @param WP_User              $user    User.
	 * @param array<string, mixed> $pending Pending login.
	 */
	private static function complete( $user, array $pending ) {
		delete_user_meta( $user->ID, self::PENDING_META );
		self::clear_cookie();
		SS_SMS_OTP::forget( self::phone_for( $user ), self::context( $user->ID ) );

		wp_set_auth_cookie( $user->ID, ! empty( $pending['remember'] ), is_ssl() );
		wp_set_current_user( $user->ID );
		do_action( 'ss_sms_2fa_authenticated', $user );

		$requested = (string) $pending['redirect'];
		$default   = admin_url();
		if ( ! self::is_admin_user( $user ) && function_exists( 'wc_get_page_permalink' ) ) {
			$default = wc_get_page_permalink( 'myaccount' );
		}
		// Core's filter, so other plugins' login redirects still apply after the SMS step.
		$redirect = apply_filters( 'login_redirect', $requested ? $requested : $default, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		wp_safe_redirect( $redirect ? $redirect : $default );
		exit;
	}

	private static function abort( $user_id ) {
		delete_user_meta( $user_id, self::PENDING_META );
		self::clear_cookie();
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{0: WP_User|null, 1: array<string, mixed>}
	 */
	private static function pending_from_cookie() {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( ! preg_match( '/^(\d+)\|([A-Za-z0-9]{43})$/', $raw, $m ) ) {
			return array( null, array() );
		}
		$user    = get_userdata( (int) $m[1] );
		$pending = $user ? get_user_meta( $user->ID, self::PENDING_META, true ) : null;
		if ( ! is_array( $pending ) || empty( $pending['hash'] ) || time() > $pending['expires'] || ! hash_equals( $pending['hash'], self::token_hash( $m[2] ) ) ) {
			return array( null, array() );
		}
		return array( $user, $pending );
	}

	private static function token_hash( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	private static function context( $user_id ) {
		return 'login:' . $user_id;
	}

	private static function url() {
		return add_query_arg( 'action', self::ACTION, wp_login_url() );
	}

	private static function set_cookie( $value, $expires ) {
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => SITECOOKIEPATH ? SITECOOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function clear_cookie() {
		self::set_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * +90 5** *** 45 67
	 */
	public static function mask( $phone ) {
		$national = ss_sms_normalize_phone( $phone );
		if ( ! $national ) {
			return '';
		}
		return '+90 ' . $national[0] . '** *** ' . substr( $national, 6, 2 ) . ' ' . substr( $national, 8, 2 );
	}
}
