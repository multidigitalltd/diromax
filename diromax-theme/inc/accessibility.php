<?php
/**
 * Accessibility layer.
 *
 * Targets WCAG 2.2 AA and Israeli Standard ת"י 5568. The toolbar is a
 * progressive enhancement: with JavaScript off the page is already usable,
 * keyboard operable and screen-reader complete.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolbar toggles. Key becomes the class dx-a11y-{key} on <html>.
 *
 * @return array<string,array{label:string,group:string}>
 */
function dx_a11y_toggles() {
	return array(
		'contrast'  => array(
			'label' => __( 'ניגודיות גבוהה', 'diromax' ),
			'group' => 'view',
		),
		'invert'    => array(
			'label' => __( 'היפוך צבעים', 'diromax' ),
			'group' => 'view',
		),
		'grayscale' => array(
			'label' => __( 'גווני אפור', 'diromax' ),
			'group' => 'view',
		),
		'readable'  => array(
			'label' => __( 'גופן קריא', 'diromax' ),
			'group' => 'text',
		),
		'spacing'   => array(
			'label' => __( 'ריווח טקסט מוגדל', 'diromax' ),
			'group' => 'text',
		),
		'links'     => array(
			'label' => __( 'הדגשת קישורים', 'diromax' ),
			'group' => 'text',
		),
		'underline' => array(
			'label' => __( 'קו תחתון לקישורים', 'diromax' ),
			'group' => 'text',
		),
		'headings'  => array(
			'label' => __( 'הדגשת כותרות', 'diromax' ),
			'group' => 'text',
		),
		'nomotion'  => array(
			'label' => __( 'עצירת אנימציות', 'diromax' ),
			'group' => 'nav',
		),
		'guide'     => array(
			'label' => __( 'סרגל קריאה', 'diromax' ),
			'group' => 'nav',
		),
		'kbd'       => array(
			'label' => __( 'סימון מוגבר לניווט במקלדת', 'diromax' ),
			'group' => 'nav',
		),
	);
}

/**
 * Group headings for the panel.
 *
 * @return array<string,string>
 */
function dx_a11y_groups() {
	return array(
		'view' => __( 'תצוגה וצבע', 'diromax' ),
		'text' => __( 'טקסט וקריאות', 'diromax' ),
		'nav'  => __( 'ניווט ותנועה', 'diromax' ),
	);
}

/**
 * Permalink of the accessibility statement page, if one exists.
 *
 * @return string
 */
function dx_a11y_url() {
	$url = get_transient( 'dx_a11y_url' );

	if ( is_string( $url ) ) {
		return $url;
	}

	$pages = get_posts(
		array(
			'post_type'              => 'page',
			'posts_per_page'         => 1,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_key'               => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'             => 'page-templates/template-accessibility.php', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	$url = $pages ? (string) get_permalink( $pages[0] ) : '';
	set_transient( 'dx_a11y_url', $url, DAY_IN_SECONDS );

	return $url;
}

/**
 * Statement page moves or appears — drop the cached URL.
 *
 * @return void
 */
function dx_flush_a11y_url() {
	delete_transient( 'dx_a11y_url' );
}
add_action( 'save_post_page', 'dx_flush_a11y_url' );
add_action( 'deleted_post', 'dx_flush_a11y_url' );

/**
 * Restore saved preferences before first paint, so nothing shifts under the
 * visitor. Small enough to stay inline; no request, no render-blocking file.
 *
 * @return void
 */
function dx_a11y_boot_script() {
	if ( ! dx_a11y_enabled() ) {
		return;
	}

	$js = '(function(){try{var s=localStorage.getItem("dxA11y");if(!s){return;}'
		. 'var p=JSON.parse(s),r=document.documentElement;'
		. 'if(p.scale){r.style.setProperty("--dx-a11y-scale",p.scale);}'
		. 'if(p.flags){for(var i=0;i<p.flags.length;i++){r.classList.add("dx-a11y-"+p.flags[i]);}}'
		. '}catch(e){}})();';

	wp_print_inline_script_tag( $js, array( 'id' => 'dx-a11y-boot' ) );
}
add_action( 'wp_head', 'dx_a11y_boot_script', 2 );

/**
 * Render the toolbar. Called from footer.php.
 *
 * @return void
 */
function dx_a11y_toolbar() {
	if ( ! dx_a11y_enabled() ) {
		return;
	}

	$groups    = dx_a11y_groups();
	$statement = dx_a11y_url();

	// Bucket the toggles once, so the render loop stays a plain nested foreach.
	$by_group = array_fill_keys( array_keys( $groups ), array() );
	foreach ( dx_a11y_toggles() as $key => $toggle ) {
		$by_group[ $toggle['group'] ][ $key ] = $toggle['label'];
	}
	?>
	<div class="dx-a11y" data-dx-a11y-root>
		<button
			type="button"
			class="dx-a11y__launch"
			aria-expanded="false"
			aria-controls="dx-a11y-panel"
			data-dx-a11y-launch
		>
			<svg class="dx-a11y__icon" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false">
				<circle cx="12" cy="4.2" r="2.1" fill="currentColor"/>
				<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"
					d="M3.6 8.2h16.8M12 8.2v6.1M12 14.3l-3.4 6.5M12 14.3l3.4 6.5"/>
			</svg>
			<span class="dx-sr-only"><?php esc_html_e( 'כלי נגישות', 'diromax' ); ?></span>
		</button>

		<div
			class="dx-a11y__panel"
			id="dx-a11y-panel"
			role="dialog"
			aria-modal="true"
			aria-labelledby="dx-a11y-title"
			hidden
		>
			<div class="dx-a11y__head">
				<h2 class="dx-a11y__title" id="dx-a11y-title"><?php esc_html_e( 'כלי נגישות', 'diromax' ); ?></h2>
				<button type="button" class="dx-a11y__close" data-dx-a11y-close>
					<span aria-hidden="true">✕</span>
					<span class="dx-sr-only"><?php esc_html_e( 'סגירת כלי הנגישות', 'diromax' ); ?></span>
				</button>
			</div>

			<div class="dx-a11y__group" role="group" aria-labelledby="dx-a11y-size">
				<h3 class="dx-a11y__gh" id="dx-a11y-size"><?php esc_html_e( 'גודל טקסט', 'diromax' ); ?></h3>
				<div class="dx-a11y__size">
					<button type="button" class="dx-a11y__step" data-dx-a11y-step="-1">
						<span aria-hidden="true">א−</span>
						<span class="dx-sr-only"><?php esc_html_e( 'הקטנת גודל הטקסט', 'diromax' ); ?></span>
					</button>
					<output class="dx-a11y__val" data-dx-a11y-value aria-live="polite">100%</output>
					<button type="button" class="dx-a11y__step" data-dx-a11y-step="1">
						<span aria-hidden="true">א+</span>
						<span class="dx-sr-only"><?php esc_html_e( 'הגדלת גודל הטקסט', 'diromax' ); ?></span>
					</button>
				</div>
			</div>

			<?php foreach ( $groups as $group_key => $group_label ) : ?>
				<div class="dx-a11y__group" role="group" aria-labelledby="dx-a11y-g-<?php echo esc_attr( $group_key ); ?>">
					<h3 class="dx-a11y__gh" id="dx-a11y-g-<?php echo esc_attr( $group_key ); ?>">
						<?php echo esc_html( $group_label ); ?>
					</h3>
					<div class="dx-a11y__toggles">
						<?php foreach ( $by_group[ $group_key ] as $key => $label ) : ?>
							<button
								type="button"
								class="dx-a11y__toggle"
								aria-pressed="false"
								data-dx-a11y-toggle="<?php echo esc_attr( $key ); ?>"
							><?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<div class="dx-a11y__foot">
				<button type="button" class="dx-a11y__reset" data-dx-a11y-reset>
					<?php esc_html_e( 'איפוס כל ההגדרות', 'diromax' ); ?>
				</button>
				<?php if ( $statement ) : ?>
					<a class="dx-a11y__link" href="<?php echo esc_url( $statement ); ?>">
						<?php esc_html_e( 'הצהרת נגישות', 'diromax' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<div class="dx-a11y__guide" data-dx-a11y-guide aria-hidden="true"></div>
	</div>
	<?php
}
