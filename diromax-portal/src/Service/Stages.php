<?php
/**
 * Stage names.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

defined( 'ABSPATH' ) || exit;

/**
 * The seven process stages, named exactly as on the site's home page.
 *
 * When the Diromax theme is active its editable content (improve.steps) is the
 * source, so renaming a stage in "תוכן האתר" renames it in the portal too.
 */
final class Stages {

	/** Number of stages. */
	public const COUNT = 7;

	/**
	 * Stage names keyed 1–7.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		$names = self::defaults();

		if ( function_exists( 'dx_rows' ) ) {
			$rows = dx_rows( 'improve.steps' );

			foreach ( array_values( (array) $rows ) as $index => $row ) {
				$title = is_array( $row ) && isset( $row['t'] ) && is_string( $row['t'] ) ? trim( $row['t'] ) : '';

				if ( '' !== $title && $index < self::COUNT ) {
					$names[ $index + 1 ] = $title;
				}
			}
		}

		return $names;
	}

	/**
	 * Name of one stage.
	 *
	 * @param int $number Stage, 1–7.
	 * @return string
	 */
	public static function name( int $number ): string {
		return self::names()[ $number ] ?? '';
	}

	/**
	 * Built-in names, matching the theme defaults.
	 *
	 * @return array<int, string>
	 */
	private static function defaults(): array {
		return array(
			1 => __( 'בדיקת פוטנציאל וזכויות בנייה', 'diromax-portal' ),
			2 => __( 'אסטרטגיה ותוכנית עבודה', 'diromax-portal' ),
			3 => __( 'ליווי תכנוני — אדריכל ויועצים', 'diromax-portal' ),
			4 => __( 'החתמת שכנים והסכמות', 'diromax-portal' ),
			5 => __( 'הוצאת היתר בנייה', 'diromax-portal' ),
			6 => __( 'ניהול ביצוע ופיקוח על הקבלנים', 'diromax-portal' ),
			7 => __( 'שיווק ומכירה בשווי החדש', 'diromax-portal' ),
		);
	}
}
