<?php
// Run after WordPress is loaded; use only a disposable development database.
if ( ! defined( 'ABSPATH' ) ) {
    require '/var/www/html/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
$result = activate_plugin( 'icpa-square-donations/icpa-square-donations.php' );
if ( is_wp_error( $result ) ) {
    throw new RuntimeException( $result->get_error_message() );
}
$count = 0;
function icpa_check( $condition, $message ) {
    global $count;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    ++$count;
}
$previous = get_option( ICPA_Square_Donations::OPTION, null );
try {
    foreach ( array( 'https://square.link/u/example', 'https://checkout.square.site/pay/example', 'https://charity.square.site/', 'https://squareup.com/checkout/example' ) as $url ) {
        icpa_check( ICPA_Square_Donations::valid_url( $url ), 'Accept Square link: ' . $url );
    }
    foreach ( array( 'http://square.link/u/x', 'javascript:alert(1)', 'https://square.link.evil.example/u/x', 'https://evil.example/?square.link', 'https://square.link@evil.example/', 'https://evil@square.link/u/x', 'https://square.link:8080/u/x', '//square.link/u/x', 'https://a.b.square.site/', '', array() ) as $url ) {
        icpa_check( ! ICPA_Square_Donations::valid_url( $url ), 'Reject unsafe or unsupported link' );
    }
    delete_option( ICPA_Square_Donations::OPTION );
    icpa_check( '' === do_shortcode( '[icpa_donate]' ), 'Unconfigured shortcode should be hidden' );
    $clean = ICPA_Square_Donations::sanitize( array( 'url' => 'https://square.link/u/example', 'label' => '<b>Give</b>', 'color' => '#ffffff' ) );
    icpa_check( 'Give' === $clean['label'], 'Settings strip markup' );
    update_option( ICPA_Square_Donations::OPTION, $clean );
    $bad = ICPA_Square_Donations::sanitize( array( 'url' => 'https://evil.example/', 'label' => '', 'color' => 'red;position:fixed' ) );
    icpa_check( $bad['url'] === $clean['url'], 'Invalid URL preserves saved link' );
    icpa_check( '#146044' === $bad['color'], 'Invalid colour falls back' );
    icpa_check( ! empty( get_settings_errors( ICPA_Square_Donations::OPTION ) ), 'Invalid URL reports an error' );
    $html = do_shortcode( '[icpa_donate label="<script>alert(1)</script>"]' );
    icpa_check( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ), 'Shortcode label is escaped' );
    icpa_check( false !== strpos( $html, 'href="https://square.link/u/example"' ), 'Button points to configured checkout' );
    icpa_check( false !== strpos( $html, 'color:#000000' ), 'Light background has dark text' );
    $clean['color'] = '#000';
    update_option( ICPA_Square_Donations::OPTION, $clean );
    icpa_check( false !== strpos( do_shortcode( '[icpa_donate]' ), 'color:#ffffff' ), 'Dark background has light text' );
    $clean['url'] = '';
    update_option( ICPA_Square_Donations::OPTION, $clean );
    icpa_check( '' === do_shortcode( '[icpa_donate]' ), 'Cleared link hides button' );
    ICPA_Square_Donations::settings();
    $settings = get_registered_settings();
    icpa_check( isset( $settings[ICPA_Square_Donations::OPTION]['sanitize_callback'] ), 'Settings API sanitization is registered' );
    wp_set_current_user( 0 );
    ob_start();
    ICPA_Square_Donations::page();
    $page = ob_get_clean();
    icpa_check( '' === $page, 'Guests cannot render settings form' );
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
    wp_set_current_user( $admins[0]->ID );
    ob_start();
    ICPA_Square_Donations::page();
    $page = ob_get_clean();
    icpa_check( false !== strpos( $page, '_wpnonce' ) && false !== strpos( $page, 'options.php' ), 'Admin form uses WordPress nonce and options handler' );
    ICPA_Square_Donations::styles();
    icpa_check( wp_style_is( 'icpa-square-donations', 'enqueued' ), 'Front end stylesheet is enqueued' );
    echo "PASS: {$count} integration checks\n";
} finally {
    if ( null === $previous ) {
        delete_option( ICPA_Square_Donations::OPTION );
    } else {
        update_option( ICPA_Square_Donations::OPTION, $previous );
    }
}
