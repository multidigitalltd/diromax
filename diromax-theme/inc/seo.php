<?php
/**
 * SEO output: social meta and JSON-LD.
 *
 * When an SEO plugin is active it owns the social meta and the site-level
 * schema, so this file steps aside and emits only what the plugin cannot know:
 * the FAQ content, which lives in the theme rather than in post content.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is a known SEO plugin handling meta output?
 *
 * @return bool
 */
function dx_seo_plugin_active() {
	return (
		defined( 'WPSEO_VERSION' )
		|| defined( 'RANK_MATH_VERSION' )
		|| defined( 'SEOPRESS_VERSION' )
		|| defined( 'AIOSEO_VERSION' )
		|| defined( 'THE_SEO_FRAMEWORK_VERSION' )
	);
}

/**
 * A short, human description of the current view.
 *
 * @return string
 */
function dx_meta_description() {
	if ( is_singular() && ! is_front_page() ) {
		$post = get_queried_object();

		if ( $post instanceof WP_Post ) {
			$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( $post->post_content );
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

			if ( '' !== $text ) {
				return wp_html_excerpt( $text, 155, '…' );
			}
		}
	}

	$per_view = array(
		'improve'  => __( 'ניהול השבחת נכסים מא׳ עד ת׳: בדיקת זכויות בנייה, תכנון, החתמת שכנים, היתר, ניהול ביצוע ומכירה בשווי החדש.', 'diromax' ),
		'broker'   => __( 'תיווך נכסים בעין של יזם — מכירה, איתור נכס לקנייה והשבחה לפני מכירה.', 'diromax' ),
		'faq'      => __( 'שאלות ותשובות על השבחת נכסים: זכויות בנייה, פיצול, חריגות, לוחות זמנים ועלויות.', 'diromax' ),
		'articles' => __( 'מאמרים על זכויות בנייה, פיצול דירות, החתמת שכנים והיתרי בנייה.', 'diromax' ),
		'contact'  => __( 'בדיקת פוטנציאל השבחה לנכס שלך — ללא עלות וללא התחייבות.', 'diromax' ),
	);

	$view = dx_current_view();

	return $per_view[ $view ] ?? get_bloginfo( 'description' );
}

/**
 * Best available sharing image for the current view.
 *
 * @return string
 */
function dx_social_image() {
	if ( is_singular() && has_post_thumbnail() ) {
		$src = wp_get_attachment_image_src( (int) get_post_thumbnail_id(), 'full' );

		if ( $src ) {
			return $src[0];
		}
	}

	$hero = (int) get_theme_mod( 'dx_hero_image' );
	if ( $hero ) {
		$src = wp_get_attachment_image_src( $hero, 'full' );

		if ( $src ) {
			return $src[0];
		}
	}

	$logo = (int) get_theme_mod( 'custom_logo' );
	if ( $logo ) {
		$src = wp_get_attachment_image_src( $logo, 'full' );

		if ( $src ) {
			return $src[0];
		}
	}

	return DX_URI . '/assets/img/logo.jpg';
}

/**
 * Canonical-ish URL for the current view, built from the query rather than from
 * REQUEST_URI, so nothing user-supplied reaches the markup.
 *
 * @return string
 */
function dx_current_url() {
	if ( is_singular() ) {
		return (string) get_permalink();
	}

	if ( is_home() ) {
		$blog = (int) get_option( 'page_for_posts' );

		return $blog ? (string) get_permalink( $blog ) : home_url( '/' );
	}

	if ( is_category() || is_tag() || is_tax() ) {
		$link = get_term_link( get_queried_object() );

		return is_wp_error( $link ) ? home_url( '/' ) : (string) $link;
	}

	return home_url( '/' );
}

/**
 * Open Graph and Twitter meta.
 */
function dx_social_meta() {
	if ( dx_seo_plugin_active() ) {
		return;
	}

	$title = wp_get_document_title();
	$image = dx_social_image();
	$url   = dx_current_url();

	$tags = array(
		'og:site_name'   => get_bloginfo( 'name' ),
		'og:locale'      => get_locale(),
		'og:type'        => is_singular( 'post' ) ? 'article' : 'website',
		'og:title'       => $title,
		'og:description' => dx_meta_description(),
		'og:url'         => $url,
		'og:image'       => $image,
	);

	printf( '<meta name="description" content="%s">' . "\n", esc_attr( dx_meta_description() ) );

	foreach ( $tags as $property => $content ) {
		if ( '' === (string) $content ) {
			continue;
		}

		printf(
			'<meta property="%1$s" content="%2$s">' . "\n",
			esc_attr( $property ),
			esc_attr( $content )
		);
	}

	printf( '<meta name="twitter:card" content="summary_large_image">' . "\n" );
	printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );
	printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( dx_meta_description() ) );
	printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $image ) );
}
add_action( 'wp_head', 'dx_social_meta', 5 );

/**
 * The business itself. Emitted once, on the front page.
 *
 * @return array<string,mixed>
 */
function dx_schema_organization() {
	$schema = array(
		'@type'       => 'RealEstateAgent',
		'@id'         => home_url( '/#organization' ),
		'name'        => get_bloginfo( 'name' ),
		'url'         => home_url( '/' ),
		'description' => __( 'תיווך, יזמות וניהול השבחת נכסים.', 'diromax' ),
		'image'       => dx_social_image(),
		'logo'        => dx_social_image(),
	);

	$phone = dx_phone_tel();
	if ( '' !== $phone && '0540000000' !== $phone ) {
		$schema['telephone'] = $phone;
	}

	$email = dx_email();
	if ( is_email( $email ) ) {
		$schema['email'] = $email;
	}

	$area = dx_area();
	if ( '' !== $area && 'להשלמה' !== $area ) {
		$schema['areaServed'] = $area;
	}

	return $schema;
}

/**
 * Breadcrumb trail for inner pages.
 *
 * @return array<string,mixed>|null
 */
function dx_schema_breadcrumbs() {
	if ( is_front_page() ) {
		return null;
	}

	$items = array(
		array(
			'@type'    => 'ListItem',
			'position' => 1,
			'name'     => __( 'ראשי', 'diromax' ),
			'item'     => home_url( '/' ),
		),
	);

	if ( is_singular( 'post' ) ) {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => 2,
			'name'     => __( 'מאמרים', 'diromax' ),
			'item'     => dx_view_url( 'articles' ),
		);
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => 3,
			'name'     => get_the_title(),
		);
	} elseif ( is_singular() ) {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => 2,
			'name'     => get_the_title(),
		);
	} else {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => 2,
			'name'     => wp_get_document_title(),
		);
	}

	return array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	);
}

/**
 * Article schema for a single post.
 *
 * @return array<string,mixed>|null
 */
function dx_schema_article() {
	if ( ! is_singular( 'post' ) ) {
		return null;
	}

	$schema = array(
		'@type'            => 'Article',
		'headline'         => get_the_title(),
		'description'      => dx_meta_description(),
		'datePublished'    => get_the_date( DATE_W3C ),
		'dateModified'     => get_the_modified_date( DATE_W3C ),
		'mainEntityOfPage' => get_permalink(),
		'inLanguage'       => get_bloginfo( 'language' ),
		'publisher'        => array( '@id' => home_url( '/#organization' ) ),
		'author'           => array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
		),
	);

	if ( has_post_thumbnail() ) {
		$src = wp_get_attachment_image_src( (int) get_post_thumbnail_id(), 'full' );

		if ( $src ) {
			$schema['image'] = $src[0];
		}
	}

	return $schema;
}

/**
 * FAQ schema, built from the theme's own question list.
 *
 * @return array<string,mixed>|null
 */
function dx_schema_faq() {
	if ( ! dx_is_template( 'template-faq.php' ) ) {
		return null;
	}

	$entities = array();
	foreach ( dx_faq_all() as $item ) {
		$entities[] = array(
			'@type'          => 'Question',
			'name'           => $item['q'],
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $item['a'],
			),
		);
	}

	if ( ! $entities ) {
		return null;
	}

	return array(
		'@type'      => 'FAQPage',
		'mainEntity' => $entities,
	);
}

/**
 * Print the JSON-LD graph for the current view.
 */
function dx_schema_output() {
	$graph = array();

	// The FAQ list is theme content; no SEO plugin can see it, so it always ships.
	$faq = dx_schema_faq();
	if ( $faq ) {
		$graph[] = $faq;
	}

	if ( ! dx_seo_plugin_active() ) {
		if ( is_front_page() ) {
			$graph[] = dx_schema_organization();
			$graph[] = array(
				'@type'      => 'WebSite',
				'@id'        => home_url( '/#website' ),
				'url'        => home_url( '/' ),
				'name'       => get_bloginfo( 'name' ),
				'inLanguage' => get_bloginfo( 'language' ),
				'publisher'  => array( '@id' => home_url( '/#organization' ) ),
			);
		}

		$crumbs = dx_schema_breadcrumbs();
		if ( $crumbs ) {
			$graph[] = $crumbs;
		}

		$article = dx_schema_article();
		if ( $article ) {
			$graph[] = $article;
		}
	}

	if ( ! $graph ) {
		return;
	}

	/*
	 * Slashes stay escaped on purpose: it makes a literal </script> impossible
	 * inside the block, whatever an editor types into a title or an excerpt.
	 */
	$payload = wp_json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		),
		JSON_UNESCAPED_UNICODE
	);

	if ( false === $payload ) {
		return;
	}

	echo '<script type="application/ld+json">' . $payload . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output, printed inside a JSON-LD script block.
}
add_action( 'wp_head', 'dx_schema_output', 20 );
