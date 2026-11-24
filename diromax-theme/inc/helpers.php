<?php
/**
 * Template helpers.
 *
 * Everything the templates need to stay readable, plus the URL resolver that
 * keeps the navigation down to one database lookup per cache cycle.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DX_URL_CACHE_KEY = 'dx_view_urls';

/**
 * Page template file for each of the five views.
 *
 * @return array<string,string>
 */
function dx_view_templates() {
	return array(
		'improve'  => 'template-improvement.php',
		'broker'   => 'template-broker.php',
		'faq'      => 'template-faq.php',
		'contact'  => 'template-contact.php',
		'articles' => 'template-articles.php',
	);
}

/**
 * Which of the five views are we on. Drives the nav underline and the body class.
 *
 * @return string
 */
function dx_current_view() {
	static $view = null;

	if ( null !== $view ) {
		return $view;
	}

	if ( is_singular( 'post' ) || is_home() || is_archive() || is_search() || dx_is_template( 'template-articles.php' ) ) {
		$view = 'articles';
	} elseif ( dx_is_template( 'template-broker.php' ) ) {
		$view = 'broker';
	} elseif ( dx_is_template( 'template-faq.php' ) ) {
		$view = 'faq';
	} elseif ( dx_is_template( 'template-contact.php' ) ) {
		$view = 'contact';
	} else {
		$view = 'improve';
	}

	return $view;
}

/**
 * Is the queried page using the given theme template?
 *
 * @param string $file Template file name inside /page-templates.
 * @return bool
 */
function dx_is_template( $file ) {
	return is_page_template( 'page-templates/' . $file );
}

/**
 * All five view URLs, resolved in a single query and cached until a page changes.
 *
 * @return array<string,string>
 */
function dx_view_urls() {
	$urls = get_transient( DX_URL_CACHE_KEY );

	if ( is_array( $urls ) ) {
		return $urls;
	}

	$urls      = array();
	$templates = dx_view_templates();

	/*
	 * One query for every template-assigned page, instead of one query per view
	 * on every request. Only the two columns the loop reads are selected.
	 */
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, m.meta_value
			   FROM {$wpdb->postmeta} m
			   INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			  WHERE m.meta_key = %s
			    AND p.post_type = 'page'
			    AND p.post_status = 'publish'",
			'_wp_page_template'
		)
	);

	$by_template = array();
	foreach ( (array) $rows as $row ) {
		$by_template[ $row->meta_value ] = (int) $row->ID;
	}

	foreach ( $templates as $view => $file ) {
		$id            = $by_template[ 'page-templates/' . $file ] ?? 0;
		$urls[ $view ] = $id ? (string) get_permalink( $id ) : '';
	}

	// Fallbacks: the front page answers for "improve", the posts page for "articles".
	if ( '' === $urls['improve'] ) {
		$urls['improve'] = home_url( '/' );
	}
	if ( '' === $urls['articles'] ) {
		$blog             = (int) get_option( 'page_for_posts' );
		$urls['articles'] = $blog ? (string) get_permalink( $blog ) : home_url( '/' );
	}
	foreach ( $urls as $view => $url ) {
		if ( '' === $url ) {
			$urls[ $view ] = home_url( '/' );
		}
	}

	set_transient( DX_URL_CACHE_KEY, $urls, DAY_IN_SECONDS );

	return $urls;
}

/**
 * Permalink for one of the five views.
 *
 * @param string $view View key.
 * @return string
 */
function dx_view_url( $view ) {
	$urls = dx_view_urls();

	return $urls[ $view ] ?? home_url( '/' );
}

/**
 * Any page save can move a template assignment, so the map is rebuilt lazily.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function dx_flush_view_urls( $post_id = 0 ) {
	if ( $post_id && 'page' !== get_post_type( $post_id ) ) {
		return;
	}

	delete_transient( DX_URL_CACHE_KEY );
}
add_action( 'save_post', 'dx_flush_view_urls' );
add_action( 'deleted_post', 'dx_flush_view_urls' );
add_action( 'permalink_structure_changed', 'dx_flush_view_urls' );
add_action( 'switch_theme', 'dx_flush_view_urls' );

/*
 * Customizer accessors — each falls back to the placeholder used in the design.
 */

/**
 * Read a theme mod, treating an empty string as "not set".
 *
 * @param string $key      Setting key without the dx_ prefix.
 * @param string $fallback Value to use when unset.
 * @return string
 */
function dx_opt( $key, $fallback = '' ) {
	$value = get_theme_mod( 'dx_' . $key, $fallback );

	return ( '' === $value || null === $value ) ? $fallback : (string) $value;
}

/**
 * Display phone number.
 *
 * @return string Display phone number.
 */
function dx_phone() {
	return dx_opt( 'phone', '054-000-0000' );
}

/**
 * A phone number reduced to what a link may hold.
 *
 * @param string $phone Phone number as written.
 * @param bool   $plus  Keep a plus sign (tel: links) or digits only.
 * @return string The digits, or an empty string when nothing is left.
 */
function dx_phone_digits( $phone, $plus = true ) {
	$digits = preg_replace( $plus ? '/[^0-9+]/' : '/[^0-9]/', '', (string) $phone );

	return is_string( $digits ) ? $digits : '';
}

/**
 * Phone number reduced to digits, for a tel: link.
 *
 * @return string Phone number reduced to digits, for a tel: link.
 */
function dx_phone_tel() {
	return dx_phone_digits( dx_opt( 'phone', '0540000000' ) );
}

/**
 * WhatsApp number in international format.
 *
 * @return string WhatsApp number in international format.
 */
function dx_whatsapp() {
	return dx_opt( 'whatsapp', '972540000000' );
}

/**
 * WhatsApp deep link.
 *
 * @return string WhatsApp deep link.
 */
function dx_wa_url() {
	return 'https://wa.me/' . dx_phone_digits( dx_whatsapp(), false );
}

/**
 * Contact e-mail address.
 *
 * @return string Contact e-mail address.
 */
function dx_email() {
	return dx_opt( 'email', 'info@diromax.co.il' );
}

/**
 * Service area.
 *
 * @return string Service area.
 */
function dx_area() {
	return dx_opt( 'area', 'להשלמה' );
}

/**
 * Whether the fixed contact bar is shown.
 *
 * @return bool Whether the fixed contact bar is shown.
 */
function dx_show_sticky() {
	return (bool) get_theme_mod( 'dx_sticky_bar', true );
}

/**
 * Whether the before/after section is shown on the home page. Off unless
 * turned on in the Customizer: most improvements are inside a flat, with
 * nothing to show from outside (theme 2.2.4).
 *
 * @return bool Whether the before/after section is shown.
 */
function dx_show_projects() {
	return (bool) get_theme_mod( 'dx_show_projects', false );
}

/**
 * Attachment ID of the image used when a post has no featured image.
 *
 * @return int
 */
function dx_default_thumb_id() {
	return (int) get_theme_mod( 'dx_default_thumb', 0 );
}

/**
 * Render the featured image, or the configured default, or nothing.
 *
 * @param string               $size  Image size.
 * @param array<string,string> $attrs Extra image attributes.
 * @return bool True when an image was printed.
 */
function dx_post_thumbnail( $size = 'dx-card', array $attrs = array() ) {
	if ( has_post_thumbnail() ) {
		the_post_thumbnail( $size, $attrs );

		return true;
	}

	$fallback = dx_default_thumb_id();

	if ( ! $fallback ) {
		return false;
	}

	// A generic image carries no information about this particular post, so it
	// is decorative unless the client wrote an alt text for it.
	$attrs['alt']   = dx_opt( 'default_thumb_alt', '' );
	$attrs['class'] = trim( ( $attrs['class'] ?? '' ) . ' dx-thumb--default' );

	echo wp_get_attachment_image( $fallback, $size, false, $attrs );

	return true;
}

/**
 * Whether the accessibility toolbar is shown.
 *
 * @return bool Whether the accessibility toolbar is shown.
 */
function dx_a11y_enabled() {
	return (bool) get_theme_mod( 'dx_a11y_toolbar', true );
}

/*
 * Navigation
 */

/**
 * The five built-in nav entries, in order.
 *
 * @return array<string,string>
 */
function dx_nav_items() {
	return array(
		'improve'  => (string) dx_content( 'general.nav_improve' ),
		'broker'   => (string) dx_content( 'general.nav_broker' ),
		'faq'      => (string) dx_content( 'general.nav_faq' ),
		'articles' => (string) dx_content( 'general.nav_articles' ),
		'contact'  => (string) dx_content( 'general.nav_contact' ),
	);
}

/**
 * Nav links to render: a menu assigned in the admin wins, otherwise the built-in five.
 *
 * @param string $location Registered menu location.
 * @return array<int,array{url:string,label:string,current:bool}>
 */
function dx_nav_links( $location = 'primary' ) {
	$links     = array();
	$current   = dx_current_view();
	$locations = get_nav_menu_locations();
	$menu_id   = empty( $locations[ $location ] ) ? 0 : (int) $locations[ $location ];

	if ( $menu_id ) {
		$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );

		foreach ( (array) $items as $item ) {
			if ( 0 !== (int) $item->menu_item_parent ) {
				continue; // The design has no dropdowns; only the top level renders.
			}

			$links[] = array(
				'url'     => $item->url,
				'label'   => $item->title,
				'current' => in_array( 'current-menu-item', (array) $item->classes, true ),
			);
		}
	}

	if ( ! $links ) {
		foreach ( dx_nav_items() as $view => $label ) {
			$links[] = array(
				'url'     => dx_view_url( $view ),
				'label'   => $label,
				'current' => ( $view === $current ),
			);
		}
	}

	/**
	 * Filters the header and footer links. The client-portal plugin adds its
	 * "אזור אישי" link here.
	 *
	 * @param array<int, array{url:string, label:string, current:bool}> $links    Links.
	 * @param string                                                    $location Menu location.
	 */
	return (array) apply_filters( 'dx_nav_links', $links, $location );
}

/*
 * Output helpers
 */

/**
 * Section eyebrow: numbered kicker used on the improvement page.
 *
 * @param string $num  Two-digit section number.
 * @param string $text Section label.
 * @return void
 */
function dx_kicker( $num, $text ) {
	printf(
		'<div class="dx-kicker"><span class="dx-kicker__n" aria-hidden="true">%1$s</span><span class="dx-kicker__t">%2$s</span></div>',
		esc_html( $num ),
		esc_html( $text )
	);
}

/**
 * Section eyebrow: dot + label used on the inner pages.
 *
 * @param string $text  Label.
 * @param string $dot   'orange' or 'teal'.
 * @param string $extra Extra class on the wrapper.
 * @return void
 */
function dx_eyebrow( $text, $dot = 'orange', $extra = '' ) {
	printf(
		'<div class="dx-eyebrow %1$s"><span class="dx-dot%2$s" aria-hidden="true"></span><span class="dx-eyebrow__t">%3$s</span></div>',
		esc_attr( $extra ),
		'teal' === $dot ? ' dx-dot--teal' : '',
		esc_html( $text )
	);
}

/**
 * Accordion rows built on native <details>, so keyboard and screen-reader
 * behaviour comes from the browser rather than from script.
 *
 * @param array<int,array{q:string,a:string}> $items Question/answer pairs.
 * @param bool                                $page  Page-level variant.
 * @return void
 */
function dx_accordion( $items, $page = false ) {
	foreach ( $items as $item ) {
		printf(
			'<details class="dx-acc%1$s"><summary><span class="dx-acc__q">%2$s</span><span class="dx-acc__p" aria-hidden="true">+</span></summary><div class="dx-acc__a"><p>%3$s</p></div></details>',
			$page ? ' dx-acc--page' : '',
			esc_html( $item['q'] ),
			esc_html( $item['a'] )
		);
	}
}

/**
 * A post's date, or an empty string when the post has none.
 *
 * @param string           $format PHP date format; empty for the site's setting.
 * @param int|WP_Post|null $post   Post; the current one when null.
 * @return string
 */
function dx_post_date( $format = '', $post = null ) {
	$date = get_the_date( $format, $post );

	return is_string( $date ) ? $date : '';
}

/**
 * The previous and next articles, for the links under a post.
 *
 * @return array<int, array{url:string, title:string, label:string}>
 */
function dx_adjacent_posts() {
	$links = array();

	foreach (
		array(
			'general.post_prev' => get_previous_post(),
			'general.post_next' => get_next_post(),
		) as $label => $post
	) {
		if ( $post instanceof WP_Post ) {
			$links[] = array(
				'url'   => get_permalink( $post ),
				'title' => get_the_title( $post ),
				'label' => $label,
			);
		}
	}

	return $links;
}
