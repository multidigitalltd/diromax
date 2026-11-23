<?php
/**
 * Tests for the payments forecast (spec 20.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\Forecast;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\Forecast
 */
final class ForecastTest extends TestCase {

	/**
	 * An open request.
	 *
	 * @param int         $id         ID.
	 * @param string|null $due        Due date.
	 * @param int         $total      Agorot.
	 * @param bool        $to_diromax Paid to Diromax.
	 * @return array{id:int, case_id:int, number:string, case_title:string, description:string, total:int, due_date:string|null, to_diromax:bool, payee:string}
	 */
	private static function request( int $id, ?string $due, int $total, bool $to_diromax ): array {
		return array(
			'id'          => $id,
			'case_id'     => 1,
			'number'      => '2026-001',
			'case_title'  => 'הזית 14',
			'description' => 'דרישה ' . $id,
			'total'       => $total,
			'due_date'    => $due,
			'to_diromax'  => $to_diromax,
			'payee'       => '',
		);
	}

	public function test_requests_fall_into_late_months_later_and_undated(): void {
		$requests = array(
			self::request( 1, '2026-11-01', 1000, true ),
			self::request( 2, '2026-11-23', 2000, false ),
			self::request( 3, '2026-11-30', 3000, true ),
			self::request( 4, '2026-12-15', 4000, false ),
			self::request( 5, '2027-01-31', 5000, true ),
			self::request( 6, '2027-02-01', 6000, false ),
			self::request( 7, null, 7000, true ),
		);

		$buckets = array_column( Forecast::buckets( $requests, '2026-11-23' ), null, 'key' );

		$this->assertSame( array( 'overdue', '2026-11', '2026-12', '2027-01', 'later', 'undated' ), array_keys( $buckets ) );
		$this->assertSame( array( 1, 1000, 0, 1000 ), array( $buckets['overdue']['count'], $buckets['overdue']['diromax'], $buckets['overdue']['suppliers'], $buckets['overdue']['total'] ) );
		$this->assertSame( array( 2, 3000, 2000, 5000 ), array( $buckets['2026-11']['count'], $buckets['2026-11']['diromax'], $buckets['2026-11']['suppliers'], $buckets['2026-11']['total'] ), 'Due today counts for this month.' );
		$this->assertSame( 4000, $buckets['2026-12']['suppliers'] );
		$this->assertSame( 5000, $buckets['2027-01']['diromax'] );
		$this->assertSame( array( '2027-02-01', '' ), array( $buckets['later']['from'], $buckets['later']['to'] ) );
		$this->assertSame( 6000, $buckets['later']['total'] );
		$this->assertSame( 7000, $buckets['undated']['total'] );
		$this->assertSame( '2026-11-23', $buckets['2026-11']['from'], 'This month starts today: earlier days are late.' );
	}

	public function test_late_requests_longest_late_first(): void {
		$late = Forecast::overdue(
			array(
				self::request( 1, '2026-11-20', 100, true ),
				self::request( 2, '2026-10-23', 200, false ),
				self::request( 3, '2026-11-23', 300, true ),
				self::request( 4, null, 400, true ),
			),
			'2026-11-23'
		);

		$this->assertSame( array( 2, 1 ), array_column( $late, 'id' ) );
		$this->assertSame( array( 31, 3 ), array_column( $late, 'days' ) );
	}

	public function test_variance_puts_overruns_first_then_the_fullest(): void {
		$rows = Forecast::variance(
			array(
				array(
					'case_id'   => 1,
					'number'    => 'a',
					'title'     => 'Under',
					'budget'    => 10000,
					'cost'      => 2000,
					'committed' => 1000,
				),
				array(
					'case_id'   => 2,
					'number'    => 'b',
					'title'     => 'Small overrun',
					'budget'    => 10000,
					'cost'      => 9000,
					'committed' => 2000,
				),
				array(
					'case_id'   => 3,
					'number'    => 'c',
					'title'     => 'Big overrun',
					'budget'    => 10000,
					'cost'      => 15000,
					'committed' => 0,
				),
				array(
					'case_id'   => 4,
					'number'    => 'd',
					'title'     => 'Near',
					'budget'    => 10000,
					'cost'      => 9500,
					'committed' => 0,
				),
				array(
					'case_id'   => 5,
					'number'    => 'e',
					'title'     => 'Empty',
					'budget'    => 0,
					'cost'      => 0,
					'committed' => 0,
				),
				array(
					'case_id'   => 6,
					'number'    => 'f',
					'title'     => 'No budget',
					'budget'    => 0,
					'cost'      => 500,
					'committed' => 0,
				),
			)
		);

		$this->assertSame( array( 3, 2, 6, 4, 1 ), array_column( $rows, 'case_id' ), 'Overruns by size, spending without a budget among them, then the fullest.' );
		$this->assertSame( array( true, false ), array( $rows[0]['over'], $rows[0]['near'] ) );
		$this->assertSame( -5000, $rows[0]['remaining'] );
		$this->assertTrue( $rows[2]['over'] );
		$this->assertSame( array( false, true ), array( $rows[3]['over'], $rows[3]['near'] ) );
		$this->assertSame( 3000, $rows[4]['used_bps'] );
	}
}
