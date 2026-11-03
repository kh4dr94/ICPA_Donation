<?php
defined( 'ABSPATH' ) || exit;

final class ICPA_Security {
    const RATE_PREFIX = '_icpa_donation_rl_';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'schedule_cleanup' ) );
        add_action( 'icpa_cleanup_rate_limits', array( __CLASS__, 'cleanup' ) );
    }

    public static function schedule_cleanup() {
        if ( ! wp_next_scheduled( 'icpa_cleanup_rate_limits' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'icpa_cleanup_rate_limits' ); }
    }

    public static function deactivate() { wp_clear_scheduled_hook( 'icpa_cleanup_rate_limits' ); }

    public static function require_post( $maximum = 65536 ) {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            if ( ! headers_sent() ) { header( 'Allow: POST' ); }
            wp_die( 'This action requires a POST request.', 'Method not allowed', array( 'response' => 405 ) );
        }
        $length = isset( $_SERVER['CONTENT_LENGTH'] ) && is_scalar( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ( $length > $maximum ) { wp_die( 'Request data is too large.', 'Invalid request', array( 'response' => 413 ) ); }
        $encoded = wp_json_encode( $_POST );
        if ( false === $encoded || strlen( $encoded ) > $maximum ) { wp_die( 'Request data is too large or invalid.', 'Invalid request', array( 'response' => 413 ) ); }
    }

    /** Strict decimal identifiers; arrays, floats, signs and SQL-like strings are rejected. */
    public static function id( $value ) {
        if ( is_int( $value ) ) { return $value >= 0 ? $value : null; }
        if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{1,18}\z/', $value ) ) { return null; }
        $value = ltrim( $value, '0' ); $value = '' === $value ? '0' : $value;
        $max = (string) PHP_INT_MAX;
        if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) { return null; }
        return (int) $value;
    }

    public static function page( $value ) {
        $id = self::id( $value );
        return null === $id ? 1 : max( 1, min( 1000, $id ) );
    }

    /** DB-atomic fixed window; no plaintext IP or credentials are stored. */
    public static function rate_limit( $scope, $limit = 10, $window = 60, $identity = null ) {
        global $wpdb;
        if ( null === $identity ) { $identity = get_current_user_id() ? 'user:' . get_current_user_id() : 'ip:' . ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown' ); }
        $key = self::RATE_PREFIX . hash_hmac( 'sha256', $scope . '|' . $identity, wp_salt( 'nonce' ) );
        $now = time(); $new = ( $now + $window ) . ':1';
        $written = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=IF(CAST(SUBSTRING_INDEX(option_value,':',1) AS UNSIGNED)<=%d,VALUES(option_value),CONCAT(SUBSTRING_INDEX(option_value,':',1),':',CAST(SUBSTRING_INDEX(option_value,':',-1) AS UNSIGNED)+1))", $key, $new, $now ) );
        if ( false === $written ) { return false; }
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $key ) );
        if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+:([0-9]+)\z/', $value, $match ) ) { return false; }
        return (int) $match[1] <= $limit;
    }

    public static function cleanup() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value,':',1) AS UNSIGNED)<=%d LIMIT 1000", $wpdb->esc_like( self::RATE_PREFIX ) . '%', time() ) );
    }

    public static function safe_story( $content ) {
        $html = wp_kses_post( is_string( $content ) ? $content : '' );
        // Stories are content, not a place to execute third-party shortcodes or dynamic blocks.
        $html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );
        return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $html );
    }

    public static function timestamp( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value ) ) { return null; }
        $normal = preg_replace( '/(\.[0-9]{6})[0-9]+/', '$1', $value );
        try {
            $date = new DateTimeImmutable( $normal );
            $errors = DateTimeImmutable::getLastErrors();
            if ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) { return null; }
            return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' );
        } catch ( Exception $e ) { return null; }
    }
}
