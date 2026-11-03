<?php
/**
 * Plugin Name: ICPA Square Donations
 * Description: Add donation buttons linking to your Square hosted checkout.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: icpa-square-donations
 */

defined( 'ABSPATH' ) || exit;

final class ICPA_Square_Donations {
    const OPTION = 'icpa_square_donations';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'settings' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
        add_shortcode( 'icpa_donate', array( __CLASS__, 'shortcode' ) );
    }

    public static function defaults() {
        return array( 'url' => '', 'label' => __( 'Donate', 'icpa-square-donations' ), 'color' => '#146044' );
    }

    /** Accept only HTTPS checkout links on Square-controlled domains. */
    public static function valid_url( $url ) {
        if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\x7f]|%0[0-9a-f]|%1[0-9a-f]|%7f/i', $url ) ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
            return false;
        }
        $host = strtolower( $parts['host'] );
        return 'square.link' === $host || 'squareup.com' === $host || 'checkout.square.site' === $host || (bool) preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.square\.site$/', $host );
    }

    public static function sanitize( $input ) {
        $old = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
        if ( ! is_array( $input ) ) {
            return $old;
        }
        $url = isset( $input['url'] ) && is_string( $input['url'] ) ? trim( $input['url'] ) : '';
        $clean_url = esc_url_raw( $url, array( 'https' ) );
        if ( '' !== $url && ( ! self::valid_url( $url ) || ! self::valid_url( $clean_url ) ) ) {
            add_settings_error( self::OPTION, 'invalid_url', __( 'Enter an HTTPS Square payment link. Your previous link was kept.', 'icpa-square-donations' ) );
            $url = $old['url'];
        } else {
            $url = $clean_url;
        }
        $label = isset( $input['label'] ) && is_string( $input['label'] ) ? sanitize_text_field( $input['label'] ) : '';
        $color = isset( $input['color'] ) && is_string( $input['color'] ) ? sanitize_hex_color( $input['color'] ) : '';
        return array( 'url' => $url, 'label' => '' !== $label ? $label : self::defaults()['label'], 'color' => $color ? $color : '#146044' );
    }

    public static function settings() {
        register_setting( self::OPTION, self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => self::defaults() ) );
    }

    public static function menu() {
        add_options_page( __( 'Square Donations', 'icpa-square-donations' ), __( 'Square Donations', 'icpa-square-donations' ), 'manage_options', 'icpa-square-donations', array( __CLASS__, 'page' ) );
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $options = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Square Donations', 'icpa-square-donations' ); ?></h1>
            <?php settings_errors( self::OPTION ); ?>
            <p><?php esc_html_e( 'Create a donation payment link in Square, then paste it below. Amounts, currency, available payment methods and any recurring options are controlled by Square.', 'icpa-square-donations' ); ?></p>
            <form action="options.php" method="post">
                <?php settings_fields( self::OPTION ); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="icpa-url"><?php esc_html_e( 'Square payment link', 'icpa-square-donations' ); ?></label></th>
                        <td><input type="url" class="regular-text" id="icpa-url" name="icpa_square_donations[url]" value="<?php echo esc_attr( $options['url'] ); ?>" placeholder="https://square.link/u/…" aria-describedby="icpa-url-help">
                        <p class="description" id="icpa-url-help"><?php esc_html_e( 'Use a Square HTTPS link. Leave blank to hide donation buttons.', 'icpa-square-donations' ); ?></p></td></tr>
                    <tr><th scope="row"><label for="icpa-label"><?php esc_html_e( 'Button text', 'icpa-square-donations' ); ?></label></th>
                        <td><input type="text" class="regular-text" id="icpa-label" name="icpa_square_donations[label]" value="<?php echo esc_attr( $options['label'] ); ?>"></td></tr>
                    <tr><th scope="row"><label for="icpa-color"><?php esc_html_e( 'Button colour', 'icpa-square-donations' ); ?></label></th>
                        <td><input type="color" id="icpa-color" name="icpa_square_donations[color]" value="<?php echo esc_attr( $options['color'] ); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2><?php esc_html_e( 'Add to your website', 'icpa-square-donations' ); ?></h2>
            <p><?php esc_html_e( 'Add a Shortcode block to a page or post and enter:', 'icpa-square-donations' ); ?> <code>[icpa_donate]</code></p>
            <p><?php esc_html_e( 'Optional custom button text:', 'icpa-square-donations' ); ?> <code>[icpa_donate label="Support our community"]</code></p>
            <p><?php esc_html_e( 'Checkout opens on Square in the same tab. This plugin does not record donations or confirm payments. Use your Square dashboard to check payments and receipts.', 'icpa-square-donations' ); ?></p>
        </div>
        <?php
    }

    public static function styles() {
        wp_enqueue_style( 'icpa-square-donations', plugins_url( 'assets/donations.css', __FILE__ ), array(), '1.0.0' );
    }

    public static function shortcode( $atts = array() ) {
        $options = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
        if ( ! self::valid_url( $options['url'] ) ) {
            return '';
        }
        $atts = shortcode_atts( array( 'label' => $options['label'] ), $atts, 'icpa_donate' );
        $label = is_string( $atts['label'] ) ? trim( $atts['label'] ) : '';
        if ( '' === $label ) {
            $label = self::defaults()['label'];
        }
        $color = sanitize_hex_color( $options['color'] );
        $color = $color ? $color : '#146044';
        // Choose the text colour with the higher WCAG contrast ratio.
        $hex = ltrim( $color, '#' );
        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $luminance = 0;
        foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
            $channel = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
            $luminance += $weight * ( $channel <= 0.04045 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 ) );
        }
        $foreground = $luminance > 0.179 ? '#000000' : '#ffffff';
        return sprintf( '<a class="icpa-donate-button" href="%s" style="background-color:%s;color:%s">%s</a>', esc_url( $options['url'], array( 'https' ) ), esc_attr( $color ), esc_attr( $foreground ), esc_html( $label ) );
    }
}

ICPA_Square_Donations::init();
