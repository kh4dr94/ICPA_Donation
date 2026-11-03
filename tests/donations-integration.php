<?php
/** Disposable database only. All Square responses below are mocked. */
$_SERVER['HTTP_HOST'] = 'localhost';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
activate_plugin( 'icpa-fundraising/icpa-fundraising.php' );
$checks = 0;
function donation_check( $condition, $message ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
global $wpdb;
$wpdb->query( 'TRUNCATE TABLE ' . ICPA_Platform::table() );
delete_option( ICPA_Square_Settings::OPTION );
update_option( ICPA_Platform::OPTION, array( 'currency' => 'AUD', 'mode' => 'sandbox', 'general_enabled' => 1, 'campaigns_enabled' => 1 ) );
$general_page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'General giving test', 'post_content' => '[icpa_donation_form]', 'post_status' => 'publish' ) );
$options = ICPA_Platform::options(); $options['general_page'] = $general_page; update_option( ICPA_Platform::OPTION, $options );
$profiles = array(
    'sandbox' => array( 'location_id' => 'sandbox-location', 'access_token' => 'sandbox-test-fixture-token', 'signature_key' => 'sandbox-test-fixture-signature' ),
    'production' => array( 'location_id' => 'production-location', 'access_token' => 'production-test-fixture-token', 'signature_key' => 'production-test-fixture-signature' ),
);
donation_check( true === ICPA_Square_Settings::save( $profiles ), 'Administrator can save separate Square profiles' );
$stored = get_option( ICPA_Square_Settings::OPTION );
donation_check( false === strpos( wp_json_encode( $stored ), 'test-fixture-token' ) && false === strpos( wp_json_encode( $stored ), 'test-fixture-signature' ), 'Stored profiles never contain plaintext credentials' );
donation_check( ! in_array( ICPA_Square_Settings::OPTION, array_keys( wp_load_alloptions() ), true ), 'Credential option is not autoloaded' );
donation_check( $profiles['sandbox']['access_token'] === ICPA_Square_Settings::profile( 'sandbox' )['access_token'], 'Sandbox credential decrypts correctly' );
donation_check( $profiles['production']['access_token'] === ICPA_Square_Settings::profile( 'production' )['access_token'], 'Production credential decrypts separately' );
donation_check( ICPA_Square::ready( 'sandbox' ) && ICPA_Square::ready( 'production' ), 'Both saved environments can be configured simultaneously' );
ICPA_Square_Settings::save( array( 'sandbox' => array( 'access_token' => '', 'signature_key' => '' ) ) );
donation_check( $profiles['sandbox']['access_token'] === ICPA_Square_Settings::profile( 'sandbox' )['access_token'], 'Blank fields keep existing credentials' );
$bad = ICPA_Square_Settings::save( array( 'sandbox' => array( 'access_token' => "invalid\r\nAuthorization: value" ) ) );
donation_check( is_wp_error( $bad ) && $profiles['sandbox']['access_token'] === ICPA_Square_Settings::profile( 'sandbox' )['access_token'], 'Header-injection characters are rejected without overwriting credentials' );
$cipher = $stored['sandbox']['access_token'];
donation_check( '' === ICPA_Square_Settings::decrypt( substr( $cipher, 0, -5 ) . 'AAAAA' ), 'Tampered ciphertext fails authentication' );
ob_start(); ICPA_Square_Settings::page(); $html = ob_get_clean();
donation_check( false === strpos( $html, 'sandbox-test-fixture-token' ) && false === strpos( $html, 'production-test-fixture-signature' ), 'Settings HTML never echoes credential values' );
donation_check( false !== strpos( $html, 'profiles[sandbox][access_token]' ) && false !== strpos( $html, 'profiles[production][signature_key]' ) && false !== strpos( $html, '_wpnonce' ), 'Admin UI includes both environment profiles with nonce protection' );
$subscriber = wp_insert_user( array( 'user_login' => 'donation_member_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) );
wp_set_current_user( $subscriber );
donation_check( is_wp_error( ICPA_Square_Settings::save( $profiles ) ), 'Subscribers cannot change Square profiles' );
ob_start(); ICPA_Square_Settings::page(); $private_html = ob_get_clean();
donation_check( '' === $private_html, 'Subscribers cannot view the Square settings panel' );
donation_check( is_wp_error( ICPA_Square_Settings::test_connection( 'sandbox' ) ), 'Subscribers cannot run backend connection tests' );

$requests = array(); $payments = array(); $location_currency = 'AUD';
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$requests, &$payments, &$location_currency, $profiles ) {
    if ( 0 !== strpos( $url, 'https://connect.squareupsandbox.com/' ) && 0 !== strpos( $url, 'https://connect.squareup.com/' ) ) { return $preempt; }
    $mode = false !== strpos( $url, 'squareupsandbox.com' ) ? 'sandbox' : 'production';
    donation_check( 'Bearer ' . $profiles[$mode]['access_token'] === $args['headers']['Authorization'], 'API request uses the matching environment credential' );
    $requests[] = array( 'mode' => $mode, 'path' => wp_parse_url( $url, PHP_URL_PATH ), 'body' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null );
    if ( '/v2/locations' === wp_parse_url( $url, PHP_URL_PATH ) ) {
        $data = array( 'locations' => array( array( 'id' => $profiles[$mode]['location_id'], 'status' => 'ACTIVE', 'currency' => $location_currency ) ) );
    } elseif ( 'POST' === $args['method'] ) {
        $body = json_decode( $args['body'], true );
        donation_check( $profiles[$mode]['location_id'] === $body['order']['location_id'], 'Checkout uses the matching environment location' );
        $data = array( 'payment_link' => array( 'order_id' => $mode . '-order-' . $body['idempotency_key'], 'url' => 'https://' . ( 'sandbox' === $mode ? 'sandbox.' : '' ) . 'square.link/u/test-fixture' ) );
    } else {
        $payment_id = basename( wp_parse_url( $url, PHP_URL_PATH ) );
        $data = array( 'payment' => $payments[$payment_id] );
    }
    return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
}, 10, 3 );
wp_set_current_user( $admins[0]->ID );
donation_check( true === ICPA_Square_Settings::test_connection( 'sandbox' ), 'Sandbox connection test validates location and currency' );
donation_check( true === ICPA_Square_Settings::test_connection( 'production' ), 'Production connection test is independent of active checkout mode' );
$location_currency = 'USD';
donation_check( is_wp_error( ICPA_Square_Settings::test_connection( 'production' ) ), 'Connection test catches a currency mismatch' );
$location_currency = 'AUD';
wp_set_current_user( 0 );
$key = wp_generate_uuid4();
donation_check( is_string( ICPA_Square::create_checkout( 0, '37.25', $key ) ), 'A guest can start a custom general donation without a campaign or account' );
$last = end( $requests );
donation_check( 3725 === $last['body']['order']['line_items'][0]['base_price_money']['amount'], 'Custom general donation sends exact cents to Square' );
donation_check( 'Donation: General donation' === $last['body']['order']['line_items'][0]['name'], 'General donation has its own Square description' );
donation_check( false !== strpos( $last['body']['checkout_options']['redirect_url'], 'icpa_return=1' ), 'General checkout uses a server-controlled return page' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ICPA_Platform::table() . ' WHERE checkout_key=%s', $key ), ARRAY_A );
donation_check( 0 === (int) $row['campaign_id'] && 0 === (int) $row['user_id'], 'General guest donations are stored separately from campaigns' );
$before = count( $requests );
ICPA_Square::create_checkout( 0, '37.25', $key );
donation_check( $before === count( $requests ), 'General checkout replay is idempotent' );
donation_check( false !== strpos( do_shortcode( '[icpa_donation_form]' ), 'name="campaign_id" value="0"' ), 'General donation shortcode renders a nonce-protected form' );
donation_check( false !== strpos( do_shortcode( '[icpa_donation_form]' ), 'Your donation (AUD)' ), 'General donation form shows the selected currency' );

$options['campaigns_enabled'] = 0; update_option( ICPA_Platform::OPTION, $options );
donation_check( is_string( ICPA_Square::create_checkout( 0, '25', wp_generate_uuid4() ) ), 'General giving remains available when campaigns are switched off' );
donation_check( false === strpos( ICPA_Views::browse(), 'icpa-grid' ) && false === strpos( ICPA_Views::nav(), 'Start a fundraiser' ), 'Disabling campaigns removes their frontend entry points' );
donation_check( false === strpos( ICPA_Views::account(), 'Your campaigns' ), 'General-only accounts do not show campaign controls' );
donation_check( is_wp_error( ICPA_Platform::create_campaign( array(), $subscriber ) ), 'Campaign submission is blocked server-side when disabled' );
$options['general_enabled'] = 0; update_option( ICPA_Platform::OPTION, $options );
donation_check( is_wp_error( ICPA_Square::create_checkout( 0, '25', wp_generate_uuid4() ) ), 'General giving switch is enforced on the server' );
$options['general_enabled'] = 1; $options['campaigns_enabled'] = 1;
wp_set_current_user( $admins[0]->ID );
$options['min_donation'] = '10'; $options['max_donation'] = '500'; $options['suggested_donations'] = '10, 25, 50, 100';
$options = ICPA_Platform::sanitize_settings( $options ); update_option( ICPA_Platform::OPTION, $options );
donation_check( is_wp_error( ICPA_Square::create_checkout( 0, '9.99', wp_generate_uuid4() ) ) && is_wp_error( ICPA_Square::create_checkout( 0, '500.01', wp_generate_uuid4() ) ), 'Configured amount bounds are enforced independently of the browser' );
donation_check( false !== strpos( ICPA_Views::general_form(), 'min="10.00"' ) && false !== strpos( ICPA_Views::general_form(), 'max="500.00"' ), 'Configured bounds reach the public form' );

$payments['sandbox-general-payment'] = array( 'id' => 'sandbox-general-payment', 'order_id' => $row['order_id'], 'location_id' => 'sandbox-location', 'amount_money' => array( 'amount' => 3725, 'currency' => 'AUD' ), 'status' => 'COMPLETED', 'updated_at' => '2026-11-03T12:00:00Z' );
$options['mode'] = 'production'; update_option( ICPA_Platform::OPTION, $options );
$event = wp_json_encode( array( 'type' => 'payment.updated', 'data' => array( 'object' => array( 'payment' => array( 'id' => 'sandbox-general-payment' ) ) ) ) );
$request = new WP_REST_Request( 'POST', '/icpa/v1/square/webhook/sandbox' );
$request->set_param( 'environment', 'sandbox' ); $request->set_body( $event );
$signature = base64_encode( hash_hmac( 'sha256', ICPA_Square::webhook_url( 'sandbox' ) . $event, $profiles['sandbox']['signature_key'], true ) );
$request->set_header( 'x-square-hmacsha256-signature', $signature );
donation_check( ! is_wp_error( ICPA_Square::webhook( $request ) ), 'Sandbox webhook verifies with its own profile while production is active' );
$confirmed = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ICPA_Platform::table() . ' WHERE id=%d', $row['id'] ), ARRAY_A );
donation_check( 'completed' === $confirmed['status'] && 'sandbox' === $confirmed['mode'], 'Inactive-environment webhook updates only its own donation record' );
donation_check( ! empty( get_option( 'icpa_square_webhook_health', array() )['sandbox'] ), 'Backend records the last verified callback without storing its payload' );
$request->set_header( 'x-square-hmacsha256-signature', base64_encode( hash_hmac( 'sha256', ICPA_Square::webhook_url( 'sandbox' ) . $event, $profiles['production']['signature_key'], true ) ) );
donation_check( is_wp_error( ICPA_Square::webhook( $request ) ), 'Production signature cannot authenticate a sandbox webhook' );
donation_check( is_string( ICPA_Square::create_checkout( 0, '25', wp_generate_uuid4() ) ), 'Production general checkout uses its own saved profile' );
wp_set_current_user( $admins[0]->ID );
ICPA_Square_Settings::save( array( 'production' => array( 'clear_access_token' => '1' ) ) );
donation_check( ! ICPA_Square::ready( 'production' ) && ICPA_Square::ready( 'sandbox' ), 'Removing a production credential leaves sandbox intact' );
delete_option( ICPA_Square_Settings::OPTION );
echo "PASS: {$checks} general-donation and Square-profile checks (all Square HTTP mocked)\n";
