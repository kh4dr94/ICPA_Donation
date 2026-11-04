<?php
/** Disposable WordPress only. Square HTTP mocked. */
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
$checks = 0;
function creation_check( $ok, $message ) { global $checks; if ( ! $ok ) throw new RuntimeException( $message ); ++$checks; }
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID; wp_set_current_user( $admin );
$old_site = get_option( ICPA_Platform::OPTION ); $old_profiles = get_option( ICPA_Square_Settings::OPTION );
delete_option( ICPA_Recurring::OPTION ); delete_option( ICPA_Recurring::PLANS );
update_option( ICPA_Platform::OPTION, array( 'mode' => 'sandbox', 'currency' => 'AUD' ) );
ICPA_Square_Settings::save( array( 'sandbox' => array( 'location_id' => 'created-sandbox-location', 'access_token' => 'created-sandbox-fixture-token', 'signature_key' => 'created-sandbox-fixture-key' ), 'production' => array( 'location_id' => 'created-production-location', 'access_token' => 'created-production-fixture-token', 'signature_key' => 'created-production-fixture-key' ) ) );
$requests = array(); $objects = array(); $post_count = 0; $link_count = 0; $fail_link = false; $bad_amount = false;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$requests, &$objects, &$post_count, &$link_count, &$fail_link, &$bad_amount ) {
    $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
    $requests[] = array( 'url' => $url, 'body' => $body );
    $mode = false !== strpos( $url, 'squareupsandbox' ) ? 'sandbox' : 'production';
    if ( 'POST' === $args['method'] && substr( $url, -18 ) === '/v2/catalog/object' ) {
        ++$post_count; $object = $body['object'];
        $id = ( 'SUBSCRIPTION_PLAN' === $object['type'] ? 'parent-' : 'variation-' ) . substr( $body['idempotency_key'], 0, 24 );
        $object['id'] = $id; $object['version'] = 1;
        if ( $bad_amount && 'SUBSCRIPTION_PLAN_VARIATION' === $object['type'] ) { ++$object['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['amount']; }
        $objects[$mode][$id] = $object; $response = array( 'catalog_object' => $object );
    } elseif ( 'GET' === $args['method'] && false !== strpos( $url, '/v2/catalog/object/' ) ) {
        $id = rawurldecode( basename( $url ) );
        if ( ! isset( $objects[$mode][$id] ) ) throw new RuntimeException( 'Unknown mocked catalog ID' );
        $response = array( 'object' => $objects[$mode][$id] );
    } elseif ( false !== strpos( $url, '/v2/online-checkout/payment-links' ) ) {
        ++$link_count;
        if ( $fail_link ) return new WP_Error( 'simulated_timeout', 'Fixture checkout timeout' );
        $response = array( 'payment_link' => array( 'url' => 'https://' . ( 'sandbox' === $mode ? 'sandbox.' : '' ) . 'square.link/u/created-fixture', 'checkout_options' => array( 'subscription_plan_id' => $body['checkout_options']['subscription_plan_id'] ) ) );
    } else { throw new RuntimeException( 'Unexpected Square request: ' . $url ); }
    return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $response ) );
}, 10, 3 );
foreach ( array( array('invalid','MONTHLY','25.00'), array('sandbox','DAILY','25.00'), array('sandbox','MONTHLY','0.50'), array('sandbox','MONTHLY',array('25')), array('sandbox',array('MONTHLY'),'25.00') ) as $invalid ) { creation_check( is_wp_error( ICPA_Recurring::create_plan( ...$invalid ) ), 'Invalid settings rejected before catalog mutations' ); }
creation_check( 0 === count( $requests ), 'Invalid requests do not touch Square' );
creation_check( true === ICPA_Recurring::create_plan( 'sandbox', 'MONTHLY', '25.00' ), 'Administrator creates recurring plan and checkout from WordPress' );
creation_check( 2 === $post_count && 1 === $link_count, 'Creates one parent and one variation, then subscription checkout' );
$parent = $requests[0]['body']; $variation = $requests[1]['body'];
creation_check( 'SUBSCRIPTION_PLAN' === $parent['object']['type'] && 'SUBSCRIPTION_PLAN_VARIATION' === $variation['object']['type'], 'Catalog objects have correct Square types' );
creation_check( $parent['idempotency_key'] !== $variation['idempotency_key'] && strlen( $parent['idempotency_key'] ) === 64, 'Catalog stages have independent stable idempotency keys' );
$phase = $variation['object']['subscription_plan_variation_data']['phases'][0];
creation_check( 'MONTHLY' === $phase['cadence'] && 0 === $phase['ordinal'] && ! isset( $phase['periods'] ), 'Single indefinite phase without trials' );
creation_check( 'STATIC' === $phase['pricing']['type'] && array('amount'=>2500,'currency'=>'AUD') === $phase['pricing']['price_money'], 'Uses exact configured price and site currency' );
creation_check( array( 'created-sandbox-location' ) === $variation['object']['present_at_location_ids'], 'New plan restricted to selected Square location' );
$link = ICPA_Recurring::options()['links']['sandbox']['MONTHLY'];
creation_check( 2500 === $link['amount'] && 'https://sandbox.square.link/u/created-fixture' === $link['url'], 'Only verified recurring checkout is configured' );
creation_check( empty( ICPA_Recurring::options()['enabled'] ), 'Creating a plan does not silently enable recurring public giving' );
creation_check( true === ICPA_Recurring::create_plan( 'sandbox', 'MONTHLY', '25.00' ) && 2 === $post_count && 1 === $link_count, 'Duplicate submission reuses existing plan and checkout' );
$old_id = $link['id'];
creation_check( true === ICPA_Recurring::create_plan( 'sandbox', 'MONTHLY', '30.00' ), 'New amount creates a separate recurring option' );
creation_check( 2500 === $objects['sandbox'][$old_id]['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['amount'], 'Existing subscribers plan is never repriced' );
$fail_link = true; $prior_posts = $post_count;
creation_check( is_wp_error( ICPA_Recurring::create_plan( 'sandbox', 'WEEKLY', '10.00' ) ), 'Failed checkout creation does not report success' );
creation_check( ! isset( ICPA_Recurring::options()['links']['sandbox']['WEEKLY'] ), 'Incomplete option is not offered to donors' );
$staged_posts = $post_count; $fail_link = false;
creation_check( true === ICPA_Recurring::create_plan( 'sandbox', 'WEEKLY', '10.00' ) && $staged_posts === $post_count && $prior_posts + 2 === $post_count, 'Retry resumes saved catalog stages without duplicate plans' );
$bad_amount = true;
creation_check( is_wp_error( ICPA_Recurring::create_plan( 'sandbox', 'QUARTERLY', '10.00' ) ), 'Wrong provider price is rejected before publication' );
creation_check( ! isset( ICPA_Recurring::options()['links']['sandbox']['QUARTERLY'] ), 'Wrong-price option remains unavailable' );
$bad_amount = false;
creation_check( true === ICPA_Recurring::create_plan( 'production', 'MONTHLY', '25.00' ), 'Production creates its own plan with explicit environment' );
$production_link = ICPA_Recurring::options()['links']['production']['MONTHLY'];
creation_check( $production_link['id'] !== $old_id && 'https://square.link/u/created-fixture' === $production_link['url'], 'Production objects and checkout isolated from Sandbox' );
ICPA_Square_Settings::save( array( 'sandbox' => array( 'location_id' => 'created-other-location' ) ) ); $prior_posts = $post_count;
creation_check( true === ICPA_Recurring::create_plan( 'sandbox', 'MONTHLY', '25.00' ) && $prior_posts + 2 === $post_count, 'Location changes use a new creation identity' );
$current = ICPA_Recurring::options()['links']['sandbox']['MONTHLY'];
$objects['sandbox'][$current['id']]['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['amount'] = 2600;
creation_check( is_wp_error( ICPA_Recurring::create_plan( 'sandbox', 'MONTHLY', '25.00' ) ), 'Cached plan must still match administrator requested price' );
creation_check( 2500 === ICPA_Recurring::options()['links']['sandbox']['MONTHLY']['amount'], 'Changed Square price does not replace configured donation amount' );
$block_save = function ( $new, $old ) { return $old; };
add_filter( 'pre_update_option_' . ICPA_Recurring::OPTION, $block_save, 10, 2 );
creation_check( is_wp_error( ICPA_Recurring::create_plan( 'production', 'ANNUAL', '120.00' ) ) && ! isset( ICPA_Recurring::options()['links']['production']['ANNUAL'] ), 'Failed local option save cannot report a published checkout' );
remove_filter( 'pre_update_option_' . ICPA_Recurring::OPTION, $block_save, 10 ); $prior_posts = $post_count;
creation_check( true === ICPA_Recurring::create_plan( 'production', 'ANNUAL', '120.00' ) && $prior_posts === $post_count, 'Retry after storage failure reuses saved catalog objects' );
wp_set_current_user( 0 ); $before = count( $requests );
creation_check( is_wp_error( ICPA_Recurring::create_plan( 'sandbox', 'MONTHLY', '25.00' ) ) && count( $requests ) === $before, 'Guest cannot create catalog objects' );
wp_set_current_user( $admin );
class CreationDenied extends RuntimeException {}
add_filter( 'wp_die_handler', function () { return function () { throw new CreationDenied(); }; } );
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); $_REQUEST = array();
$denied = false; ob_start(); try { ICPA_Recurring::create_handler(); } catch ( CreationDenied $e ) { $denied = true; } ob_end_clean();
creation_check( $denied && count( $requests ) === $before, 'GET cannot create subscription plans' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( '_wpnonce' => 'forged' ); $_REQUEST = $_POST;
$denied = false; ob_start(); try { ICPA_Recurring::create_handler(); } catch ( CreationDenied $e ) { $denied = true; } ob_end_clean();
creation_check( $denied && count( $requests ) === $before, 'Forged admin nonce cannot create plans' );
ob_start(); ICPA_Recurring::page(); $html = ob_get_clean();
creation_check( 2 === substr_count( $html, 'class="icpa-create-recurring-plan"' ) && false !== strpos( $html, 'Create Production plan &amp; checkout' ), 'Backend has separate creation forms with explicit Production action' );
creation_check( false !== strpos( $html, 'Existing subscriptions are unchanged' ), 'Backend explains existing subscription behavior' );
update_option( ICPA_Platform::OPTION, $old_site );
if ( false === $old_profiles ) delete_option( ICPA_Square_Settings::OPTION ); else update_option( ICPA_Square_Settings::OPTION, $old_profiles );
delete_option( ICPA_Recurring::OPTION ); delete_option( ICPA_Recurring::PLANS );
echo 'PASS: ' . $checks . " WordPress recurring-plan creation and retry checks (Square mocked)\n";
