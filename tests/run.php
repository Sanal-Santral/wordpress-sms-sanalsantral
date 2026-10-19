<?php
/**
 * Standalone tests for the WordPress-independent parts (ported from the Odoo
 * module's test_phone_normalization.py). Run:
 *
 *     php tests/run.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal stand-ins for the WordPress functions these classes touch.
function __( $s ) { return $s; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_specialchars_decode( $s ) { return htmlspecialchars_decode( (string) $s, ENT_QUOTES ); }

$root = dirname( __DIR__ ) . '/sanalsantral-sms/includes/';
require $root . 'class-ss-sms-exceptions.php';
require $root . 'class-ss-sms-phone.php';
require $root . 'class-ss-sms-settings.php';
require $root . 'class-ss-sms-client.php';

$failures = 0;
$count    = 0;
function check( $label, $actual, $expected ) {
	global $failures, $count;
	++$count;
	if ( $actual !== $expected ) {
		++$failures;
		echo "FAIL $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

// Every shape the database actually holds converges to the 10-digit form.
foreach ( array( '+905321234567', '00905321234567', '905321234567', '05321234567', '5321234567', '+90 532 123 45 67', '(0532) 123-45-67', '+90 0532 123 45 67' ) as $raw ) {
	check( "accepted $raw", SS_SMS_Phone::normalize( $raw ), array( '5321234567', null ) );
}
// '+5321347780' kept its '+' and once took down a 1545-message campaign.
check( 'missing country code', SS_SMS_Phone::normalize( '+5321347780' ), array( '5321347780', null ) );
foreach ( array( '+49 170 1234567', '+15551234567', '004915112345678' ) as $raw ) {
	check( "foreign $raw", SS_SMS_Phone::normalize( $raw ), array( null, 'sms_country_not_supported' ) );
}
check( 'landline', SS_SMS_Phone::normalize( '0212 123 45 67' )[1], 'sms_number_format' );
check( 'empty', SS_SMS_Phone::normalize( '' )[1], 'sms_number_missing' );
check( 'false', SS_SMS_Phone::normalize( false )[1], 'sms_number_missing' );
check( 'null', SS_SMS_Phone::normalize( null )[1], 'sms_number_missing' );
check( 'letters', SS_SMS_Phone::normalize( 'abc' )[1], 'sms_number_missing' );
check( 'zero string', SS_SMS_Phone::normalize( '0' )[1], 'sms_number_format' );
check( 'e164', SS_SMS_Phone::to_e164( '05321234567' ), '+905321234567' );
check( 'e164 no cc', SS_SMS_Phone::to_e164( '+5321234567' ), '+905321234567' );
check( 'e164 foreign', SS_SMS_Phone::to_e164( '+49 170 1234567' ), null );

// Gateway error code mapping.
check( 'dup', SS_SMS_Client::failure_type( 'ERR_SMS_PKG_DUPLICATION' ), 'sms_duplicate' );
check( 'balance', SS_SMS_Client::failure_type( 'ERR_INSUFFICIENT_BALANCE' ), 'sms_credit' );
check( '401', SS_SMS_Client::failure_type( null, 401 ), 'sms_acc' );
check( '402', SS_SMS_Client::failure_type( null, 402 ), 'sms_credit' );
check( '500', SS_SMS_Client::failure_type( null, 500 ), 'sms_server' );

// One bad number must not abort the package.
class Fake_Client extends SS_SMS_Client {
	public $calls = array();
	public function call_api( $path, array $payload, $timeout = null ) {
		$this->calls[] = array( $path, $payload );
		return array( 'pkgID' => 7 );
	}
}
$client = new Fake_Client( array( 'username' => 'u', 'api_key' => 'k', 'sender' => 'TESTSENDER' ) );
$result = $client->send_bulk(
	array(
		array( '+905321234567', 'ok' ),
		array( '', 'missing' ),
		array( '+49 170 1234567', 'foreign' ),
		array( '0212 1234567', 'landline' ),
		array( '+5321234568', 'no country code' ),
	)
);
check( 'bulk one call', count( $client->calls ), 1 );
check( 'bulk sent numbers', array_column( $client->calls[0][1]['numbers'], 'nr' ), array( 5321234567, 5321234568 ) );
check( 'bulk sendingType', $client->calls[0][1]['sendingType'], 2 );
check( 'bulk invalid', $result['invalid'], array( 1 => 'sms_number_missing', 2 => 'sms_country_not_supported', 3 => 'sms_number_format' ) );
check( 'bulk pkg', $result['pkg_id'], 7 );

$client = new Fake_Client( array( 'username' => 'u', 'api_key' => 'k', 'sender' => 'S' ) );
$result = $client->send_bulk( array( array( '', 'a' ), array( '+49 170 1234567', 'b' ) ) );
check( 'no valid number, no call', count( $client->calls ), 0 );
check( 'no valid number, all invalid', count( $result['invalid'] ), 2 );

$client = new Fake_Client( array( 'username' => 'u', 'api_key' => 'k', 'sender' => 'S' ) );
$client->send( '0532 123 45 67', 'hi' );
$p = $client->calls[0][1];
check( 'single payload', array( $p['number'], $p['sendingType'], $p['sender'], $p['content'] ), array( 5321234567, 0, 'S', 'hi' ) );

$client = new Fake_Client( array( 'username' => 'u', 'api_key' => 'k', 'sender' => '' ) );
try {
	$client->send( '05321234567', 'x' );
	check( 'no sender raises', 'no exception', 'exception' );
} catch ( SS_SMS_Gateway_Error $e ) {
	check( 'no sender failure type', $e->failure_type, 'sms_acc' );
}

// Messages are escaped when thrown and decoded back to plain text once.
$e = new SS_SMS_Gateway_Error( esc_html( 'Body: {"err":"<x> & \'y\'"}' ) );
check( 'exception text round-trip', $e->text(), 'Body: {"err":"<x> & \'y\'"}' );

echo $failures ? "\n$failures of $count checks failed\n" : "All $count checks passed\n";
exit( $failures ? 1 : 0 );
