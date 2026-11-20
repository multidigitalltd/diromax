<?php
/**
 * Stage names.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Domain\StageProgress;

defined( 'ABSPATH' ) || exit;

/**
 * The seven process stages, named exactly as on the site's home page.
 *
 * When the Diromax theme is active its editable content (improve.steps) is the
 * source, so renaming a stage in "תוכן האתר" renames it in the portal too.
 */
final class Stages {

	/** Number of stages. */
	public const COUNT = StageProgress::COUNT;

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
	 * Stage number as shown: 01–07.
	 *
	 * @param int $number Stage.
	 * @return string
	 */
	public static function number( int $number ): string {
		return str_pad( (string) $number, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * "03 · name".
	 *
	 * @param int $number Stage.
	 * @return string
	 */
	public static function label( int $number ): string {
		return self::number( $number ) . ' · ' . self::name( $number );
	}

	/**
	 * Status of each stage, keyed 1–7.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages Stage states.
	 * @return array<int, string>
	 */
	public static function statuses( array $stages ): array {
		return array_map( static fn( array $state ): string => $state['status'], $stages );
	}

	/**
	 * Where a case stands, in a few words: the stage in progress, the
	 * stages in progress when there are several, the next stage when none
	 * is, or that all are done.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages Stage states.
	 * @return string
	 */
	public static function summary( array $stages ): string {
		$active = StageProgress::active( $stages );

		if ( 1 === count( $active ) ) {
			return self::label( $active[0] );
		}

		if ( count( $active ) > 1 ) {
			/* translators: %s: stage numbers, e.g. "03, 04". */
			return sprintf( __( 'שלבים %s בתהליך', 'diromax-portal' ), implode( ', ', array_map( array( self::class, 'number' ), $active ) ) );
		}

		if ( StageProgress::finished( $stages ) ) {
			return __( 'כל השלבים הושלמו', 'diromax-portal' );
		}

		/* translators: %s: stage number and name. */
		return sprintf( __( 'הבא: %s', 'diromax-portal' ), self::label( StageProgress::lead( $stages ) ) );
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
