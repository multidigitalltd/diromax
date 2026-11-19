<?php
/**
 * Content editor.
 *
 * An admin screen that edits every visible string on the site. It writes only
 * the values that differ from the theme defaults, so a field left untouched
 * costs nothing and keeps following the theme when the defaults change.
 *
 * Admin only — this file is never loaded on a front-end request.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field definitions for the editor, grouped into the tabs of the screen.
 *
 * Types:
 *   text      single line
 *   lines     multi-line, where each line break becomes a <br> on the site
 *   textarea  multi-line paragraph
 *   list      an ordered list of single-line values
 *   repeater  rows of fields, described by 'cols'
 *
 * Defaults are not repeated here — they come from inc/content.php.
 *
 * @return array<string,array{label:string,note?:string,fields:array<string,array<int,mixed>>}>
 */
function dx_content_schema() {
	return array(

		'general'  => array(
			'label'  => 'כללי — תפריט, פוטר ועמוד שגיאה',
			'fields' => array(
				'nav_improve'      => array( 'לשונית תפריט: השבחה', 'text' ),
				'nav_broker'       => array( 'לשונית תפריט: תיווך', 'text' ),
				'nav_faq'          => array( 'לשונית תפריט: שאלות ותשובות', 'text' ),
				'nav_articles'     => array( 'לשונית תפריט: מאמרים', 'text' ),
				'nav_contact'      => array( 'לשונית תפריט: צור קשר', 'text' ),
				'header_cta'       => array( 'כפתור בראש האתר', 'text' ),
				'sticky_call'      => array( 'סרגל תחתון: שיחה', 'text' ),
				'sticky_wa'        => array( 'סרגל תחתון: וואטסאפ', 'text' ),
				'sticky_cta'       => array( 'סרגל תחתון: כפתור כתום', 'text' ),
				'footer_improve'   => array( 'פוטר: שם העמוד הראשי', 'text' ),
				'footer_tagline'   => array( 'פוטר: משפט הפתיחה', 'textarea' ),
				'footer_col_site'  => array( 'פוטר: כותרת עמודת האתר', 'text' ),
				'footer_col_mail'  => array( 'פוטר: כותרת עמודת יצירת קשר', 'text' ),
				'footer_col_info'  => array( 'פוטר: כותרת עמודת המידע', 'text' ),
				'footer_rights'    => array( 'פוטר: שורת זכויות (אחרי השנה ושם האתר)', 'text' ),
				'footer_note'      => array( 'פוטר: הבהרה משפטית', 'textarea' ),
				'privacy_title'    => array( 'הודעת פרטיות: כותרת', 'text' ),
				'privacy_text'     => array( 'הודעת פרטיות: טקסט (לעדכן אם נוספות עוגיות מעקב)', 'textarea' ),
				'privacy_ok'       => array( 'הודעת פרטיות: כפתור', 'text' ),
				'privacy_link'     => array( 'הודעת פרטיות: קישור למדיניות', 'text' ),
				'privacy_settings' => array( 'פוטר: קישור הגדרות פרטיות', 'text' ),
				'post_cta_text'    => array( 'סוף מאמר: משפט הקריאה לפעולה', 'text' ),
				'post_cta_btn'     => array( 'סוף מאמר: כפתור', 'text' ),
				'post_prev'        => array( 'ניווט מאמרים: הקודם', 'text' ),
				'post_next'        => array( 'ניווט מאמרים: הבא', 'text' ),
				'e404_eyebrow'     => array( 'עמוד 404: תווית', 'text' ),
				'e404_title'       => array( 'עמוד 404: כותרת', 'lines' ),
				'e404_lead'        => array( 'עמוד 404: פסקה', 'textarea' ),
				'e404_btn_improve' => array( 'עמוד 404: כפתור ראשי', 'text' ),
				'e404_btn_contact' => array( 'עמוד 404: כפתור משני', 'text' ),
			),
		),

		'improve'  => array(
			'label'  => 'עמוד השבחת נכסים',
			'note'   => 'זהו גם עמוד הבית. המקטעים מופיעים כאן בסדר שבו הם מוצגים באתר.',
			'fields' => array(
				'hero_eyebrow'    => array( 'הירו: תווית עליונה', 'text' ),
				'hero_title'      => array( 'הירו: כותרת ראשית', 'lines' ),
				'hero_lead'       => array( 'הירו: פסקה ראשונה', 'textarea' ),
				'hero_lead2'      => array( 'הירו: פסקה מודגשת', 'textarea' ),
				'hero_btn'        => array( 'הירו: כפתור כתום', 'text' ),
				'hero_btn2'       => array( 'הירו: כפתור מסגרת', 'text' ),
				'hero_img_alt'    => array( 'הירו: תיאור התמונה לקוראי מסך', 'text' ),
				'facts'           => array(
					'הירו: שלוש הנקודות מתחת לכפתורים',
					'repeater',
					array(
						't' => 'כותרת',
						's' => 'שורת משנה',
					),
				),

				'band_eyebrow'    => array( 'פס כחול: תווית', 'text' ),
				'band_quote'      => array( 'פס כחול: המשפט הגדול', 'lines' ),
				'band_sub'        => array( 'פס כחול: משפט המשך', 'textarea' ),
				'band_note'       => array( 'פס כחול: פסקת הצד', 'textarea' ),
				'band_link'       => array( 'פס כחול: קישור', 'text' ),

				's1_kicker'       => array( 'מקטע 01: תווית', 'text' ),
				's1_title'        => array( 'מקטע 01: כותרת', 'lines' ),
				'unknowns'        => array(
					'מקטע 01: שלוש העמודות',
					'repeater',
					array(
						'h' => 'כותרת',
						'p' => 'טקסט',
					),
				),

				's2_kicker'       => array( 'מקטע 02: תווית', 'text' ),
				's2_title'        => array( 'מקטע 02: כותרת', 'lines' ),
				'tools'           => array(
					'מקטע 02: הכלים',
					'repeater',
					array(
						'h' => 'כותרת',
						'p' => 'טקסט',
					),
				),

				's3_kicker'       => array( 'מקטע 03: תווית', 'text' ),
				's3_title'        => array( 'מקטע 03: כותרת', 'lines' ),
				's3_intro'        => array( 'מקטע 03: פסקת פתיחה', 'textarea' ),
				'steps'           => array(
					'מקטע 03: שלבי התהליך',
					'repeater',
					array(
						'n' => 'מספר',
						't' => 'כותרת',
						'b' => 'תיאור',
					),
				),

				's4_kicker'       => array( 'מקטע 04: תווית', 'text' ),
				's4_title'        => array( 'מקטע 04: כותרת', 'lines' ),
				's4_caption'      => array( 'מקטע 04: תיאור הטבלה לקוראי מסך', 'textarea' ),
				's4_col_stage'    => array( 'מקטע 04: כותרת עמודה — השלב', 'text' ),
				's4_col_alone'    => array( 'מקטע 04: כותרת עמודה — לבד', 'text' ),
				's4_col_us'       => array( 'מקטע 04: כותרת עמודה — איתנו', 'text' ),
				'compare'         => array(
					'מקטע 04: שורות הטבלה',
					'repeater',
					array(
						's' => 'השלב',
						'a' => 'להשביח לבד',
						'u' => 'עם דירומקס',
					),
				),

				's5_kicker'       => array( 'מקטע 05: תווית', 'text' ),
				's5_title'        => array( 'מקטע 05: כותרת', 'lines' ),
				's5_intro'        => array( 'מקטע 05: פסקת פתיחה', 'textarea' ),
				'cost_cards'      => array(
					'מקטע 05: שלושת הכרטיסים',
					'repeater',
					array(
						'rule' => 'צבע הקו (grey / teal / orange)',
						'h'    => 'כותרת',
						'p'    => 'טקסט',
					),
				),
				'callout'         => array( 'מקטע 05: הפס הכתום', 'textarea' ),
				'box_eyebrow'     => array( 'מקטע 05: תווית התיבה', 'text' ),
				'box_title'       => array( 'מקטע 05: כותרת התיבה', 'lines' ),
				'box_intro'       => array( 'מקטע 05: פסקת התיבה', 'textarea' ),
				'tracks'          => array(
					'מקטע 05: המסלולים',
					'repeater',
					array(
						'h' => 'כותרת',
						'p' => 'טקסט',
					),
				),
				'box_rule'        => array( 'מקטע 05: משפט הסיום של התיבה', 'textarea' ),

				'diff_eyebrow'    => array( 'בידול: תווית', 'text' ),
				'diff_title'      => array( 'בידול: כותרת', 'lines' ),
				'differentiators' => array(
					'בידול: שלושת הכרטיסים',
					'repeater',
					array(
						'l' => 'אות',
						'h' => 'כותרת',
						'p' => 'טקסט',
					),
				),

				's6_kicker'       => array( 'מקטע 06: תווית', 'text' ),
				's6_title'        => array( 'מקטע 06: כותרת', 'lines' ),
				'audience'        => array(
					'מקטע 06: קהלי היעד',
					'repeater',
					array(
						'h' => 'כותרת',
						'p' => 'טקסט',
					),
				),

				's7_kicker'       => array( 'מקטע 07: תווית', 'text' ),
				's7_title'        => array( 'מקטע 07: כותרת', 'lines' ),
				'project_title'   => array( 'מקטע 07: כותרת כרטיס ריק', 'text' ),
				'project_desc'    => array( 'מקטע 07: טקסט כרטיס ריק', 'textarea' ),

				's8_kicker'       => array( 'מקטע 08: תווית', 'text' ),
				's8_title'        => array( 'מקטע 08: כותרת', 'lines' ),
				's8_btn'          => array( 'מקטע 08: כפתור', 'text' ),
				'faq'             => array(
					'מקטע 08: השאלות בעמוד הבית',
					'repeater',
					array(
						'q' => 'שאלה',
						'a' => 'תשובה',
					),
				),

				'cta_title'       => array( 'מקטע סיום: כותרת', 'lines' ),
				'cta_intro'       => array( 'מקטע סיום: פסקה', 'textarea' ),
				'cta_phone'       => array( 'מקטע סיום: תווית טלפון', 'text' ),
				'cta_wa'          => array( 'מקטע סיום: תווית וואטסאפ', 'text' ),
			),
		),

		'broker'   => array(
			'label'  => 'עמוד תיווך',
			'fields' => array(
				'eyebrow'   => array( 'תווית', 'text' ),
				'title'     => array( 'כותרת ראשית', 'lines' ),
				'lead'      => array( 'פסקת פתיחה', 'textarea' ),
				'services'  => array(
					'השירותים',
					'repeater',
					array(
						'n' => 'מספר',
						'h' => 'כותרת',
						'p' => 'טקסט',
					),
				),
				'strip'     => array( 'פס כחול: משפט', 'textarea' ),
				'strip_btn' => array( 'פס כחול: כפתור', 'text' ),
			),
		),

		'faq'      => array(
			'label'  => 'עמוד שאלות ותשובות',
			'fields' => array(
				'eyebrow' => array( 'תווית', 'text' ),
				'title'   => array( 'כותרת ראשית', 'lines' ),
				'btn'     => array( 'כפתור בתחתית', 'text' ),
				'items'   => array(
					'השאלות והתשובות',
					'repeater',
					array(
						'q' => 'שאלה',
						'a' => 'תשובה',
					),
				),
			),
		),

		'articles' => array(
			'label'  => 'עמוד מאמרים',
			'fields' => array(
				'eyebrow'     => array( 'תווית', 'text' ),
				'title'       => array( 'כותרת ראשית', 'lines' ),
				'empty'       => array( 'טקסט כשאין מאמרים', 'textarea' ),
				'none'        => array( 'טקסט כשחיפוש לא מצא דבר', 'textarea' ),
				'search'      => array( 'כותרת עמוד חיפוש (%s = מילת החיפוש)', 'text' ),
				'default_cat' => array( 'תווית כשלמאמר אין קטגוריה', 'text' ),
			),
		),

		'contact'  => array(
			'label'  => 'עמוד צור קשר',
			'fields' => array(
				'eyebrow'    => array( 'תווית', 'text' ),
				'title'      => array( 'כותרת ראשית', 'lines' ),
				'intro'      => array( 'פסקת פתיחה', 'textarea' ),
				'row_phone'  => array( 'תווית שורה: טלפון', 'text' ),
				'row_wa'     => array( 'תווית שורה: וואטסאפ', 'text' ),
				'row_mail'   => array( 'תווית שורה: דוא״ל', 'text' ),
				'row_area'   => array( 'תווית שורה: אזור פעילות', 'text' ),
				'next_title' => array( 'כותרת התיבה "מה קורה אחרי"', 'text' ),
				'steps'      => array(
					'שלבי התיבה',
					'repeater',
					array(
						'n' => 'מספר',
						't' => 'טקסט',
					),
				),
			),
		),

		'form'     => array(
			'label'  => 'טופס יצירת קשר',
			'note'   => 'הטופס מופיע בעמוד הבית, בעמוד ההשבחה ובעמוד צור קשר.',
			'fields' => array(
				'light_title'   => array( 'כותרת הטופס בעמוד הבית', 'text' ),
				'blue_title'    => array( 'כותרת הטופס בעמוד צור קשר', 'text' ),
				'label_name'    => array( 'תווית שדה: שם', 'text' ),
				'label_phone'   => array( 'תווית שדה: טלפון', 'text' ),
				'label_address' => array( 'תווית שדה: כתובת', 'text' ),
				'label_type'    => array( 'תווית שדה: סוג הנכס', 'text' ),
				'label_subject' => array( 'תווית שדה: נושא', 'text' ),
				'label_message' => array( 'תווית שדה: פרטים', 'text' ),
				'hint_phone'    => array( 'הסבר מתחת לשדה הטלפון', 'text' ),
				'hint_address'  => array( 'הסבר מתחת לשדה הכתובת', 'text' ),
				'btn_light'     => array( 'כפתור שליחה — עמוד הבית', 'text' ),
				'btn_blue'      => array( 'כפתור שליחה — צור קשר', 'text' ),
				'note'          => array( 'הערת פרטיות מתחת לטופס', 'textarea' ),
				'ok_light_h'    => array( 'הודעת תודה — כותרת (עמוד הבית)', 'text' ),
				'ok_light_p'    => array( 'הודעת תודה — טקסט (עמוד הבית)', 'textarea' ),
				'ok_blue_h'     => array( 'הודעת תודה — כותרת (צור קשר)', 'text' ),
				'ok_blue_p'     => array( 'הודעת תודה — טקסט (צור קשר)', 'textarea' ),
				'types'         => array( 'אפשרויות "סוג הנכס"', 'list' ),
				'subjects'      => array( 'אפשרויות "במה נוכל לעזור"', 'list' ),
			),
		),
	);
}

/**
 * Register the editor screen.
 *
 * @return void
 */
function dx_content_menu() {
	add_menu_page(
		__( 'תוכן האתר', 'diromax' ),
		__( 'תוכן האתר', 'diromax' ),
		'edit_theme_options',
		'dx-content',
		'dx_content_screen',
		'dashicons-edit-page',
		59
	);
}
add_action( 'admin_menu', 'dx_content_menu' );

/**
 * Sanitise one submitted value according to its declared type.
 *
 * @param mixed                $value Raw value.
 * @param string               $type  Field type.
 * @param array<string,string> $cols Repeater columns.
 * @return mixed
 */
function dx_sanitize_content_value( $value, $type, array $cols = array() ) {
	switch ( $type ) {

		case 'lines':
		case 'textarea':
			return sanitize_textarea_field( wp_unslash( (string) $value ) );

		case 'list':
			// Edited as one option per line.
			$lines = preg_split( '/\R/', (string) $value );
			$out   = array();

			foreach ( false === $lines ? array() : $lines as $item ) {
				$item = sanitize_text_field( wp_unslash( $item ) );

				if ( '' !== trim( $item ) ) {
					$out[] = $item;
				}
			}
			return $out;

		case 'repeater':
			$out = array();
			foreach ( (array) $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$clean = array();
				$empty = true;

				foreach ( array_keys( $cols ) as $col ) {
					$field         = sanitize_textarea_field( wp_unslash( (string) ( $row[ $col ] ?? '' ) ) );
					$clean[ $col ] = $field;

					if ( '' !== trim( $field ) ) {
						$empty = false;
					}
				}

				if ( ! $empty ) {
					$out[] = $clean;
				}
			}
			return $out;

		default:
			return sanitize_text_field( wp_unslash( (string) $value ) );
	}
}

/**
 * Save handler. Stores only what differs from the theme defaults.
 *
 * @return void
 */
function dx_content_save() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'אין לך הרשאה לערוך את תוכן האתר.', 'diromax' ), '', array( 'response' => 403 ) );
	}

	$group  = isset( $_POST['dx_group'] ) ? sanitize_key( wp_unslash( $_POST['dx_group'] ) ) : '';
	$schema = dx_content_schema();

	if ( ! isset( $schema[ $group ] ) ) {
		wp_die( esc_html__( 'מקטע לא מוכר.', 'diromax' ), '', array( 'response' => 400 ) );
	}

	check_admin_referer( 'dx_content_' . $group );

	$stored = get_option( DX_CONTENT_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	// Reset returns the whole group to the theme defaults.
	if ( isset( $_POST['dx_reset'] ) ) {
		unset( $stored[ $group ] );
		update_option( DX_CONTENT_OPTION, $stored, false );
		dx_content_redirect( $group, 'reset' );
	}

	$defaults = dx_content_defaults( $group );
	$posted   = isset( $_POST['dx'] ) && is_array( $_POST['dx'] ) ? wp_unslash( $_POST['dx'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- each field is sanitised by type below.
	$clean    = array();

	foreach ( $schema[ $group ]['fields'] as $key => $field ) {
		$type = $field[1];
		$cols = $field[2] ?? array();

		if ( ! array_key_exists( $key, $posted ) && 'repeater' !== $type && 'list' !== $type ) {
			continue;
		}

		$value = dx_sanitize_content_value( $posted[ $key ] ?? '', $type, $cols );

		// Anything identical to the default is not worth storing.
		if ( isset( $defaults[ $key ] ) && $value === $defaults[ $key ] ) {
			continue;
		}

		$clean[ $key ] = $value;
	}

	if ( $clean ) {
		$stored[ $group ] = $clean;
	} else {
		unset( $stored[ $group ] );
	}

	// autoload=false: the front end reads this once per request, and the option
	// stays out of the always-loaded set even on a heavily edited site.
	update_option( DX_CONTENT_OPTION, $stored, false );

	dx_content_redirect( $group, 'saved' );
}
add_action( 'admin_post_dx_save_content', 'dx_content_save' );

/**
 * Redirect back to the editor with a status flag.
 *
 * @param string $group  Group key.
 * @param string $status Status flag.
 * @return void
 */
function dx_content_redirect( $group, $status ) {
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'      => 'dx-content',
				'tab'       => $group,
				'dx_status' => $status,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Render one field's input.
 *
 * @param string           $key   Field key.
 * @param array<int,mixed> $field Schema entry.
 * @param mixed            $value Current value.
 * @return void
 */
function dx_content_field( $key, $field, $value ) {
	list( $label, $type ) = $field;
	$cols                 = $field[2] ?? array();
	$id                   = 'dx-' . $key;
	?>
	<tr>
		<th scope="row">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		</th>
		<td>
			<?php if ( 'text' === $type ) : ?>

				<input type="text" class="large-text" id="<?php echo esc_attr( $id ); ?>"
					name="dx[<?php echo esc_attr( $key ); ?>]"
					value="<?php echo esc_attr( (string) $value ); ?>">

			<?php elseif ( 'lines' === $type || 'textarea' === $type ) : ?>

				<textarea class="large-text" rows="<?php echo 'lines' === $type ? 2 : 3; ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="dx[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( (string) $value ); ?></textarea>
				<?php if ( 'lines' === $type ) : ?>
					<p class="description"><?php esc_html_e( 'כל שורה חדשה תוצג כשבירת שורה בכותרת.', 'diromax' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'list' === $type ) : ?>

				<textarea class="large-text" rows="<?php echo (int) max( 3, count( (array) $value ) + 1 ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="dx[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( implode( "\n", (array) $value ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'אפשרות אחת בכל שורה.', 'diromax' ); ?></p>

			<?php elseif ( 'repeater' === $type ) : ?>

				<table class="widefat dx-rep" data-dx-repeater="<?php echo esc_attr( $key ); ?>">
					<thead>
						<tr>
							<?php foreach ( $cols as $col_label ) : ?>
								<th scope="col"><?php echo esc_html( $col_label ); ?></th>
							<?php endforeach; ?>
							<th scope="col" class="dx-rep__act"><?php esc_html_e( 'פעולה', 'diromax' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$rows = (array) $value;
						foreach ( $rows as $index => $row ) :
							?>
							<tr>
								<?php foreach ( $cols as $col => $col_label ) : ?>
									<td>
										<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-' . $index . '-' . $col ); ?>">
											<?php echo esc_html( $label . ' — ' . $col_label ); ?>
										</label>
										<textarea rows="2" class="large-text"
											id="<?php echo esc_attr( $id . '-' . $index . '-' . $col ); ?>"
											name="dx[<?php echo esc_attr( $key ); ?>][<?php echo (int) $index; ?>][<?php echo esc_attr( $col ); ?>]"
										><?php echo esc_textarea( (string) ( is_array( $row ) ? ( $row[ $col ] ?? '' ) : '' ) ); ?></textarea>
									</td>
								<?php endforeach; ?>
								<td class="dx-rep__act">
									<button type="button" class="button-link dx-rep__del">
										<?php esc_html_e( 'מחיקה', 'diromax' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="button" class="button dx-rep__add"
						data-dx-add="<?php echo esc_attr( $key ); ?>"
						data-dx-cols="<?php echo esc_attr( (string) wp_json_encode( array_keys( $cols ) ) ); ?>">
						<?php esc_html_e( 'הוספת שורה', 'diromax' ); ?>
					</button>
				</p>

			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * The editor screen.
 *
 * @return void
 */
function dx_content_screen() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'אין לך הרשאה לגשת לעמוד הזה.', 'diromax' ) );
	}

	$schema = dx_content_schema();
	$tabs   = array_keys( $schema );

	// Read-only navigation state from the admin URL.
	$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status = isset( $_GET['dx_status'] ) ? sanitize_key( wp_unslash( $_GET['dx_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! in_array( $tab, $tabs, true ) ) {
		$tab = $tabs[0];
	}

	$group     = $schema[ $tab ];
	$defaults  = dx_content_defaults( $tab );
	$overrides = dx_content_overrides();
	?>
	<div class="wrap dx-content-wrap">
		<h1><?php esc_html_e( 'תוכן האתר', 'diromax' ); ?></h1>

		<?php if ( 'saved' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'התוכן נשמר.', 'diromax' ); ?></p></div>
		<?php elseif ( 'reset' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'המקטע הוחזר לטקסטים המקוריים של הערכה.', 'diromax' ); ?></p></div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'כל טקסט שמופיע באתר נמצא כאן. שדה שלא נגעת בו ממשיך להשתמש בטקסט המקורי של הערכה.', 'diromax' ); ?>
		</p>

		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'מקטעי התוכן', 'diromax' ); ?>">
			<?php foreach ( $schema as $key => $section ) : ?>
				<a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"
					<?php echo $key === $tab ? 'aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>
					href="<?php echo esc_url( admin_url( 'admin.php?page=dx-content&tab=' . $key ) ); ?>">
					<?php echo esc_html( $section['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( ! empty( $group['note'] ) ) : ?>
			<p class="description dx-content-note"><?php echo esc_html( $group['note'] ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dx_save_content">
			<input type="hidden" name="dx_group" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'dx_content_' . $tab ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php
					foreach ( $group['fields'] as $key => $field ) {
						$value = $overrides[ $tab ][ $key ] ?? ( $defaults[ $key ] ?? '' );
						dx_content_field( $key, $field, $value );
					}
					?>
				</tbody>
			</table>

			<p class="submit">
				<?php submit_button( __( 'שמירת התוכן', 'diromax' ), 'primary', 'submit', false ); ?>
				<button type="submit" name="dx_reset" value="1" class="button button-secondary dx-reset">
					<?php esc_html_e( 'החזרת המקטע לטקסט המקורי', 'diromax' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Screen assets: a small stylesheet and the repeater's add/remove behaviour.
 * Loaded only on this screen.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function dx_content_admin_assets( $hook ) {
	if ( 'toplevel_page_dx-content' !== $hook ) {
		return;
	}

	$css = '.dx-content-wrap .dx-rep{margin-bottom:8px}'
		. '.dx-content-wrap .dx-rep td{vertical-align:top}'
		. '.dx-content-wrap .dx-rep__act{width:90px;text-align:center}'
		. '.dx-content-wrap .dx-rep__del{color:#b32d2e}'
		. '.dx-content-wrap .dx-content-note{margin:12px 0 0}'
		. '.dx-content-wrap .form-table th{width:260px}'
		. '.dx-content-wrap .dx-reset{margin-inline-start:8px}';
	wp_register_style( 'dx-content-admin', false, array(), DX_VERSION );
	wp_enqueue_style( 'dx-content-admin' );
	wp_add_inline_style( 'dx-content-admin', $css );

	$js = <<<'JS'
(function(){
"use strict";
document.addEventListener("click",function(e){
  var del=e.target.closest(".dx-rep__del");
  if(del){ e.preventDefault(); var tr=del.closest("tr"); if(tr) tr.remove(); return; }

  var add=e.target.closest(".dx-rep__add");
  if(!add){ return; }
  e.preventDefault();

  var key=add.getAttribute("data-dx-add");
  var cols=JSON.parse(add.getAttribute("data-dx-cols")||"[]");
  var table=document.querySelector('[data-dx-repeater="'+key+'"] tbody');
  if(!table){ return; }

  var index=table.querySelectorAll("tr").length;
  var tr=document.createElement("tr");

  cols.forEach(function(col){
    var td=document.createElement("td");
    var ta=document.createElement("textarea");
    ta.rows=2; ta.className="large-text";
    ta.name="dx["+key+"]["+index+"]["+col+"]";
    ta.setAttribute("aria-label",key+" "+col);
    td.appendChild(ta); tr.appendChild(td);
  });

  var act=document.createElement("td");
  act.className="dx-rep__act";
  var btn=document.createElement("button");
  btn.type="button"; btn.className="button-link dx-rep__del"; btn.textContent="מחיקה";
  act.appendChild(btn); tr.appendChild(act);

  table.appendChild(tr);
  var first=tr.querySelector("textarea");
  if(first){ first.focus(); }
});
})();
JS;
	wp_register_script( 'dx-content-admin', false, array(), DX_VERSION, true );
	wp_enqueue_script( 'dx-content-admin' );
	wp_add_inline_script( 'dx-content-admin', $js );
}
add_action( 'admin_enqueue_scripts', 'dx_content_admin_assets' );
