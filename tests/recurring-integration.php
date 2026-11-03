<?php
/** Disposable WordPress database only. Every Square response is mocked. */
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
activate_plugin( 'icpa-fundraising/icpa-fundraising.php' );
$checks = 0;
function recurring_check( $condition, $message ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
delete_option( ICPA_Recurring::OPTION ); delete_option( ICPA_Recurring::PLANS );
update_option( ICPA_Platform::OPTION, array( 'currency' => 'AUD', 'mode' => 'sandbox', 'general_enabled' => 1, 'campaigns_enabled' => 1 ) );
ICPA_Square_Settings::save( array( 'sandbox' => array( 'location_id' => 'sandbox-location', 'access_token' => 'sandbox-recurring-fixture-token', 'signature_key' => 'sandbox-recurring-fixture-key' ), 'production' => array( 'location_id' => 'production-location', 'access_token' => 'production-recurring-fixture-token', 'signature_key' => 'production-recurring-fixture-key' ) ) );
$monthly = array( 'type' => 'SUBSCRIPTION_PLAN_VARIATION', 'id' => 'monthly-plan', 'version' => 1, 'present_at_all_locations' => true, 'subscription_plan_variation_data' => array( 'name' => 'Monthly generosity', 'phases' => array( array( 'cadence' => 'MONTHLY', 'pricing' => array( 'type' => 'STATIC', 'price_money' => array( 'amount' => 2500, 'currency' => 'AUD' ) ) ) ) ) );
$annual = $monthly; $annual['id'] = 'annual-plan'; $annual['subscription_plan_variation_data']['name'] = 'Yearly generosity'; $annual['subscription_plan_variation_data']['phases'][0]['cadence'] = 'ANNUAL'; $annual['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['amount'] = 30000;
$objects = array( 'monthly-plan' => $monthly, 'annual-plan' => $annual );
recurring_check( ! is_wp_error( ICPA_Recurring::parse_plan( $monthly, 'sandbox' ) ), 'Static monthly plan is supported' );
$invalid = $monthly; $invalid['subscription_plan_variation_data']['phases'][] = $invalid['subscription_plan_variation_data']['phases'][0];
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Multi-phase and trial plans are rejected' );
$invalid = $monthly; $invalid['subscription_plan_variation_data']['phases'][0]['periods'] = 12;
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Finite phases cannot be labelled until cancelled' );
$invalid = $monthly; $invalid['subscription_plan_variation_data']['can_prorate'] = true;
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Prorated plans are rejected' );
$invalid = $monthly; $invalid['subscription_plan_variation_data']['phases'][0]['pricing']['type'] = 'RELATIVE';
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Relative pricing is rejected' );
$invalid = $monthly; $invalid['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['currency'] = 'USD';
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Currency mismatches are rejected' );
$invalid = $monthly; $invalid['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['amount'] = 0;
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Free or out-of-bounds plan prices are rejected' );
$invalid = $monthly; $invalid['present_at_all_locations'] = false;
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Unavailable plan locations are rejected' );
$invalid = $monthly; $invalid['is_deleted'] = true;
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Deleted plans are rejected' );
$invalid = $monthly; $invalid['subscription_plan_variation_data']['phases'][0]['cadence'] = array();
recurring_check( is_wp_error( ICPA_Recurring::parse_plan( $invalid, 'sandbox' ) ), 'Malformed provider data fails closed' );

$calls = array(); $bad_link = false; $wrong_confirmation = false;
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$objects, &$calls, &$bad_link, &$wrong_confirmation ) {
    if ( 0 !== strpos( $url, 'https://connect.squareupsandbox.com/' ) && 0 !== strpos( $url, 'https://connect.squareup.com/' ) ) { return $preempt; }
    $mode = false !== strpos( $url, 'squareupsandbox.com' ) ? 'sandbox' : 'production';
    $path = wp_parse_url( $url, PHP_URL_PATH ); $calls[] = $mode . ':' . $path;
    if ( '/v2/catalog/search' === $path ) {
        $body = json_decode( $args['body'], true );
        recurring_check( array( 'SUBSCRIPTION_PLAN_VARIATION' ) === $body['object_types'], 'Catalog requests subscription plan variations' );
        $response = array( 'objects' => array_values( $objects ) );
    } elseif ( 0 === strpos( $path, '/v2/catalog/object/' ) ) {
        $response = array( 'object' => $objects[basename( $path )] );
    } elseif ( '/v2/online-checkout/payment-links' === $path ) {
        $body = json_decode( $args['body'], true );
        recurring_check( isset( $body['quick_pay'], $body['checkout_options']['subscription_plan_id'] ), 'Square receives a hosted subscription checkout, not a one-time order' );
        $id = $body['checkout_options']['subscription_plan_id'];
        recurring_check( $objects[$id]['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['amount'] === $body['quick_pay']['price_money']['amount'], 'Subscription checkout uses the validated fixed plan price' );
        recurring_check( $mode . '-location' === $body['quick_pay']['location_id'], 'Subscription checkout uses its own Square environment location' );
        $response = array( 'payment_link' => array( 'url' => $bad_link ? 'https://evil.example/pay' : 'https://' . ( 'sandbox' === $mode ? 'sandbox.' : '' ) . 'square.link/u/' . $id, 'checkout_options' => array( 'subscription_plan_id' => $wrong_confirmation ? 'wrong-plan' : $id ) ) );
    } else { throw new RuntimeException( 'Unexpected Square request in subscription test' ); }
    return array( 'headers' => array(), 'body' => wp_json_encode( $response ), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
}, 10, 3 );
recurring_check( true === ICPA_Recurring::refresh( 'sandbox' ), 'Admin can load eligible Square plans' );
recurring_check( count( get_option( ICPA_Recurring::PLANS )['sandbox']['plans'] ) === 2, 'Catalog stores eligible plans for selection' );
recurring_check( is_wp_error( ICPA_Recurring::save_plan( 'sandbox', 'ANNUAL', 'monthly-plan' ) ), 'A monthly plan cannot be offered as yearly' );
recurring_check( true === ICPA_Recurring::save_plan( 'sandbox', 'MONTHLY', 'monthly-plan' ), 'Admin can validate and create monthly checkout' );
$before = count( $calls );
recurring_check( true === ICPA_Recurring::save_plan( 'sandbox', 'MONTHLY', 'monthly-plan' ) && count( $calls ) === $before + 1, 'Saving an unchanged plan revalidates without creating another link' );
recurring_check( true === ICPA_Recurring::save_plan( 'sandbox', 'ANNUAL', 'annual-plan' ), 'Admin can configure a separate yearly option' );
recurring_check( true === ICPA_Recurring::save_settings( array( 'enabled' => 1, 'one_time' => 1, 'cadences' => array( 'MONTHLY', 'ANNUAL', 'unsupported' ) ) ), 'Admin configures offered intervals and one-time availability' );
recurring_check( count( ICPA_Recurring::available() ) === 2, 'Only enabled and configured intervals are offered' );
wp_set_current_user( 0 );
global $wpdb; $before_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ICPA_Platform::table() );
recurring_check( 'https://sandbox.square.link/u/monthly-plan' === ICPA_Recurring::checkout( 'MONTHLY', ICPA_Recurring::quote( ICPA_Recurring::available()['MONTHLY'] ) ), 'Guest recurring checkout is revalidated and routed to Square' );
recurring_check( $before_rows === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ICPA_Platform::table() ), 'Starting a subscription does not manufacture a paid or pending one-time donation' );
recurring_check( is_wp_error( ICPA_Recurring::checkout( 'WEEKLY' ) ), 'An unconfigured interval cannot be submitted manually' );
recurring_check( is_wp_error( ICPA_Recurring::checkout( array( 'MONTHLY' ) ) ), 'Malformed interval input is rejected' );
$form = ICPA_Views::general_form();
recurring_check( false !== strpos( $form, 'value="MONTHLY"' ) && false !== strpos( $form, 'repeating until cancelled' ), 'General giving shows recurring amount, interval and cancellation terms' );
recurring_check( false !== strpos( $form, 'icpa-donation-form' ) && false !== strpos( $form, 'icpa-recurring-form' ), 'One-time and recurring giving coexist' );
recurring_check( is_wp_error( ICPA_Recurring::checkout( 'MONTHLY', 'tampered-price-quote' ) ), 'Client cannot substitute a stale or forged recurring quote' );
$objects['monthly-plan']['subscription_plan_variation_data']['phases'][0]['pricing']['price_money']['amount'] = 5000; $objects['monthly-plan']['version'] = 2;
recurring_check( is_wp_error( ICPA_Recurring::checkout( 'MONTHLY', ICPA_Recurring::quote( ICPA_Recurring::available()['MONTHLY'] ) ) ), 'Changed Square prices cannot silently replace the displayed recurring price' );
$objects['monthly-plan'] = $monthly;
$subscriber = wp_insert_user( array( 'user_login' => 'recurring_member_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) ); wp_set_current_user( $subscriber );
recurring_check( is_wp_error( ICPA_Recurring::save_settings( array() ) ) && is_wp_error( ICPA_Recurring::save_plan( 'sandbox', 'MONTHLY', 'monthly-plan' ) ) && is_wp_error( ICPA_Recurring::refresh( 'sandbox' ) ), 'Subscribers cannot change or create recurring options' );
ob_start(); ICPA_Recurring::page(); $private = ob_get_clean(); recurring_check( '' === $private, 'Recurring settings are administrator-only' );
wp_set_current_user( $admins[0]->ID );
ICPA_Recurring::save_settings( array( 'enabled' => 1, 'cadences' => array( 'MONTHLY' ) ) );
recurring_check( false === strpos( ICPA_Views::general_form(), 'class="icpa-donation-form"' ), 'One-time general giving can be disabled independently' );
recurring_check( is_wp_error( ICPA_Square::create_checkout( 0, '25', wp_generate_uuid4() ) ), 'Disabling one-time giving is enforced server-side' );
$campaign = wp_insert_post( array( 'post_type' => ICPA_Platform::TYPE, 'post_title' => 'One-time campaign', 'post_content' => 'Campaign test', 'post_status' => 'publish' ) ); update_post_meta( $campaign, '_icpa_goal', 500000 );
recurring_check( false !== strpos( ICPA_Views::campaign( $campaign ), 'icpa-donation-form' ) && false === strpos( ICPA_Views::campaign( $campaign ), 'icpa-recurring-form' ), 'Campaign giving remains one-time and separate from general subscriptions' );
recurring_check( is_wp_error( ICPA_Recurring::save_settings( array() ) ), 'Settings cannot disable every giving option without a recurring choice' );
$o = ICPA_Platform::options(); $o['mode'] = 'production'; update_option( ICPA_Platform::OPTION, $o );
recurring_check( array() === ICPA_Recurring::available(), 'Sandbox plans never appear in production' );
$bad_link = true;
recurring_check( is_wp_error( ICPA_Recurring::save_plan( 'production', 'MONTHLY', 'monthly-plan' ) ), 'Unsafe provider checkout URLs are rejected' );
$bad_link = false; $wrong_confirmation = true;
recurring_check( is_wp_error( ICPA_Recurring::save_plan( 'production', 'MONTHLY', 'monthly-plan' ) ), 'A returned one-time or mismatched plan link cannot be published as a subscription' );
$wrong_confirmation = false;
recurring_check( true === ICPA_Recurring::save_plan( 'production', 'MONTHLY', 'monthly-plan' ), 'Production has a separately validated checkout' );
recurring_check( 'https://square.link/u/monthly-plan' === ICPA_Recurring::checkout( 'MONTHLY', ICPA_Recurring::quote( ICPA_Recurring::available()['MONTHLY'] ) ), 'Production recurring checkout uses the production link' );
$profile = ICPA_Square_Settings::profile( 'production' ); ICPA_Square_Settings::save( array( 'production' => array( 'location_id' => 'different-location' ) ) );
recurring_check( array() === ICPA_Recurring::available(), 'Changing Square account/location invalidates prior recurring links' );
ob_start(); ICPA_Recurring::page(); $admin_html = ob_get_clean();
recurring_check( false !== strpos( $admin_html, 'name="cadences[]"' ) && false !== strpos( $admin_html, 'name="plan_id"' ) && false !== strpos( $admin_html, '_wpnonce' ), 'Settings provide interval selection, environment-specific plans and nonce protection' );
delete_option( ICPA_Recurring::OPTION ); delete_option( ICPA_Recurring::PLANS ); delete_option( ICPA_Square_Settings::OPTION );
echo "PASS: {$checks} recurring-settings and hosted-subscription checks (all Square HTTP mocked)\n";
