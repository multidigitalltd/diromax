<?php
/**
 * Timeline layout.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The geometry of a case's timeline (spec 22): where each planned and actual
 * bar, milestone and "today" sits along the range, in percent, so a
 * template can draw it with no script. Also works out a plan from typical
 * stage lengths.
 *
 * Pure: no WordPress, no database. Dates are Y-m-d.
 *
 * @phpstan-type TimelineItem array{start:string|null, end:string|null, actual_start:string|null, actual_end:string|null}
 * @phpstan-type Span array{offset:float, width:float}
 */
final class Timeline {

	/** Longest stage, in weeks. */
	public const MAX_WEEKS = 104;

	/**
	 * Place items on a common range that also holds today.
	 *
	 * @param array<int|string, array{start:string|null, end:string|null, actual_start:string|null, actual_end:string|null}> $items Items: a stage has a start and an end; a milestone only an end.
	 * @param string                                                                                                         $today Today.
	 * @return array{from:string, to:string, today:float, months:array<int, array{month:string, offset:float}>, items:array<int|string, array{plan:array{offset:float, width:float}|null, actual:array{offset:float, width:float}|null, mark:float|null}>}|null Null when nothing is dated.
	 */
	public static function layout( array $items, string $today ): ?array {
		$dates = array();

		foreach ( $items as $item ) {
			foreach ( array( 'start', 'end', 'actual_start', 'actual_end' ) as $key ) {
				if ( null !== $item[ $key ] && self::is_date( $item[ $key ] ) ) {
					$dates[] = $item[ $key ];
				}
			}
		}

		if ( array() === $dates ) {
			return null;
		}

		$dates[] = $today;
		$from    = min( $dates );
		$to      = max( $dates );
		$span    = self::days( $from, $to ) + 1;
		$placed  = array();

		foreach ( $items as $key => $item ) {
			$mark           = null === $item['start'] && null !== $item['end'] ? self::at( $from, $item['end'], $span ) : null;
			$placed[ $key ] = array(
				'plan'   => null === $mark ? self::bar( $from, $span, $item['start'], $item['end'] ) : null,
				// Work under way runs to today.
				'actual' => self::bar( $from, $span, $item['actual_start'], $item['actual_end'] ?? ( null === $item['actual_start'] ? null : $today ) ),
				'mark'   => $mark,
			);
		}

		return array(
			'from'   => $from,
			'to'     => $to,
			'today'  => self::at( $from, $today, $span ),
			'months' => self::months( $from, $to, $span ),
			'items'  => $placed,
		);
	}

	/**
	 * A plan from typical stage lengths: each stage with a length starts the
	 * day after the one before it ends. Stages without one are left out.
	 *
	 * @param string          $start First day.
	 * @param array<int, int> $weeks Weeks by stage number.
	 * @return array<int, array{start:string, end:string}>
	 */
	public static function from_weeks( string $start, array $weeks ): array {
		ksort( $weeks );
		$cursor = new \DateTimeImmutable( $start, new \DateTimeZone( 'UTC' ) );
		$plan   = array();

		foreach ( $weeks as $stage => $length ) {
			if ( $length < 1 ) {
				continue;
			}

			$end            = $cursor->modify( '+' . ( $length * 7 - 1 ) . ' days' );
			$plan[ $stage ] = array(
				'start' => $cursor->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			);
			$cursor         = $end->modify( '+1 day' );
		}

		return $plan;
	}

	/**
	 * A real Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	public static function is_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Days from one date to another.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int Negative when $to is earlier.
	 */
	public static function days( string $from, string $to ): int {
		$utc = new \DateTimeZone( 'UTC' );

		return (int) round( ( ( new \DateTimeImmutable( $to, $utc ) )->getTimestamp() - ( new \DateTimeImmutable( $from, $utc ) )->getTimestamp() ) / 86400 );
	}

	/**
	 * A bar from one day to another, both included.
	 *
	 * @param string      $from  Range start.
	 * @param int         $span  Range length in days.
	 * @param string|null $start First day.
	 * @param string|null $end   Last day.
	 * @return array{offset:float, width:float}|null
	 */
	private static function bar( string $from, int $span, ?string $start, ?string $end ): ?array {
		if ( null === $start || null === $end || $end < $start ) {
			return null;
		}

		return array(
			'offset' => self::at( $from, $start, $span ),
			'width'  => round( ( self::days( $start, $end ) + 1 ) * 100 / $span, 2 ),
		);
	}

	/**
	 * Where a day starts along the range, in percent.
	 *
	 * @param string $from Range start.
	 * @param string $date Day.
	 * @param int    $span Range length in days.
	 * @return float
	 */
	private static function at( string $from, string $date, int $span ): float {
		return round( self::days( $from, $date ) * 100 / $span, 2 );
	}

	/**
	 * The first day of each month inside the range.
	 *
	 * @param string $from Range start.
	 * @param string $to   Range end.
	 * @param int    $span Range length in days.
	 * @return array<int, array{month:string, offset:float}> Month as Y-m.
	 */
	private static function months( string $from, string $to, int $span ): array {
		$utc    = new \DateTimeZone( 'UTC' );
		$month  = ( new \DateTimeImmutable( $from, $utc ) )->modify( 'first day of next month' );
		$last   = new \DateTimeImmutable( $to, $utc );
		$months = array();

		if ( '01' === substr( $from, 8, 2 ) ) {
			$month = new \DateTimeImmutable( $from, $utc );
		}

		// Five years of months at most: a longer plan still draws, with fewer labels.
		for ( $left = 60; $month <= $last && $left > 0; $left-- ) {
			$months[] = array(
				'month'  => $month->format( 'Y-m' ),
				'offset' => self::at( $from, $month->format( 'Y-m-d' ), $span ),
			);
			$month    = $month->modify( '+1 month' );
		}

		return $months;
	}
}
