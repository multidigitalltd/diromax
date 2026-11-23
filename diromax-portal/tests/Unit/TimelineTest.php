<?php
/**
 * Tests for the timeline geometry and the plan from stage lengths (spec 22).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\Timeline;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\Timeline
 */
final class TimelineTest extends TestCase {

	public function test_nothing_dated_draws_nothing(): void {
		$this->assertNull(
			Timeline::layout(
				array(
					1 => array(
						'start'        => null,
						'end'          => null,
						'actual_start' => null,
						'actual_end'   => null,
					),
				),
				'2027-01-10'
			)
		);
	}

	public function test_bars_milestones_and_today_share_one_range(): void {
		$layout = Timeline::layout(
			array(
				'stage-1' => array(
					'start'        => '2027-01-01',
					'end'          => '2027-01-10',
					'actual_start' => '2027-01-03',
					'actual_end'   => null,
				),
				'stage-2' => array(
					'start'        => '2027-01-11',
					'end'          => '2027-02-09',
					'actual_start' => null,
					'actual_end'   => null,
				),
				'm-7'     => array(
					'start'        => null,
					'end'          => '2027-01-21',
					'actual_start' => null,
					'actual_end'   => null,
				),
			),
			'2027-01-06'
		);

		$this->assertNotNull( $layout );
		// 2027-01-01 to 2027-02-09: 40 days.
		$this->assertSame( array( '2027-01-01', '2027-02-09', 12.5 ), array( $layout['from'], $layout['to'], $layout['today'] ) );
		$this->assertSame(
			array(
				'offset' => 0.0,
				'width'  => 25.0,
			),
			$layout['items']['stage-1']['plan']
		);
		$this->assertSame(
			array(
				'offset' => 5.0,
				'width'  => 10.0,
			),
			$layout['items']['stage-1']['actual'],
			'Work under way runs to today.'
		);
		$this->assertSame(
			array(
				'offset' => 25.0,
				'width'  => 75.0,
			),
			$layout['items']['stage-2']['plan']
		);
		$this->assertNull( $layout['items']['stage-2']['actual'] );
		$this->assertSame( array( null, null, 50.0 ), array( $layout['items']['m-7']['plan'], $layout['items']['m-7']['actual'], $layout['items']['m-7']['mark'] ) );
		$this->assertSame(
			array(
				array(
					'month'  => '2027-01',
					'offset' => 0.0,
				),
				array(
					'month'  => '2027-02',
					'offset' => 77.5,
				),
			),
			$layout['months']
		);
	}

	public function test_today_outside_the_plan_widens_the_range(): void {
		$layout = Timeline::layout(
			array(
				1 => array(
					'start'        => '2027-03-01',
					'end'          => '2027-03-10',
					'actual_start' => null,
					'actual_end'   => null,
				),
			),
			'2027-02-19'
		);

		$this->assertSame( array( '2027-02-19', '2027-03-10', 0.0 ), array( $layout['from'] ?? '', $layout['to'] ?? '', $layout['today'] ?? -1.0 ) );
	}

	public function test_a_plan_from_stage_lengths_runs_stage_after_stage(): void {
		$this->assertSame(
			array(
				1 => array(
					'start' => '2027-01-03',
					'end'   => '2027-01-16',
				),
				3 => array(
					'start' => '2027-01-17',
					'end'   => '2027-01-23',
				),
			),
			Timeline::from_weeks(
				'2027-01-03',
				array(
					3 => 1,
					1 => 2,
					2 => 0,
				)
			),
			'Stages without a length are left out.'
		);
	}

	public function test_dates_and_day_counts(): void {
		$this->assertTrue( Timeline::is_date( '2028-02-29' ) );
		$this->assertFalse( Timeline::is_date( '2027-02-29' ) );
		$this->assertFalse( Timeline::is_date( '2027-1-5' ) );
		$this->assertSame( 365, Timeline::days( '2027-01-01', '2028-01-01' ) );
		$this->assertSame( -2, Timeline::days( '2027-03-28', '2027-03-26' ), 'Across a clock change, still whole days.' );
	}
}
