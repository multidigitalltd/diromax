<?php
/**
 * The office's payments forecast.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Open payment requests across the active cases (spec 20.2): what is late,
 * what falls due this month and the next two, what comes later and what has
 * no date, each split between payments to Diromax and to suppliers; and each
 * case's budget against what is paid and committed.
 *
 * Pure: no WordPress, no database, no clock. "Today" is an argument.
 *
 * @phpstan-type OpenRequest array{id:int, case_id:int, number:string, case_title:string, description:string, total:int, due_date:string|null, to_diromax:bool, payee:string}
 * @phpstan-type Bucket array{key:string, from:string, to:string, count:int, diromax:int, suppliers:int, total:int}
 * @phpstan-type CaseMoney array{case_id:int, number:string, title:string, budget:int, cost:int, committed:int}
 * @phpstan-type LateRequest array{id:int, case_id:int, number:string, case_title:string, description:string, total:int, due_date:string|null, to_diromax:bool, payee:string, days:int}
 * @phpstan-type CaseVariance array{case_id:int, number:string, title:string, budget:int, cost:int, committed:int, forecast:int, remaining:int, used_bps:int, over:bool, near:bool}
 */
final class Forecast {

	/** Months shown one by one, the current one included. */
	public const MONTHS = 3;

	/**
	 * Requests by when they fall due.
	 *
	 * @param OpenRequest[] $requests Open requests.
	 * @param string        $today    Y-m-d.
	 * @return Bucket[] overdue, then each month ("Y-m"), then later, then undated.
	 */
	public static function buckets( array $requests, string $today ): array {
		$month   = new \DateTimeImmutable( substr( $today, 0, 7 ) . '-01' );
		$buckets = array( 'overdue' => self::bucket( 'overdue', '', $today ) );

		for ( $i = 0; $i < self::MONTHS; $i++ ) {
			$start = $month->modify( '+' . $i . ' months' );
			$key   = $start->format( 'Y-m' );

			$buckets[ $key ] = self::bucket( $key, 0 === $i ? $today : $start->format( 'Y-m-d' ), $start->modify( '+1 month' )->format( 'Y-m-d' ) );
		}

		$buckets['later']   = self::bucket( 'later', $month->modify( '+' . self::MONTHS . ' months' )->format( 'Y-m-d' ), '' );
		$buckets['undated'] = self::bucket( 'undated', '', '' );

		foreach ( $requests as $request ) {
			$key = self::key_for( $request['due_date'], $today, $month );

			$bucket = $buckets[ $key ] ?? self::bucket( $key, '', '' );
			++$bucket['count'];
			$bucket['total'] += $request['total'];

			if ( $request['to_diromax'] ) {
				$bucket['diromax'] += $request['total'];
			} else {
				$bucket['suppliers'] += $request['total'];
			}

			$buckets[ $key ] = $bucket;
		}

		return array_values( $buckets );
	}

	/**
	 * Late requests, longest late first.
	 *
	 * @param OpenRequest[] $requests Open requests.
	 * @param string        $today    Y-m-d.
	 * @return LateRequest[]
	 */
	public static function overdue( array $requests, string $today ): array {
		$late = array();

		foreach ( $requests as $request ) {
			if ( null !== $request['due_date'] && $request['due_date'] < $today ) {
				$late[] = $request + array( 'days' => self::days( $request['due_date'], $today ) );
			}
		}

		usort( $late, static fn( array $a, array $b ): int => array( $b['days'], $b['total'] ) <=> array( $a['days'], $a['total'] ) );

		return $late;
	}

	/**
	 * Budget against paid and committed, per case: over budget first (largest
	 * overrun first), then by how much of the budget is used. A case with no
	 * budget and nothing spent is left out.
	 *
	 * @param CaseMoney[] $cases Active cases with their summary numbers.
	 * @return CaseVariance[]
	 */
	public static function variance( array $cases ): array {
		$rows = array();

		foreach ( $cases as $case ) {
			$forecast = $case['cost'] + $case['committed'];

			if ( 0 === $case['budget'] && 0 === $forecast ) {
				continue;
			}

			$used   = $case['budget'] > 0 ? Money::share_bps( $forecast, $case['budget'] ) : Money::BPS_WHOLE * 10;
			$rows[] = $case + array(
				'forecast'  => $forecast,
				'remaining' => $case['budget'] - $forecast,
				'used_bps'  => $used,
				'over'      => $forecast > $case['budget'],
				'near'      => $forecast <= $case['budget'] && $used >= 9000,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( $a['over'] !== $b['over'] ) {
					return $a['over'] ? -1 : 1;
				}

				// Overruns: the largest first. The rest: the fullest first.
				return $a['over'] ? $a['remaining'] <=> $b['remaining'] : $b['used_bps'] <=> $a['used_bps'];
			}
		);

		return $rows;
	}

	/**
	 * Which bucket a due date falls in.
	 *
	 * @param string|null        $due   Y-m-d or null.
	 * @param string             $today Y-m-d.
	 * @param \DateTimeImmutable $month First day of this month.
	 * @return string
	 */
	private static function key_for( ?string $due, string $today, \DateTimeImmutable $month ): string {
		if ( null === $due || '' === $due ) {
			return 'undated';
		}

		if ( $due < $today ) {
			return 'overdue';
		}

		$last = $month->modify( '+' . self::MONTHS . ' months' )->format( 'Y-m-d' );

		return $due >= $last ? 'later' : substr( $due, 0, 7 );
	}

	/**
	 * An empty bucket.
	 *
	 * @param string $key  Key.
	 * @param string $from First day, '' when open.
	 * @param string $to   Day after the last, '' when open.
	 * @return Bucket
	 */
	private static function bucket( string $key, string $from, string $to ): array {
		return array(
			'key'       => $key,
			'from'      => $from,
			'to'        => $to,
			'count'     => 0,
			'diromax'   => 0,
			'suppliers' => 0,
			'total'     => 0,
		);
	}

	/**
	 * Whole days from one date to a later one.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	private static function days( string $from, string $to ): int {
		return intdiv( (int) strtotime( $to . ' UTC' ) - (int) strtotime( $from . ' UTC' ), 86400 );
	}
}
