<?php
defined( 'ABSPATH' ) || exit;

final class ICPA_Square {
    const API_VERSION = '2025-01-23';

    public static function init() {
        add_action( 'admin_post_icpa_checkout', array( __CLASS__, 'checkout' ) );
        add_action( 'admin_post_nopriv_icpa_checkout', array( __CLASS__, 'checkout' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function secret( $name ) {
        $value = defined( $name ) ? constant( $name ) : getenv( $name );
        return is_string( $value ) ? $value : '';
    }

    public static function ready( $mode = null ) {
        $profile = ICPA_Square_Settings::profile( $mode );
        return '' !== $profile['access_token'] && '' !== $profile['signature_key'] && '' !== $profile['location_id'];
    }

    public static function webhook_url( $mode ) {
        return rest_url( 'icpa/v1/square/webhook/' . ICPA_Square_Settings::mode( $mode ) );
    }

    public static function api( $method, $path, $body = null, $mode = null ) {
        $mode = ICPA_Square_Settings::mode( $mode );
        $profile = ICPA_Square_Settings::profile( $mode );
        $host = 'production' === $mode ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
        $args = array( 'method' => $method, 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 2097152, 'headers' => array( 'Authorization' => 'Bearer ' . $profile['access_token'], 'Square-Version' => self::API_VERSION, 'Content-Type' => 'application/json' ) );
        if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
        $response = wp_remote_request( $host . $path, $args );
        if ( is_wp_error( $response ) ) { return new WP_Error( 'square_unavailable', 'Square is temporarily unavailable. Please try again.' ); }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            return new WP_Error( 'square_response', 'Square could not complete this request. Please contact ICPA if the problem continues.' );
        }
        return $data;
    }

    public static function valid_checkout_url( $url ) {
        if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f]|%0[0-9a-f]|%1[0-9a-f]|%7f/i', $url ) ) { return false; }
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) || empty( $p['host'] ) || ! isset( $p['scheme'] ) || 'https' !== strtolower( $p['scheme'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== $p['port'] ) ) { return false; }
        return in_array( strtolower( $p['host'] ), array( 'square.link', 'sandbox.square.link', 'checkout.square.site', 'checkout.squareupsandbox.com' ), true );
    }

    /** One local row and one Square checkout per submission key, including network retries. */
    public static function create_checkout( $campaign, $amount_string, $key ) {
        global $wpdb;
        $campaign = ICPA_Security::id( $campaign );
        if ( null === $campaign ) { return new WP_Error( 'invalid_campaign', 'Invalid campaign identifier.' ); }
        if ( 0 === (int) $campaign && ! ICPA_Recurring::options()['one_time'] ) { return new WP_Error( 'one_time_disabled', 'One-time general donations are currently disabled. Choose an available recurring option.' ); }
        if ( ! self::ready() ) { return new WP_Error( 'not_configured', 'Donations are not available yet. Please check back soon.' ); }
        $amount = ICPA_Platform::cents( $amount_string );
        if ( ( 0 === (int) $campaign ? ! ICPA_Platform::options()['general_enabled'] : ! ICPA_Platform::open( $campaign ) ) || ! $amount || $amount < ICPA_Platform::options()['minimum_amount'] || $amount > ICPA_Platform::options()['maximum_amount'] || ! is_string( $key ) || ! preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', $key ) ) {
            return new WP_Error( 'invalid_checkout', 'Choose an available donation option and an amount within the configured donation limits.' );
        }
        $key = strtolower( $key );
        $table = ICPA_Platform::table();
        $o = ICPA_Platform::options();
        $o['location_id'] = ICPA_Square_Settings::profile()['location_id'];
        $user = get_current_user_id();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE checkout_key=%s", $key ), ARRAY_A );
        if ( ! $row ) {
            $inserted = $wpdb->insert( $table, array( 'campaign_id' => $campaign, 'user_id' => $user, 'amount' => $amount, 'currency' => $o['currency'], 'mode' => $o['mode'], 'checkout_key' => $key, 'status' => 'pending', 'created_at' => current_time( 'mysql', true ) ) );
            if ( ! $inserted ) {
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE checkout_key=%s", $key ), ARRAY_A );
            } else {
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $wpdb->insert_id ), ARRAY_A );
            }
        }
        if ( ! $row || (int) $row['user_id'] !== $user || (int) $row['campaign_id'] !== (int) $campaign || (int) $row['amount'] !== $amount || $row['mode'] !== $o['mode'] || $row['currency'] !== $o['currency'] ) {
            return new WP_Error( 'checkout_conflict', 'This submission has changed. Reload the campaign and try again.' );
        }
        if ( ! empty( $row['checkout_url'] ) && self::valid_checkout_url( $row['checkout_url'] ) ) { return $row['checkout_url']; }
        $response = self::api( 'POST', '/v2/online-checkout/payment-links', array(
            'idempotency_key' => $key,
            'payment_note' => 'ICPA donation ' . $key,
            'order' => array( 'location_id' => $o['location_id'], 'reference_id' => 'icpa-' . $row['id'], 'line_items' => array( array( 'name' => wp_html_excerpt( 'Donation: ' . ICPA_Platform::donation_label( $campaign ), 200, '' ), 'quantity' => '1', 'base_price_money' => array( 'amount' => $amount, 'currency' => $o['currency'] ) ) ) ),
            'checkout_options' => array( 'allow_tipping' => false, 'redirect_url' => add_query_arg( 'icpa_return', '1', $campaign ? get_permalink( $campaign ) : ( $o['general_page'] ? get_permalink( $o['general_page'] ) : home_url( '/' ) ) ) ),
        ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $link = isset( $response['payment_link'] ) && is_array( $response['payment_link'] ) ? $response['payment_link'] : array();
        if ( empty( $link['order_id'] ) || ! is_string( $link['order_id'] ) || strlen( $link['order_id'] ) > 192 || empty( $link['url'] ) || ! self::valid_checkout_url( $link['url'] ) ) {
            return new WP_Error( 'invalid_square_link', 'Square did not return a valid checkout. Please try again.' );
        }
        $saved = $wpdb->update( $table, array( 'order_id' => $link['order_id'], 'checkout_url' => $link['url'] ), array( 'id' => $row['id'] ) );
        if ( false === $saved ) { return new WP_Error( 'checkout_storage', 'Checkout could not be saved. Please retry your submission.' ); }
        return $link['url'];
    }

    public static function checkout() {
        ICPA_Security::require_post( 16384 );
        check_admin_referer( 'icpa_checkout' );
        if ( isset( $_POST['environment'] ) && wp_unslash( $_POST['environment'] ) !== ICPA_Platform::options()['mode'] ) { wp_die( 'The payment environment has changed. Reload the donation page before continuing.', 'Reload donation page', array( 'response' => 400, 'back_link' => true ) ); }
        if ( ! ICPA_Security::rate_limit( 'checkout' ) ) { wp_die( 'Please wait a minute before starting another checkout.', 'Try again shortly', array( 'response' => 429, 'back_link' => true ) ); }
        $campaign = ICPA_Security::id( isset( $_POST['campaign_id'] ) ? wp_unslash( $_POST['campaign_id'] ) : 0 );
        if ( null === $campaign ) { wp_die( 'Invalid campaign identifier.', 'Invalid request', array( 'response' => 400 ) ); }
        $amount = isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : '';
        $key = isset( $_POST['checkout_key'] ) ? wp_unslash( $_POST['checkout_key'] ) : '';
        $giving_option = isset( $_POST['giving_option'] ) ? wp_unslash( $_POST['giving_option'] ) : 'once';
        if ( 'once' === $giving_option ) { $url = self::create_checkout( $campaign, $amount, $key ); }
        else { $url = $campaign ? new WP_Error( 'recurring_scope', 'Recurring checkout is available for general giving only.' ) : ICPA_Recurring::checkout( $giving_option, isset( $_POST['recurring_quote'] ) ? wp_unslash( $_POST['recurring_quote'] ) : '' ); }
        if ( is_wp_error( $url ) ) { wp_die( esc_html( $url->get_error_message() ), 'Checkout unavailable', array( 'response' => 400, 'back_link' => true ) ); }
        // Destination has been restricted to Square-controlled checkout hosts above.
        wp_redirect( $url, 303, 'ICPA Fundraising' );
        exit;
    }

    public static function routes() {
        register_rest_route( 'icpa/v1', '/square/webhook', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'webhook' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'icpa/v1', '/square/webhook/(?P<environment>sandbox|production)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'webhook' ), 'permission_callback' => '__return_true' ) );
    }

    public static function reconcile( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ICPA_Platform::table() . ' WHERE id=%d', $id ), ARRAY_A );
        if ( ! $row || ! $row['order_id'] || ! in_array( $row['mode'], array( 'sandbox', 'production' ), true ) || ! self::ready( $row['mode'] ) ) { return new WP_Error( 'no_payment', 'No payment to refresh in this environment.' ); }
        $payment_id = $row['payment_id'];
        if ( ! $payment_id ) {
            $order = self::api( 'GET', '/v2/orders/' . rawurlencode( $row['order_id'] ), null, $row['mode'] );
            if ( is_wp_error( $order ) ) { return $order; }
            foreach ( isset( $order['order']['tenders'] ) && is_array( $order['order']['tenders'] ) ? $order['order']['tenders'] : array() as $tender ) {
                if ( ! empty( $tender['payment_id'] ) && is_string( $tender['payment_id'] ) ) { $payment_id = $tender['payment_id']; break; }
            }
        }
        if ( ! $payment_id ) { return new WP_Error( 'not_paid', 'Square has not recorded a payment for this checkout.' ); }
        $result = self::api( 'GET', '/v2/payments/' . rawurlencode( $payment_id ), null, $row['mode'] );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( empty( $result['payment'] ) || ! is_array( $result['payment'] ) || ! isset( $result['payment']['id'], $result['payment']['order_id'] ) || $result['payment']['id'] !== $payment_id || $result['payment']['order_id'] !== $row['order_id'] ) { return new WP_Error( 'payment_missing', 'Square did not return the expected payment.' ); }
        return self::apply_payment( $result['payment'], $row['mode'] );
    }

    public static function signature_valid( $body, $signature, $mode = null, $url = null ) {
        $key = ICPA_Square_Settings::profile( $mode )['signature_key'];
        if ( '' === $key || ! is_string( $signature ) || '' === $signature ) { return false; }
        $expected = base64_encode( hash_hmac( 'sha256', ( $url ?: rest_url( 'icpa/v1/square/webhook' ) ) . $body, $key, true ) );
        return hash_equals( $expected, $signature );
    }

    public static function webhook( WP_REST_Request $request ) {
        $body = $request->get_body();
        if ( ! preg_match( '~\A/icpa/v1/square/webhook(?:/(sandbox|production))?/?\z~', $request->get_route(), $route ) ) { return new WP_Error( 'invalid_route', 'Invalid webhook route.', array( 'status' => 404 ) ); }
        $environment = isset( $route[1] ) && '' !== $route[1] ? $route[1] : null;
        $mode = ICPA_Square_Settings::mode( $environment );
        $url = null === $environment ? rest_url( 'icpa/v1/square/webhook' ) : self::webhook_url( $mode );
        if ( strlen( $body ) > 1048576 || ! self::signature_valid( $body, $request->get_header( 'x-square-hmacsha256-signature' ), $mode, $url ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid webhook signature.', array( 'status' => 403 ) );
        }
        $event = json_decode( $body, true );
        if ( ! is_array( $event ) || empty( $event['type'] ) || ! is_string( $event['type'] ) ) { return new WP_Error( 'invalid_event', 'Invalid event.', array( 'status' => 400 ) ); }
        $object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
        $id = '';
        if ( in_array( $event['type'], array( 'payment.created', 'payment.updated' ), true ) ) {
            $id = isset( $object['payment']['id'] ) ? $object['payment']['id'] : '';
        } elseif ( in_array( $event['type'], array( 'refund.created', 'refund.updated' ), true ) ) {
            $id = isset( $object['refund']['payment_id'] ) ? $object['refund']['payment_id'] : '';
        } else { return new WP_REST_Response( array( 'received' => true ), 200 ); }
        if ( ! is_string( $id ) || '' === $id || strlen( $id ) > 192 ) { return new WP_Error( 'invalid_payment', 'Missing payment ID.', array( 'status' => 400 ) ); }
        // Re-fetch authoritative payment state rather than trusting event status or event order.
        $result = self::api( 'GET', '/v2/payments/' . rawurlencode( $id ), null, $mode );
        if ( is_wp_error( $result ) || empty( $result['payment'] ) || ! is_array( $result['payment'] ) || ! isset( $result['payment']['id'] ) || $id !== $result['payment']['id'] ) {
            return new WP_Error( 'payment_lookup', 'Unable to verify payment. Retry later.', array( 'status' => 503 ) );
        }
        $saved = self::apply_payment( $result['payment'], $mode );
        if ( ! is_wp_error( $saved ) ) {
            $health = get_option( 'icpa_square_webhook_health', array() );
            $health[$mode] = current_time( 'mysql', true );
            update_option( 'icpa_square_webhook_health', $health, false );
        }
        return is_wp_error( $saved ) ? $saved : new WP_REST_Response( array( 'received' => true ), 200 );
    }

    /** SQL timestamp guard makes duplicate and out-of-order notifications safe. */
    public static function apply_payment( $payment, $mode = null ) {
        global $wpdb;
        $table = ICPA_Platform::table();
        if ( empty( $payment['order_id'] ) || ! is_string( $payment['order_id'] ) ) { return true; }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_id=%s AND mode=%s", $payment['order_id'], ICPA_Square_Settings::mode( $mode ) ), ARRAY_A );
        $source = null;
        if ( ! $row && isset( $payment['note'] ) && is_string( $payment['note'] ) && preg_match( '/^ICPA donation ([a-f0-9-]{36})$/i', $payment['note'], $match ) ) {
            // Square can create a new order when a hosted link is reused. The
            // server-assigned payment note preserves the campaign/donor reference.
            $source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE checkout_key=%s", $match[1] ), ARRAY_A );
            $row = $source;
        }
        if ( ! $row ) { return true; } // An unrelated Square transaction.
        $o = ICPA_Platform::options();
        $o['mode'] = ICPA_Square_Settings::mode( $mode );
        $o['location_id'] = ICPA_Square_Settings::profile( $o['mode'] )['location_id'];
        $money = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
        if ( empty( $payment['id'] ) || ! is_string( $payment['id'] ) || strlen( $payment['id'] ) > 192 || empty( $payment['location_id'] ) || $payment['location_id'] !== $o['location_id'] || $row['mode'] !== $o['mode'] || ! isset( $money['amount'], $money['currency'] ) || ! is_int( $money['amount'] ) || (int) $row['amount'] !== $money['amount'] || $row['currency'] !== $money['currency'] ) {
            return new WP_Error( 'payment_mismatch', 'Payment does not match the expected donation.', array( 'status' => 409 ) );
        }
        $status_map = array( 'COMPLETED' => 'completed', 'APPROVED' => 'pending', 'PENDING' => 'pending', 'CANCELED' => 'canceled', 'FAILED' => 'failed' );
        if ( ! isset( $payment['status'] ) || ! is_string( $payment['status'] ) || ! isset( $status_map[$payment['status']] ) || empty( $payment['updated_at'] ) || ! is_string( $payment['updated_at'] ) ) {
            return new WP_Error( 'payment_state', 'Missing payment state.', array( 'status' => 400 ) );
        }
        $timestamp = ICPA_Security::timestamp( $payment['updated_at'] );
        if ( null === $timestamp ) { return new WP_Error( 'payment_time', 'Invalid payment time.', array( 'status' => 400 ) ); }
        if ( isset( $payment['refunded_money'] ) && ( ! is_array( $payment['refunded_money'] ) || ! isset( $payment['refunded_money']['amount'], $payment['refunded_money']['currency'] ) ) ) { return new WP_Error( 'refund_mismatch', 'Invalid refund data.', array( 'status' => 409 ) ); }
        $refunded = isset( $payment['refunded_money']['amount'] ) ? $payment['refunded_money']['amount'] : 0;
        if ( ! is_int( $refunded ) || $refunded < 0 || $refunded > (int) $row['amount'] || ( isset( $payment['refunded_money']['currency'] ) && $payment['refunded_money']['currency'] !== $row['currency'] ) ) { return new WP_Error( 'refund_mismatch', 'Invalid refund amount.', array( 'status' => 409 ) ); }
        if ( $source ) {
            if ( 'COMPLETED' !== $payment['status'] ) { return true; }
            $wpdb->insert( $table, array( 'campaign_id' => $source['campaign_id'], 'user_id' => $source['user_id'], 'amount' => $source['amount'], 'currency' => $source['currency'], 'mode' => $source['mode'], 'checkout_key' => wp_generate_uuid4(), 'order_id' => $payment['order_id'], 'status' => 'pending', 'created_at' => current_time( 'mysql', true ) ) );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_id=%s AND mode=%s", $payment['order_id'], ICPA_Square_Settings::mode( $mode ) ), ARRAY_A );
            if ( ! $row ) { return new WP_Error( 'payment_storage', 'Payment could not be saved. Retry later.', array( 'status' => 503 ) ); }
        }
        $status = $status_map[$payment['status']];
        // A failed card attempt may be followed by a new payment ID for the same order.
        // Once completed, only that payment's completed/refund state may update the row.
        $saved = $wpdb->query( $wpdb->prepare( "UPDATE $table SET payment_id=%s,status=%s,refunded=%d,updated_at=%s WHERE id=%d AND (updated_at<=%s OR (payment_id<>%s AND status<>'completed' AND %s='completed')) AND (payment_id IS NULL OR payment_id=%s OR status<>'completed') AND (status<>'completed' OR %s='completed')", $payment['id'], $status, $refunded, $timestamp, $row['id'], $timestamp, $payment['id'], $status, $payment['id'], $status ) );
        if ( false === $saved ) { return new WP_Error( 'payment_storage', 'Payment could not be saved. Retry later.', array( 'status' => 503 ) ); }
        if ( $source ) {
            // Hide the unpaid template checkout from the donor's history once
            // its actual paid order has been recorded. Keep it for reconciliation.
            $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='linked' WHERE id=%d AND status IN ('pending','failed','canceled')", $source['id'] ) );
        }
        return true;
    }
}
