<?php
/** Disposable demo fixture only. Never run on a live site. Not included in the plugin ZIP. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ICPA_RUN_DEMO' ) ) { exit( 'Demo requires explicit CLI opt-in.' ); }
define( 'ICPA_SQUARE_ACCESS_TOKEN', 'demo-fixture-not-a-real-token' );
define( 'ICPA_SQUARE_WEBHOOK_SIGNATURE_KEY', 'demo-fixture-not-a-real-key' );
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_SITEURL', 'http://localhost' );
define( 'WP_HOME', 'http://localhost' );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
if ( 'ICPA test' !== get_option( 'blogname' ) ) { exit( 'This fixture only runs on the disposable ICPA test site.' ); }
activate_plugin( 'icpa-fundraising/icpa-fundraising.php' );
ICPA_Platform::register();
global $wpdb;
foreach ( get_posts( array( 'post_type' => ICPA_Platform::TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
$wpdb->query( 'TRUNCATE TABLE ' . ICPA_Platform::table() );
update_option( 'home', 'http://localhost' );
update_option( 'siteurl', 'http://localhost' );
update_option( 'users_can_register', 1 );
update_option( 'default_role', 'subscriber' );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admins[0]->ID;
wp_update_user( array( 'ID' => $admin, 'display_name' => 'ICPA Community' ) );
$donor = username_exists( 'demo_donor' );
if ( ! $donor ) { $donor = wp_insert_user( array( 'user_login' => 'demo_donor', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'Amina' ) ); }
$pages = array();
foreach ( array( 'general_page' => array( 'General donations', '[icpa_donation_form]' ), 'browse_page' => array( 'Discover campaigns', '[icpa_campaigns]' ), 'submit_page' => array( 'Start a fundraiser', '[icpa_start_campaign]' ), 'account_page' => array( 'My account', '[icpa_account]' ) ) as $key => $page ) {
    $id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => $page[0], 'post_content' => $page[1], 'post_status' => 'publish' ) );
    update_post_meta( $id, '_wp_page_template', 'icpa-fundraising-full-width' );
    $pages[$key] = $id;
}
update_option( ICPA_Platform::OPTION, array_merge( $pages, array( 'currency' => 'AUD', 'mode' => 'sandbox', 'location_id' => 'demo-location', 'layout' => 'standalone' ) ) );
$fixtures = array(
    array( 'Help families put food on the table', 'Relief', '25000', 1845000, 147, 'No family should have to choose between paying the bills and putting a meal on the table. This example campaign brings our community together to provide food essentials for families facing difficult times.\n\nFunds would support the purchase and distribution of food parcels through ICPA. Every contribution, large or small, helps us work towards a community where everyone feels supported.\n\nThis is a demonstration campaign. Its story and donation amounts are sample data.' ),
    array( 'Open a brighter chapter for children', 'Education', '15000', 825000, 82, 'A book can open a whole new world. Help build a welcoming place where children can read, learn and discover what they are capable of.\n\nThis example campaign would fund learning materials, books and community learning activities. ICPA would receive donations and manage the use of funds.\n\nThis is a demonstration campaign. Its story and donation amounts are sample data.' ),
    array( 'Bring essential care closer to home', 'Health', '20000', 1268000, 106, 'Everyone deserves access to care and support when they need it most. Together, we can make a difference for families facing barriers to essential health services.\n\nThis example campaign would help ICPA support community health initiatives and access to basic care.\n\nThis is a demonstration campaign. Its story and donation amounts are sample data.' ),
);
$campaigns = array();
foreach ( $fixtures as $index => $f ) {
    $term = get_term_by( 'name', $f[1], 'icpa_cause' );
    $id = ICPA_Platform::create_campaign( array( 'title' => $f[0], 'story' => str_replace( '\n', "\n", $f[5] ), 'goal' => $f[2], 'cause' => $term->term_id, 'deadline' => '', 'consent' => '1' ), 0 === $index ? $donor : $admin );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    wp_update_post( array( 'ID' => $id, 'post_status' => 'publish', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - $index * 60 ) ) );
    $campaigns[] = $id;
    for ( $i = 0; $i < $f[4]; ++$i ) {
        $amount = (int) floor( $f[3] / $f[4] ) + ( $i < $f[3] % $f[4] ? 1 : 0 );
        $wpdb->insert( ICPA_Platform::table(), array( 'campaign_id' => $id, 'user_id' => 0 === $i ? $donor : 0, 'amount' => $amount, 'currency' => 'AUD', 'mode' => 'sandbox', 'checkout_key' => wp_generate_uuid4(), 'order_id' => 'demo-order-' . $index . '-' . $i, 'payment_id' => 'demo-payment-' . $index . '-' . $i, 'status' => 'completed', 'created_at' => current_time( 'mysql', true ) ) );
    }
}
wp_set_current_user( $donor );
$embedded_options = ICPA_Platform::options();
$embedded_options['layout'] = 'theme';
update_option( ICPA_Platform::OPTION, $embedded_options );
$sample_links = array();
foreach ( array( 'MONTHLY' => 2500, 'ANNUAL' => 30000 ) as $cadence => $amount ) {
    $sample_links[$cadence] = array( 'id' => 'sample-' . strtolower( $cadence ), 'version' => 1, 'name' => 'Sample ' . strtolower( ICPA_Recurring::cadences()[$cadence] ) . ' plan', 'cadence' => $cadence, 'amount' => $amount, 'currency' => 'AUD', 'url' => 'https://sandbox.square.link/u/sample-' . strtolower( $cadence ), 'fingerprint' => ICPA_Recurring::fingerprint( 'sandbox' ) );
}
update_option( ICPA_Recurring::OPTION, array( 'enabled' => 1, 'one_time' => 1, 'cadences' => array( 'MONTHLY', 'ANNUAL' ), 'links' => array( 'sandbox' => $sample_links ) ), false );
$sample_catalog = array(); foreach ( $sample_links as $p ) { $sample_catalog[$p['id']] = $p; }
update_option( ICPA_Recurring::PLANS, array( 'sandbox' => array( 'fingerprint' => ICPA_Recurring::fingerprint( 'sandbox' ), 'plans' => $sample_catalog, 'cursor' => '' ) ), false );
$data = array( 'donate' => ICPA_Views::general_form(), 'browse' => ICPA_Views::browse(), 'urls' => array( 'donate' => get_permalink( $pages['general_page'] ), 'browse' => get_permalink( $pages['browse_page'] ), 'start' => get_permalink( $pages['submit_page'] ), 'account' => get_permalink( $pages['account_page'] ) ) );
foreach ( $campaigns as $index => $id ) { $data['campaign' . $index] = ICPA_Views::campaign( $id ); $data['urls']['campaign' . $index] = get_permalink( $id ); }
wp_set_current_user( $donor );
$data['start'] = ICPA_Views::start();
$data['account'] = ICPA_Views::account();
wp_set_current_user( $admin );
ob_start(); ICPA_Platform::settings_page(); ICPA_Square_Settings::page();
$data['settings'] = '<div class="demo-admin"><p><strong>WordPress administration preview only. Do not enter real credentials here.</strong></p>' . ob_get_clean() . '</div>';
ob_start(); ICPA_Recurring::page();
$data['recurring_settings'] = '<div class="demo-admin"><p><strong>Recurring settings preview only. Plans and prices are sample data; this preview does not create or change anything in Square.</strong></p>' . ob_get_clean() . '</div>';
file_put_contents( '/tmp/icpa-platform-views.json', wp_json_encode( $data ) );
$embedded_options['layout'] = 'standalone';
update_option( ICPA_Platform::OPTION, $embedded_options );
wp_set_current_user( 0 );
$checks = array(
    get_permalink( $pages['general_page'] ) => array( 'Donate &amp; Support', 'Donations will open once ICPA connects Square.' ),
    get_permalink( $pages['browse_page'] ) => array( 'Support A Campaign', 'Help families put food on the table', 'platform.css', 'platform.js' ),
    get_permalink( $campaigns[0] ) => array( 'Make good happen.', '18,450.00', 'Donations will open once ICPA connects Square.' ),
    get_permalink( $pages['submit_page'] ) => array( 'Sign in to start your fundraiser', 'Create an account' ),
    get_permalink( $pages['account_page'] ) => array( 'Your generosity, all in one place.', 'Sign in' ),
);
foreach ( $checks as $url => $expected ) {
    $html = file_get_contents( $url );
    foreach ( $expected as $text ) { if ( false === strpos( $html, $text ) ) { throw new RuntimeException( 'HTTP smoke check failed: ' . $text ); } }
    if ( false === strpos( $html, 'icpa-platform-page' ) ) { throw new RuntimeException( 'Full-width template was not applied.' ); }
}
// Verify the native theme still renders around both browse and campaign screens.
$native = ICPA_Platform::options();
$native['layout'] = 'theme';
update_option( ICPA_Platform::OPTION, $native );
foreach ( array( get_permalink( $pages['browse_page'] ), get_permalink( $campaigns[0] ) ) as $url ) {
    $html = file_get_contents( $url );
    if ( false === strpos( $html, 'icpa-theme-integrated' ) || false === strpos( $html, 'wp-site-blocks' ) || false !== strpos( $html, 'icpa-platform-page' ) ) { throw new RuntimeException( 'Native WordPress theme integration failed.' ); }
    if ( $url === get_permalink( $pages['browse_page'] ) && ( false !== strpos( $html, '<header class="icpa-hero' ) || false !== strpos( $html, 'class="icpa-giving-intro"' ) ) ) { throw new RuntimeException( 'Plugin unexpectedly duplicated the existing website banner or introduction.' ); }
}
$native['layout'] = 'standalone';
update_option( ICPA_Platform::OPTION, $native );
foreach ( array( 'assets/community.svg' => '<svg', 'assets/platform.css' => '.icpa-platform', 'assets/platform.js' => '.icpa-amounts' ) as $asset => $expected ) {
    $body = file_get_contents( plugins_url( $asset, ICPA_FUNDRAISING_FILE ) );
    if ( false === strpos( $body, $expected ) ) { throw new RuntimeException( 'WordPress asset did not load: ' . $asset ); }
}
// Real HTTP has no fixture credentials. The campaign must keep checkout disabled.
foreach ( array(
    array( admin_url( 'admin-post.php' ), 'action=icpa_checkout&campaign_id=' . $campaigns[0] . '&amount=50', 403 ),
    array( admin_url( 'admin-post.php' ), 'action=icpa_submit_campaign&title=Unapproved', 302 ),
    array( rest_url( 'icpa/v1/square/webhook' ), '{}', 403 ),
    array( rest_url( 'icpa/v1/square/webhook/sandbox' ), '{}', 403 ),
    array( rest_url( 'icpa/v1/square/webhook/production' ), '{}', 403 ),
) as $check ) {
    $context = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $check[1], 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10 ) ) );
    file_get_contents( $check[0], false, $context );
    if ( ! isset( $http_response_header[0] ) || false === strpos( $http_response_header[0], (string) $check[2] ) ) { throw new RuntimeException( 'HTTP permission/CSRF check failed.' ); }
}
file_get_contents( add_query_arg( 'action', 'icpa_checkout', admin_url( 'admin-post.php' ) ), false, stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'follow_location' => 0 ) ) ) );
if ( ! isset( $http_response_header[0] ) || false === strpos( $http_response_header[0], '405' ) ) { throw new RuntimeException( 'Checkout accepted a GET request.' ); }
$oversized = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => 'action=icpa_checkout&padding=' . str_repeat( 'a', 20000 ), 'ignore_errors' => true, 'follow_location' => 0 ) ) );
file_get_contents( admin_url( 'admin-post.php' ), false, $oversized );
if ( ! isset( $http_response_header[0] ) || false === strpos( $http_response_header[0], '413' ) ) { throw new RuntimeException( 'Checkout accepted an oversized form.' ); }
echo "PASS: 5 standalone and 2 native-theme HTTP pages plus checkout CSRF, guest submission and webhook checks; checkout disabled without real Square configuration. Demo views exported.\n";
