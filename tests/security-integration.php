<?php
/** Security regression checks. Disposable WordPress only. All Square HTTP is mocked. */
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
activate_plugin( 'icpa-fundraising/icpa-fundraising.php' );
ICPA_Platform::register();
$checks = 0;
function security_check( $condition, $message ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admins[0]->ID; wp_set_current_user( $admin );
update_option( ICPA_Platform::OPTION, array( 'currency' => 'AUD', 'mode' => 'sandbox' ) );
delete_option( ICPA_Recurring::OPTION ); delete_option( ICPA_Square_Settings::OPTION );
$sandbox_key = 'security-sandbox-fixture-key';
$production_key = 'security-production-fixture-key';
ICPA_Square_Settings::save( array( 'sandbox' => array( 'location_id' => 'security-sandbox', 'access_token' => 'security-fixture-token', 'signature_key' => $sandbox_key ), 'production' => array( 'location_id' => 'security-production', 'access_token' => 'security-production-token', 'signature_key' => $production_key ) ) );

foreach ( array( array( 1 ), 1.5, -1, '1 OR 1=1', '1e3', '+1', "1\n", '999999999999999999999999' ) as $bad ) {
    security_check( null === ICPA_Security::id( $bad ), 'Malformed numeric identifiers are rejected' );
    security_check( is_wp_error( ICPA_Square::create_checkout( $bad, '25.50', wp_generate_uuid4() ) ), 'Malformed campaign identifiers cannot start checkout' );
}
security_check( 0 === ICPA_Security::id( '0' ) && 12 === ICPA_Security::id( '12' ), 'Valid general/campaign identifiers remain supported' );
security_check( 1000 === ICPA_Security::page( '9999999999' ) && 1 === ICPA_Security::page( array() ), 'Public pagination is bounded and scalar' );
security_check( is_wp_error( ICPA_Square::create_checkout( 0, '25.50', wp_generate_uuid4() . "\n" ) ), 'UUIDs cannot contain trailing control characters' );
foreach ( array( 'javascript:alert(1)', 'https://square.link.evil.example/', 'https://square.link@evil.example/', 'https://evil@square.link/', '//square.link/u/pay', 'http://square.link/u/pay', 'https://square.link:8080/', "https://square.link/u/x\r\nLocation:evil", 'https://square.link/u/x%0d%0aLocation:evil', 'https://square.link/u/x%00evil' ) as $url ) {
    security_check( ! ICPA_Square::valid_checkout_url( $url ), 'Unsafe checkout redirect destination is rejected' );
}
security_check( null === ICPA_Security::timestamp( 'now' ) && null === ICPA_Security::timestamp( '2026-02-30T00:00:00Z' ), 'Provider timestamps must be valid absolute RFC3339 dates' );
security_check( '2026-11-03 00:00:00.123456' === ICPA_Security::timestamp( '2026-11-03T11:00:00.123456789+11:00' ), 'Timestamp timezone and subsecond normalization are deterministic' );

// Stored/reflected XSS and shortcode execution through the real the_content pipeline.
$probe_calls = 0;
add_shortcode( 'icpa_security_probe', function () use ( &$probe_calls ) { ++$probe_calls; return 'EXECUTED_SHORTCODE'; } );
$payload = '<p>Campaign story [icpa_security_probe]</p><!-- wp:shortcode -->[icpa_security_probe]<!-- /wp:shortcode --><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">unsafe</a><script>alert(1)</script><svg onload="alert(1)"></svg>';
$campaign = wp_insert_post( array( 'post_type' => ICPA_Platform::TYPE, 'post_title' => 'Security campaign', 'post_content' => 'Initial story', 'post_status' => 'publish', 'post_author' => $admin ) );
global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_content' => $payload ), array( 'ID' => $campaign ) ); clean_post_cache( $campaign ); update_post_meta( $campaign, '_icpa_goal', 500000 );
$old_query = $GLOBALS['wp_query']; $old_main = $GLOBALS['wp_the_query'];
$q = new WP_Query( array( 'post_type' => ICPA_Platform::TYPE, 'p' => $campaign ) );
$GLOBALS['wp_query'] = $q; $GLOBALS['wp_the_query'] = $q; $q->the_post();
$html = apply_filters( 'the_content', get_post( $campaign )->post_content );
security_check( 0 === $probe_calls && false === strpos( $html, 'EXECUTED_SHORTCODE' ), 'Campaign stories cannot execute third-party shortcodes or shortcode blocks' );
foreach ( array( '<script', 'onerror=', 'onload=', 'javascript:', '<svg' ) as $danger ) { security_check( false === stripos( $html, $danger ), 'Unsafe story markup is removed before rendering' ); }
security_check( false !== strpos( $html, '&#91;icpa_security_probe&#93;' ), 'Bracketed story text is preserved as inert text' );
$GLOBALS['wp_query'] = $old_query; $GLOBALS['wp_the_query'] = $old_main; wp_reset_postdata();
$_GET['icpa_search'] = '\"><svg onload=alert(1)>SQL\' OR 1=1--';
$browse = ICPA_Views::browse();
security_check( false === stripos( $browse, '<svg onload' ), 'Reflected campaign searches cannot inject active HTML' );
unset( $_GET['icpa_search'] );

// Credential confidentiality, authenticated encryption contexts and v1 compatibility.
$cipher = ICPA_Square_Settings::encrypt( 'confidential-test-fixture', 'sandbox|access_token' );
security_check( is_string( $cipher ) && 0 === strpos( $cipher, 'v2:' ), 'New credentials use authenticated context-bound encryption' );
security_check( 'confidential-test-fixture' === ICPA_Square_Settings::decrypt( $cipher, 'sandbox|access_token' ), 'Credential decrypts only with the correct key/context' );
security_check( '' === ICPA_Square_Settings::decrypt( $cipher, 'production|access_token' ) && '' === ICPA_Square_Settings::decrypt( $cipher, 'sandbox|signature_key' ), 'Ciphertexts cannot be swapped between environments or credential fields' );
$binary = base64_decode( substr( $cipher, 3 ) ); $binary[30] = chr( ord( $binary[30] ) ^ 1 );
security_check( '' === ICPA_Square_Settings::decrypt( 'v2:' . base64_encode( $binary ), 'sandbox|access_token' ), 'Modified ciphertext fails GCM authentication' );
$iv = random_bytes( 12 ); $tag = ''; $legacy_key = hash( 'sha256', wp_salt( 'auth' ) . '|ICPA Square profiles v1', true );
$legacy_data = openssl_encrypt( 'legacy-test-fixture', 'aes-256-gcm', $legacy_key, OPENSSL_RAW_DATA, $iv, $tag );
security_check( 'legacy-test-fixture' === ICPA_Square_Settings::decrypt( 'v1:' . base64_encode( $iv . $tag . $legacy_data ), 'sandbox|access_token' ), 'Existing encrypted profiles remain readable after upgrade' );
$backup_profiles = get_option( ICPA_Square_Settings::OPTION ); $legacy_profiles = $backup_profiles; $legacy_profiles['sandbox']['access_token'] = 'v1:' . base64_encode( $iv . $tag . $legacy_data ); update_option( ICPA_Square_Settings::OPTION, $legacy_profiles, false );
security_check( true === ICPA_Square_Settings::save( array( 'sandbox' => array( 'access_token' => '' ) ) ), 'Blank-secret profile saves can migrate legacy ciphertext safely' );
security_check( 0 === strpos( get_option( ICPA_Square_Settings::OPTION )['sandbox']['access_token'], 'v2:' ) && 'legacy-test-fixture' === ICPA_Square_Settings::profile( 'sandbox' )['access_token'], 'Legacy migration preserves the credential while binding its encryption context' );
update_option( ICPA_Square_Settings::OPTION, $backup_profiles, false );
security_check( is_wp_error( ICPA_Square_Settings::save( array( 'sandbox' => array( 'access_token' => "valid-test-token\n" ) ) ) ), 'Trailing header-control characters are rejected' );
ob_start(); ICPA_Square_Settings::page(); ICPA_Recurring::page(); $settings = ob_get_clean();
security_check( false === strpos( $settings, 'security-fixture-token' ) && false === strpos( $settings, $sandbox_key ) && false === strpos( $settings, $production_key ), 'Admin HTML does not reveal saved credentials' );

// Exercise capability, CSRF and method checks without letting handlers terminate the suite.
class ICPA_Security_Test_Die extends RuntimeException { public $status; public function __construct( $status ) { $this->status = $status; parent::__construct( 'WordPress request rejected' ); } }
$die_filter = function () { return function ( $message, $title, $args ) { throw new ICPA_Security_Test_Die( isset( $args['response'] ) ? (int) $args['response'] : 500 ); }; };
add_filter( 'wp_die_handler', $die_filter );
$subscriber = wp_insert_user( array( 'user_login' => 'security_member_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) );
$handlers = array( array( 'ICPA_Square_Settings', 'save_handler' ), array( 'ICPA_Square_Settings', 'test_handler' ), array( 'ICPA_Recurring', 'settings_handler' ), array( 'ICPA_Recurring', 'refresh_handler' ), array( 'ICPA_Recurring', 'plan_handler' ), array( 'ICPA_Platform', 'reconcile' ) );
$original_profiles = get_option( ICPA_Square_Settings::OPTION );
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['CONTENT_LENGTH'] = '100';
$_POST = array( 'environment' => 'sandbox', 'donation_id' => '1', 'profiles' => array( 'sandbox' => array( 'access_token' => 'attacker-token-fixture' ) ) );
foreach ( $handlers as $handler ) {
    wp_set_current_user( $subscriber ); $_REQUEST = array( '_wpnonce' => 'forged' );
    try { call_user_func( $handler ); throw new RuntimeException( 'Subscriber handler unexpectedly succeeded' ); } catch ( ICPA_Security_Test_Die $e ) { security_check( 403 === $e->status, 'Subscribers cannot invoke administrative mutation handlers' ); }
    wp_set_current_user( $admin );
    try { call_user_func( $handler ); throw new RuntimeException( 'Forged nonce unexpectedly succeeded' ); } catch ( ICPA_Security_Test_Die $e ) { security_check( 403 === $e->status, 'Administrative mutation handlers reject forged nonces' ); }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try { call_user_func( $handler ); throw new RuntimeException( 'GET mutation unexpectedly succeeded' ); } catch ( ICPA_Security_Test_Die $e ) { security_check( 405 === $e->status, 'Administrative mutations reject GET requests' ); }
    $_SERVER['REQUEST_METHOD'] = 'POST';
}
security_check( $original_profiles === get_option( ICPA_Square_Settings::OPTION ), 'Rejected administrative requests do not overwrite credentials' );
$_SERVER['CONTENT_LENGTH'] = '999999';
try { ICPA_Security::require_post( 16384 ); throw new RuntimeException( 'Oversized request was accepted' ); } catch ( ICPA_Security_Test_Die $e ) { security_check( 413 === $e->status, 'Oversized form submissions are rejected' ); }
$_SERVER['CONTENT_LENGTH'] = '0'; $_POST = array(); $_REQUEST = array();
remove_filter( 'wp_die_handler', $die_filter );

// Atomic rate limiting, parallel requests and restricted cleanup.
$identity = wp_generate_uuid4();
for ( $i = 0; $i < 3; ++$i ) { security_check( ICPA_Security::rate_limit( 'security-sequential', 3, 60, $identity ), 'Requests inside the configured limit are accepted' ); }
security_check( ! ICPA_Security::rate_limit( 'security-sequential', 3, 60, $identity ), 'Requests above the configured limit are rejected' );
$workers = array(); $parallel_id = wp_generate_uuid4();
for ( $i = 0; $i < 8; ++$i ) {
    $pipes = array(); $process = proc_open( array( PHP_BINARY, '/test/rate-worker.php', $parallel_id ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start rate-limit worker' ); }
    $workers[] = array( $process, $pipes );
}
$accepted = 0;
foreach ( $workers as $worker ) {
    $out = stream_get_contents( $worker[1][1] ); $err = stream_get_contents( $worker[1][2] ); fclose( $worker[1][1] ); fclose( $worker[1][2] );
    $exit = proc_close( $worker[0] );
    security_check( 0 === $exit && in_array( $out, array( '0', '1' ), true ) && '' === $err, 'Concurrent rate-limit worker completed against real WordPress' );
    $accepted += (int) $out;
}
security_check( $accepted <= 3, 'Parallel requests cannot exceed the atomic rate limit' );
$rate_name = ICPA_Security::RATE_PREFIX . hash_hmac( 'sha256', 'security-expiry|' . $identity, wp_salt( 'nonce' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => $rate_name, 'option_value' => ( time() - 1 ) . ':99', 'autoload' => 'no' ) );
security_check( ICPA_Security::rate_limit( 'security-expiry', 3, 60, $identity ), 'Expired rate windows reset correctly' );
$expired = ICPA_Security::RATE_PREFIX . 'expired-fixture'; $wpdb->insert( $wpdb->options, array( 'option_name' => $expired, 'option_value' => '1:99', 'autoload' => 'no' ) );
$foreign = 'icpa_foreign_fixture'; add_option( $foreign, '1:99', '', false ); ICPA_Security::cleanup();
security_check( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name=%s", $expired ) ) && '1:99' === get_option( $foreign ), 'Cleanup removes only expired plugin rate metadata' ); delete_option( $foreign );

// Signed Square callbacks, environment tampering, malformed payment data and accounting invariants.
$calls = 0; $sent_key = ''; $last_mode = ''; $payment = array();
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$calls, &$sent_key, &$last_mode, &$payment ) {
    if ( 0 !== strpos( $url, 'https://connect.squareupsandbox.com/' ) && 0 !== strpos( $url, 'https://connect.squareup.com/' ) ) { return $preempt; }
    ++$calls; $last_mode = false !== strpos( $url, 'squareupsandbox.com' ) ? 'sandbox' : 'production';
    if ( 'POST' === $args['method'] ) { $body = json_decode( $args['body'], true ); $sent_key = $body['idempotency_key']; $result = array( 'payment_link' => array( 'order_id' => 'security-order-' . $sent_key, 'url' => 'https://sandbox.square.link/u/security-fixture' ) ); }
    else { $result = array( 'payment' => $payment ); }
    return array( 'headers' => array(), 'body' => wp_json_encode( $result ), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
}, 10, 3 );
wp_set_current_user( $admin ); $key = wp_generate_uuid4(); ICPA_Square::create_checkout( 0, '25.50', strtoupper( $key ) );
security_check( $sent_key === strtolower( $key ), 'UUID case is canonicalized before Square idempotency requests' );
$before = $calls; ICPA_Square::create_checkout( 0, '25.50', strtolower( $key ) ); security_check( $before === $calls, 'Case variants reuse the same checkout' );
$payment = array( 'id' => 'security-payment', 'order_id' => 'security-order-' . $key, 'location_id' => 'security-sandbox', 'amount_money' => array( 'amount' => 2550, 'currency' => 'AUD' ), 'status' => 'COMPLETED', 'updated_at' => '2026-11-03T12:00:00Z' );
$body = wp_json_encode( array( 'environment' => 'production', 'type' => 'payment.updated', 'data' => array( 'object' => array( 'payment' => array( 'id' => 'security-payment' ) ) ) ) );
$request = new WP_REST_Request( 'POST', '/icpa/v1/square/webhook/sandbox' ); $request->set_query_params( array( 'environment' => 'production' ) ); $request->set_body( $body );
$request->set_header( 'x-square-hmacsha256-signature', 'forged' ); $before = $calls;
security_check( is_wp_error( ICPA_Square::webhook( $request ) ) && $calls === $before, 'Forged webhook signatures cannot trigger Square API reads' );
$request->set_header( 'x-square-hmacsha256-signature', base64_encode( hash_hmac( 'sha256', ICPA_Square::webhook_url( 'sandbox' ) . $body, $sandbox_key, true ) ) );
security_check( ! is_wp_error( ICPA_Square::webhook( $request ) ) && 'sandbox' === $last_mode, 'Query/body environment parameters cannot override the signed webhook route' );
$request->set_header( 'x-square-hmacsha256-signature', base64_encode( hash_hmac( 'sha256', ICPA_Square::webhook_url( 'production' ) . $body, $production_key, true ) ) ); $before = $calls;
security_check( is_wp_error( ICPA_Square::webhook( $request ) ) && $before === $calls, 'Production signing keys cannot authenticate sandbox routes' );
$request->set_body( str_repeat( 'x', 1048577 ) ); security_check( is_wp_error( ICPA_Square::webhook( $request ) ), 'Oversized webhook bodies are rejected' );
$invalid_payment = $payment; $invalid_payment['refunded_money'] = 'malformed';
security_check( is_wp_error( ICPA_Square::apply_payment( $invalid_payment, 'sandbox' ) ), 'Malformed refund metadata cannot overwrite accounting state' );
$invalid_payment = $payment; $invalid_payment['updated_at'] = 'next Monday';
security_check( is_wp_error( ICPA_Square::apply_payment( $invalid_payment, 'sandbox' ) ), 'Relative timestamps cannot bypass event ordering' );
$invalid_payment = $payment; $invalid_payment['amount_money']['amount'] = 1;
security_check( is_wp_error( ICPA_Square::apply_payment( $invalid_payment, 'sandbox' ) ), 'Tampered payment amounts cannot mark donations paid' );
$wpdb->insert( ICPA_Platform::table(), array( 'campaign_id' => 0, 'user_id' => $subscriber, 'amount' => 1200, 'currency' => 'AUD', 'mode' => 'sandbox', 'checkout_key' => wp_generate_uuid4(), 'status' => 'pending', 'created_at' => current_time( 'mysql', true ) ) );
wp_set_current_user( $subscriber ); $_GET['user_id'] = $admin; $account = ICPA_Views::account();
security_check( false !== strpos( $account, '12.00' ) && false === strpos( $account, '25.50' ), 'Donor query parameters cannot expose another account’s donation history' ); unset( $_GET['user_id'] );
delete_option( ICPA_Square_Settings::OPTION );
echo "PASS: {$checks} security regression checks (including concurrent requests; no live Square calls)\n";
