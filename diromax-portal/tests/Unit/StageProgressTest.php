<?php
/**
 * Tests for StageProgress.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\StageProgress;
use Diromax\Portal\Domain\StageStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\StageProgress
 */
final class StageProgressTest extends TestCase {

	private const TODAY = '2026-11-20';

	/**
	 * Seven stages with the given statuses, no dates.
	 *
	 * @param string ...$statuses One per stage, in order.
	 * @return array<int, array{status:string, started:string|null, done:string|null}>
	 */
	private static function stages( string ...$statuses ): array {
		$stages = array();

		foreach ( $statuses as $index => $status ) {
			$stages[ $index + 1 ] = array(
				'status'  => $status,
				'started' => null,
				'done'    => null,
			);
		}

		return $stages;
	}

	/**
	 * Statuses only, keyed 1–7.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages States.
	 * @return string[]
	 */
	private static function statuses( array $stages ): array {
		return array_values( array_column( $stages, 'status' ) );
	}

	public function test_several_stages_can_be_in_progress_at_once(): void {
		$stages = StageProgress::set(
			self::stages( 'done', 'done', 'active', 'pending', 'pending', 'pending', 'pending' ),
			array( 4 => StageStatus::Active ),
			self::TODAY
		);

		$this->assertSame( array( 'done', 'done', 'active', 'active', 'pending', 'pending', 'pending' ), self::statuses( $stages ) );
		$this->assertSame( array( 3, 4 ), StageProgress::active( $stages ) );
		$this->assertSame( 4, StageProgress::lead( $stages ), 'The furthest stage in progress leads.' );
		$this->assertSame( self::TODAY, $stages[4]['started'] );
	}

	public function test_dates_are_set_once_and_cleared_when_a_stage_goes_back(): void {
		$stages = self::stages( 'active', 'pending', 'pending', 'pending', 'pending', 'pending', 'pending' );

		$stages[1]['started'] = '2026-10-01';

		$done = StageProgress::set( $stages, array( 1 => StageStatus::Done ), self::TODAY );
		$this->assertSame( '2026-10-01', $done[1]['started'], 'The start date is kept.' );
		$this->assertSame( self::TODAY, $done[1]['done'] );

		$again = StageProgress::set( $done, array( 1 => StageStatus::Done ), '2026-12-31' );
		$this->assertSame( $done, $again, 'Saving the same status again changes nothing.' );

		$back = StageProgress::set( $done, array( 1 => StageStatus::Pending ), self::TODAY );
		$this->assertNull( $back[1]['started'] );
		$this->assertNull( $back[1]['done'] );

		$reopened = StageProgress::set( $done, array( 1 => StageStatus::Active ), self::TODAY );
		$this->assertNull( $reopened[1]['done'], 'A stage back in progress is no longer done.' );
		$this->assertSame( '2026-10-01', $reopened[1]['started'] );
	}

	public function test_advance_finishes_the_earlier_stages_and_keeps_skipped_ones(): void {
		$stages = StageProgress::advance(
			self::stages( 'active', 'skipped', 'active', 'active', 'pending', 'done', 'pending' ),
			5,
			self::TODAY
		);

		$this->assertSame( array( 'done', 'skipped', 'done', 'done', 'active', 'pending', 'pending' ), self::statuses( $stages ) );
		$this->assertNull( $stages[6]['done'], 'A later stage is no longer done.' );
	}

	public function test_advance_into_a_skipped_stage_makes_it_relevant_again(): void {
		$stages = StageProgress::advance( self::stages( 'done', 'skipped', 'pending', 'pending', 'pending', 'pending', 'pending' ), 2, self::TODAY );

		$this->assertSame( 'active', $stages[2]['status'] );
	}

	public function test_start_changes_only_that_stage(): void {
		$before = self::stages( 'done', 'active', 'pending', 'pending', 'pending', 'pending', 'pending' );
		$after  = StageProgress::start( $before, 4, self::TODAY );

		$this->assertSame( array( 'done', 'active', 'pending', 'active', 'pending', 'pending', 'pending' ), self::statuses( $after ) );
	}

	public function test_lead_without_a_stage_in_progress(): void {
		$this->assertSame( 1, StageProgress::lead( self::stages( 'pending', 'pending', 'pending', 'pending', 'pending', 'pending', 'pending' ) ), 'A new case.' );
		$this->assertSame( 4, StageProgress::lead( self::stages( 'done', 'done', 'done', 'pending', 'pending', 'pending', 'pending' ) ), 'The next stage.' );
		$this->assertSame( 6, StageProgress::lead( self::stages( 'done', 'done', 'done', 'done', 'done', 'done', 'skipped' ) ), 'The last one done.' );
		$this->assertSame( 1, StageProgress::lead( self::stages( 'skipped', 'skipped', 'skipped', 'skipped', 'skipped', 'skipped', 'skipped' ) ) );
	}

	public function test_finished(): void {
		$this->assertTrue( StageProgress::finished( self::stages( 'done', 'done', 'skipped', 'done', 'done', 'done', 'done' ) ) );
		$this->assertFalse( StageProgress::finished( self::stages( 'done', 'done', 'done', 'done', 'done', 'done', 'active' ) ) );
		$this->assertFalse( StageProgress::finished( self::stages( 'skipped', 'skipped', 'skipped', 'skipped', 'skipped', 'skipped', 'skipped' ) ) );
	}

	public function test_parse_accepts_only_known_stages_and_statuses(): void {
		$this->assertSame(
			array(
				1 => StageStatus::Done,
				3 => StageStatus::Active,
				4 => StageStatus::Active,
			),
			StageProgress::parse( '1:done,3:active,4:active' )
		);
		$this->assertSame( array(), StageProgress::parse( '' ) );

		foreach ( array( '8:active', '0:done', 'x:done', '2:started', '2', '2:done:1', '2:done,2:active', '-1:done' ) as $bad ) {
			$this->assertNull( StageProgress::parse( $bad ), $bad );
		}
	}

	public function test_days_in_a_stage(): void {
		$state = array(
			'status'  => 'active',
			'started' => '2026-11-01',
			'done'    => null,
		);

		$this->assertSame( 19, StageProgress::days_in( $state, self::TODAY ) );
		$this->assertNull( StageProgress::days_in( array( 'status' => 'done' ) + $state, self::TODAY ) );
		$this->assertNull( StageProgress::days_in( array( 'started' => null ) + $state, self::TODAY ) );
	}
}
