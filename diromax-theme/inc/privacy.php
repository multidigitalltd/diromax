<?php
/**
 * Footer duties: the first-visit privacy notice, the mandatory legal links
 * and the Multi Digital credit (Multi Digital standard 15.4–15.6).
 *
 * The notice is the same static HTML for every visitor, hidden until the
 * script decides to show it, so it is safe under a full-page cache and
 * causes no layout shift (fixed position, shown after load).
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version of the privacy policy: the policy page's last change.
 *
 * A new version shows the notice again to everyone (standard 15.5.2).
 *
 * @return string
 */
function dx_privacy_version() {
	$page_id = (int) get_option( 'wp_page_for_privacy_policy' );
	$page    = $page_id ? get_post( $page_id ) : null;

	return $page instanceof WP_Post && 'publish' === $page->post_status ? (string) mysql2date( 'Y-m-d-His', $page->post_modified_gmt, false ) : '1';
}

/**
 * Terms of use page URL, if one was chosen.
 *
 * @return string
 */
function dx_terms_url() {
	$page_id = (int) get_theme_mod( 'dx_terms_page', 0 );

	return $page_id && 'publish' === get_post_status( $page_id ) ? (string) get_permalink( $page_id ) : '';
}

/**
 * Footer legal links: privacy policy, terms, accessibility statement
 * (standard 15.6.1), then any links of the "footer" menu not already there.
 *
 * @return array<int, array{url:string, label:string}>
 */
function dx_legal_links() {
	$links = array();

	foreach (
		array(
			array( get_privacy_policy_url(), __( 'מדיניות פרטיות', 'diromax' ) ),
			array( dx_terms_url(), __( 'תנאי שימוש', 'diromax' ) ),
			array( (string) dx_a11y_url(), __( 'הצהרת נגישות', 'diromax' ) ),
		) as $link
	) {
		if ( '' !== $link[0] ) {
			$links[] = array(
				'url'   => $link[0],
				'label' => $link[1],
			);
		}
	}

	$known = array_map( static fn( $link ) => untrailingslashit( $link['url'] ), $links );

	if ( has_nav_menu( 'footer' ) ) {
		foreach ( dx_nav_links( 'footer' ) as $link ) {
			if ( ! in_array( untrailingslashit( $link['url'] ), $known, true ) ) {
				$links[] = array(
					'url'   => $link['url'],
					'label' => $link['label'],
				);
			}
		}
	}

	return $links;
}

/**
 * Show the Multi Digital credit? On by default; an administrator can turn
 * it off only where agreed with the client (standard 15.4.3).
 *
 * @return bool
 */
function dx_show_credit() {
	return (bool) get_theme_mod( 'dx_credit', true );
}

/**
 * The privacy notice script, small and deferred, on every front-end page.
 *
 * @return void
 */
function dx_privacy_script() {
	wp_enqueue_script(
		'dx-privacy',
		dx_asset_url( 'assets/js/privacy.min.js', 'assets/js/privacy.js' ),
		array(),
		DX_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'dx_privacy_script' );

/**
 * Warn the site owner when there is no privacy policy page to link to.
 *
 * @return void
 */
function dx_privacy_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) || '' !== get_privacy_policy_url() ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
		esc_html__( 'לאתר אין עמוד מדיניות פרטיות פעיל, והודעת הפרטיות לא יכולה לקשר אליו (תקן Multi Digital 15.5).', 'diromax' ),
		esc_url( admin_url( 'options-privacy.php' ) ),
		esc_html__( 'לבחירת העמוד', 'diromax' )
	);
}
add_action( 'admin_notices', 'dx_privacy_admin_notice' );
