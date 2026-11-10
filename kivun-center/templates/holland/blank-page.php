<?php
/**
 * Template: the Holland questionnaire's page, without the site's header,
 * footer or page title.
 *
 * Chosen by Kivun_Holland::blank_template() for any page carrying
 * [kivun_holland]. Like Elementor's "Canvas" template, it keeps WordPress's
 * head and footer hooks — so the page's styles and scripts, Elementor's among
 * them, load as usual — and draws the page's own content and nothing else.
 *
 * The background and the space around the card are the design handoff's
 * frame: #f3f1f1, 48px above and below on a desktop, 24px on a phone.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'kivun-holland-page' ); ?>>
<?php wp_body_open(); ?>
<main class="kivun-holland-page__main" id="content">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
