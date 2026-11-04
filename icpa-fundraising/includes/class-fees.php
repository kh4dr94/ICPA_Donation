<?php
defined( 'ABSPATH' ) || exit;

/** Optional donation contribution, estimated from administrator-supplied processing rates. */
final class ICPA_Fees {
    public static function decimal_hundredths( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/\A([0-9]{1,2})(?:\.([0-9]{1,2}))?\z/', $value, $m ) ) { return null; }
        return (int) $m[1] * 100 + (int) str_pad( isset( $m[2] ) ? $m[2] : '', 2, '0' );
    }

    public static function mode() {
        $o = ICPA_Platform::options();
        return in_array( $o['fee_mode'], array( 'absorb', 'pass_on', 'customer_choice' ), true ) ? $o['fee_mode'] : ( $o['fee_enabled'] ? 'customer_choice' : 'absorb' );
    }

    public static function policy( $setting = true ) {
        if ( ! self::enabled() || false === $setting || 'off' === $setting ) { return 0; }
        if ( 'optional' === $setting ) { return 1; }
        return 'pass_on' === $setting || 'pass_on' === self::mode() ? 2 : 1;
    }

    public static function enabled() {
        $o = ICPA_Platform::options();
        return 'absorb' !== self::mode() && ( $o['fee_bps'] > 0 || $o['fee_fixed'] > 0 );
    }

    private static function signature( $campaign, $expires, $allowed ) {
        $o = ICPA_Platform::options();
        return hash_hmac( 'sha256', wp_json_encode( array( $campaign, $expires, $allowed, get_current_user_id(), $o['mode'], $o['currency'], $o['fee_enabled'], self::mode(), $o['fee_bps'], $o['fee_fixed'], ICPA_Square_Settings::profile()['location_id'] ) ), wp_salt( 'auth' ) );
    }

    public static function quote( $campaign, $allowed ) {
        $expires = time() + 7200;
        $allowed = self::policy( $allowed );
        return $expires . '.' . $allowed . '.' . self::signature( (int) $campaign, $expires, $allowed );
    }

    public static function line_items( $campaign, $breakdown ) {
        $items = array( array( 'name' => wp_html_excerpt( 'Donation: ' . ICPA_Platform::donation_label( $campaign ), 200, '' ), 'quantity' => '1', 'base_price_money' => array( 'amount' => $breakdown['donation'], 'currency' => ICPA_Platform::options()['currency'] ) ) );
        if ( $breakdown['fee'] ) { $items[] = array( 'name' => 'Processing-cost contribution (estimated)', 'quantity' => '1', 'base_price_money' => array( 'amount' => $breakdown['fee'], 'currency' => ICPA_Platform::options()['currency'] ) ); }
        return $items;
    }

    public static function calculate( $amount, $campaign, $cover = '0', $quote = '' ) {
        if ( ! in_array( $cover, array( '0', '1', 0, 1 ), true ) ) { return new WP_Error( 'fee_choice', 'Choose whether to add a processing-cost contribution.' ); }
        $policy = 0;
        if ( '' !== $quote ) {
            if ( ! is_string( $quote ) || ! preg_match( '/\A([0-9]{10})\.([012])\.([a-f0-9]{64})\z/', $quote, $match ) || (int) $match[1] < time() || (int) $match[1] > time() + 7500 || ! hash_equals( self::signature( (int) $campaign, (int) $match[1], (int) $match[2] ), $match[3] ) ) { return new WP_Error( 'fee_quote', 'Processing-cost settings changed or expired. Reload the donation form before continuing.' ); }
            $policy = (int) $match[2];
        } elseif ( 'pass_on' === self::mode() && self::enabled() ) { return new WP_Error( 'fee_quote', 'Reload the donation form to review its processing-cost total.' ); }
        if ( 2 === $policy && '1' !== (string) $cover ) { return new WP_Error( 'fee_choice', 'This form includes a processing-cost contribution. Reload to review the total.' ); }
        if ( '1' !== (string) $cover ) { return array( 'donation' => $amount, 'fee' => 0, 'total' => $amount ); }
        if ( ! $policy || ! self::enabled() ) { return new WP_Error( 'fee_choice', 'This form does not accept an additional processing-cost contribution.' ); }
        $o = ICPA_Platform::options();
        if ( PHP_INT_SIZE < 8 ) { return new WP_Error( 'fee_platform', 'Processing contributions require 64-bit PHP.' ); }
        $denominator = 10000 - (int) $o['fee_bps'];
        if ( $denominator < 9000 || $denominator > 10000 || $o['fee_fixed'] < 0 || $o['fee_fixed'] > 1000 ) { return new WP_Error( 'fee_config', 'Processing-cost settings are unavailable.' ); }
        $total = intdiv( ( $amount + (int) $o['fee_fixed'] ) * 10000 + $denominator - 1, $denominator );
        if ( $total > $o['maximum_amount'] ) { return new WP_Error( 'fee_limit', 'The total including your contribution exceeds the donation limit. Reduce the amount or choose donation only.' ); }
        return array( 'donation' => $amount, 'fee' => $total - $amount, 'total' => $total );
    }
}
