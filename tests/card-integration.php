<?php
/** Disposable WordPress only. No real Square calls. */
define( 'DOING_AJAX', true );
require '/var/www/html/wp-load.php';
$checks = 0;
function card_check( $value, $message ) { global $checks; if ( ! $value ) { throw new RuntimeException( $message ); } ++$checks; }
class CardResponse extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new CardResponse(); }; } );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $admin );
update_option( ICPA_Platform::OPTION, array( 'mode' => 'sandbox', 'currency' => 'AUD' ) );
delete_option( ICPA_Recurring::OPTION ); delete_option( ICPA_Square_Settings::OPTION );
card_check( true === ICPA_Square_Settings::save( array( 'sandbox' => array( 'application_id' => 'sandbox-sq0idb-fixture', 'location_id' => 'card-test-location', 'access_token' => 'card-test-fixture-token', 'signature_key' => 'card-test-fixture-signature' ), 'production' => array( 'application_id' => 'sq0idp-fixture' ) ) ), 'Application IDs save separately' );
card_check( 'sandbox-sq0idb-fixture' === ICPA_Square_Settings::profile()['application_id'], 'Sandbox selects matching application ID' );
card_check( 'sq0idp-fixture' === ICPA_Square_Settings::profile( 'production' )['application_id'], 'Production application remains independent' );
card_check( is_wp_error( ICPA_Square_Settings::save( array( 'sandbox' => array( 'application_id' => '"><script>' ) ) ) ), 'Reject application ID XSS' );
$html = ICPA_Views::donation_form();
file_put_contents( '/tmp/icpa-card-view.html', $html );
card_check( false !== strpos( $html, 'data-card-payment="1"' ) && false !== strpos( $html, 'icpa-card-container' ), 'Embedded card form renders when application is configured' );
card_check( false === strpos( $html, 'card-test-fixture-token' ) && false === strpos( $html, 'card-test-fixture-signature' ), 'Private credentials never enter markup' );
wp_set_current_user( 0 );
$nonce = wp_create_nonce( 'icpa_checkout' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '192.0.2.177';
function card_request( $input ) {
    $_POST = $input; $_REQUEST = $input; ob_start();
    try { ICPA_Square::card_payment(); } catch ( CardResponse $e ) {}
    return json_decode( ob_get_clean(), true );
}
$orders = 0; $payments = 0; $fail = false; $mismatch = false; $captured = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$orders, &$payments, &$fail, &$mismatch, &$captured ) {
    $body = json_decode( $args['body'], true ); $captured[] = $body;
    if ( substr( $url, -10 ) === '/v2/orders' ) {
        ++$orders;
        $data = array( 'order' => array( 'id' => 'embedded-order-' . $body['idempotency_key'], 'location_id' => $body['order']['location_id'] ) );
    } elseif ( substr( $url, -12 ) === '/v2/payments' ) {
        ++$payments;
        if ( $fail ) { return new WP_Error( 'timeout', 'Simulated timeout' ); }
        $data = array( 'payment' => array( 'id' => 'embedded-pay-' . $body['idempotency_key'], 'order_id' => $body['order_id'], 'location_id' => $body['location_id'], 'amount_money' => $body['amount_money'], 'status' => 'COMPLETED', 'updated_at' => '2026-11-04T00:00:00Z' ) );
        if ( $mismatch ) { $data['payment']['amount_money']['amount']++; }
    } else { throw new RuntimeException( 'Unexpected outbound request: ' . $url ); }
    return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ), 'headers' => array() );
}, 10, 3 );
$input = array( '_wpnonce' => $nonce, 'environment' => 'sandbox', 'campaign_id' => '0', 'amount' => '25.50', 'checkout_key' => wp_generate_uuid4(), 'source_id' => 'cnon:card-test-token', 'email' => 'donor@example.invalid' );
$r = card_request( $input );
card_check( $r['success'] && 'completed' === $r['data']['status'], 'Guest can complete embedded donation' );
card_check( 1 === $orders && 1 === $payments, 'Creates one order and payment' );
card_check( 2550 === $captured[1]['amount_money']['amount'] && 'AUD' === $captured[1]['amount_money']['currency'] && 'card-test-location' === $captured[1]['location_id'], 'Server binds amount, currency and location' );
global $wpdb;
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ICPA_Platform::table() . ' WHERE checkout_key=%s', $input['checkout_key'] ), ARRAY_A );
card_check( 'completed' === $row['status'] && '' === (string) $row['checkout_url'], 'Confirmed embedded payment saved without hosted URL' );
card_check( false === strpos( wp_json_encode( $row ), $input['source_id'] ), 'Single-use token not stored in ledger' );
$r = card_request( $input );
card_check( $r['success'] && 1 === $payments, 'Repeated completed submission does not charge again' );
$changed = $input; $changed['amount'] = '99.00'; $r = card_request( $changed );
card_check( ! $r['success'] && 1 === $payments, 'Changed amount cannot reuse a payment attempt' );
$changed = $input; $changed['environment'] = 'production'; $r = card_request( $changed );
card_check( ! $r['success'] && 1 === $payments, 'Stale or forged environment rejected before payment' );
$changed = $input; $changed['source_id'] = array( 'invalid' ); $r = card_request( $changed );
card_check( ! $r['success'] && 1 === $payments, 'Malformed token rejected before payment' );
$_SERVER['HTTPS'] = 'off'; $r = card_request( $input ); $_SERVER['HTTPS'] = 'on';
card_check( ! $r['success'] && 1 === $payments, 'Insecure transport cannot charge' );
// A transient failure retries the same order and Square idempotency key.
$input['checkout_key'] = wp_generate_uuid4(); $fail = true; $r = card_request( $input );
card_check( ! $r['success'], 'API failure cannot display success' );
$prior_orders = $orders; $fail = false; $r = card_request( $input );
card_check( $r['success'] && $prior_orders === $orders, 'Network retry reuses the persisted order' );
card_check( $captured[count($captured)-1]['idempotency_key'] === $captured[count($captured)-2]['idempotency_key'], 'Network retry uses identical payment idempotency key' );
card_check( is_wp_error( ICPA_Square::create_checkout( 0, '25.50', $input['checkout_key'] ) ), 'Embedded attempt cannot change to hosted checkout' );
$_SERVER['REMOTE_ADDR'] = '192.0.2.178'; $input['checkout_key'] = wp_generate_uuid4(); $mismatch = true; $r = card_request( $input );
card_check( ! $r['success'], 'Mismatched provider amount cannot display success' );
$input['_wpnonce'] = 'forged'; $r = card_request( $input );
card_check( null === $r, 'Forged nonce cannot reach payment API' );
wp_set_current_user( $admin ); ICPA_Square_Settings::save( array( 'sandbox' => array( 'application_id' => '' ) ) );
card_check( false === strpos( ICPA_Views::donation_form(), 'data-card-payment' ), 'Existing hosted checkout remains available without Application ID' );
delete_option( ICPA_Square_Settings::OPTION );
echo 'PASS: ' . $checks . " embedded-card integration checks (Square mocked)\n";
