<?php
/**
 * Asset loading.
 *
 * Rules this file exists to enforce (Multi Digital standard):
 * - Nothing loads on a page that does not need it.
 * - No third-party host: fonts are self-hosted, so there is no external request.
 * - Minified files are served in production, sources when SCRIPT_DEBUG is on.
 * - Scripts are deferred; the stylesheet is the only render-blocking resource.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a minified asset, falling back to the source file.
 *
 * @param string $min_relative Path to the minified file, relative to the theme root.
 * @param string $src_relative Path to the source file, relative to the theme root.
 * @return string Absolute URL.
 */
function dx_asset_url( $min_relative, $src_relative ) {
	$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

	if ( ! $debug && file_exists( DX_DIR . '/' . $min_relative ) ) {
		return DX_URI . '/' . $min_relative;
	}

	return DX_URI . '/' . $src_relative;
}

/**
 * Does the current request render editor content that needs the block stylesheet?
 *
 * @return bool
 */
function dx_needs_block_styles() {
	if ( ! is_singular() ) {
		return false;
	}

	$post = get_queried_object();

	return ( $post instanceof WP_Post ) && has_blocks( $post );
}

/**
 * Does the current request render a lead form?
 *
 * Exactly three views do. This checks the templates directly rather than
 * dx_current_view(), whose 'improve' value is also the catch-all fallback for
 * every ordinary page — which would pull the script onto pages with no form.
 *
 * @return bool
 */
function dx_needs_lead_script() {
	return (
		is_front_page()
		|| dx_is_template( 'template-improvement.php' )
		|| dx_is_template( 'template-contact.php' )
	);
}

/**
 * Front-end styles and scripts.
 */
function dx_enqueue_assets() {
	// --- Stylesheet -------------------------------------------------------
	wp_enqueue_style(
		'dx-style',
		dx_asset_url( 'style.min.css', 'style.css' ),
		array(),
		DX_VERSION
	);

	// A child theme's own style.css still loads, layered on top.
	if ( is_child_theme() ) {
		wp_enqueue_style( 'dx-child-style', get_stylesheet_uri(), array( 'dx-style' ), DX_VERSION );
	}

	// --- Scripts ----------------------------------------------------------
	if ( dx_a11y_enabled() ) {
		wp_enqueue_script(
			'dx-a11y',
			dx_asset_url( 'assets/js/a11y.min.js', 'assets/js/a11y.js' ),
			array(),
			DX_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	if ( dx_needs_lead_script() ) {
		wp_enqueue_script(
			'dx-forms',
			dx_asset_url( 'assets/js/forms.min.js', 'assets/js/forms.js' ),
			array(),
			DX_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		/*
		 * Only endpoints and strings — no nonce here on purpose. Under a full-page
		 * cache (LiteSpeed, WP Rocket, Cloudflare) a printed nonce eventually goes
		 * stale, so the script pulls a fresh one from the REST route on first use.
		 */
		wp_add_inline_script(
			'dx-forms',
			'window.dxForms=' . wp_json_encode(
				array(
					'submit'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'nonce'   => esc_url_raw( rest_url( 'diromax/v1/nonce' ) ),
					'strings' => array(
						'sending'    => __( 'שולח…', 'diromax' ),
						'error'      => __( 'משהו לא עבד. אפשר להתקשר אלינו ישירות.', 'diromax' ),
						// The confirmation copy is editable under "תוכן האתר".
						'thanksTtl'  => (string) dx_content( 'form.ok_light_h' ),
						'thanksTxt'  => (string) dx_content( 'form.ok_light_p' ),
						'thanksTtl2' => (string) dx_content( 'form.ok_blue_h' ),
						'thanksTxt2' => (string) dx_content( 'form.ok_blue_p' ),
					),
				)
			) . ';',
			'before'
		);
	}
}
add_action( 'wp_enqueue_scripts', 'dx_enqueue_assets' );

/**
 * Preload the two fonts that paint above the fold, so the LCP heading does not
 * wait for the stylesheet to discover them.
 */
function dx_preload_fonts() {
	$fonts = array(
		DX_URI . '/assets/fonts/heebo-hebrew.woff2'      => 'font/woff2',
		DX_URI . '/assets/fonts/anomalia-bold-aaa.woff'  => 'font/woff',
	);

	foreach ( $fonts as $url => $type ) {
		printf(
			'<link rel="preload" href="%1$s" as="font" type="%2$s" crossorigin>' . "\n",
			esc_url( $url ),
			esc_attr( $type )
		);
	}
}
add_action( 'wp_head', 'dx_preload_fonts', 1 );

/**
 * Strip core output this theme does not use.
 *
 * Each removal is a real request or a real payload the visitor would otherwise
 * download for nothing.
 */
function dx_dequeue_unused() {
	if ( is_admin() ) {
		return;
	}

	// Emoji: a script plus an inline stylesheet, for a feature modern browsers ship.
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	wp_dequeue_style( 'wp-emoji-styles' );
	add_filter( 'emoji_svg_url', '__return_false' );

	// oEmbed discovery + the embed script: this site is not embedded elsewhere.
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	wp_deregister_script( 'wp-embed' );

	// Header noise with no consumer here.
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );

	// Block CSS only where editor blocks are actually rendered.
	if ( ! dx_needs_block_styles() ) {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'classic-theme-styles' );
	}

	/*
	 * Global styles is ~9KB of inline CSS derived from core defaults. This theme
	 * ships no theme.json and styles every element itself, so nothing on the
	 * page consumes those custom properties.
	 */
	wp_dequeue_style( 'global-styles' );
}
add_action( 'wp_enqueue_scripts', 'dx_dequeue_unused', 100 );

/**
 * Global styles are printed from their own hooks as well; drop those too.
 */
function dx_drop_global_styles() {
	if ( is_admin() ) {
		return;
	}

	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
	remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
}
add_action( 'wp', 'dx_drop_global_styles' );

/**
 * Load only the styles of the blocks a page actually renders, instead of the
 * whole block library, on the pages that do use editor content.
 */
add_filter( 'should_load_separate_core_block_assets', '__return_true' );

/**
 * The design is right-to-left whatever the site locale is set to, so the
 * document direction is stated explicitly when the locale did not supply one.
 *
 * Front end only: wp-admin renders through language_attributes() as well, and
 * flipping it there would put an English admin into RTL without the matching
 * admin stylesheet.
 *
 * @param string $output Attribute string from language_attributes().
 * @return string
 */
function dx_force_rtl_attributes( $output ) {
	if ( ! is_admin() && false === strpos( $output, 'dir=' ) ) {
		$output .= ' dir="rtl"';
	}

	return $output;
}
add_filter( 'language_attributes', 'dx_force_rtl_attributes' );

/**
 * Drop the s.w.org prefetch — nothing on the front end resolves that host.
 *
 * @param string[] $urls          Resource hint URLs.
 * @param string   $relation_type Hint type.
 * @return string[]
 */
function dx_clean_resource_hints( $urls, $relation_type ) {
	if ( 'dns-prefetch' === $relation_type ) {
		$urls = array_filter(
			$urls,
			static function ( $url ) {
				return false === strpos( (string) $url, 's.w.org' );
			}
		);
	}

	return $urls;
}
add_filter( 'wp_resource_hints', 'dx_clean_resource_hints', 10, 2 );

/**
 * Lead pages must never be served from a full-page cache with a stale form.
 *
 * The script refreshes its own nonce, so this only marks the response for cache
 * layers that honour the standard headers; it does not disable caching elsewhere.
 */
function dx_cache_headers() {
	if ( is_singular() && dx_needs_lead_script() ) {
		// LiteSpeed / WP Rocket read this to keep the page cacheable but revalidated.
		header( 'Cache-Control: public, max-age=0, s-maxage=3600, must-revalidate', false );
	}
}
add_action( 'template_redirect', 'dx_cache_headers' );
