<?php
/**
 * Customizer — the values the client edits without touching code.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register sections, settings and controls.
 *
 * @param WP_Customize_Manager $wp_customize Customizer manager.
 */
function dx_customize_register( $wp_customize ) {

	/* ---------------------------------------------------------------
	 * Contact details
	 * ------------------------------------------------------------ */
	$wp_customize->add_section(
		'dx_contact',
		array(
			'title'       => __( 'דירומקס — פרטי קשר', 'diromax' ),
			'description' => __( 'הפרטים האלה מופיעים בפוטר, בעמוד צור קשר, בסרגל התחתון ובסימון הסכמה (Schema).', 'diromax' ),
			'priority'    => 20,
		)
	);

	$text_fields = array(
		'dx_phone'    => array(
			'label'    => __( 'טלפון להצגה', 'diromax' ),
			'default'  => '054-000-0000',
			'sanitize' => 'sanitize_text_field',
		),
		'dx_whatsapp' => array(
			'label'    => __( 'וואטסאפ (פורמט בינלאומי, לדוגמה 972541234567)', 'diromax' ),
			'default'  => '972540000000',
			'sanitize' => 'sanitize_text_field',
		),
		'dx_email'    => array(
			'label'    => __( 'דוא״ל', 'diromax' ),
			'default'  => 'info@diromax.co.il',
			'sanitize' => 'sanitize_email',
		),
		'dx_area'     => array(
			'label'    => __( 'אזור פעילות', 'diromax' ),
			'default'  => 'להשלמה',
			'sanitize' => 'sanitize_text_field',
		),
		'dx_lead_to'  => array(
			'label'    => __( 'כתובת לקבלת לידים (ריק = מייל המנהל)', 'diromax' ),
			'default'  => '',
			'sanitize' => 'sanitize_email',
		),
	);

	foreach ( $text_fields as $id => $args ) {
		$wp_customize->add_setting(
			$id,
			array(
				'default'           => $args['default'],
				'sanitize_callback' => $args['sanitize'],
				'transport'         => 'refresh',
			)
		);
		$wp_customize->add_control(
			$id,
			array(
				'label'   => $args['label'],
				'section' => 'dx_contact',
				'type'    => 'sanitize_email' === $args['sanitize'] ? 'email' : 'text',
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Display
	 * ------------------------------------------------------------ */
	$wp_customize->add_section(
		'dx_layout',
		array(
			'title'    => __( 'דירומקס — תצוגה', 'diromax' ),
			'priority' => 21,
		)
	);

	$wp_customize->add_setting( 'dx_hero_image', array( 'sanitize_callback' => 'absint' ) );
	$wp_customize->add_control(
		new WP_Customize_Media_Control(
			$wp_customize,
			'dx_hero_image',
			array(
				'label'       => __( 'תמונת הירו (עמוד השבחה)', 'diromax' ),
				'description' => __( 'פורטרט, יחס 3:4. מוצגת בשחור־לבן.', 'diromax' ),
				'section'     => 'dx_layout',
				'mime_type'   => 'image',
			)
		)
	);

	$wp_customize->add_setting(
		'dx_hero_alt',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'dx_hero_alt',
		array(
			'label'       => __( 'תיאור תמונת ההירו (טקסט חלופי)', 'diromax' ),
			'description' => __( 'תיאור קצר של מה שרואים בתמונה — נדרש לנגישות.', 'diromax' ),
			'section'     => 'dx_layout',
			'type'        => 'text',
		)
	);

	$toggles = array(
		'dx_sticky_bar'    => __( 'סרגל קשר קבוע בתחתית המסך', 'diromax' ),
		'dx_show_projects' => __( 'הצגת מקטע "לפני ואחרי"', 'diromax' ),
	);

	foreach ( $toggles as $id => $label ) {
		$wp_customize->add_setting(
			$id,
			array(
				'default'           => true,
				'sanitize_callback' => 'dx_sanitize_bool',
			)
		);
		$wp_customize->add_control(
			$id,
			array(
				'label'   => $label,
				'section' => 'dx_layout',
				'type'    => 'checkbox',
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Accessibility
	 * ------------------------------------------------------------ */
	$wp_customize->add_section(
		'dx_a11y',
		array(
			'title'       => __( 'דירומקס — נגישות', 'diromax' ),
			'description' => __( 'הפרטים כאן מוזנים אוטומטית להצהרת הנגישות, כנדרש בתקנות.', 'diromax' ),
			'priority'    => 22,
		)
	);

	$wp_customize->add_setting(
		'dx_a11y_toolbar',
		array(
			'default'           => true,
			'sanitize_callback' => 'dx_sanitize_bool',
		)
	);
	$wp_customize->add_control(
		'dx_a11y_toolbar',
		array(
			'label'   => __( 'הצגת סרגל כלי הנגישות', 'diromax' ),
			'section' => 'dx_a11y',
			'type'    => 'checkbox',
		)
	);

	$a11y_fields = array(
		'dx_a11y_name'    => array(
			'label'    => __( 'שם רכז/ת הנגישות', 'diromax' ),
			'default'  => '',
			'sanitize' => 'sanitize_text_field',
			'type'     => 'text',
		),
		'dx_a11y_phone'   => array(
			'label'    => __( 'טלפון רכז/ת הנגישות (ריק = הטלפון הראשי)', 'diromax' ),
			'default'  => '',
			'sanitize' => 'sanitize_text_field',
			'type'     => 'text',
		),
		'dx_a11y_email'   => array(
			'label'    => __( 'דוא״ל לפניות נגישות (ריק = הדוא״ל הראשי)', 'diromax' ),
			'default'  => '',
			'sanitize' => 'sanitize_email',
			'type'     => 'email',
		),
		'dx_a11y_updated' => array(
			'label'    => __( 'תאריך עדכון ההצהרה', 'diromax' ),
			'default'  => '',
			'sanitize' => 'sanitize_text_field',
			'type'     => 'date',
		),
	);

	foreach ( $a11y_fields as $id => $args ) {
		$wp_customize->add_setting(
			$id,
			array(
				'default'           => $args['default'],
				'sanitize_callback' => $args['sanitize'],
			)
		);
		$wp_customize->add_control(
			$id,
			array(
				'label'   => $args['label'],
				'section' => 'dx_a11y',
				'type'    => $args['type'],
			)
		);
	}
}
add_action( 'customize_register', 'dx_customize_register' );

/**
 * Checkbox sanitiser.
 *
 * @param mixed $value Raw value.
 * @return bool
 */
function dx_sanitize_bool( $value ) {
	return ( isset( $value ) && (bool) $value );
}
