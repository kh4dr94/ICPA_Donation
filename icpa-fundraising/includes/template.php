<?php
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class( 'icpa-platform-page' ); ?>>
<?php wp_body_open(); ?>
<a class="icpa-skip" href="#icpa-main">Skip to content</a>
<main id="icpa-main"><?php while ( have_posts() ) { the_post(); the_content(); } ?></main>
<?php wp_footer(); ?>
</body></html>
