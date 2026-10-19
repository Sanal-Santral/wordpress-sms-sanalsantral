<?php
/**
 * Admin screens: gateway settings, bulk SMS, order SMS and the log.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Admin {

	const MENU = 'ss-sms';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ss_sms_sync_senders', array( __CLASS__, 'handle_sync_senders' ) );
		add_action( 'admin_post_ss_sms_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_ss_sms_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SS_SMS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Sanal Santral SMS', 'sanalsantral-sms' ), __( 'Sanal Santral SMS', 'sanalsantral-sms' ), self::CAP, self::MENU, array( __CLASS__, 'page_settings' ), 'dashicons-smartphone', 58 );
		add_submenu_page( self::MENU, __( 'Settings', 'sanalsantral-sms' ), __( 'Settings', 'sanalsantral-sms' ), self::CAP, self::MENU, array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::MENU, __( 'Bulk SMS', 'sanalsantral-sms' ), __( 'Bulk SMS', 'sanalsantral-sms' ), self::CAP, self::MENU . '-bulk', array( __CLASS__, 'page_bulk' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page( self::MENU, __( 'Order SMS', 'sanalsantral-sms' ), __( 'Order SMS', 'sanalsantral-sms' ), self::CAP, self::MENU . '-orders', array( __CLASS__, 'page_orders' ) );
		}
		add_submenu_page( self::MENU, __( 'SMS Log', 'sanalsantral-sms' ), __( 'SMS Log', 'sanalsantral-sms' ), self::CAP, self::MENU . '-log', array( __CLASS__, 'page_log' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU ) ) . '">' . esc_html__( 'Settings', 'sanalsantral-sms' ) . '</a>' );
		return $links;
	}

	public static function register_settings() {
		register_setting( 'ss_sms_settings', SS_SMS_Settings::OPTION, array( 'sanitize_callback' => array( 'SS_SMS_Settings', 'sanitize' ) ) );
		if ( class_exists( 'SS_SMS_WooCommerce' ) ) {
			register_setting( 'ss_sms_wc_settings', SS_SMS_WooCommerce::OPTION, array( 'sanitize_callback' => array( 'SS_SMS_WooCommerce', 'sanitize' ) ) );
		}
	}

	public static function assets( $hook ) {
		wp_register_script( 'ss-sms-admin', SS_SMS_URL . 'assets/admin.js', array(), SS_SMS_VERSION, true );
		wp_localize_script(
			'ss-sms-admin',
			'ssSmsAdmin',
			array(
				'encoding' => (int) SS_SMS_Settings::get( 'encoding' ),
				/* translators: 1: characters, 2: SMS parts */
				'counter'  => __( '%1$d characters · %2$d SMS', 'sanalsantral-sms' ),
			)
		);
		$screen = get_current_screen();
		$is_order_screen = $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( false !== strpos( $hook, self::MENU ) || $is_order_screen ) {
			wp_enqueue_script( 'ss-sms-admin' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Notices                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Queue a notice for the current user's next admin page.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Plain text.
	 */
	public static function notice( $type, $message ) {
		$key     = 'ss_sms_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$notices[] = array( $type, $message );
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	public static function render_notices() {
		$key     = 'ss_sms_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}

	private static function back( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function require_cap() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sanalsantral-sms' ), 403 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings page                                                        */
	/* ------------------------------------------------------------------ */

	public static function page_settings() {
		$s       = SS_SMS_Settings::all();
		$senders = SS_SMS_Settings::senders();
		$opt     = SS_SMS_Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sanal Santral SMS', 'sanalsantral-sms' ); ?></h1>
			<?php settings_errors( $opt ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ss_sms_settings' ); ?>
				<h2><?php esc_html_e( 'Gateway credentials', 'sanalsantral-sms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ss_api_endpoint"><?php esc_html_e( 'API endpoint', 'sanalsantral-sms' ); ?></label></th>
						<td><input type="url" class="regular-text code" id="ss_api_endpoint" name="<?php echo esc_attr( $opt ); ?>[api_endpoint]" value="<?php echo esc_attr( $s['api_endpoint'] ); ?>" placeholder="https://sms.sanalsantral.com.tr:9588" /></td>
					</tr>
					<tr>
						<th><label for="ss_username"><?php esc_html_e( 'Username', 'sanalsantral-sms' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ss_username" name="<?php echo esc_attr( $opt ); ?>[username]" value="<?php echo esc_attr( $s['username'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="ss_api_key"><?php esc_html_e( 'API key (token)', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="ss_api_key" name="<?php echo esc_attr( $opt ); ?>[api_key]" value="" autocomplete="new-password" placeholder="<?php echo $s['api_key'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'sanalsantral-sms' ) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th><label for="ss_sender"><?php esc_html_e( 'Sender header', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<?php if ( $senders ) : ?>
								<select id="ss_sender" name="<?php echo esc_attr( $opt ); ?>[sender]">
									<option value=""><?php esc_html_e( 'Select', 'sanalsantral-sms' ); ?></option>
									<?php
									$titles = wp_list_pluck( $senders, 'title' );
									if ( $s['sender'] && ! in_array( $s['sender'], $titles, true ) ) {
										$titles[] = $s['sender'];
									}
									foreach ( $titles as $title ) :
										?>
										<option value="<?php echo esc_attr( $title ); ?>" <?php selected( $s['sender'], $title ); ?>><?php echo esc_html( $title ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" class="regular-text" id="ss_sender" name="<?php echo esc_attr( $opt ); ?>[sender]" value="<?php echo esc_attr( $s['sender'] ); ?>" />
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Save the credentials, then use "Sync senders" below to load the headers approved on your account.', 'sanalsantral-sms' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sending', 'sanalsantral-sms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ss_encoding"><?php esc_html_e( 'Message encoding', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<select id="ss_encoding" name="<?php echo esc_attr( $opt ); ?>[encoding]">
								<option value="0" <?php selected( (int) $s['encoding'], 0 ); ?>><?php esc_html_e( 'GSM (Turkish alphabet), 160 characters per SMS', 'sanalsantral-sms' ); ?></option>
								<option value="1" <?php selected( (int) $s['encoding'], 1 ); ?>><?php esc_html_e( 'Unicode (UCS-2), 70 characters per SMS', 'sanalsantral-sms' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ss_bulk_size"><?php esc_html_e( 'Bulk batch size', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<input type="number" min="1" max="5000" class="small-text" id="ss_bulk_size" name="<?php echo esc_attr( $opt ); ?>[bulk_size]" value="<?php echo esc_attr( $s['bulk_size'] ); ?>" />
							<p class="description"><?php esc_html_e( 'How many messages go into a single gateway call. Lower it if the gateway rejects large packages.', 'sanalsantral-sms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Rejected batches', 'sanalsantral-sms' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[bulk_fallback_single]" value="1" <?php checked( $s['bulk_fallback_single'] ); ?> /> <?php esc_html_e( 'Retry rejected batches one message at a time', 'sanalsantral-sms' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only runs when the gateway accepted nothing from the package, so no message can be delivered twice.', 'sanalsantral-sms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ss_api_timeout"><?php esc_html_e( 'API timeout (seconds)', 'sanalsantral-sms' ); ?></label></th>
						<td><input type="number" min="5" max="120" class="small-text" id="ss_api_timeout" name="<?php echo esc_attr( $opt ); ?>[api_timeout]" value="<?php echo esc_attr( $s['api_timeout'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ss_log_retention_days"><?php esc_html_e( 'Keep log for (days)', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<input type="number" min="0" max="3650" class="small-text" id="ss_log_retention_days" name="<?php echo esc_attr( $opt ); ?>[log_retention_days]" value="<?php echo esc_attr( $s['log_retention_days'] ); ?>" />
							<p class="description"><?php esc_html_e( '0 keeps the log forever.', 'sanalsantral-sms' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Sender headers', 'sanalsantral-sms' ); ?></h2>
			<?php if ( $senders ) : ?>
				<p>
					<?php
					/* translators: %s: comma-separated sender headers */
					printf( esc_html__( 'Available: %s', 'sanalsantral-sms' ), '<code>' . esc_html( implode( ', ', wp_list_pluck( $senders, 'title' ) ) ) . '</code>' );
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ss_sms_sync_senders" />
				<?php wp_nonce_field( 'ss_sms_sync_senders' ); ?>
				<?php submit_button( __( 'Sync senders', 'sanalsantral-sms' ), 'secondary', 'submit', false, SS_SMS_Settings::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Send a test SMS', 'sanalsantral-sms' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ss_sms_test" />
				<?php wp_nonce_field( 'ss_sms_test' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ss_test_phone"><?php esc_html_e( 'Mobile phone', 'sanalsantral-sms' ); ?></label></th>
						<td><input type="tel" class="regular-text" id="ss_test_phone" name="phone" placeholder="05XX XXX XX XX" required /></td>
					</tr>
					<tr>
						<th><label for="ss_test_message"><?php esc_html_e( 'Message', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="ss_test_message" name="message" data-ss-sms-counter="1" required><?php echo esc_textarea( __( 'Test message from Sanal Santral SMS.', 'sanalsantral-sms' ) ); ?></textarea>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Send test SMS', 'sanalsantral-sms' ), 'secondary', 'submit', true, SS_SMS_Settings::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_sync_senders() {
		self::require_cap();
		check_admin_referer( 'ss_sms_sync_senders' );
		try {
			$senders = ( new SS_SMS_Client() )->fetch_senders();
		} catch ( SS_SMS_Gateway_Error $e ) {
			/* translators: %s: error */
			self::notice( 'error', sprintf( __( 'Could not sync senders: %s', 'sanalsantral-sms' ), $e->text() ) );
			self::back( self::MENU );
		}
		SS_SMS_Settings::save_senders( $senders );

		// One header on the account: nothing to choose, select it.
		$settings = SS_SMS_Settings::all();
		if ( '' === $settings['sender'] && 1 === count( $senders ) ) {
			$settings['sender'] = $senders[0]['title'];
			update_option( SS_SMS_Settings::OPTION, $settings );
		}
		/* translators: %d: number of senders */
		self::notice( $senders ? 'success' : 'warning', sprintf( _n( '%d sender header synced.', '%d sender headers synced.', count( $senders ), 'sanalsantral-sms' ), count( $senders ) ) );
		self::back( self::MENU );
	}

	public static function handle_test() {
		self::require_cap();
		check_admin_referer( 'ss_sms_test' );
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$result  = ss_sms_send( $phone, $message, array( 'source' => 'test' ) );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error */
			self::notice( 'error', sprintf( __( 'Test SMS failed: %s', 'sanalsantral-sms' ), $result->get_error_message() ) );
		} else {
			/* translators: %s: phone number */
			self::notice( 'success', sprintf( __( 'Test SMS sent to %s.', 'sanalsantral-sms' ), ss_sms_to_e164( $phone ) ) );
		}
		self::back( self::MENU );
	}

	/* ------------------------------------------------------------------ */
	/* Bulk SMS                                                             */
	/* ------------------------------------------------------------------ */

	public static function page_bulk() {
		$roles = wp_roles()->get_names();
		$count = count( SS_SMS_Users::marketing_audience() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk SMS', 'sanalsantral-sms' ); ?></h1>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'Commercial (campaign) SMS may only be sent to people who gave consent, and the sender must be registered with İYS (İleti Yönetim Sistemi). Informational messages such as order and shipping updates do not need consent.', 'sanalsantral-sms' ); ?>
			</p></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ss-sms-bulk">
				<input type="hidden" name="action" value="ss_sms_bulk" />
				<?php wp_nonce_field( 'ss_sms_bulk' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Recipients', 'sanalsantral-sms' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="audience" value="optin" checked />
									<?php
									/* translators: %d: number of people */
									printf( esc_html__( 'Everyone who agreed to campaign SMS (%d people)', 'sanalsantral-sms' ), (int) $count );
									?>
								</label>
								<p style="margin:.4em 0 .8em 1.8em">
									<label for="ss_bulk_role"><?php esc_html_e( 'Only this role:', 'sanalsantral-sms' ); ?></label>
									<select id="ss_bulk_role" name="role">
										<option value=""><?php esc_html_e( 'All (including guest orders)', 'sanalsantral-sms' ); ?></option>
										<?php foreach ( $roles as $slug => $name ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( translate_user_role( $name ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<label><input type="radio" name="audience" value="manual" /> <?php esc_html_e( 'These numbers:', 'sanalsantral-sms' ); ?></label>
								<p style="margin:.4em 0 0 1.8em">
									<textarea name="numbers" rows="5" class="large-text code" placeholder="05321234567&#10;+90 532 765 43 21"></textarea>
									<span class="description"><?php esc_html_e( 'One number per line (commas and semicolons also work). Duplicates are sent once.', 'sanalsantral-sms' ); ?></span>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th><label for="ss_bulk_message"><?php esc_html_e( 'Message', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<textarea id="ss_bulk_message" name="message" rows="5" class="large-text" data-ss-sms-counter="1" required></textarea>
							<p class="description"><?php esc_html_e( 'Placeholders: {first_name}, {last_name}, {site}. Unknown names become empty.', 'sanalsantral-sms' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				submit_button(
					__( 'Send SMS', 'sanalsantral-sms' ),
					'primary',
					'submit',
					true,
					array( 'onclick' => "return confirm('" . esc_js( __( 'Send this message to all selected recipients?', 'sanalsantral-sms' ) ) . "');" ) + ( SS_SMS_Settings::is_configured() ? array() : array( 'disabled' => 'disabled' ) )
				);
				?>
			</form>
		</div>
		<?php
	}

	public static function handle_bulk() {
		self::require_cap();
		check_admin_referer( 'ss_sms_bulk' );
		$audience = isset( $_POST['audience'] ) ? sanitize_key( $_POST['audience'] ) : 'optin';
		$template = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$page     = self::MENU . '-bulk';
		if ( '' === trim( $template ) ) {
			self::notice( 'error', __( 'The message is empty.', 'sanalsantral-sms' ) );
			self::back( $page );
		}

		$recipients = array();
		$invalid    = 0;
		if ( 'manual' === $audience ) {
			$raw = isset( $_POST['numbers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['numbers'] ) ) : '';
			foreach ( preg_split( '/[\r\n,;]+/', $raw ) as $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				$national = ss_sms_normalize_phone( $line );
				if ( ! $national ) {
					++$invalid;
					continue;
				}
				$recipients[ $national ] = array(
					'phone'      => $national,
					'first_name' => '',
					'last_name'  => '',
				);
			}
		} else {
			$role       = isset( $_POST['role'] ) ? sanitize_key( $_POST['role'] ) : '';
			$recipients = SS_SMS_Users::marketing_audience( $role );
		}

		if ( ! $recipients ) {
			self::notice( 'warning', __( 'No valid recipients.', 'sanalsantral-sms' ) );
			self::back( $page );
		}

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$items = array();
		foreach ( $recipients as $r ) {
			$items[] = array(
				$r['phone'],
				trim( strtr( $template, array( '{first_name}' => $r['first_name'], '{last_name}' => $r['last_name'], '{site}' => $site ) ) ),
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- large campaigns can outlast max_execution_time.
		}
		$results = ss_sms_send_bulk( $items, array( 'source' => 'bulk:' . gmdate( 'Ymd-His' ) ) );

		$sent   = count( array_filter( $results, static function ( $r ) {
			return true === $r;
		} ) );
		$failed = count( $results ) - $sent;
		/* translators: 1: sent count, 2: failed count */
		$msg = sprintf( __( 'Bulk SMS finished: %1$d sent, %2$d failed.', 'sanalsantral-sms' ), $sent, $failed );
		if ( $invalid ) {
			/* translators: %d: number of skipped lines */
			$msg .= ' ' . sprintf( _n( '%d line was not a valid number and was skipped.', '%d lines were not valid numbers and were skipped.', $invalid, 'sanalsantral-sms' ), $invalid );
		}
		if ( $failed ) {
			$first = null;
			foreach ( $results as $r ) {
				if ( is_wp_error( $r ) ) {
					$first = $r;
					break;
				}
			}
			/* translators: %s: error */
			$msg .= ' ' . sprintf( __( 'First error: %s', 'sanalsantral-sms' ), $first->get_error_message() );
		}
		self::notice( $failed ? ( $sent ? 'warning' : 'error' ) : 'success', $msg );
		self::back( self::MENU . '-log' );
	}

	/* ------------------------------------------------------------------ */
	/* Order SMS                                                            */
	/* ------------------------------------------------------------------ */

	public static function page_orders() {
		$s        = SS_SMS_WooCommerce::settings();
		$opt      = SS_SMS_WooCommerce::OPTION;
		$statuses = SS_SMS_WooCommerce::statuses();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order SMS', 'sanalsantral-sms' ); ?></h1>
			<?php settings_errors( $opt ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'ss_sms_wc_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Status SMS', 'sanalsantral-sms' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Text the customer when an order moves to one of the statuses below', 'sanalsantral-sms' ); ?></label></td>
					</tr>
					<?php foreach ( $statuses as $slug => $label ) : ?>
						<tr>
							<th>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $s['statuses'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							</th>
							<td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[templates][<?php echo esc_attr( $slug ); ?>]" data-ss-sms-counter="1"><?php echo esc_textarea( isset( $s['templates'][ $slug ] ) ? $s['templates'][ $slug ] : '' ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Shipping (cargo) SMS', 'sanalsantral-sms' ); ?></h2>
				<p><?php esc_html_e( 'Sent when your cargo integration reports a tracking number (see "For developers" below), once per tracking number.', 'sanalsantral-sms' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Message', 'sanalsantral-sms' ); ?></th>
						<td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[templates][<?php echo esc_attr( SS_SMS_WooCommerce::SHIPPING ); ?>]" data-ss-sms-counter="1"><?php echo esc_textarea( $s['templates'][ SS_SMS_WooCommerce::SHIPPING ] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="ss_wc_default_carrier"><?php esc_html_e( 'Default carrier', 'sanalsantral-sms' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ss_wc_default_carrier" name="<?php echo esc_attr( $opt ); ?>[default_carrier]" value="<?php echo esc_attr( $s['default_carrier'] ); ?>" placeholder="Örnek Kargo" /></td>
					</tr>
					<tr>
						<th><label for="ss_wc_tracking_url"><?php esc_html_e( 'Tracking URL', 'sanalsantral-sms' ); ?></label></th>
						<td>
							<input type="text" class="large-text code" id="ss_wc_tracking_url" name="<?php echo esc_attr( $opt ); ?>[tracking_url]" value="<?php echo esc_attr( $s['tracking_url'] ); ?>" placeholder="https://kargotakip.example.com/?no={tracking_number}" />
							<p class="description"><?php esc_html_e( 'Used for {tracking_url} when the integration sends no link; {tracking_number} is replaced with the order\'s tracking number.', 'sanalsantral-sms' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="card" style="max-width:none;margin:8px 0 18px">
					<h2 class="title"><?php esc_html_e( 'For developers: cargo integration', 'sanalsantral-sms' ); ?></h2>
					<p><?php esc_html_e( 'Your cargo plugin, ERP or carrier webhook reports the tracking number in one of three ways:', 'sanalsantral-sms' ); ?></p>
					<p><strong>PHP</strong></p>
					<pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:4px"><code style="background:none;padding:0">ss_sms_wc_send_shipping( $order_id, array( 'tracking_number' =&gt; 'KRG1234567890', 'carrier' =&gt; 'Örnek Kargo' ) );</code></pre>
					<p><strong><?php esc_html_e( 'Action (no dependency on this plugin)', 'sanalsantral-sms' ); ?></strong></p>
					<pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:4px"><code style="background:none;padding:0">do_action( 'ss_sms_wc_shipped', $order_id, array( 'tracking_number' =&gt; 'KRG1234567890' ) );</code></pre>
					<p><strong>REST API</strong></p>
					<pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:4px"><code style="background:none;padding:0">POST <?php echo esc_html( rest_url( SS_SMS_WooCommerce::REST_NAMESPACE . '/orders/{id}/shipping' ) ); ?>

{ "tracking_number": "KRG1234567890", "carrier": "Örnek Kargo" }</code></pre>
					<p class="description"><?php esc_html_e( 'Authenticate with an application password of a user who can edit orders (Users > Profile > Application Passwords). The same tracking number is never texted twice unless "force": true is sent.', 'sanalsantral-sms' ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'Placeholders: {first_name}, {last_name}, {order_number}, {order_status}, {order_total}, {order_url}, {carrier}, {tracking_number}, {tracking_url}, {site}', 'sanalsantral-sms' ); ?></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Log                                                                  */
	/* ------------------------------------------------------------------ */

	public static function page_log() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$state  = isset( $_GET['state'] ) ? sanitize_key( $_GET['state'] ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable
		$per_page = 50;
		$result   = SS_SMS_Log::query(
			array(
				'state'    => in_array( $state, array( 'sent', 'error' ), true ) ? $state : '',
				'search'   => $search,
				'page'     => $paged,
				'per_page' => $per_page,
			)
		);
		$base = admin_url( 'admin.php?page=' . self::MENU . '-log' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SMS Log', 'sanalsantral-sms' ); ?></h1>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base ); ?>" <?php echo '' === $state ? 'class="current"' : ''; ?>><?php esc_html_e( 'All', 'sanalsantral-sms' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'state', 'sent', $base ) ); ?>" <?php echo 'sent' === $state ? 'class="current"' : ''; ?>><?php esc_html_e( 'Sent', 'sanalsantral-sms' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'state', 'error', $base ) ); ?>" <?php echo 'error' === $state ? 'class="current"' : ''; ?>><?php esc_html_e( 'Failed', 'sanalsantral-sms' ); ?></a></li>
			</ul>
			<form method="get" class="search-form" style="float:right">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU . '-log' ); ?>" />
				<?php if ( $state ) : ?>
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Number, error or source', 'sanalsantral-sms' ); ?>" />
				<?php submit_button( __( 'Search', 'sanalsantral-sms' ), '', '', false ); ?>
			</form>
			<table class="widefat striped" style="clear:both;margin-top:1em">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'sanalsantral-sms' ); ?></th>
						<th><?php esc_html_e( 'Number', 'sanalsantral-sms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'sanalsantral-sms' ); ?></th>
						<th><?php esc_html_e( 'Message', 'sanalsantral-sms' ); ?></th>
						<th><?php esc_html_e( 'Source', 'sanalsantral-sms' ); ?></th>
						<th><?php esc_html_e( 'Sender', 'sanalsantral-sms' ); ?></th>
						<th><?php esc_html_e( 'Package', 'sanalsantral-sms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $result['rows'] ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No SMS yet.', 'sanalsantral-sms' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i:s' ) ); ?></td>
							<td><code><?php echo esc_html( $row->number ); ?></code></td>
							<td>
								<?php if ( 'sent' === $row->state ) : ?>
									<span style="color:#008a20">&#10003; <?php esc_html_e( 'Sent', 'sanalsantral-sms' ); ?></span>
								<?php else : ?>
									<span style="color:#b32d2e">&#10007; <?php echo esc_html( ss_sms_failure_label( $row->failure_type ) ); ?></span>
									<br /><small><?php echo esc_html( $row->failure_reason ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo $row->is_otp ? '<em>' . esc_html__( 'One-time code (hidden)', 'sanalsantral-sms' ) . '</em>' : esc_html( $row->body ); ?></td>
							<td><code><?php echo esc_html( $row->source ); ?></code></td>
							<td><?php echo esc_html( $row->sender ); ?></td>
							<td><?php echo esc_html( $row->pkg_id ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $result['total'] / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				) . '</div></div>';
			}
			?>
		</div>
		<?php
	}
}
