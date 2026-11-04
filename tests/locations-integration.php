<?php
/** Disposable WordPress; Square HTTP mocked. */
require '/var/www/html/wp-load.php';
$checks = 0;
function location_check( $ok, $message ) { global $checks; if ( ! $ok ) { throw new RuntimeException( $message ); } ++$checks; }
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $admin );
$old_options = get_option( ICPA_Platform::OPTION ); $old_profiles = get_option( ICPA_Square_Settings::OPTION );
update_option( ICPA_Platform::OPTION, array( 'mode' => 'sandbox', 'currency' => 'AUD' ) );
delete_option( 'icpa_square_locations' );
ICPA_Square_Settings::save( array( 'sandbox' => array( 'location_id' => 'old-location', 'access_token' => 'location-sandbox-fixture-token', 'signature_key' => 'location-sandbox-fixture-signature' ), 'production' => array( 'access_token' => 'location-production-fixture-token' ) ) );
$requests = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$requests ) {
    $requests[] = $url;
    return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'locations' => array(
        array( 'id' => 'old-location', 'name' => 'Bankstown', 'status' => 'ACTIVE', 'currency' => 'AUD' ),
        array( 'id' => 'new-location', 'name' => 'Sydney & Community', 'status' => 'ACTIVE', 'currency' => 'AUD' ),
        array( 'id' => 'inactive-location', 'name' => 'Closed', 'status' => 'INACTIVE', 'currency' => 'AUD' ),
        array( 'id' => 'usd-location', 'name' => 'USD branch', 'status' => 'ACTIVE', 'currency' => 'USD' ),
        array( 'id' => '"><script>', 'name' => 'Invalid', 'status' => 'ACTIVE', 'currency' => 'AUD' ),
    ) ) ) );
}, 10, 3 );
location_check( true === ICPA_Square_Settings::load_locations( 'sandbox' ), 'Can load Sandbox locations without a prior location selection' );
location_check( 4 === count( ICPA_Square_Settings::locations( 'sandbox' ) ), 'Drops malformed provider identifiers' );
location_check( array() === ICPA_Square_Settings::locations( 'production' ), 'Sandbox and Production location caches are independent' );
location_check( true === ICPA_Square_Settings::load_locations( 'production' ) && false !== strpos( $requests[1], 'connect.squareup.com' ), 'Production location discovery uses Production API' );
ob_start(); ICPA_Square_Settings::location_select( 'sandbox' ); $html = ob_get_clean();
location_check( false !== strpos( $html, 'Sydney &amp; Community' ) && false !== strpos( $html, '<select' ), 'Dropdown shows escaped location names' );
location_check( 2 === substr_count( $html, 'disabled=' ), 'Inactive and wrong-currency choices are disabled' );
foreach ( array( 'inactive-location', 'usd-location', 'forged-location' ) as $id ) { location_check( is_wp_error( ICPA_Square_Settings::save( array( 'sandbox' => array( 'location_id' => $id ) ) ) ), 'Cannot select ineligible or forged discovered location' ); }
global $wpdb; $key = wp_generate_uuid4();
$wpdb->insert( ICPA_Platform::table(), array( 'campaign_id' => 0, 'amount' => 2500, 'currency' => 'AUD', 'mode' => 'sandbox', 'location_id' => '', 'checkout_key' => $key, 'order_id' => 'legacy-location-order', 'created_at' => current_time( 'mysql', true ) ) );
$id = $wpdb->insert_id;
location_check( true === ICPA_Square_Settings::save( array( 'sandbox' => array( 'location_id' => 'new-location' ) ) ), 'Eligible location can be selected' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ICPA_Platform::table() . ' WHERE id=%d', $id ), ARRAY_A );
location_check( 'old-location' === $row['location_id'], 'Legacy donation retains original location before default changes' );
$payment = array( 'id' => 'legacy-location-payment', 'order_id' => 'legacy-location-order', 'location_id' => 'old-location', 'amount_money' => array( 'amount' => 2500, 'currency' => 'AUD' ), 'status' => 'COMPLETED', 'updated_at' => '2026-11-04T03:00:00Z' );
location_check( true === ICPA_Square::apply_payment( $payment, 'sandbox' ), 'Old-location payment still reconciles after default changes' );
ICPA_Square_Settings::save( array( 'sandbox' => array( 'access_token' => 'changed-location-fixture-token' ) ) );
location_check( array() === ICPA_Square_Settings::locations( 'sandbox' ), 'Changing token invalidates cached location names' );
wp_set_current_user( 0 ); location_check( is_wp_error( ICPA_Square_Settings::load_locations( 'sandbox' ) ), 'Guests cannot discover locations' );
wp_set_current_user( $admin );
update_option( ICPA_Platform::OPTION, $old_options ); if ( false === $old_profiles ) { delete_option( ICPA_Square_Settings::OPTION ); } else { update_option( ICPA_Square_Settings::OPTION, $old_profiles ); } delete_option( 'icpa_square_locations' );
$wpdb->delete( ICPA_Platform::table(), array( 'id' => $id ) );
$minimal = ICPA_Views::general_form( array( 'appearance' => 'minimal' ) );
location_check( false !== strpos( $minimal, 'icpa-widget-minimal' ), 'Page builders can embed borderless minimal form' );
echo 'PASS: ' . $checks . " Square location discovery/selection and historical-payment checks (Square mocked)\n";
