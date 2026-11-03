<?php
defined( 'ABSPATH' ) || exit;

/** Square-hosted subscriptions. Renewals are deliberately not treated as one-time payments. */
final class ICPA_Recurring {
    const OPTION = 'icpa_recurring_settings';
    const PLANS = 'icpa_recurring_catalog';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_icpa_recurring_settings', array( __CLASS__, 'settings_handler' ) );
        add_action( 'admin_post_icpa_refresh_plans', array( __CLASS__, 'refresh_handler' ) );
        add_action( 'admin_post_icpa_recurring_plan', array( __CLASS__, 'plan_handler' ) );
    }

    public static function cadences() {
        return array( 'WEEKLY' => 'Weekly', 'MONTHLY' => 'Monthly', 'QUARTERLY' => 'Quarterly', 'ANNUAL' => 'Yearly' );
    }

    public static function options() {
        return wp_parse_args( get_option( self::OPTION, array() ), array( 'enabled' => 0, 'one_time' => 1, 'cadences' => array( 'MONTHLY', 'ANNUAL' ), 'links' => array() ) );
    }

    public static function menu() {
        add_submenu_page( 'icpa-donations', 'Recurring giving', 'Recurring giving', 'manage_options', 'icpa-recurring', array( __CLASS__, 'page' ) );
    }

    public static function fingerprint( $mode ) {
        $p = ICPA_Square_Settings::profile( $mode );
        return hash_hmac( 'sha256', $mode . '|' . $p['location_id'] . '|' . $p['access_token'] . '|' . ICPA_Platform::options()['currency'], wp_salt( 'auth' ) );
    }

    public static function save_settings( $input ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Administrator access required.' ); }
        if ( ! is_array( $input ) ) { return new WP_Error( 'invalid_settings', 'Invalid recurring settings.' ); }
        $o = self::options();
        $o['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
        $o['one_time'] = empty( $input['one_time'] ) ? 0 : 1;
        $o['cadences'] = array_values( array_intersect( array_keys( self::cadences() ), isset( $input['cadences'] ) && is_array( $input['cadences'] ) ? array_filter( $input['cadences'], 'is_string' ) : array() ) );
        if ( ! $o['one_time'] && ( ! $o['enabled'] || ! $o['cadences'] ) ) { return new WP_Error( 'no_options', 'Keep one-time giving enabled or enable at least one recurring interval.' ); }
        update_option( self::OPTION, $o, false );
        return true;
    }

    /** Accept one fixed-price, indefinite phase, without trials, discounts or proration. */
    public static function parse_plan( $object, $mode ) {
        if ( ! is_array( $object ) || empty( $object['id'] ) || ! is_string( $object['id'] ) || 'SUBSCRIPTION_PLAN_VARIATION' !== ( isset( $object['type'] ) ? $object['type'] : '' ) || ! empty( $object['is_deleted'] ) ) { return new WP_Error( 'unsupported_plan', 'Select a subscription plan variation.' ); }
        $data = isset( $object['subscription_plan_variation_data'] ) && is_array( $object['subscription_plan_variation_data'] ) ? $object['subscription_plan_variation_data'] : array();
        $phases = isset( $data['phases'] ) && is_array( $data['phases'] ) ? $data['phases'] : array();
        if ( count( $phases ) !== 1 || ! isset( $phases[0] ) || ! is_array( $phases[0] ) || ! empty( $data['can_prorate'] ) || ! empty( $data['monthly_billing_anchor_date'] ) || isset( $phases[0]['periods'] ) || empty( $phases[0]['cadence'] ) || ! is_string( $phases[0]['cadence'] ) || ! isset( self::cadences()[$phases[0]['cadence']] ) ) { return new WP_Error( 'unsupported_phase', 'Use an indefinite, single-phase weekly, monthly, quarterly or annual plan without trials, billing anchors or proration.' ); }
        $pricing = isset( $phases[0]['pricing'] ) && is_array( $phases[0]['pricing'] ) ? $phases[0]['pricing'] : array();
        $money = isset( $pricing['price_money'] ) && is_array( $pricing['price_money'] ) ? $pricing['price_money'] : array();
        $site = ICPA_Platform::options();
        if ( ! isset( $pricing['type'], $money['amount'], $money['currency'] ) || 'STATIC' !== $pricing['type'] || ! empty( $pricing['discount_ids'] ) || ! is_int( $money['amount'] ) || $money['amount'] < $site['minimum_amount'] || $money['amount'] > $site['maximum_amount'] || $money['currency'] !== $site['currency'] ) { return new WP_Error( 'unsupported_price', 'Use static pricing in the site currency and donation limits, without discounts.' ); }
        $location = ICPA_Square_Settings::profile( $mode )['location_id'];
        if ( isset( $object['present_at_all_locations'] ) && false === $object['present_at_all_locations'] && ! in_array( $location, isset( $object['present_at_location_ids'] ) && is_array( $object['present_at_location_ids'] ) ? $object['present_at_location_ids'] : array(), true ) ) { return new WP_Error( 'plan_location', 'This plan is not available at the configured Square location.' ); }
        if ( in_array( $location, isset( $object['absent_at_location_ids'] ) && is_array( $object['absent_at_location_ids'] ) ? $object['absent_at_location_ids'] : array(), true ) ) { return new WP_Error( 'plan_location', 'This plan is unavailable at this Square location.' ); }
        return array( 'id' => $object['id'], 'version' => isset( $object['version'] ) ? $object['version'] : 0, 'name' => isset( $data['name'] ) && is_string( $data['name'] ) ? sanitize_text_field( $data['name'] ) : 'Donation plan', 'cadence' => $phases[0]['cadence'], 'amount' => $money['amount'], 'currency' => $money['currency'] );
    }

    public static function refresh( $mode, $more = false ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Administrator access required.' ); }
        if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) || ! ICPA_Square::ready( $mode ) ) { return new WP_Error( 'profile_missing', 'Configure this Square environment first.' ); }
        $all = get_option( self::PLANS, array() );
        $old = isset( $all[$mode] ) ? $all[$mode] : array();
        $same = isset( $old['fingerprint'] ) && hash_equals( $old['fingerprint'], self::fingerprint( $mode ) );
        $body = array( 'object_types' => array( 'SUBSCRIPTION_PLAN_VARIATION' ), 'limit' => 100 );
        if ( $more && $same && ! empty( $old['cursor'] ) ) { $body['cursor'] = $old['cursor']; }
        $result = ICPA_Square::api( 'POST', '/v2/catalog/search', $body, $mode );
        if ( is_wp_error( $result ) ) { return $result; }
        $plans = isset( $body['cursor'], $old['plans'] ) && is_array( $old['plans'] ) ? $old['plans'] : array();
        foreach ( isset( $result['objects'] ) && is_array( $result['objects'] ) ? $result['objects'] : array() as $object ) {
            $plan = self::parse_plan( $object, $mode );
            if ( ! is_wp_error( $plan ) ) { $plans[$plan['id']] = $plan; }
        }
        $all[$mode] = array( 'fingerprint' => self::fingerprint( $mode ), 'plans' => $plans, 'cursor' => isset( $result['cursor'] ) && is_string( $result['cursor'] ) ? $result['cursor'] : '' );
        update_option( self::PLANS, $all, false );
        return true;
    }

    public static function save_plan( $mode, $cadence, $id ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Administrator access required.' ); }
        if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) || ! is_string( $cadence ) || ! isset( self::cadences()[$cadence] ) || ! is_string( $id ) || ! preg_match( '/\A[A-Za-z0-9_-]{0,192}\z/', $id ) ) { return new WP_Error( 'invalid_option', 'Invalid environment, interval or plan ID.' ); }
        $o = self::options();
        if ( '' === $id ) { unset( $o['links'][$mode][$cadence] ); update_option( self::OPTION, $o, false ); return true; }
        if ( ! ICPA_Square::ready( $mode ) ) { return new WP_Error( 'profile_missing', 'Configure this Square environment first.' ); }
        $response = ICPA_Square::api( 'GET', '/v2/catalog/object/' . rawurlencode( $id ), null, $mode );
        if ( is_wp_error( $response ) ) { return $response; }
        $plan = self::parse_plan( isset( $response['object'] ) ? $response['object'] : array(), $mode );
        if ( is_wp_error( $plan ) ) { return $plan; }
        if ( $plan['id'] !== $id || $plan['cadence'] !== $cadence ) { return new WP_Error( 'wrong_interval', 'The Square plan must match the selected interval.' ); }
        $fingerprint = self::fingerprint( $mode );
        $saved = isset( $o['links'][$mode][$cadence] ) ? $o['links'][$mode][$cadence] : array();
        if ( isset( $saved['id'], $saved['amount'], $saved['version'], $saved['fingerprint'], $saved['url'] ) && $saved['id'] === $id && $saved['amount'] === $plan['amount'] && $saved['version'] === $plan['version'] && hash_equals( $saved['fingerprint'], $fingerprint ) && ICPA_Square::valid_checkout_url( $saved['url'] ) ) { return true; }
        $key = hash( 'sha256', wp_salt( 'nonce' ) . wp_json_encode( array( $fingerprint, $id, $plan['version'], $plan['amount'] ) ) );
        $result = ICPA_Square::api( 'POST', '/v2/online-checkout/payment-links', array( 'idempotency_key' => $key, 'quick_pay' => array( 'name' => 'ICPA ' . strtolower( self::cadences()[$cadence] ) . ' donation', 'location_id' => ICPA_Square_Settings::profile( $mode )['location_id'], 'price_money' => array( 'amount' => $plan['amount'], 'currency' => $plan['currency'] ) ), 'checkout_options' => array( 'subscription_plan_id' => $id, 'allow_tipping' => false ) ), $mode );
        if ( is_wp_error( $result ) ) { return $result; }
        $url = isset( $result['payment_link']['url'] ) ? $result['payment_link']['url'] : '';
        if ( ! ICPA_Square::valid_checkout_url( $url ) ) { return new WP_Error( 'invalid_link', 'Square did not return a valid subscription checkout URL.' ); }
        if ( ! isset( $result['payment_link']['checkout_options']['subscription_plan_id'] ) || $result['payment_link']['checkout_options']['subscription_plan_id'] !== $id ) { return new WP_Error( 'subscription_unconfirmed', 'Square did not confirm the requested subscription plan. This option has not been published.' ); }
        $plan['url'] = $url; $plan['fingerprint'] = $fingerprint;
        $o['links'][$mode][$cadence] = $plan;
        update_option( self::OPTION, $o, false );
        return true;
    }

    public static function available() {
        $o = self::options(); $mode = ICPA_Platform::options()['mode']; $site = ICPA_Platform::options();
        if ( ! $o['enabled'] || ! $site['general_enabled'] || ! ICPA_Square::ready( $mode ) ) { return array(); }
        $available = array();
        foreach ( $o['cadences'] as $cadence ) {
            $p = isset( $o['links'][$mode][$cadence] ) ? $o['links'][$mode][$cadence] : array();
            if ( isset( $p['fingerprint'], $p['url'], $p['amount'], $p['currency'] ) && hash_equals( $p['fingerprint'], self::fingerprint( $mode ) ) && ICPA_Square::valid_checkout_url( $p['url'] ) && $p['currency'] === $site['currency'] && $p['amount'] >= $site['minimum_amount'] && $p['amount'] <= $site['maximum_amount'] ) { $available[$cadence] = $p; }
        }
        return $available;
    }

    public static function quote( $plan ) {
        return hash_hmac( 'sha256', wp_json_encode( array( ICPA_Platform::options()['mode'], $plan['id'], $plan['version'], $plan['amount'], $plan['currency'], $plan['fingerprint'] ) ), wp_salt( 'nonce' ) );
    }

    public static function checkout( $cadence, $quote = '' ) {
        $plans = self::available();
        if ( ! is_string( $cadence ) || ! isset( $plans[$cadence] ) ) { return new WP_Error( 'recurring_unavailable', 'This recurring donation option is unavailable.' ); }
        $p = $plans[$cadence]; $mode = ICPA_Platform::options()['mode'];
        if ( ! is_string( $quote ) || ! hash_equals( self::quote( $p ), $quote ) ) { return new WP_Error( 'stale_recurring_option', 'This donation option has changed. Reload the donation page and review its current price and environment.' ); }
        $result = ICPA_Square::api( 'GET', '/v2/catalog/object/' . rawurlencode( $p['id'] ), null, $mode );
        if ( is_wp_error( $result ) ) { return $result; }
        $live = self::parse_plan( isset( $result['object'] ) ? $result['object'] : array(), $mode );
        if ( is_wp_error( $live ) || $live['id'] !== $p['id'] || $live['amount'] !== $p['amount'] || $live['cadence'] !== $cadence || $live['version'] !== $p['version'] ) { return new WP_Error( 'plan_changed', 'This recurring option has changed. Please contact ICPA or choose a one-time donation.' ); }
        return $p['url'];
    }

    public static function forms() {
        $plans = self::available();
        if ( ! $plans ) { return ''; }
        $units = array( 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'QUARTERLY' => 'quarter', 'ANNUAL' => 'year' );
        ob_start();
        ?><section class="icpa-recurring-options" aria-label="Recurring donation options"><h3>Make it a regular gift</h3><p class="icpa-small">Choose a fixed recurring amount. Square handles billing; contact ICPA to change or cancel a subscription.</p>
        <?php foreach ( $plans as $cadence => $plan ) { ?>
            <details class="icpa-recurring-option"><summary><?php echo esc_html( self::cadences()[$cadence] . ' — ' . ICPA_Platform::money( $plan['amount'], $plan['currency'] ) ); ?></summary>
                <p><?php echo esc_html( ICPA_Platform::money( $plan['amount'], $plan['currency'] ) . ' per ' . $units[$cadence] . ', repeating until cancelled.' ); ?> Review and authorise recurring billing on Square before subscribing.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="icpa-recurring-form" data-recurring-price="<?php echo esc_attr( ICPA_Platform::money( $plan['amount'], $plan['currency'] ) ); ?>">
                    <input type="hidden" name="action" value="icpa_checkout"><input type="hidden" name="campaign_id" value="0"><input type="hidden" name="environment" value="<?php echo esc_attr( ICPA_Platform::options()['mode'] ); ?>"><input type="hidden" name="giving_option" value="<?php echo esc_attr( $cadence ); ?>"><input type="hidden" name="recurring_quote" value="<?php echo esc_attr( self::quote( $plan ) ); ?>"><?php wp_nonce_field( 'icpa_checkout' ); ?>
                    <button type="submit" class="icpa-btn">Continue with <?php echo esc_html( strtolower( self::cadences()[$cadence] ) ); ?> giving ›</button>
                </form>
            </details>
        <?php } ?><p class="icpa-small">Recurring payment records are managed in Square and are not included in this website’s one-time donation history.</p></section><?php
        return ob_get_clean();
    }

    public static function finish( $result ) {
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Recurring settings not saved', array( 'response' => 400, 'back_link' => true ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=icpa-recurring&saved=1' ) ); exit;
    }
    public static function settings_handler() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        ICPA_Security::require_post();
        check_admin_referer( 'icpa_recurring_settings' ); self::finish( self::save_settings( wp_unslash( $_POST ) ) );
    }
    public static function refresh_handler() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        ICPA_Security::require_post();
        check_admin_referer( 'icpa_refresh_plans' ); self::finish( self::refresh( isset( $_POST['environment'] ) && is_string( $_POST['environment'] ) ? sanitize_key( $_POST['environment'] ) : '', ! empty( $_POST['more'] ) ) );
    }
    public static function plan_handler() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        ICPA_Security::require_post();
        check_admin_referer( 'icpa_recurring_plan' ); self::finish( self::save_plan( isset( $_POST['environment'] ) ? wp_unslash( $_POST['environment'] ) : '', isset( $_POST['cadence'] ) ? wp_unslash( $_POST['cadence'] ) : '', isset( $_POST['plan_id'] ) ? wp_unslash( $_POST['plan_id'] ) : '' ) );
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $o = self::options(); $cache = get_option( self::PLANS, array() );
        echo '<div class="wrap"><h1>Recurring giving</h1><p>Configure recurring options for general donations. Campaign checkout remains one-time. Create fixed-price subscription plans in Square, load them here, then choose which intervals to offer.</p><div class="notice notice-info"><p>Square manages recurring charges, receipts, cancellations and subscription records. Renewals are not synchronised into this plugin’s one-time donation ledger or campaign totals. Disabling an option removes it from this plugin; deactivate checkout links or cancel existing subscriptions in Square.</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="icpa_recurring_settings">';
        wp_nonce_field( 'icpa_recurring_settings' );
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( $o['enabled'], 1, false ) . '> Enable recurring general donations</label></p><p><label><input type="checkbox" name="one_time" value="1" ' . checked( $o['one_time'], 1, false ) . '> Offer one-time general donations with custom amounts</label></p><fieldset><legend>Intervals donors may choose</legend>';
        foreach ( self::cadences() as $cadence => $label ) { echo '<p><label><input type="checkbox" name="cadences[]" value="' . esc_attr( $cadence ) . '" ' . checked( in_array( $cadence, $o['cadences'], true ), true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
        echo '</fieldset>'; submit_button( 'Save recurring options' ); echo '</form>';
        foreach ( array( 'sandbox', 'production' ) as $mode ) {
            $valid = isset( $cache[$mode]['fingerprint'] ) && hash_equals( $cache[$mode]['fingerprint'], self::fingerprint( $mode ) );
            $plans = $valid && isset( $cache[$mode]['plans'] ) ? $cache[$mode]['plans'] : array();
            echo '<h2>' . esc_html( ucfirst( $mode ) ) . ' subscription plans</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="icpa_refresh_plans"><input type="hidden" name="environment" value="' . esc_attr( $mode ) . '">'; wp_nonce_field( 'icpa_refresh_plans' ); submit_button( 'Load ' . ucfirst( $mode ) . ' plans', 'secondary', 'submit', false );
            if ( $valid && ! empty( $cache[$mode]['cursor'] ) ) { echo ' <button class="button" name="more" value="1">Load next page</button>'; }
            echo '</form><p>Only single-phase, indefinite, static-price plans in your site currency and donation limits are offered. Trials, discounts, proration and scheduled billing anchors are excluded.</p>';
            foreach ( self::cadences() as $cadence => $label ) {
                $saved = isset( $o['links'][$mode][$cadence] ) ? $o['links'][$mode][$cadence] : array();
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="icpa_recurring_plan"><input type="hidden" name="environment" value="' . esc_attr( $mode ) . '"><input type="hidden" name="cadence" value="' . esc_attr( $cadence ) . '">'; wp_nonce_field( 'icpa_recurring_plan' );
                echo '<p><label>' . esc_html( $label ) . ' plan <select name="plan_id"><option value="">Not offered / remove configured plan</option>';
                foreach ( $plans as $plan ) { if ( $plan['cadence'] === $cadence ) { echo '<option value="' . esc_attr( $plan['id'] ) . '" ' . selected( isset( $saved['id'] ) ? $saved['id'] : '', $plan['id'], false ) . '>' . esc_html( $plan['name'] . ' — ' . ICPA_Platform::money( $plan['amount'], $plan['currency'] ) ) . '</option>'; } }
                if ( isset( $saved['id'] ) && ! isset( $plans[$saved['id']] ) ) { echo '<option value="' . esc_attr( $saved['id'] ) . '" selected>' . esc_html( $saved['name'] . ' — saved; reload catalog to check' ) . '</option>'; }
                echo '</select></label> '; submit_button( 'Validate & create checkout', 'secondary', 'submit', false ); echo '</p></form>';
            }
        }
        echo '<p>Saving each plan creates or reuses a subscription payment link in that Square environment. Test the actual recurring signup and cancellation flow in Sandbox before enabling Production. This plugin does not collect or store card details.</p></div>';
    }
}
