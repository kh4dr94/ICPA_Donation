<?php
/**
 * Plugin Name: ICPA Donations & Campaigns
 * Description: Embedded Square card donations, configurable Square-hosted recurring giving and optional campaigns inside WordPress.
 * Version: 0.10.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: icpa-fundraising
 */
defined( 'ABSPATH' ) || exit;
define( 'ICPA_FUNDRAISING_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-security.php';
require_once __DIR__ . '/includes/class-platform.php';
require_once __DIR__ . '/includes/class-square.php';
require_once __DIR__ . '/includes/class-square-settings.php';
require_once __DIR__ . '/includes/class-recurring.php';
require_once __DIR__ . '/includes/class-fees.php';
require_once __DIR__ . '/includes/class-views.php';
register_activation_hook( __FILE__, array( 'ICPA_Platform', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
register_deactivation_hook( __FILE__, array( 'ICPA_Security', 'deactivate' ) );
ICPA_Security::init();
ICPA_Platform::init();
ICPA_Square::init();
ICPA_Square_Settings::init();
ICPA_Recurring::init();
ICPA_Views::init();
