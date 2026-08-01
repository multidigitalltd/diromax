<?php
/**
 * Diromax theme bootstrap.
 *
 * Loads the theme modules and declares theme support. Every module under /inc has
 * a single responsibility; nothing here does work of its own.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DX_VERSION', '2.0.0' );
define( 'DX_DIR', get_template_directory() );
define( 'DX_URI', get_template_directory_uri() );

require_once DX_DIR . '/inc/helpers.php';
require_once DX_DIR . '/inc/content.php';
require_once DX_DIR . '/inc/assets.php';
require_once DX_DIR . '/inc/accessibility.php';
require_once DX_DIR . '/inc/seo.php';
require_once DX_DIR . '/inc/customizer.php';
require_once DX_DIR . '/inc/leads.php';
require_once DX_DIR . '/inc/projects.php';

if ( is_admin() ) {
	// Content installer is an admin-only screen; never parsed on front-end requests.
	require_once DX_DIR . '/inc/setup.php';
}

/**
 * Theme support and registrations.
 */
function dx_setup() {
	load_theme_textdomain( 'diromax', DX_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 46,
			'flex-width'  => true,
			'flex-height' => true,
		)
	);
	add_theme_support(
		'html5',
		array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	register_nav_menus(
		array(
			'primary' => __( 'תפריט ראשי', 'diromax' ),
			'footer'  => __( 'תפריט פוטר', 'diromax' ),
		)
	);

	// Only the sizes the templates actually request — no orphan derivatives on upload.
	add_image_size( 'dx-hero', 900, 1200, true );
	add_image_size( 'dx-square', 800, 800, true );
	add_image_size( 'dx-card', 800, 500, true );
}
add_action( 'after_setup_theme', 'dx_setup' );

/**
 * Content width matches the design's 1280px container.
 */
function dx_content_width() {
	$GLOBALS['content_width'] = 1280;
}
add_action( 'after_setup_theme', 'dx_content_width', 0 );

/**
 * Drop the derivatives core generates but this theme never renders.
 *
 * @param array $sizes Intermediate image sizes.
 * @return array
 */
function dx_trim_image_sizes( $sizes ) {
	unset( $sizes['medium_large'], $sizes['1536x1536'], $sizes['2048x2048'] );
	return $sizes;
}
add_filter( 'intermediate_image_sizes_advanced', 'dx_trim_image_sizes' );

/**
 * Body class carries the current view, so the nav underline and any page-scoped
 * rule can key off it without a second lookup.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function dx_body_class( $classes ) {
	$classes[] = 'dx-page-' . dx_current_view();
	return $classes;
}
add_filter( 'body_class', 'dx_body_class' );

/**
 * Archive listings use the article card grid, 12 per page.
 *
 * @param WP_Query $query Query object.
 */
function dx_posts_per_page( $query ) {
	if ( ! is_admin() && $query->is_main_query() && ( $query->is_home() || $query->is_archive() ) ) {
		$query->set( 'posts_per_page', 12 );
	}
}
add_action( 'pre_get_posts', 'dx_posts_per_page' );

/**
 * Excerpt length tuned for the article card.
 *
 * @return int
 */
function dx_excerpt_length() {
	return 26;
}
add_filter( 'excerpt_length', 'dx_excerpt_length' );

/**
 * Ellipsis instead of the default "[...]".
 *
 * @return string
 */
function dx_excerpt_more() {
	return '…';
}
add_filter( 'excerpt_more', 'dx_excerpt_more' );
