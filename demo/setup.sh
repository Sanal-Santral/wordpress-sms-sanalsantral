#!/bin/sh
# Installs and configures one demo site. Runs inside wordpress:cli; re-runnable.
set -eu
cd /var/www/html
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin}"
CUSTOMER_PASSWORD="${CUSTOMER_PASSWORD:-musteri123}"

echo "[setup] waiting for WordPress files and the database..."
until [ -f wp-config.php ] && [ -f wp-includes/version.php ]; do sleep 2; done
until php -r 'exit(@mysqli_connect(getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME")) ? 0 : 1);'; do sleep 2; done

# Pin the WordPress major when the image ships a newer one (see docker-compose.yml).
if [ -n "${WP_CORE_VERSION:-}" ]; then
	case "$(wp core version)" in
		"$WP_CORE_VERSION"|"$WP_CORE_VERSION".*) ;;
		*)
			echo "[setup] pinning WordPress to $WP_CORE_VERSION"
			WP_CLI_PHP_ARGS="-d memory_limit=512M" wp core download --version="$WP_CORE_VERSION" --force --skip-content
			wp core is-installed 2>/dev/null && wp core update --minor || true
			;;
	esac
fi

if ! wp core is-installed 2>/dev/null; then
	echo "[setup] installing WordPress $(wp core version)"
	wp core install --url="$SITE_URL" --title="$SITE_TITLE" \
		--admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" \
		--admin_email=admin@example.com --skip-email
	# The image tag can trail the latest point release of its major.
	wp core update --minor || true
	wp language core install tr_TR --activate || echo "[setup] Turkish language pack not available, staying in English"
	wp option update timezone_string Europe/Istanbul
	wp rewrite structure '/%postname%/'

	echo "[setup] installing WooCommerce"
	wp plugin install woocommerce --activate
	wp language plugin install woocommerce tr_TR || true
	wp option update woocommerce_default_country 'TR:TR34'
	wp option update woocommerce_currency TRY
	wp option update woocommerce_coming_soon no
	wp option update woocommerce_store_pages_only no
	wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
	wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Kapıda ödeme","description":"Ödemeyi teslimatta yapın.","instructions":"","enable_for_methods":[],"enable_for_virtual":"yes"}' --format=json
	wp wc tool run install_pages --user="$ADMIN_USER" >/dev/null || true
	# High-performance order storage, as on a new WooCommerce shop.
	wp wc hpos enable || true
fi

echo "[setup] activating the SMS plugins"
wp plugin activate sanalsantral-sms sanalsantral-sms-otp
wp language plugin install woocommerce tr_TR >/dev/null 2>&1 || true

# Gateway credentials from .env (only the ones that are set), order SMS on,
# customer 2FA on. Administrator 2FA stays off: that is a deliberate opt-in.
wp eval '
$s = SS_SMS_Settings::all();
foreach ( array( "username" => "SS_SMS_USERNAME", "api_key" => "SS_SMS_API_KEY", "sender" => "SS_SMS_SENDER", "api_endpoint" => "SS_SMS_ENDPOINT" ) as $key => $env ) {
	$value = trim( (string) getenv( $env ) );
	if ( "" !== $value ) {
		$s[ $key ] = $value;
	}
}
update_option( SS_SMS_Settings::OPTION, $s );

// Only switches are stored: message templates fall back to the defaults in
// the site language until someone edits them.
if ( ! get_option( SS_SMS_WooCommerce::OPTION ) ) {
	update_option( SS_SMS_WooCommerce::OPTION, array(
		"enabled"         => true,
		"statuses"        => array( "processing", "completed", "cancelled" ),
		"default_carrier" => "Örnek Kargo",
	) );
}
if ( ! get_option( SS_SMS_OTP_Settings::OPTION ) ) {
	update_option( SS_SMS_OTP_Settings::OPTION, array( "twofa_customers" => true ) );
}

if ( SS_SMS_Settings::is_configured() ) {
	try {
		$senders = ( new SS_SMS_Client() )->fetch_senders();
		SS_SMS_Settings::save_senders( $senders );
		$s = SS_SMS_Settings::all();
		if ( "" === $s["sender"] && 1 === count( $senders ) ) {
			$s["sender"] = $senders[0]["title"];
			update_option( SS_SMS_Settings::OPTION, $s );
		}
		echo "[setup] senders: " . implode( ", ", wp_list_pluck( $senders, "title" ) ) . "\n";
	} catch ( Exception $e ) {
		echo "[setup] could not sync senders: " . ( $e instanceof SS_SMS_Gateway_Error ? $e->text() : $e->getMessage() ) . "\n";
	}
} else {
	echo "[setup] gateway credentials not set; enter them in WP Admin → Sanal Santral SMS\n";
}
'

# Demo customer, product and order (once).
if ! wp user get musteri >/dev/null 2>&1; then
	echo "[setup] creating demo customer, product and order"
	CID=$(wp user create musteri musteri@example.com --role=customer --user_pass="$CUSTOMER_PASSWORD" \
		--first_name=Ayşe --last_name=Yılmaz --porcelain)
	wp user meta update "$CID" billing_first_name Ayşe
	wp user meta update "$CID" billing_last_name Yılmaz
	wp user meta update "$CID" billing_country TR
	wp user meta update "$CID" billing_state TR34
	wp user meta update "$CID" billing_city İstanbul
	wp user meta update "$CID" billing_address_1 "Demo Mah. Test Sok. No:1"
	wp user meta update "$CID" billing_email musteri@example.com
	wp user meta update "$CID" ss_sms_marketing_optin 1

	PID=$(wp wc product create --name="Demo Ürün" --type=simple --regular_price=250 --status=publish \
		--user="$ADMIN_USER" --porcelain)
	wp wc shop_order create --user="$ADMIN_USER" --customer_id="$CID" --status=on-hold \
		--payment_method=cod --payment_method_title="Kapıda ödeme" \
		--billing='{"first_name":"Ayşe","last_name":"Yılmaz","country":"TR","state":"TR34","city":"İstanbul","address_1":"Demo Mah. Test Sok. No:1","email":"musteri@example.com","phone":"'"${DEMO_PHONE:-}"'"}' \
		--line_items='[{"product_id":'"$PID"',"quantity":1}]' --porcelain >/dev/null
fi

# The demo phone follows .env on every run.
if [ -n "${DEMO_PHONE:-}" ]; then
	CID=$(wp user get musteri --field=ID)
	wp user meta update "$CID" billing_phone "$DEMO_PHONE" >/dev/null
	CID="$CID" wp eval 'foreach ( wc_get_orders( array( "customer_id" => (int) getenv( "CID" ), "limit" => -1 ) ) as $o ) { $o->set_billing_phone( getenv( "DEMO_PHONE" ) ); $o->save(); }'
fi

echo "[setup] done: $SITE_URL/wp-admin  ($ADMIN_USER / $ADMIN_PASSWORD), customer: musteri / $CUSTOMER_PASSWORD at $SITE_URL/my-account/"
