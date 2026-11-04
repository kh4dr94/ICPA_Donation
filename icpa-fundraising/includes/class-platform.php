<?php
defined( 'ABSPATH' ) || exit;

final class ICPA_Platform {
    const VERSION = '0.8.1';
    const OPTION = 'icpa_fundraising_settings';
    const TYPE = 'icpa_campaign';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'upgrade' ), 5 );
        add_action( 'init', array( __CLASS__, 'register' ) );
        add_action( 'admin_init', array( __CLASS__, 'settings' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save_meta' ) );
        add_action( 'admin_post_icpa_submit_campaign', array( __CLASS__, 'submit' ) );
        add_action( 'admin_post_nopriv_icpa_submit_campaign', array( __CLASS__, 'submit' ) );
        add_action( 'template_redirect', array( __CLASS__, 'private_pages' ) );
        add_action( 'admin_post_icpa_reconcile_donation', array( __CLASS__, 'reconcile' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'icpa_donations';
    }

    public static function upgrade() {
        if ( get_option( 'icpa_fundraising_version' ) !== self::VERSION ) { self::activate(); }
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            amount bigint(20) unsigned NOT NULL,
            refunded bigint(20) unsigned NOT NULL DEFAULT 0,
            currency varchar(3) NOT NULL,
            mode varchar(12) NOT NULL,
            location_id varchar(100) NOT NULL DEFAULT '',
            checkout_key varchar(36) NOT NULL,
            checkout_url text DEFAULT NULL,
            order_id varchar(192) DEFAULT NULL,
            payment_id varchar(192) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            updated_at varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            UNIQUE KEY checkout_key (checkout_key),
            UNIQUE KEY payment_id (payment_id),
            KEY campaign_status (campaign_id,status),
            KEY donor (user_id)
        ) $charset;" );
        $role = get_role( 'administrator' );
        if ( $role ) {
            foreach ( array( 'edit_icpa_campaign', 'read_icpa_campaign', 'delete_icpa_campaign', 'edit_icpa_campaigns', 'edit_others_icpa_campaigns', 'publish_icpa_campaigns', 'read_private_icpa_campaigns', 'delete_icpa_campaigns', 'delete_private_icpa_campaigns', 'delete_published_icpa_campaigns', 'delete_others_icpa_campaigns', 'edit_private_icpa_campaigns', 'edit_published_icpa_campaigns' ) as $cap ) {
                $role->add_cap( $cap );
            }
        }
        self::register();
        foreach ( array( 'Relief', 'Education', 'Health', 'Community' ) as $category ) {
            if ( ! term_exists( $category, 'icpa_cause' ) ) {
                wp_insert_term( $category, 'icpa_cause' );
            }
        }
        update_option( 'icpa_fundraising_version', self::VERSION );
        flush_rewrite_rules();
    }

    public static function register() {
        register_post_type( self::TYPE, array(
            'labels' => array( 'name' => 'Fundraising campaigns', 'menu_name' => 'Campaigns', 'singular_name' => 'Campaign', 'add_new_item' => 'Add campaign', 'edit_item' => 'Review campaign' ),
            'show_in_menu' => 'icpa-donations',
            'public' => true, 'show_in_rest' => true, 'has_archive' => true,
            'rewrite' => array( 'slug' => 'campaigns' ), 'menu_icon' => 'dashicons-heart',
            'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
            'capability_type' => array( 'icpa_campaign', 'icpa_campaigns' ), 'map_meta_cap' => true,
        ) );
        register_taxonomy( 'icpa_cause', self::TYPE, array( 'label' => 'Causes', 'public' => true, 'show_in_rest' => true, 'hierarchical' => true, 'capabilities' => array( 'manage_terms' => 'manage_options', 'edit_terms' => 'manage_options', 'delete_terms' => 'manage_options', 'assign_terms' => 'edit_icpa_campaigns' ) ) );
    }

    public static function options() {
        return wp_parse_args( get_option( self::OPTION, array() ), array( 'currency' => 'AUD', 'mode' => 'sandbox', 'location_id' => '', 'account_page' => 0, 'submit_page' => 0, 'browse_page' => 0, 'general_page' => 0, 'general_enabled' => 1, 'campaigns_enabled' => 1, 'minimum_amount' => 100, 'maximum_amount' => 100000000, 'suggested_amounts' => array( 2500, 5000, 10000, 25000 ), 'donation_title' => 'Donate & Support', 'layout' => 'theme', 'primary_color' => '', 'heading_color' => '', 'hero_image_id' => 0, 'intro_title' => 'Donations & Charities', 'intro_text' => 'Support ICPA’s community campaigns. Your generosity helps turn compassion into practical support for those who need it.' ) );
    }

    public static function donation_label( $campaign ) {
        return $campaign ? ( get_the_title( $campaign ) ?: 'Campaign unavailable' ) : 'General donation';
    }

    public static function money( $amount, $currency = null ) {
        $currency = $currency ?: self::options()['currency'];
        $symbols = array( 'GBP' => '£', 'EUR' => '€' );
        return $currency . ' ' . ( isset( $symbols[$currency] ) ? $symbols[$currency] : '$' ) . number_format_i18n( $amount / 100, 2 );
    }

    /** Convert decimal strings without floating point rounding. All supported currencies use cents. */
    public static function cents( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{1,7})(?:\.([0-9]{1,2}))?$/', trim( $value ), $m ) ) {
            return 0;
        }
        $amount = (int) $m[1] * 100 + (int) str_pad( isset( $m[2] ) ? $m[2] : '', 2, '0' );
        return $amount >= 100 && $amount <= 100000000 ? $amount : 0;
    }

    public static function stats( $id ) {
        global $wpdb;
        $table = self::table();
        $mode = self::options()['mode'];
        return $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(amount-refunded),0) AS raised, COUNT(*) AS donations FROM $table WHERE campaign_id=%d AND status='completed' AND mode=%s AND refunded<amount", $id, $mode ), ARRAY_A );
    }

    public static function open( $id ) {
        if ( ! self::options()['campaigns_enabled'] || self::TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || get_post_meta( $id, '_icpa_closed', true ) || (int) get_post_meta( $id, '_icpa_goal', true ) < 100 ) {
            return false;
        }
        $end = get_post_meta( $id, '_icpa_deadline', true );
        return ! $end || $end >= current_time( 'Y-m-d' );
    }

    public static function valid_date( $date ) {
        if ( ! is_string( $date ) || ! preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $m ) ) {
            return false;
        }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    public static function settings() {
        register_setting( self::OPTION, self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );
    }

    public static function sanitize_settings( $input ) {
        global $wpdb;
        $old = self::options();
        if ( ! is_array( $input ) ) {
            return $old;
        }
        $currency = isset( $input['currency'] ) && in_array( $input['currency'], array( 'AUD', 'USD', 'CAD', 'GBP', 'EUR', 'NZD' ), true ) ? $input['currency'] : $old['currency'];
        if ( $currency !== $old['currency'] && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ) > 0 ) {
            add_settings_error( self::OPTION, 'currency_locked', 'Currency cannot change after the first checkout. Create a separate site for another currency.' );
            $currency = $old['currency'];
        }
        $clean = array( 'currency' => $currency, 'mode' => isset( $input['mode'] ) && 'production' === $input['mode'] ? 'production' : 'sandbox', 'location_id' => $old['location_id'] );
        foreach ( array( 'general_enabled', 'campaigns_enabled' ) as $field ) { $clean[$field] = isset( $input[$field] ) ? ( empty( $input[$field] ) ? 0 : 1 ) : $old[$field]; }
        $minimum = isset( $input['min_donation'] ) ? self::cents( $input['min_donation'] ) : $old['minimum_amount'];
        $maximum = isset( $input['max_donation'] ) ? self::cents( $input['max_donation'] ) : $old['maximum_amount'];
        if ( ! $minimum || ! $maximum || $maximum < $minimum ) {
            add_settings_error( self::OPTION, 'donation_bounds', 'Enter valid donation limits between 1 and 1,000,000, with maximum at least the minimum. Previous limits were kept.' );
            $minimum = $old['minimum_amount']; $maximum = $old['maximum_amount'];
        }
        $clean['minimum_amount'] = $minimum; $clean['maximum_amount'] = $maximum;
        $suggestions = $old['suggested_amounts'];
        if ( isset( $input['suggested_donations'] ) && is_string( $input['suggested_donations'] ) ) {
            $suggestions = array();
            foreach ( array_slice( explode( ',', $input['suggested_donations'] ), 0, 6 ) as $value ) {
                $amount = self::cents( trim( $value ) );
                if ( $amount >= $minimum && $amount <= $maximum ) { $suggestions[] = $amount; }
            }
            $suggestions = array_values( array_unique( $suggestions ) );
            if ( ! $suggestions ) { add_settings_error( self::OPTION, 'suggested_invalid', 'Add up to six valid suggested amounts within your donation limits. Previous suggestions were kept.' ); $suggestions = $old['suggested_amounts']; }
        }
        $clean['suggested_amounts'] = $suggestions;
        $clean['donation_title'] = isset( $input['donation_title'] ) && is_string( $input['donation_title'] ) && '' !== trim( $input['donation_title'] ) ? wp_html_excerpt( sanitize_text_field( $input['donation_title'] ), 120, '' ) : $old['donation_title'];
        $clean['layout'] = isset( $input['layout'] ) ? ( 'standalone' === $input['layout'] ? 'standalone' : 'theme' ) : $old['layout'];
        foreach ( array( 'primary_color', 'heading_color' ) as $field ) {
            $value = isset( $input[$field] ) && is_string( $input[$field] ) ? trim( $input[$field] ) : $old[$field];
            $color = sanitize_hex_color( $value );
            if ( '' !== $value && ! $color ) {
                add_settings_error( self::OPTION, 'invalid_' . $field, 'Brand colours must be hex values such as #123456. Your previous colour was kept.' );
                $color = $old[$field];
            }
            $clean[$field] = $color ?: '';
        }
        foreach ( array( 'intro_title', 'intro_text' ) as $field ) {
            $clean[$field] = isset( $input[$field] ) && is_string( $input[$field] ) ? sanitize_text_field( $input[$field] ) : $old[$field];
            $clean[$field] = wp_html_excerpt( $clean[$field], 'intro_title' === $field ? 140 : 500, '' );
        }
        $image = isset( $input['hero_image_id'] ) ? absint( $input['hero_image_id'] ) : $old['hero_image_id'];
        $clean['hero_image_id'] = $image && wp_attachment_is_image( $image ) ? $image : 0;
        foreach ( array( 'account_page', 'submit_page', 'browse_page', 'general_page' ) as $field ) {
            $id = isset( $input[$field] ) ? absint( $input[$field] ) : 0;
            $clean[$field] = 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? $id : 0;
        }
        return $clean;
    }

    public static function admin_assets() {
        if ( current_user_can( 'manage_options' ) && isset( $_GET['page'] ) && 'icpa-donations' === $_GET['page'] ) { wp_enqueue_style( 'icpa-ledger', plugins_url( 'assets/admin-ledger.css', ICPA_FUNDRAISING_FILE ), array(), self::VERSION ); }
        if ( ! isset( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'icpa-platform', 'icpa-general-settings' ), true ) || ! current_user_can( 'manage_options' ) ) { return; }
        wp_enqueue_media();
        wp_enqueue_script( 'icpa-branding-admin', plugins_url( 'assets/branding-admin.js', ICPA_FUNDRAISING_FILE ), array( 'media-views' ), self::VERSION, true );
    }

    public static function private_pages() {
        $o = self::options();
        $post = get_post();
        if ( ( $o['account_page'] && is_page( $o['account_page'] ) ) || ( $o['submit_page'] && is_page( $o['submit_page'] ) ) || ( $o['general_page'] && is_page( $o['general_page'] ) ) || is_singular( self::TYPE ) || ( $post && ( has_shortcode( $post->post_content, 'icpa_account' ) || has_shortcode( $post->post_content, 'icpa_start_campaign' ) || has_shortcode( $post->post_content, 'icpa_donation_form' ) ) ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
            nocache_headers();
        }
    }

    public static function donations_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $table = self::table();
        $page = isset( $_GET['paged'] ) ? ICPA_Security::page( $_GET['paged'] ) : 1;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE mode=%s ORDER BY id DESC LIMIT 25 OFFSET %d", self::options()['mode'], ( $page - 1 ) * 25 ), ARRAY_A );
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE mode=%s", self::options()['mode'] ) );
        echo '<div class="wrap icpa-ledger"><h1>One-time donations — ' . esc_html( self::options()['mode'] ) . '</h1><p>Only completed donations count towards campaign goals. Totals subtract refunds and do not subtract Square processing fees. Pending checkouts may have been abandoned. Manage refunds and receipts in Square.</p>';
        if ( ICPA_Recurring::options()['enabled'] ) { echo '<div class="notice notice-info"><p>Recurring subscriptions and renewal payments are managed in Square. They are not included in this one-time donation ledger or campaign totals.</p></div>'; }
        if ( isset( $_GET['icpa_sync'] ) ) { echo '<div class="notice notice-info"><p>' . ( 'ok' === $_GET['icpa_sync'] ? 'Square payment state refreshed.' : 'Unable to refresh this payment. It may not have been paid yet. Confirm the payment and connection in Square, then retry.' ) . '</p></div>'; }
        $summary = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CASE WHEN status='completed' THEN amount-refunded ELSE 0 END) AS received, SUM(status='completed') AS completed, SUM(status='pending') AS pending FROM $table WHERE mode=%s", self::options()['mode'] ), ARRAY_A );
        echo '<div class="icpa-ledger-stats"><div><span>Confirmed donations, less refunds</span><strong>' . esc_html( self::money( (int) $summary['received'] ) ) . '</strong></div><div><span>Completed payments</span><strong>' . esc_html( (int) $summary['completed'] ) . '</strong></div><div><span>Awaiting confirmation</span><strong>' . esc_html( (int) $summary['pending'] ) . '</strong></div></div>';
        echo '<table class="widefat striped"><thead><tr><th>ID / Started (UTC)</th><th>Campaign</th><th>Donor</th><th>Amount / Refunded</th><th>Status</th><th>Square order</th><th>Reconcile</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $user = $row['user_id'] ? get_user_by( 'id', $row['user_id'] ) : false;
            $refresh = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="icpa_reconcile_donation"><input type="hidden" name="donation_id" value="' . esc_attr( $row['id'] ) . '">' . wp_nonce_field( 'icpa_reconcile_' . $row['id'], '_wpnonce', true, false ) . '<button type="submit" class="button-link">Refresh from Square</button></form>';
            echo '<tr><td>' . esc_html( $row['id'] ) . '<br>' . esc_html( $row['created_at'] ) . '</td><td>' . esc_html( self::donation_label( (int) $row['campaign_id'] ) ) . '</td><td>' . esc_html( $user ? $user->display_name : 'Guest or deleted account' ) . '</td><td>' . esc_html( self::money( (int) $row['amount'], $row['currency'] ) ) . '<br>' . esc_html( self::money( (int) $row['refunded'], $row['currency'] ) ) . ' refunded</td><td>' . '<span class="icpa-ledger-status status-' . esc_attr( sanitize_key( $row['status'] ) ) . '">' . esc_html( $row['status'] ) . '</span></td><td>' . esc_html( $row['order_id'] ?: 'Checkout not created' ) . '</td><td>' . ( $row['order_id'] ? $refresh : '—' ) . '</td></tr>';
        }
        if ( ! $rows ) { echo '<tr><td colspan="7">No donations in this environment yet.</td></tr>'; }
        echo '</tbody></table>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => (int) ceil( $count / 25 ) ) ) ) . '</div>';
    }

    public static function reconcile() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        ICPA_Security::require_post( 8192 );
        $id = ICPA_Security::id( isset( $_POST['donation_id'] ) ? wp_unslash( $_POST['donation_id'] ) : 0 );
        if ( ! $id ) { wp_die( 'Invalid donation identifier.', 'Invalid request', array( 'response' => 400 ) ); }
        check_admin_referer( 'icpa_reconcile_' . $id );
        $result = ICPA_Square::reconcile( $id );
        wp_safe_redirect( add_query_arg( array( 'post_type' => self::TYPE, 'page' => 'icpa-donations', 'icpa_sync' => is_wp_error( $result ) ? 'error' : 'ok' ), admin_url( 'edit.php' ) ) );
        exit;
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $o = self::options();
        echo '<div class="wrap"><h1>ICPA Donation settings</h1>';
        settings_errors( self::OPTION );
        echo '<p>Every campaign raises funds for ICPA. Organisers cannot change the receiving Square account.</p><form action="options.php" method="post">';
        settings_fields( self::OPTION );
        echo '<h2>Donation options</h2><p>Use <code>[icpa_donation_form]</code> for general giving and <code>[icpa_campaigns]</code> for optional campaigns. Neither requires replacing your theme.</p><table class="form-table">';
        foreach ( array( 'general_enabled' => 'Enable general donations', 'campaigns_enabled' => 'Enable campaign donations and submissions' ) as $key => $label ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="hidden" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="0"><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="1" ' . checked( $o[$key], 1, false ) . '> Enabled</label></td></tr>';
        }
        foreach ( array( 'donation_title' => array( 'General donation heading', $o['donation_title'] ), 'min_donation' => array( 'Minimum donation', number_format( $o['minimum_amount'] / 100, 2, '.', '' ) ), 'max_donation' => array( 'Maximum donation', number_format( $o['maximum_amount'] / 100, 2, '.', '' ) ), 'suggested_donations' => array( 'Suggested amounts (comma separated)', implode( ', ', array_map( function ( $amount ) { return number_format( $amount / 100, 2, '.', '' ); }, $o['suggested_amounts'] ) ) ) ) as $key => $field ) {
            echo '<tr><th><label for="icpa-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td><input class="regular-text" id="icpa-' . esc_attr( $key ) . '" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $field[1] ) . '"></td></tr>';
        }
        echo '</table>';
        echo '<table class="form-table"><tr><th><label for="icpa-currency">Site currency</label></th><td><select id="icpa-currency" name="' . esc_attr( self::OPTION ) . '[currency]">';
        foreach ( array( 'AUD', 'USD', 'CAD', 'GBP', 'EUR', 'NZD' ) as $currency ) {
            echo '<option ' . selected( $o['currency'], $currency, false ) . '>' . esc_html( $currency ) . '</option>';
        }
        echo '</select></td></tr><tr><th><label for="icpa-mode">Square environment</label></th><td><select id="icpa-mode" name="' . esc_attr( self::OPTION ) . '[mode]"><option value="sandbox" ' . selected( $o['mode'], 'sandbox', false ) . '>Sandbox (test payments)</option><option value="production" ' . selected( $o['mode'], 'production', false ) . '>Production (real payments)</option></select></td></tr>';
        echo '<tr><th>Square location</th><td><p>Choose the location by name under <a href="' . esc_url( admin_url( 'admin.php?page=icpa-square-settings' ) ) . '">Square connections</a>, separately for Sandbox and Production. The selected environment uses its saved profile location.</p></td></tr>';
        foreach ( array( 'general_page' => 'General donation page [icpa_donation_form]', 'browse_page' => 'Browse page [icpa_campaigns]', 'submit_page' => 'Start page [icpa_start_campaign]', 'account_page' => 'Account page [icpa_account]' ) as $key => $label ) {
            echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
            wp_dropdown_pages( array( 'name' => self::OPTION . '[' . $key . ']', 'id' => $key, 'selected' => $o[$key], 'show_option_none' => 'Select a page', 'option_none_value' => 0 ) );
            echo '</td></tr>';
        }
        echo '</table><h2>Website appearance</h2><p>Use your existing WordPress theme to keep the website header, footer, logo and typography. Standalone mode uses the plugin’s full-width layout.</p><table class="form-table"><tr><th><label for="icpa-layout">Layout</label></th><td><select id="icpa-layout" name="' . esc_attr( self::OPTION ) . '[layout]"><option value="theme" ' . selected( $o['layout'], 'theme', false ) . '>Use my website theme (recommended)</option><option value="standalone" ' . selected( $o['layout'], 'standalone', false ) . '>Standalone fundraising pages</option></select></td></tr>';
        foreach ( array( 'primary_color' => 'Button and accent colour', 'heading_color' => 'Heading and text colour', 'intro_title' => 'Donation page heading', 'intro_text' => 'Donation page introduction', 'hero_image_id' => 'Banner image (Media Library ID)' ) as $key => $label ) {
            echo '<tr><th><label for="icpa-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="icpa-' . esc_attr( $key ) . '" type="' . ( 'hero_image_id' === $key ? 'number' : 'text' ) . '" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $o[$key] ) . '">';
            if ( in_array( $key, array( 'primary_color', 'heading_color' ), true ) ) { echo '<p class="description">Optional hex colour. Leave blank to inherit your theme’s colour where available.</p>'; }
            if ( 'hero_image_id' === $key ) { echo '<p class="description">Optional. Use an existing image from your WordPress Media Library. The plugin does not download images from other websites.</p>'; }
            if ( 'hero_image_id' === $key ) { echo '<p><button type="button" class="button" id="icpa-choose-banner">Choose banner image</button> <button type="button" class="button" id="icpa-clear-banner">Remove image</button></p><div id="icpa-banner-preview">' . ( $o[$key] ? wp_get_attachment_image( $o[$key], 'thumbnail' ) : '' ) . '</div>'; }
            echo '</td></tr>';
        }
        echo '</table>';
        submit_button();
        echo '</form><h2>Square connection</h2><p>Status: <strong>' . ( ICPA_Square::ready() ? 'Configured — validate checkout and webhooks before launch' : 'Not configured — checkout is disabled' ) . '</strong></p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=icpa-square-settings' ) ) . '">Configure Sandbox and Production profiles</a></p><p>Store access tokens, webhook signature keys and separate location IDs under ICPA Donations → Square connections. Existing legacy server-based location configuration remains supported.</p><p>Enable WordPress membership registration with the Subscriber default role if you want self-service donor accounts or organiser submissions. General donations also work for guests.</p></div>';
    }

    public static function meta_boxes() {
        add_meta_box( 'icpa-details', 'Campaign details', array( __CLASS__, 'meta_box' ), self::TYPE, 'side' );
    }

    public static function meta_box( $post ) {
        wp_nonce_field( 'icpa_campaign_meta', 'icpa_campaign_nonce' );
        $goal = (int) get_post_meta( $post->ID, '_icpa_goal', true );
        echo '<p><label>Goal (' . esc_html( self::options()['currency'] ) . ')<br><input type="number" min="1" max="1000000" step="0.01" name="icpa_goal" value="' . esc_attr( number_format( $goal / 100, 2, '.', '' ) ) . '"></label></p><p><label>End date (optional)<br><input type="date" name="icpa_deadline" value="' . esc_attr( get_post_meta( $post->ID, '_icpa_deadline', true ) ) . '"></label></p><p><label><input type="checkbox" name="icpa_closed" value="1" ' . checked( get_post_meta( $post->ID, '_icpa_closed', true ), '1', false ) . '> Close donations</label></p><p>Publishing approves this campaign. Add a featured image and select a cause before publishing.</p>';
    }

    public static function save_meta( $id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) || ! isset( $_POST['icpa_campaign_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['icpa_campaign_nonce'] ) ), 'icpa_campaign_meta' ) ) { return; }
        $goal = isset( $_POST['icpa_goal'] ) ? self::cents( wp_unslash( $_POST['icpa_goal'] ) ) : 0;
        if ( $goal ) { update_post_meta( $id, '_icpa_goal', $goal ); }
        $deadline = isset( $_POST['icpa_deadline'] ) ? wp_unslash( $_POST['icpa_deadline'] ) : '';
        if ( '' === $deadline || self::valid_date( $deadline ) ) { update_post_meta( $id, '_icpa_deadline', $deadline ); }
        update_post_meta( $id, '_icpa_closed', isset( $_POST['icpa_closed'] ) ? '1' : '' );
    }

    /** Return a pending campaign ID or a validation error; callers handle authentication/CSRF. */
    public static function create_campaign( $input, $user_id ) {
        if ( ! self::options()['campaigns_enabled'] ) { return new WP_Error( 'campaigns_disabled', 'Campaign submissions are currently disabled.' ); }
        if ( ! get_user_by( 'id', $user_id ) || ! is_array( $input ) ) {
            return new WP_Error( 'sign_in', 'Please sign in to submit a campaign.' );
        }
        $title = isset( $input['title'] ) && is_string( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
        $story = isset( $input['story'] ) && is_string( $input['story'] ) ? sanitize_textarea_field( $input['story'] ) : '';
        $goal = isset( $input['goal'] ) ? self::cents( $input['goal'] ) : 0;
        $deadline = isset( $input['deadline'] ) ? $input['deadline'] : '';
        $cause = isset( $input['cause'] ) ? ICPA_Security::id( $input['cause'] ) : null;
        if ( strlen( $title ) < 5 || strlen( $title ) > 160 || strlen( $story ) < 80 || strlen( $story ) > 16000 || ! $goal || ! $cause || ! term_exists( $cause, 'icpa_cause' ) || ( '' !== $deadline && ( ! self::valid_date( $deadline ) || $deadline < current_time( 'Y-m-d' ) ) ) || ! isset( $input['consent'] ) || ! in_array( $input['consent'], array( '1', 1 ), true ) ) {
            return new WP_Error( 'campaign_invalid', 'Add a title (5–160 characters), story (80–16000 characters), valid goal and cause. The end date must be today or later. Confirm that ICPA receives and manages the funds.' );
        }
        $pending = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'pending', 'author' => $user_id, 'posts_per_page' => 5, 'fields' => 'ids' ) );
        if ( count( $pending ) >= 5 ) { return new WP_Error( 'pending_limit', 'You already have five campaigns awaiting review.' ); }
        $id = wp_insert_post( array( 'post_type' => self::TYPE, 'post_title' => $title, 'post_content' => $story, 'post_status' => 'pending', 'post_author' => $user_id ), true );
        if ( is_wp_error( $id ) ) { return $id; }
        update_post_meta( $id, '_icpa_goal', $goal );
        update_post_meta( $id, '_icpa_deadline', $deadline );
        wp_set_object_terms( $id, array( $cause ), 'icpa_cause' );
        return $id;
    }

    public static function submit() {
        ICPA_Security::require_post( 32768 );
        if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
        check_admin_referer( 'icpa_submit_campaign' );
        if ( ! ICPA_Security::rate_limit( 'campaign_submission', 5, HOUR_IN_SECONDS ) ) { wp_die( 'Please wait before submitting more campaigns.', 'Submission limit', array( 'response' => 429 ) ); }
        $result = self::create_campaign( wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Campaign not submitted', array( 'response' => 400, 'back_link' => true ) ); }
        $account = self::options()['account_page'];
        wp_safe_redirect( add_query_arg( 'icpa_submitted', '1', $account ? get_permalink( $account ) : home_url( '/' ) ) );
        exit;
    }
}
