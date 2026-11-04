<?php
defined( 'ABSPATH' ) || exit;

final class ICPA_Square_Settings {
    const OPTION = 'icpa_square_profiles';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_icpa_load_locations', array( __CLASS__, 'locations_handler' ) );
        add_action( 'admin_post_icpa_save_square', array( __CLASS__, 'save_handler' ) );
        add_action( 'admin_post_icpa_test_square', array( __CLASS__, 'test_handler' ) );
    }

    public static function menu() {
        add_menu_page( 'ICPA Donations', 'ICPA Donations', 'manage_options', 'icpa-donations', array( 'ICPA_Platform', 'donations_page' ), 'dashicons-heart' );
        add_submenu_page( 'icpa-donations', 'Donations', 'Donations', 'manage_options', 'icpa-donations', array( 'ICPA_Platform', 'donations_page' ) );
        add_submenu_page( 'icpa-donations', 'Causes', 'Causes', 'manage_options', 'edit-tags.php?taxonomy=icpa_cause&post_type=' . ICPA_Platform::TYPE );
        add_submenu_page( 'icpa-donations', 'Donation settings', 'Settings', 'manage_options', 'icpa-general-settings', array( 'ICPA_Platform', 'settings_page' ) );
        add_submenu_page( 'icpa-donations', 'Square connections', 'Square connections', 'manage_options', 'icpa-square-settings', array( __CLASS__, 'page' ) );
    }

    public static function mode( $mode = null ) {
        $active = ICPA_Platform::options()['mode'];
        return in_array( $mode, array( 'sandbox', 'production' ), true ) ? $mode : ( in_array( $active, array( 'sandbox', 'production' ), true ) ? $active : 'sandbox' );
    }

    public static function locations( $mode ) {
        $all = get_option( 'icpa_square_locations', array() );
        if ( ! is_array( $all ) ) { $all = array(); }
        $profile = self::profile( $mode );
        $fingerprint = hash_hmac( 'sha256', $profile['access_token'], wp_salt( 'auth' ) );
        return isset( $all[$mode]['fingerprint'], $all[$mode]['items'] ) && hash_equals( $fingerprint, $all[$mode]['fingerprint'] ) ? $all[$mode]['items'] : array();
    }

    public static function load_locations( $mode ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Administrator access required.' ); }
        if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) || ! self::profile( $mode )['access_token'] ) { return new WP_Error( 'not_configured', 'Save this environment’s access token first.' ); }
        $result = ICPA_Square::api( 'GET', '/v2/locations', null, $mode );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( ! isset( $result['locations'] ) || ! is_array( $result['locations'] ) ) { return new WP_Error( 'invalid_locations', 'Square did not return a location list.' ); }
        $items = array();
        foreach ( array_slice( $result['locations'], 0, 1000 ) as $location ) {
            if ( ! is_array( $location ) || empty( $location['id'] ) || ! is_string( $location['id'] ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,100}\z/', $location['id'] ) ) { continue; }
            $items[$location['id']] = array( 'name' => isset( $location['name'] ) && is_string( $location['name'] ) ? sanitize_text_field( $location['name'] ) : $location['id'], 'status' => isset( $location['status'] ) && 'ACTIVE' === $location['status'] ? 'ACTIVE' : 'INACTIVE', 'currency' => isset( $location['currency'] ) && is_string( $location['currency'] ) ? sanitize_text_field( $location['currency'] ) : '' );
        }
        $all = get_option( 'icpa_square_locations', array() );
        if ( ! is_array( $all ) ) { $all = array(); }
        $all[$mode] = array( 'fingerprint' => hash_hmac( 'sha256', self::profile( $mode )['access_token'], wp_salt( 'auth' ) ), 'items' => $items );
        update_option( 'icpa_square_locations', $all, false );
        return true;
    }

    public static function locations_handler() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        ICPA_Security::require_post();
        $mode = isset( $_POST['environment'] ) && is_string( $_POST['environment'] ) ? sanitize_key( $_POST['environment'] ) : '';
        check_admin_referer( 'icpa_load_locations_' . $mode );
        $result = self::load_locations( $mode );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Locations unavailable', array( 'response' => 400, 'back_link' => true ) ); }
        wp_safe_redirect( add_query_arg( array( 'page' => 'icpa-square-settings', 'locations_loaded' => $mode ), admin_url( 'admin.php' ) ) ); exit;
    }

    public static function location_select( $mode ) {
        $profile = self::profile( $mode );
        $items = self::locations( $mode );
        $override = '' !== ICPA_Square::secret( 'ICPA_SQUARE_' . strtoupper( $mode ) . '_LOCATION_ID' );
        echo '<select id="' . esc_attr( $mode ) . '-location" name="profiles[' . esc_attr( $mode ) . '][location_id]" ' . disabled( $override, true, false ) . '><option value="">Select a Square location</option>';
        if ( $profile['location_id'] && ! isset( $items[$profile['location_id']] ) ) { echo '<option value="' . esc_attr( $profile['location_id'] ) . '" selected>' . esc_html( $profile['location_id'] . ' — current location (refresh to verify)' ) . '</option>'; }
        foreach ( $items as $id => $item ) {
            $eligible = 'ACTIVE' === $item['status'] && ICPA_Platform::options()['currency'] === $item['currency'];
            echo '<option value="' . esc_attr( $id ) . '" ' . selected( $profile['location_id'], $id, false ) . ' ' . disabled( ! $eligible, true, false ) . '>' . esc_html( $item['name'] . ' — ' . $item['currency'] . ' — ' . $id . ( $eligible ? '' : ' (inactive or different currency)' ) ) . '</option>';
        }
        echo '</select><p class="description">' . ( $override ? 'Location is controlled by server configuration.' : 'Save your access token, then use Load locations below. Locations are separate for Sandbox and Production. Only active locations matching the site currency can be selected.' ) . '</p>';
    }

    public static function encrypt( $value, $context = '' ) {
        if ( ! is_string( $value ) || ! is_string( $context ) || ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) { return new WP_Error( 'encryption_missing', 'PHP OpenSSL AES-GCM support is required to save Square credentials. Server configuration remains available.' ); }
        try { $iv = random_bytes( 12 ); } catch ( Exception $e ) { return new WP_Error( 'encryption_failed', 'Secure encryption is unavailable. Previous settings were kept.' ); }
        $tag = '';
        $key = hash( 'sha256', wp_salt( 'auth' ) . '|ICPA Square profiles v2', true );
        $encrypted = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context );
        return false === $encrypted ? new WP_Error( 'encryption_failed', 'Credentials could not be encrypted. Previous settings were kept.' ) : 'v2:' . base64_encode( $iv . $tag . $encrypted );
    }

    public static function decrypt( $value, $context = '' ) {
        if ( ! is_string( $value ) || strlen( $value ) > 8192 || ! is_string( $context ) || ! in_array( substr( $value, 0, 3 ), array( 'v1:', 'v2:' ), true ) || ! function_exists( 'openssl_decrypt' ) ) { return ''; }
        $version = substr( $value, 0, 2 );
        $data = base64_decode( substr( $value, 3 ), true );
        if ( false === $data || strlen( $data ) <= 28 ) { return ''; }
        $key = hash( 'sha256', wp_salt( 'auth' ) . '|ICPA Square profiles ' . $version, true );
        $plain = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ), 'v2' === $version ? $context : '' );
        return false === $plain ? '' : $plain;
    }

    /** Environment-specific server configuration overrides encrypted admin settings. */
    public static function profile( $mode = null ) {
        $mode = self::mode( $mode );
        $all = get_option( self::OPTION, array() );
        $saved = isset( $all[$mode] ) && is_array( $all[$mode] ) ? $all[$mode] : array();
        $profile = array( 'location_id' => isset( $saved['location_id'] ) ? $saved['location_id'] : '', 'application_id' => isset( $saved['application_id'] ) ? $saved['application_id'] : '', 'access_token' => '', 'signature_key' => '' );
        foreach ( array( 'access_token' => 'ACCESS_TOKEN', 'signature_key' => 'WEBHOOK_SIGNATURE_KEY' ) as $field => $suffix ) {
            $server = ICPA_Square::secret( 'ICPA_SQUARE_' . strtoupper( $mode ) . '_' . $suffix );
            if ( '' === $server && $mode === ICPA_Platform::options()['mode'] ) { $server = ICPA_Square::secret( 'ICPA_SQUARE_' . $suffix ); }
            $profile[$field] = '' !== $server ? $server : self::decrypt( isset( $saved[$field] ) ? $saved[$field] : '', $mode . '|' . $field );
            if ( ! preg_match( '/\A[\x21-\x7e]{10,4096}\z/', $profile[$field] ) ) { $profile[$field] = ''; }
        }
        $app = ICPA_Square::secret( 'ICPA_SQUARE_' . strtoupper( $mode ) . '_APPLICATION_ID' );
        if ( '' !== $app ) { $profile['application_id'] = $app; }
        if ( ! is_string( $profile['application_id'] ) || ! preg_match( '/\A[a-zA-Z0-9_-]{0,100}\z/', $profile['application_id'] ) ) { $profile['application_id'] = ''; }
        $location = ICPA_Square::secret( 'ICPA_SQUARE_' . strtoupper( $mode ) . '_LOCATION_ID' );
        if ( '' !== $location ) { $profile['location_id'] = $location; }
        if ( '' === $profile['location_id'] && $mode === ICPA_Platform::options()['mode'] ) { $profile['location_id'] = ICPA_Platform::options()['location_id']; }
        if ( ! is_string( $profile['location_id'] ) || ! preg_match( '/\A[a-zA-Z0-9_-]{0,100}\z/', $profile['location_id'] ) ) { $profile['location_id'] = ''; }
        return $profile;
    }

    public static function save( $input ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Administrator access required.' ); }
        if ( ! is_array( $input ) ) { return new WP_Error( 'invalid', 'Invalid Square settings.' ); }
        $profiles = get_option( self::OPTION, array() );
        if ( ! is_array( $profiles ) ) { $profiles = array(); }
        foreach ( array( 'sandbox', 'production' ) as $mode ) {
            if ( ! isset( $input[$mode] ) || ! is_array( $input[$mode] ) ) { continue; }
            $incoming = $input[$mode];
            $saved = isset( $profiles[$mode] ) && is_array( $profiles[$mode] ) ? $profiles[$mode] : array();
            if ( isset( $incoming['application_id'] ) ) {
                if ( ! is_string( $incoming['application_id'] ) || ! preg_match( '/\A[a-zA-Z0-9_-]{0,100}\z/', $incoming['application_id'] ) ) { return new WP_Error( 'application_invalid', 'Enter a valid Square Application ID. Previous profiles were kept.' ); }
                $saved['application_id'] = $incoming['application_id'];
            }
            if ( isset( $incoming['location_id'] ) ) {
                if ( ! is_string( $incoming['location_id'] ) || ! preg_match( '/\A[a-zA-Z0-9_-]{0,100}\z/', $incoming['location_id'] ) ) { return new WP_Error( 'location_invalid', 'Enter a valid Square location ID. Previous profiles were kept.' ); }
                $items = self::locations( $mode );
                $current = self::profile( $mode )['location_id'];
                $new_location = $incoming['location_id'];
                if ( $new_location && $new_location !== $current && $items && ( ! isset( $items[$new_location] ) || 'ACTIVE' !== $items[$new_location]['status'] || ICPA_Platform::options()['currency'] !== $items[$new_location]['currency'] ) ) { return new WP_Error( 'location_invalid', 'Choose an active Square location matching the site currency.' ); }
                $saved['location_id'] = $new_location;
            }
            foreach ( array( 'access_token', 'signature_key' ) as $field ) {
                if ( ! empty( $incoming['clear_' . $field] ) ) { $saved[$field] = ''; continue; }
                if ( ! isset( $incoming[$field] ) || '' === $incoming[$field] ) {
                    // Explicit profile saves upgrade readable legacy ciphertext without exposing its value.
                    if ( isset( $saved[$field] ) && is_string( $saved[$field] ) && 0 === strpos( $saved[$field], 'v1:' ) ) {
                        $legacy = self::decrypt( $saved[$field] );
                        if ( '' !== $legacy ) {
                            $upgraded = self::encrypt( $legacy, $mode . '|' . $field );
                            if ( is_wp_error( $upgraded ) ) { return $upgraded; }
                            $saved[$field] = $upgraded;
                        }
                    }
                    continue;
                }
                if ( ! is_string( $incoming[$field] ) || ! preg_match( '/\A[\x21-\x7e]{10,4096}\z/', $incoming[$field] ) ) { return new WP_Error( 'credential_invalid', 'Credentials must contain 10–4096 printable characters without whitespace. Previous profiles were kept.' ); }
                $encrypted = self::encrypt( $incoming[$field], $mode . '|' . $field );
                if ( is_wp_error( $encrypted ) ) { return $encrypted; }
                $saved[$field] = $encrypted;
            }
            $profiles[$mode] = $saved;
        }
        global $wpdb;
        foreach ( array( 'sandbox', 'production' ) as $mode ) {
            $previous = self::profile( $mode )['location_id'];
            if ( $previous && false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . ICPA_Platform::table() . " SET location_id=%s WHERE mode=%s AND location_id=''", $previous, $mode ) ) ) { return new WP_Error( 'location_storage', 'Historical donation locations could not be saved. Previous profiles were kept.' ); }
        }
        update_option( self::OPTION, $profiles, false );
        delete_option( 'icpa_square_connection_results' );
        return true;
    }

    public static function save_handler() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        ICPA_Security::require_post();
        check_admin_referer( 'icpa_save_square' );
        $result = self::save( isset( $_POST['profiles'] ) ? wp_unslash( $_POST['profiles'] ) : array() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Square settings not saved', array( 'response' => 400, 'back_link' => true ) ); }
        wp_safe_redirect( add_query_arg( array( 'page' => 'icpa-square-settings', 'saved' => '1' ), admin_url( 'admin.php' ) ) ); exit;
    }

    public static function test_connection( $mode ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Administrator access required.' ); }
        if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) ) { return new WP_Error( 'invalid_mode', 'Choose Sandbox or Production.' ); }
        $profile = self::profile( $mode );
        if ( ! $profile['access_token'] || ! $profile['location_id'] ) { return new WP_Error( 'incomplete', 'Save this environment’s access token and location ID first.' ); }
        $response = ICPA_Square::api( 'GET', '/v2/locations', null, $mode );
        if ( is_wp_error( $response ) ) { return $response; }
        foreach ( isset( $response['locations'] ) && is_array( $response['locations'] ) ? $response['locations'] : array() as $location ) {
            if ( isset( $location['id'], $location['status'], $location['currency'] ) && $profile['location_id'] === $location['id'] && 'ACTIVE' === $location['status'] && ICPA_Platform::options()['currency'] === $location['currency'] ) { return true; }
        }
        return new WP_Error( 'location_mismatch', 'The configured location must be active, accessible with this token and use the site currency.' );
    }

    public static function test_handler() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        ICPA_Security::require_post();
        $mode = isset( $_POST['environment'] ) && is_string( $_POST['environment'] ) ? sanitize_key( $_POST['environment'] ) : '';
        check_admin_referer( 'icpa_test_square_' . $mode );
        $result = self::test_connection( $mode );
        $results = get_option( 'icpa_square_connection_results', array() );
        if ( in_array( $mode, array( 'sandbox', 'production' ), true ) ) {
            $results[$mode] = array( 'passed' => ! is_wp_error( $result ), 'time' => current_time( 'mysql' ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'Square location and currency verified. A sandbox checkout and webhook test are still required.' );
            update_option( 'icpa_square_connection_results', $results, false );
        }
        wp_safe_redirect( add_query_arg( 'page', 'icpa-square-settings', admin_url( 'admin.php' ) ) ); exit;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $results = get_option( 'icpa_square_connection_results', array() );
        echo '<div class="wrap"><h1>Square connections</h1><p>Save separate Sandbox and Production profiles. Choose the active checkout environment in <a href="' . esc_url( admin_url( 'admin.php?page=icpa-general-settings' ) ) . '">Donation settings</a>. Saving a profile does not switch the active environment.</p>';
        if ( isset( $_GET['locations_loaded'] ) ) { echo '<div class="notice notice-success"><p>Locations loaded. Choose a location in the matching profile above and save.</p></div>'; }
        if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Square profiles saved.</p></div>'; }
        echo '<p>Credentials are encrypted using the WordPress authentication salts and never displayed again. Blank fields keep existing credentials; use the remove checkbox to clear one. Server-defined credentials override saved values. Changing WordPress salts requires re-entering saved credentials.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="icpa_save_square">';
        wp_nonce_field( 'icpa_save_square' );
        foreach ( array( 'sandbox', 'production' ) as $mode ) {
            $profile = self::profile( $mode );
            echo '<h2>' . esc_html( ucfirst( $mode ) ) . ( 'production' === $mode ? ' — real payments' : ' — test payments' ) . '</h2><table class="form-table"><tr><th><label for="' . esc_attr( $mode ) . '-location">Square location</label></th><td>';
            self::location_select( $mode );
            echo '</td></tr>';
            echo '<tr><th><label for="' . esc_attr( $mode ) . '-application">Application ID</label></th><td><input id="' . esc_attr( $mode ) . '-application" class="regular-text" name="profiles[' . esc_attr( $mode ) . '][application_id]" value="' . esc_attr( $profile['application_id'] ) . '" autocomplete="off"><p class="description">Use the matching environment’s Application ID from Square Developer Dashboard → Credentials. Enables card entry on your donation page (HTTPS required). This is not the Application secret. Blank keeps one-time checkout hosted on Square. Recurring gifts remain hosted on Square.</p></td></tr>';
            foreach ( array( 'access_token' => 'Access token', 'signature_key' => 'Webhook signature key' ) as $field => $label ) {
                echo '<tr><th><label for="' . esc_attr( $mode . '-' . $field ) . '">' . esc_html( $label ) . '</label></th><td><input type="password" id="' . esc_attr( $mode . '-' . $field ) . '" class="regular-text" name="profiles[' . esc_attr( $mode ) . '][' . esc_attr( $field ) . ']" value="" autocomplete="new-password"><p class="description">' . ( $profile[$field] ? 'Configured' : 'Not configured (or encrypted value could not be opened)' ) . '</p><label><input type="checkbox" name="profiles[' . esc_attr( $mode ) . '][clear_' . esc_attr( $field ) . ']" value="1"> Remove saved credential (server overrides remain)</label></td></tr>';
            }
            $health = get_option( 'icpa_square_webhook_health', array() );
            echo '<tr><th>Webhook URL</th><td><code>' . esc_html( ICPA_Square::webhook_url( $mode ) ) . '</code><p class="description">Last successfully verified payment notification (UTC): ' . esc_html( isset( $health[$mode] ) ? $health[$mode] : 'Not received yet' ) . '</p></td></tr></table>';
        }
        submit_button( 'Save Square profiles' );
        echo '</form><h2>Square locations</h2><p>These actions load location names and IDs from Square without taking a payment. Save profile edits before loading locations.</p>';
        foreach ( array( 'sandbox', 'production' ) as $mode ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="icpa_load_locations"><input type="hidden" name="environment" value="' . esc_attr( $mode ) . '">';
            wp_nonce_field( 'icpa_load_locations_' . $mode );
            submit_button( 'Load ' . ucfirst( $mode ) . ' locations', 'secondary', 'submit', false );
            echo '</form><br>';
        }
        echo '<h2>Connection tests</h2><p>These read-only tests check the location and currency. They do not charge a card or verify webhook delivery.</p>';
        foreach ( array( 'sandbox', 'production' ) as $mode ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="icpa_test_square"><input type="hidden" name="environment" value="' . esc_attr( $mode ) . '">';
            wp_nonce_field( 'icpa_test_square_' . $mode );
            submit_button( 'Test ' . ucfirst( $mode ) . ' connection', 'secondary', 'submit', false );
            if ( isset( $results[$mode] ) ) { echo '<p>' . esc_html( $results[$mode]['time'] . ' — ' . $results[$mode]['message'] ) . '</p>'; }
            echo '</form><br>';
        }
        echo '<p>Subscribe each Square environment to payment.created, payment.updated, refund.created and refund.updated, using its exact URL and corresponding signature key above. The legacy webhook URL remains supported for the active environment only.</p></div>';
    }
}
