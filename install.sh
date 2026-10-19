#!/bin/sh
# Installs Sanal Santral SMS (and SMS OTP) on a WordPress site with WP-CLI.
#
#   curl -fsSL https://raw.githubusercontent.com/Sanal-Santral/wordpress-sms-sanalsantral/main/install.sh | sh -s -- \
#     --path=/var/www/html --username=API_USER --api-key=API_KEY --sender=BASLIK
#
# Options:
#   --path=DIR          WordPress directory (default: current directory)
#   --url=URL           Site URL, for multisite
#   --username=USER     Sanal Santral API username
#   --api-key=KEY       Sanal Santral API key (or SS_SMS_API_KEY in the environment)
#   --sender=NAME       Sender name; without it the only sender on the account is used
#   --version=X.Y.Z     Release to install (default: latest)
#   --source=DIR        Install from local zips instead of GitHub
#   --no-otp            Skip the SMS OTP plugin
#   --test-phone=NUM    Send a test SMS at the end
#
# Run it as the web server user (e.g. sudo -u www-data sh install.sh ...), so
# the plugin files are not owned by root.
set -eu

REPO="Sanal-Santral/wordpress-sms-sanalsantral"
WP_PATH="."
WP_URL=""
SS_USERNAME="${SS_SMS_USERNAME:-}"
SS_API_KEY="${SS_SMS_API_KEY:-}"
SS_SENDER="${SS_SMS_SENDER:-}"
VERSION="latest"
SOURCE=""
WITH_OTP=1
TEST_PHONE=""

for arg in "$@"; do
	case "$arg" in
		--path=*) WP_PATH="${arg#*=}" ;;
		--url=*) WP_URL="${arg#*=}" ;;
		--username=*) SS_USERNAME="${arg#*=}" ;;
		--api-key=*) SS_API_KEY="${arg#*=}" ;;
		--sender=*) SS_SENDER="${arg#*=}" ;;
		--version=*) VERSION="${arg#*=}" ;;
		--source=*) SOURCE="${arg#*=}" ;;
		--no-otp) WITH_OTP=0 ;;
		--test-phone=*) TEST_PHONE="${arg#*=}" ;;
		-h|--help) sed -n '2,20p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) echo "Unknown option: $arg" >&2; exit 2 ;;
	esac
done

say() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

command -v wp >/dev/null 2>&1 || die "WP-CLI is required: https://wp-cli.org/#installing"

set -- --path="$WP_PATH"
[ -n "$WP_URL" ] && set -- "$@" --url="$WP_URL"
if [ "$(id -u)" = "0" ]; then
	echo "warning: running as root; plugin files will be owned by root. Prefer: sudo -u www-data sh install.sh ..." >&2
	set -- "$@" --allow-root
fi

wp "$@" core is-installed >/dev/null 2>&1 || die "no WordPress installation found at $WP_PATH"
WP_VERSION="$(wp "$@" core version)"
case "$WP_VERSION" in
	[0-6].*) die "WordPress $WP_VERSION is too old; 7.0 or newer is required" ;;
esac
say "WordPress $WP_VERSION at $WP_PATH"

package() {
	if [ -n "$SOURCE" ]; then
		echo "$SOURCE/$1.zip"
	elif [ "$VERSION" = "latest" ]; then
		echo "https://github.com/$REPO/releases/latest/download/$1.zip"
	else
		echo "https://github.com/$REPO/releases/download/v${VERSION#v}/$1.zip"
	fi
}

say "Installing Sanal Santral SMS"
wp "$@" plugin install "$(package sanalsantral-sms)" --force --activate
if [ "$WITH_OTP" = "1" ]; then
	say "Installing Sanal Santral SMS OTP"
	wp "$@" plugin install "$(package sanalsantral-sms-otp)" --force --activate
fi

if [ -n "$SS_USERNAME" ] && [ -n "$SS_API_KEY" ]; then
	say "Saving gateway credentials and syncing senders"
	# Values travel in the environment, never on the PHP command line.
	SS_U="$SS_USERNAME" SS_K="$SS_API_KEY" SS_S="$SS_SENDER" wp "$@" eval '
		$s = SS_SMS_Settings::all();
		$s["username"] = getenv( "SS_U" );
		$s["api_key"]  = getenv( "SS_K" );
		update_option( SS_SMS_Settings::OPTION, $s );
		try {
			$senders = ( new SS_SMS_Client() )->fetch_senders();
		} catch ( Exception $e ) {
			WP_CLI::error( "Gateway rejected the credentials: " . ( $e instanceof SS_SMS_Gateway_Error ? $e->text() : $e->getMessage() ) );
		}
		SS_SMS_Settings::save_senders( $senders );
		$titles = wp_list_pluck( $senders, "title" );
		$want   = (string) getenv( "SS_S" );
		if ( "" !== $want ) {
			if ( ! in_array( $want, $titles, true ) ) {
				WP_CLI::error( "Sender \"$want\" is not approved on this account." );
			}
			$s["sender"] = $want;
		} elseif ( 1 === count( $titles ) ) {
			$s["sender"] = $titles[0];
		}
		update_option( SS_SMS_Settings::OPTION, $s );
		WP_CLI::log( count( $titles ) . " sender(s) on the account; using: " . ( $s["sender"] ? $s["sender"] : "none yet, choose one in WP Admin" ) );
	'
else
	echo "No credentials given; enter them in WP Admin > Sanal Santral SMS > Settings."
fi

if [ -n "$TEST_PHONE" ]; then
	say "Sending a test SMS to $TEST_PHONE"
	SS_P="$TEST_PHONE" wp "$@" eval '
		$r = ss_sms_send( getenv( "SS_P" ), "Sanal Santral SMS kurulumu tamamlandı.", array( "source" => "test" ) );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( "Test SMS sent." );
	'
fi

say "Done. Settings: $(wp "$@" option get siteurl)/wp-admin/admin.php?page=ss-sms"
