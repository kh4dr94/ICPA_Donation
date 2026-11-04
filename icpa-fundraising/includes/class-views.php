<?php
defined( 'ABSPATH' ) || exit;

final class ICPA_Views {
    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_filter( 'the_content', array( __CLASS__, 'single_content' ), 20 );
        add_filter( 'the_content', array( __CLASS__, 'safe_campaign_content' ), 1 );
        add_shortcode( 'icpa_campaigns', array( __CLASS__, 'browse' ) );
        add_shortcode( 'icpa_start_campaign', array( __CLASS__, 'start' ) );
        add_shortcode( 'icpa_account', array( __CLASS__, 'account' ) );
        add_shortcode( 'icpa_donation_form', array( __CLASS__, 'general_form' ) );
        add_filter( 'theme_page_templates', array( __CLASS__, 'page_templates' ) );
        add_filter( 'template_include', array( __CLASS__, 'template' ) );
    }

    public static function page_templates( $templates ) {
        $templates['icpa-fundraising-full-width'] = 'ICPA Fundraising (full width)';
        return $templates;
    }

    public static function template( $template ) {
        if ( 'theme' === ICPA_Platform::options()['layout'] ) { return $template; }
        if ( is_singular( ICPA_Platform::TYPE ) || ( is_page() && 'icpa-fundraising-full-width' === get_page_template_slug() ) ) {
            return dirname( ICPA_FUNDRAISING_FILE ) . '/includes/template.php';
        }
        return $template;
    }

    public static function assets() {
        wp_enqueue_style( 'icpa-fundraising', plugins_url( 'assets/platform.css', ICPA_FUNDRAISING_FILE ), array(), ICPA_Platform::VERSION );
        wp_enqueue_script( 'icpa-fundraising', plugins_url( 'assets/platform.js', ICPA_FUNDRAISING_FILE ), array(), ICPA_Platform::VERSION, true );
    }

    public static function url( $field ) {
        $o = ICPA_Platform::options();
        return $o[$field] ? get_permalink( $o[$field] ) : ( 'browse_page' === $field ? get_post_type_archive_link( ICPA_Platform::TYPE ) : wp_login_url( home_url( '/' ) ) );
    }

    public static function nav() {
        $integrated = 'theme' === ICPA_Platform::options()['layout'];
        $brand = $integrated ? '<a class="icpa-section-label" href="' . esc_url( self::url( 'browse_page' ) ) . '">Donations &amp; Charities</a>' : ( has_custom_logo() ? get_custom_logo() : '<a class="icpa-brand" href="' . esc_url( self::url( 'browse_page' ) ) . '">ICPA<span class="icpa-brand-sub">Donations &amp; Charities</span></a>' );
        $campaign_links = ICPA_Platform::options()['campaigns_enabled'] ? '<a href="' . esc_url( self::url( 'browse_page' ) ) . '">Discover</a><a class="icpa-btn icpa-btn-outline" href="' . esc_url( self::url( 'submit_page' ) ) . '">Start a fundraiser <span aria-hidden="true">›</span></a>' : '';
        return '<nav class="icpa-nav" aria-label="Fundraising">' . $brand . '<div class="icpa-nav-links">' . $campaign_links . '<a href="' . esc_url( self::url( 'account_page' ) ) . '">My account</a></div></nav>';
    }

    public static function donation_form( $campaign = 0 ) {
        if ( ! $campaign && ! ICPA_Recurring::options()['one_time'] && ! ICPA_Recurring::available() ) { return '<div class="icpa-info">No donation options are available right now. Please contact ICPA.</div>'; }
        $o = ICPA_Platform::options();
        $id = wp_unique_id( 'icpa-amount-' );
        $profile = ICPA_Square_Settings::profile();
        $embedded = ! empty( $profile['application_id'] );
        if ( $embedded ) {
            wp_enqueue_script( 'icpa-square-web', 'production' === $o['mode'] ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js', array(), null, true );
            wp_enqueue_script( 'icpa-card-payment', plugins_url( 'assets/card-payment.js', ICPA_FUNDRAISING_FILE ), array( 'icpa-square-web' ), ICPA_Platform::VERSION, true );
        }
        $return = $campaign ? get_permalink( $campaign ) : ( $o['general_page'] ? get_permalink( $o['general_page'] ) : home_url( '/' ) );
        ob_start();
        ?>
        <?php if ( $campaign || ICPA_Recurring::options()['one_time'] ) { ?>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="icpa-donation-form"<?php if ( $embedded ) { ?> data-card-payment="1" data-app-id="<?php echo esc_attr( $profile['application_id'] ); ?>" data-location-id="<?php echo esc_attr( $profile['location_id'] ); ?>" data-currency="<?php echo esc_attr( $o['currency'] ); ?>" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"<?php } ?>>
            <input type="hidden" name="action" value="icpa_checkout"><input type="hidden" name="environment" value="<?php echo esc_attr( $o['mode'] ); ?>"><input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign ); ?>"><input type="hidden" name="checkout_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php wp_nonce_field( 'icpa_checkout' ); ?>
            <label for="<?php echo esc_attr( $id ); ?>">Your donation (<?php echo esc_html( $o['currency'] ); ?>)</label>
            <div class="icpa-amounts" aria-label="Suggested donation amounts"><?php foreach ( $o['suggested_amounts'] as $amount ) { if ( $amount < $o['minimum_amount'] || $amount > $o['maximum_amount'] ) { continue; } ?><button type="button" data-amount="<?php echo esc_attr( number_format( $amount / 100, $amount % 100 ? 2 : 0, '.', '' ) ); ?>" aria-pressed="false"><?php echo esc_html( number_format_i18n( $amount / 100, $amount % 100 ? 2 : 0 ) ); ?></button><?php } ?></div>
            <input id="<?php echo esc_attr( $id ); ?>" type="number" min="<?php echo esc_attr( number_format( $o['minimum_amount'] / 100, 2, '.', '' ) ); ?>" max="<?php echo esc_attr( number_format( $o['maximum_amount'] / 100, 2, '.', '' ) ); ?>" step="0.01" name="amount" required placeholder="Or enter your own amount" inputmode="decimal">
            <?php if ( $embedded ) { ?>
            <label for="<?php echo esc_attr( $id ); ?>-first">First name</label><input id="<?php echo esc_attr( $id ); ?>-first" name="first_name" autocomplete="given-name" maxlength="100" required>
            <label for="<?php echo esc_attr( $id ); ?>-last">Last name</label><input id="<?php echo esc_attr( $id ); ?>-last" name="last_name" autocomplete="family-name" maxlength="100" required>
            <label for="<?php echo esc_attr( $id ); ?>-email">Email</label><input id="<?php echo esc_attr( $id ); ?>-email" type="email" name="email" autocomplete="email" maxlength="254" required>
            <span>Card details</span><div id="<?php echo esc_attr( $id ); ?>-card" class="icpa-card-container"></div>
            <p class="icpa-card-validity icpa-small" role="status" aria-live="polite" hidden></p>
            <p class="icpa-card-status icpa-small" role="status" aria-live="polite">Loading secure card form…</p><noscript>JavaScript is required for secure card payments.</noscript>
            <?php } ?>
            <button class="icpa-btn" type="submit"<?php if ( $embedded ) { ?> disabled<?php } ?>>Donate</button>
            <p class="icpa-small"><?php echo $embedded ? 'One-time donation. Card details are securely handled by Square.' : 'One-time donation. You’ll complete payment on Square.'; ?> <?php if ( ! is_user_logged_in() && $o['account_page'] ) { ?><a href="<?php echo esc_url( wp_login_url( $return ) ); ?>">Sign in first</a> to save it to your donor account.<?php } ?></p>
        </form>
        <?php } if ( ! $campaign ) { echo ICPA_Recurring::forms(); } ?>
        <?php return ob_get_clean();
    }

    public static function general_form( $atts = array() ) {
        $atts = shortcode_atts( array( 'campaign' => 0 ), $atts, 'icpa_donation_form' );
        $campaign = absint( $atts['campaign'] );
        $o = ICPA_Platform::options();
        if ( $campaign ? ! ICPA_Platform::open( $campaign ) : ! $o['general_enabled'] ) { return '<div class="icpa-info">This donation option is currently unavailable.</div>'; }
        ob_start();
        echo self::wrapper() . self::mode_notice();
        ?><section class="icpa-general-widget" aria-label="Make a donation"><span class="icpa-eyebrow">Giving back to the community</span><h2><?php echo esc_html( $campaign ? get_the_title( $campaign ) : $o['donation_title'] ); ?></h2><p><?php echo $campaign || ICPA_Recurring::options()['one_time'] ? 'Choose a suggested amount or enter your own. Every donation goes to ICPA.' : 'Choose a recurring gift below. Every donation goes to ICPA.'; ?></p>
        <?php if ( isset( $_GET['icpa_return'] ) ) { ?><div class="icpa-info" role="status">Welcome back. Payment is confirmed only when Square verifies it. Returning here alone does not confirm a donation.</div><?php } ?>
        <?php echo ICPA_Square::ready() ? self::donation_form( $campaign ) : '<div class="icpa-info">Donations will open once ICPA connects Square.</div>'; ?>
        <div class="icpa-recipient"><strong><?php echo $campaign ? 'Campaign donation' : 'General donation to ICPA'; ?></strong><p><?php echo $campaign ? 'ICPA receives donations and manages this campaign’s distribution of funds.' : 'This supports ICPA’s work without being assigned to a particular campaign.'; ?></p></div></section></div>
        <?php return ob_get_clean();
    }

    public static function wrapper() {
        $o = ICPA_Platform::options();
        $style = '';
        foreach ( array( 'primary_color' => '--icpa-green', 'heading_color' => '--icpa-ink' ) as $field => $variable ) {
            $color = sanitize_hex_color( $o[$field] );
            if ( $color ) { $style .= $variable . ':' . $color . ';'; }
        }
        $color = sanitize_hex_color( $o['primary_color'] );
        if ( $color ) {
            $hex = ltrim( $color, '#' );
            if ( 3 === strlen( $hex ) ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
            $luminance = 0;
            foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
                $channel = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
                $luminance += $weight * ( $channel <= 0.04045 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 ) );
            }
            $style .= '--icpa-on-accent:' . ( $luminance > 0.179 ? '#000000' : '#ffffff' ) . ';';
        }
        return '<div class="icpa-platform ' . ( 'theme' === $o['layout'] ? 'icpa-theme-integrated' : 'icpa-standalone' ) . '" style="' . esc_attr( $style ) . '">';
    }

    public static function mode_notice() {
        return 'sandbox' === ICPA_Platform::options()['mode'] ? '<div class="icpa-test-notice">Test environment · Sandbox donations only. No real funds are raised here.</div>' : '';
    }

    public static function image( $id, $class = '' ) {
        if ( has_post_thumbnail( $id ) ) { return get_the_post_thumbnail( $id, 'large', array( 'class' => $class, 'alt' => '' ) ); }
        $terms = wp_get_post_terms( $id, 'icpa_cause', array( 'fields' => 'slugs' ) );
        $art = ! is_wp_error( $terms ) && in_array( 'education', $terms, true ) ? 'education' : ( ! is_wp_error( $terms ) && in_array( 'health', $terms, true ) ? 'health' : 'community' );
        return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( plugins_url( 'assets/' . $art . '.svg', ICPA_FUNDRAISING_FILE ) ) . '" alt="" loading="lazy">';
    }

    public static function progress( $id ) {
        $stats = ICPA_Platform::stats( $id );
        $raised = (int) $stats['raised'];
        $goal = (int) get_post_meta( $id, '_icpa_goal', true );
        $percent = $goal > 0 ? min( 100, (int) floor( $raised * 100 / $goal ) ) : 0;
        return '<div class="icpa-progress" role="progressbar" aria-label="Fundraising progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $percent ) . '"><span style="width:' . esc_attr( $percent ) . '%"></span></div><div class="icpa-progress-label"><strong>' . esc_html( ICPA_Platform::money( $raised ) ) . '</strong><span>of ' . esc_html( ICPA_Platform::money( $goal ) ) . '</span></div><div class="icpa-small">' . esc_html( number_format_i18n( $stats['donations'] ) ) . ' donations · ' . esc_html( $percent ) . '% funded</div>';
    }

    public static function card( $post ) {
        $id = $post->ID;
        $terms = wp_get_post_terms( $id, 'icpa_cause', array( 'fields' => 'names' ) );
        $cause = ! is_wp_error( $terms ) && $terms ? $terms[0] : 'Community';
        $excerpt = has_excerpt( $id ) ? get_the_excerpt( $id ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 18 );
        return '<article class="icpa-card"><a class="icpa-card-image" tabindex="-1" aria-hidden="true" href="' . esc_url( get_permalink( $id ) ) . '">' . self::image( $id ) . '<span class="icpa-tag">' . esc_html( $cause ) . '</span></a><div class="icpa-card-body"><div class="icpa-small">By ' . esc_html( get_the_author_meta( 'display_name', $post->post_author ) ) . '</div><h3><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3><p>' . esc_html( $excerpt ) . '</p>' . self::progress( $id ) . '</div></article>';
    }

    public static function browse( $atts = array() ) {
        if ( ! ICPA_Platform::options()['campaigns_enabled'] ) { return '<div class="icpa-info">Campaigns are currently unavailable. You can still use any available general donation form.</div>'; }
        $atts = shortcode_atts( array( 'hero' => 'auto', 'intro' => 'auto', 'faq' => 'yes' ), $atts, 'icpa_campaigns' );
        foreach ( array( 'hero', 'intro' ) as $section ) {
            if ( 'auto' === $atts[$section] ) { $atts[$section] = 'theme' === ICPA_Platform::options()['layout'] ? 'no' : 'yes'; }
        }
        $page = isset( $_GET['icpa_page'] ) ? ICPA_Security::page( $_GET['icpa_page'] ) : 1;
        $search = isset( $_GET['icpa_search'] ) && is_string( $_GET['icpa_search'] ) ? wp_html_excerpt( sanitize_text_field( wp_unslash( $_GET['icpa_search'] ) ), 160, '' ) : '';
        $cause = isset( $_GET['icpa_cause'] ) && is_string( $_GET['icpa_cause'] ) ? sanitize_title( wp_unslash( $_GET['icpa_cause'] ) ) : '';
        $args = array( 'post_type' => ICPA_Platform::TYPE, 'post_status' => 'publish', 'posts_per_page' => 9, 'paged' => $page, 's' => $search );
        if ( $cause ) { $args['tax_query'] = array( array( 'taxonomy' => 'icpa_cause', 'field' => 'slug', 'terms' => $cause ) ); }
        $query = new WP_Query( $args );
        $terms = get_terms( array( 'taxonomy' => 'icpa_cause', 'hide_empty' => false ) );
        ob_start();
        ?>
        <?php echo self::wrapper(); ?>
            <?php echo self::nav(); // All dynamic fields escaped in helpers. ?>
            <?php echo self::mode_notice(); ?>
            <?php if ( 'no' !== $atts['hero'] ) { ?><header class="icpa-hero <?php echo ICPA_Platform::options()['hero_image_id'] ? 'icpa-hero-with-image' : 'icpa-hero-pattern'; ?>">
                <div class="icpa-hero-copy"><span class="icpa-eyebrow">ICPA community campaigns</span><h1><?php echo esc_html( ICPA_Platform::options()['intro_title'] ); ?></h1><p><?php echo esc_html( ICPA_Platform::options()['intro_text'] ); ?></p><a class="icpa-btn" href="#icpa-discover">Find a cause <span aria-hidden="true">›</span></a><div class="icpa-hero-foot">Community-led campaigns. Funds received and managed by ICPA.</div></div>
                <div class="icpa-hero-art" aria-hidden="true"><?php if ( ICPA_Platform::options()['hero_image_id'] ) { echo wp_get_attachment_image( ICPA_Platform::options()['hero_image_id'], 'large', false, array( 'alt' => '' ) ); } else { ?><img src="<?php echo esc_url( plugins_url( 'assets/community.svg', ICPA_FUNDRAISING_FILE ) ); ?>" alt=""><?php } ?></div>
            </header><?php } ?>
            <div class="icpa-trust"><span>✳ Built around community</span><span>› Checkout hosted by Square</span><span>♡ Every contribution matters</span></div>
            <?php if ( 'no' !== $atts['intro'] ) { ?><section class="icpa-giving-intro" aria-label="Giving back to the community"><div><span class="icpa-eyebrow">Donate &amp; support</span><h2>Giving Back To<br>The Community</h2><p>Support the causes that bring our community together. Choose a campaign and help ICPA provide practical support where it is needed.</p></div><div class="icpa-giving-points"><h3>Your generosity. A meaningful impact.</h3><p><span aria-hidden="true">✓</span> Funds received and managed by ICPA</p><p><span aria-hidden="true">✓</span> Secure payment on Square’s hosted checkout</p><p><span aria-hidden="true">✓</span> Give as a guest or follow your impact with an account</p></div></section><?php } ?>
            <section id="icpa-discover" class="icpa-discover"><div class="icpa-section-head"><div><span class="icpa-eyebrow">Donate &amp; support</span><h2>Support A Campaign</h2></div><form class="icpa-search" action="<?php echo esc_url( self::url( 'browse_page' ) ); ?>" method="get">
                <?php if ( ! get_option( 'permalink_structure' ) && ICPA_Platform::options()['browse_page'] ) { ?><input type="hidden" name="page_id" value="<?php echo esc_attr( ICPA_Platform::options()['browse_page'] ); ?>"><?php } ?>
                <label class="icpa-sr-only" for="icpa-search">Search campaigns</label><input id="icpa-search" name="icpa_search" type="search" placeholder="Find a campaign…" value="<?php echo esc_attr( $search ); ?>"><button type="submit" aria-label="Search campaigns">›</button><?php if ( $cause ) { ?><input type="hidden" name="icpa_cause" value="<?php echo esc_attr( $cause ); ?>"><?php } ?>
            </form></div>
            <div class="icpa-filters" aria-label="Filter by cause"><a class="<?php echo '' === $cause ? 'is-active' : ''; ?>" href="<?php echo esc_url( remove_query_arg( array( 'icpa_cause', 'icpa_page' ) ) ); ?>" <?php echo '' === $cause ? 'aria-current="true"' : ''; ?>>All causes</a>
            <?php if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { ?><a class="<?php echo $cause === $term->slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'icpa_cause' => $term->slug, 'icpa_page' => false ) ) ); ?>" <?php echo $cause === $term->slug ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $term->name ); ?></a><?php } } ?></div>
            <div class="icpa-grid"><?php foreach ( $query->posts as $post ) { echo self::card( $post ); } ?></div>
            <?php if ( ! $query->have_posts() ) { ?><div class="icpa-empty"><h3>No campaigns found</h3><p>Try another search or be the first to start a fundraiser.</p></div><?php } ?>
            <?php echo '<div class="icpa-pagination">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'icpa_page', '%#%' ), 'format' => '', 'current' => $page, 'total' => $query->max_num_pages ) ) ) . '</div>'; ?>
            </section><section class="icpa-callout"><div><span class="icpa-eyebrow">Have a cause close to your heart?</span><h2>Be someone’s reason to hope.</h2><p>Start a campaign. Share your story. Bring your community together.</p></div><a class="icpa-btn" href="<?php echo esc_url( self::url( 'submit_page' ) ); ?>">Start a fundraiser ›</a></section>
            <?php if ( 'no' !== $atts['faq'] ) { ?><section class="icpa-faq"><div><span class="icpa-eyebrow">Giving with confidence</span><h2>Frequently Asked<br>Questions</h2><p>A little more about supporting an ICPA fundraiser.</p></div><div class="icpa-faq-items"><details><summary>Where does my donation go?</summary><p>Payments go to ICPA’s Square account. ICPA reviews campaigns and manages the distribution of funds.</p></details><details><summary>Can I donate without an account?</summary><p>Yes. You can give as a guest. Sign in before starting checkout if you would like the donation to appear in your donor account.</p></details><details><summary>When does my donation appear in the campaign total?</summary><p>After Square confirms a completed payment. Refunded amounts are deducted from the total. Returning from checkout alone does not confirm a payment.</p></details></div></section><?php } ?>
            <footer class="icpa-footer">ICPA Fundraising <span>Generosity brings us together.</span></footer>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function single_content( $content ) {
        if ( is_singular( ICPA_Platform::TYPE ) && in_the_loop() && is_main_query() ) { return self::campaign( get_the_ID() ); }
        return $content;
    }

    public static function safe_campaign_content( $content ) {
        return is_singular( ICPA_Platform::TYPE ) && in_the_loop() && is_main_query() ? ICPA_Security::safe_story( $content ) : $content;
    }

    public static function campaign( $id ) {
        $post = get_post( $id );
        if ( ! $post || ICPA_Platform::TYPE !== $post->post_type || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $id ) ) ) { return ''; }
        $open = ICPA_Platform::open( $id );
        $ready = ICPA_Square::ready();
        $deadline = get_post_meta( $id, '_icpa_deadline', true );
        ob_start();
        ?>
        <?php echo self::wrapper() . self::nav() . self::mode_notice(); ?>
            <div class="icpa-breadcrumb"><a href="<?php echo esc_url( self::url( 'browse_page' ) ); ?>">← All campaigns</a></div>
            <header class="icpa-campaign-heading"><span class="icpa-eyebrow">Community-led. Together for good.</span><h1><?php echo esc_html( get_the_title( $id ) ); ?></h1><p>Organised by <?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?> · Funds received and managed by ICPA</p></header>
            <?php if ( isset( $_GET['icpa_return'] ) ) { ?><div class="icpa-info" role="status">Welcome back. Your donation will appear after Square confirms payment. Returning to this page does not confirm a successful payment.</div><?php } ?>
            <div class="icpa-campaign-layout"><div><div class="icpa-campaign-cover"><?php echo self::image( $id ); ?></div><section class="icpa-story"><span class="icpa-eyebrow">The story behind the cause</span><h2>About this fundraiser</h2><?php echo wpautop( ICPA_Security::safe_story( $post->post_content ) ); ?></section></div>
            <aside class="icpa-donate-panel" aria-label="Support this campaign"><h2>Make good happen.</h2><?php echo self::progress( $id ); ?>
                <?php if ( $deadline ) { ?><p class="icpa-small">Campaign ends <?php echo esc_html( $deadline ); ?> (<?php echo esc_html( wp_timezone_string() ?: 'UTC' ); ?>)</p><?php } ?>
                <?php if ( $open && $ready ) { ?>
                <?php echo self::donation_form( $id ); ?>
                <?php } else { ?><div class="icpa-info"><?php echo $open ? 'Donations will open once ICPA connects Square.' : 'This campaign is not accepting donations.'; ?></div><?php } ?>
                <div class="icpa-recipient"><strong>Where your donation goes</strong><p>Payments go to ICPA’s Square account. ICPA reviews campaigns and manages the distribution of funds.</p></div>
                <button class="icpa-share" type="button" data-share="<?php echo esc_url( get_permalink( $id ) ); ?>">Copy campaign link ›</button><span class="icpa-share-status icpa-small" role="status"></span>
            </aside></div><footer class="icpa-footer">ICPA Fundraising <span>Generosity brings us together.</span></footer>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function login_prompt( $reason ) {
        $return = get_permalink() ?: home_url( '/' );
        $html = '<div class="icpa-empty"><h2>' . esc_html( $reason ) . '</h2><p>Your WordPress account is your donor and organiser account.</p><a class="icpa-btn" href="' . esc_url( wp_login_url( $return ) ) . '">Sign in</a>';
        if ( get_option( 'users_can_register' ) ) { $html .= ' <a class="icpa-btn icpa-btn-outline" href="' . esc_url( wp_registration_url() ) . '">Create an account</a>'; }
        return $html . '<p><a href="' . esc_url( wp_lostpassword_url( $return ) ) . '">Forgot your password?</a></p></div>';
    }

    public static function start() {
        if ( ! ICPA_Platform::options()['campaigns_enabled'] ) { return '<div class="icpa-info">Campaign submissions are currently disabled.</div>'; }
        ob_start();
        echo self::wrapper() . self::nav() . self::mode_notice();
        if ( ! is_user_logged_in() ) { echo self::login_prompt( 'Sign in to start your fundraiser' ) . '</div>'; return ob_get_clean(); }
        $terms = get_terms( array( 'taxonomy' => 'icpa_cause', 'hide_empty' => false ) );
        ?>
        <header class="icpa-form-heading"><span class="icpa-eyebrow">A story worth sharing</span><h1>Start something good.</h1><p>Tell us about your cause. ICPA will review your campaign before it goes live.</p></header>
        <form class="icpa-campaign-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="icpa_submit_campaign"><?php wp_nonce_field( 'icpa_submit_campaign' ); ?>
            <label for="icpa-title">Campaign title</label><input id="icpa-title" name="title" type="text" minlength="5" maxlength="160" placeholder="Give your cause a clear, meaningful title" required>
            <div class="icpa-form-row"><div><label for="icpa-goal">Fundraising goal (<?php echo esc_html( ICPA_Platform::options()['currency'] ); ?>)</label><input id="icpa-goal" name="goal" type="number" min="1" max="1000000" step="0.01" required placeholder="5000"></div><div><label for="icpa-cause">Cause</label><select id="icpa-cause" name="cause" required><option value="">Choose a cause</option><?php if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { ?><option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option><?php } } ?></select></div></div>
            <label for="icpa-story">Your story</label><textarea id="icpa-story" name="story" rows="8" minlength="80" maxlength="16000" placeholder="Who will this help? How will the funds be used? Why does it matter to you?" required></textarea>
            <label for="icpa-deadline">End date (optional)</label><input id="icpa-deadline" name="deadline" type="date" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
            <p class="icpa-small">An ICPA administrator can add a cover image during review. Published campaign changes are managed by ICPA.</p>
            <label class="icpa-checkbox"><input type="checkbox" name="consent" value="1" required><span>I confirm this story is accurate and understand that ICPA receives donations and manages distribution.</span></label>
            <button class="icpa-btn" type="submit">Submit for review ›</button>
        </form></div>
        <?php
        return ob_get_clean();
    }

    public static function account() {
        global $wpdb;
        $user = wp_get_current_user();
        ob_start();
        echo self::wrapper() . self::nav() . self::mode_notice();
        if ( ! $user->ID ) { echo self::login_prompt( 'Your generosity, all in one place.' ) . '</div>'; return ob_get_clean(); }
        $table = ICPA_Platform::table();
        $page = isset( $_GET['icpa_donations_page'] ) ? ICPA_Security::page( $_GET['icpa_donations_page'] ) : 1;
        $offset = ( $page - 1 ) * 10;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE user_id=%d AND mode=%s AND status<>'linked' ORDER BY id DESC LIMIT 10 OFFSET %d", $user->ID, ICPA_Platform::options()['mode'], $offset ), ARRAY_A );
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE user_id=%d AND mode=%s AND status<>'linked'", $user->ID, ICPA_Platform::options()['mode'] ) );
        $campaign_page = isset( $_GET['icpa_organiser_page'] ) ? ICPA_Security::page( $_GET['icpa_organiser_page'] ) : 1;
        $campaigns = new WP_Query( array( 'post_type' => ICPA_Platform::TYPE, 'author' => $user->ID, 'post_status' => array( 'publish', 'pending', 'draft', 'private' ), 'posts_per_page' => 10, 'paged' => $campaign_page ) );
        ?>
        <header class="icpa-form-heading"><span class="icpa-eyebrow">Your community. Your impact.</span><h1>Hello, <?php echo esc_html( $user->display_name ); ?>.</h1><p>Follow your donations<?php echo ICPA_Platform::options()['campaigns_enabled'] ? ' and the campaigns you’ve started' : ''; ?>. <a href="<?php echo esc_url( wp_logout_url( self::url( 'account_page' ) ) ); ?>">Sign out</a></p></header>
        <?php if ( isset( $_GET['icpa_submitted'] ) ) { ?><div class="icpa-info" role="status">Campaign submitted. ICPA will review it before publication.</div><?php } ?>
        <section class="icpa-account-section"><h2>Your donations</h2><p class="icpa-small">One-time donations started while signed in appear here. Pending means payment has not been confirmed; it may have been abandoned.</p>
            <?php if ( ICPA_Recurring::options()['enabled'] ) { ?><p class="icpa-small">This history covers one-time donations. Recurring payment records and subscription changes are managed by ICPA through Square.</p><?php } ?>
            <?php if ( ! $rows ) { ?><div class="icpa-empty"><p>Your first act of generosity starts here.</p><a href="<?php echo esc_url( self::url( 'browse_page' ) ); ?>">Discover a campaign ›</a></div><?php } else { ?>
            <div class="icpa-table-wrap"><table class="icpa-table"><thead><tr><th>Campaign</th><th>Donation</th><th>Status</th><th>Started (UTC)</th></tr></thead><tbody>
            <?php foreach ( $rows as $row ) { $campaign = $row['campaign_id'] ? get_post( $row['campaign_id'] ) : null; ?><tr><td><?php if ( $campaign && 'publish' === $campaign->post_status ) { ?><a href="<?php echo esc_url( get_permalink( $campaign ) ); ?>"><?php echo esc_html( $campaign->post_title ); ?></a><?php } else { echo esc_html( ICPA_Platform::donation_label( (int) $row['campaign_id'] ) ); } ?></td><td><?php echo esc_html( ICPA_Platform::money( (int) $row['amount'], $row['currency'] ) ); ?><?php if ( (int) $row['refunded'] > 0 ) { ?><br><small><?php echo esc_html( ICPA_Platform::money( (int) $row['refunded'], $row['currency'] ) ); ?> refunded</small><?php } ?></td><td><span class="icpa-status"><?php echo esc_html( (int) $row['refunded'] === (int) $row['amount'] ? 'Refunded' : ucfirst( $row['status'] ) ); ?></span></td><td><?php echo esc_html( $row['created_at'] ); ?></td></tr><?php } ?>
            </tbody></table></div><?php echo '<div class="icpa-pagination">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'icpa_donations_page', '%#%' ), 'format' => '', 'current' => $page, 'total' => (int) ceil( $count / 10 ) ) ) ) . '</div>'; } ?>
        </section><?php if ( ICPA_Platform::options()['campaigns_enabled'] ) { ?><section class="icpa-account-section"><div class="icpa-section-head"><h2>Your campaigns</h2><a class="icpa-btn icpa-btn-outline" href="<?php echo esc_url( self::url( 'submit_page' ) ); ?>">Start a fundraiser ›</a></div>
            <?php if ( ! $campaigns->posts ) { ?><p>You haven’t submitted a campaign yet.</p><?php } foreach ( $campaigns->posts as $campaign ) { ?><div class="icpa-organiser-row"><div><strong><?php if ( 'publish' === $campaign->post_status ) { ?><a href="<?php echo esc_url( get_permalink( $campaign ) ); ?>"><?php echo esc_html( $campaign->post_title ); ?></a><?php } else { echo esc_html( $campaign->post_title ); } ?></strong><p class="icpa-small"><?php echo esc_html( 'pending' === $campaign->post_status ? 'Awaiting ICPA review' : ucfirst( $campaign->post_status ) ); ?> · Goal <?php echo esc_html( ICPA_Platform::money( (int) get_post_meta( $campaign->ID, '_icpa_goal', true ) ) ); ?></p></div><span class="icpa-status"><?php echo esc_html( 'publish' === $campaign->post_status ? 'Published' : 'In review' ); ?></span></div><?php } ?>
            <?php echo '<div class="icpa-pagination">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'icpa_organiser_page', '%#%' ), 'format' => '', 'current' => $campaign_page, 'total' => $campaigns->max_num_pages ) ) ) . '</div>'; ?>
        </section><?php } ?><p class="icpa-small">Square handles payment receipts. For corrections, refunds or campaign updates, contact ICPA.</p></div>
        <?php
        return ob_get_clean();
    }
}
