<?php
/**
 * Updates from GitHub Releases for the copies installed from GitHub.
 *
 * Uses the core Update URI mechanism (WordPress 5.8+): both plugins declare
 * "Update URI: https://github.com/Sanal-Santral/wordpress-sms-sanalsantral",
 * core then asks the update_plugins_github.com filter instead of
 * WordPress.org, and this class answers from the release.json manifest that
 * every GitHub release carries.
 *
 * The WordPress.org build ships without this file and without the header:
 * plugins hosted there are updated by WordPress.org itself.
 *
 * @package SanalSantralSMS
 */

defined( 'ABSPATH' ) || exit;

class SS_SMS_Updater {

	const REPO  = 'Sanal-Santral/wordpress-sms-sanalsantral';
	const CACHE = 'ss_sms_release_manifest';

	/** Plugin file => slug, for both plugins of the repository. */
	const PLUGINS = array(
		'sanalsantral-sms/sanalsantral-sms.php'         => 'sanalsantral-sms',
		'sanalsantral-sms-otp/sanalsantral-sms-otp.php' => 'sanalsantral-sms-otp',
	);

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		// "Check again" on Dashboard > Updates must really check again.
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_flush_on_force_check' ) );
	}

	public static function manifest_url() {
		/**
		 * Filters where the release manifest is read from, e.g. a mirror on
		 * your own server.
		 *
		 * @param string $url Manifest URL.
		 */
		return apply_filters( 'ss_sms_update_manifest_url', 'https://github.com/' . self::REPO . '/releases/latest/download/release.json' );
	}

	/**
	 * The latest release manifest, cached for 12 hours (1 hour after a failure).
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest() {
		$manifest = get_site_transient( self::CACHE );
		if ( is_array( $manifest ) ) {
			return $manifest;
		}
		$manifest = array();
		// The stable "latest/download" URL redirects to the asset; it does not
		// count against the GitHub API rate limit that shared hosts run into.
		$response = wp_remote_get( self::manifest_url(), array( 'timeout' => 10 ) );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && isset( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
				$manifest = $data;
			}
		}
		set_site_transient( self::CACHE, $manifest, $manifest ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $manifest;
	}

	/**
	 * @param array<string, mixed> $slug Plugin slug.
	 * @return array<string, mixed>|null
	 */
	private static function release( $slug ) {
		$manifest = self::manifest();
		return isset( $manifest['plugins'][ $slug ] ) && is_array( $manifest['plugins'][ $slug ] ) ? $manifest['plugins'][ $slug ] : null;
	}

	/**
	 * Answer core's update check for our plugin files.
	 *
	 * @param array|false          $update      Update data so far.
	 * @param array<string, mixed> $plugin_data Plugin headers.
	 * @param string               $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( ! isset( self::PLUGINS[ $plugin_file ] ) ) {
			return $update;
		}
		$slug    = self::PLUGINS[ $plugin_file ];
		$release = self::release( $slug );
		if ( ! $release || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $update;
		}
		return array(
			'slug'         => $slug,
			'version'      => (string) $release['version'],
			'url'          => isset( $release['homepage'] ) ? (string) $release['homepage'] : 'https://github.com/' . self::REPO,
			'package'      => (string) $release['package'],
			'tested'       => isset( $release['tested'] ) ? (string) $release['tested'] : '',
			'requires'     => isset( $release['requires'] ) ? (string) $release['requires'] : '',
			'requires_php' => isset( $release['requires_php'] ) ? (string) $release['requires_php'] : '',
			'icons'        => array(),
		);
	}

	/**
	 * "View details" popup for our plugins.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || ! in_array( $args->slug, self::PLUGINS, true ) ) {
			return $result;
		}
		$release = self::release( $args->slug );
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => isset( $release['name'] ) ? $release['name'] : $args->slug,
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://www.sanalsantral.com.tr">Sanal Santral</a>',
			'homepage'      => isset( $release['homepage'] ) ? $release['homepage'] : 'https://github.com/' . self::REPO,
			'requires'      => isset( $release['requires'] ) ? $release['requires'] : '',
			'tested'        => isset( $release['tested'] ) ? $release['tested'] : '',
			'requires_php'  => isset( $release['requires_php'] ) ? $release['requires_php'] : '',
			'last_updated'  => isset( $release['released'] ) ? $release['released'] : '',
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => wpautop( esc_html( isset( $release['description'] ) ? $release['description'] : '' ) ),
				'changelog'   => wpautop( esc_html( isset( $release['changelog'] ) ? $release['changelog'] : '' ) ),
			),
		);
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	public static function maybe_flush_on_force_check() {
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::flush();
		}
	}
}
