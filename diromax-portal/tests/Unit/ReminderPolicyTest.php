<?php
/**
 * Tests for ReminderPolicy.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\ReminderPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\ReminderPolicy
 */
final class ReminderPolicyTest extends TestCase {

	private const DUE = '2026-12-10';

	private ReminderPolicy $policy;

	protected function setUp(): void {
		$this->policy = new ReminderPolicy( 3, 7, 3 );
	}

	public function test_before_reminder_opens_three_days_ahead(): void {
		$this->assertNull( $this->policy->due( '2026-12-06', self::DUE, '2026-12-01', null, 0 ) );
		$this->assertSame( ReminderPolicy::BEFORE, $this->policy->due( '2026-12-07', self::DUE, '2026-12-01', null, 0 ) );
		$this->assertSame( ReminderPolicy::BEFORE, $this->policy->due( '2026-12-10', self::DUE, '2026-12-01', null, 0 ) );
	}

	public function test_only_one_before_reminder_inside_the_window(): void {
		$this->assertNull( $this->policy->due( '2026-12-09', self::DUE, '2026-12-01', '2026-12-07', 0 ) );
	}

	public function test_a_manual_reminder_long_before_the_window_does_not_block_it(): void {
		$this->assertSame( ReminderPolicy::BEFORE, $this->policy->due( '2026-12-08', self::DUE, '2026-11-20', '2026-11-25', 0 ) );
	}

	public function test_no_reminder_on_the_day_the_request_was_created(): void {
		$this->assertNull( $this->policy->due( '2026-12-08', self::DUE, '2026-12-08', null, 0 ) );
	}

	public function test_never_two_reminders_on_one_day(): void {
		$this->assertNull( $this->policy->due( '2026-12-11', self::DUE, '2026-12-01', '2026-12-11', 0 ) );
	}

	/**
	 * Spec 15.11: late by 1, 8, 15 and 22 days gives exactly three reminders.
	 */
	public function test_overdue_reminders_follow_the_interval_up_to_the_maximum(): void {
		$sent = 0;
		$last = null;
		$days = array();

		for ( $late = 1; $late <= 30; $late++ ) {
			$today = gmdate( 'Y-m-d', (int) strtotime( self::DUE . ' +' . $late . ' days UTC' ) );

			if ( ReminderPolicy::OVERDUE === $this->policy->due( $today, self::DUE, '2026-12-01', $last, $sent ) ) {
				++$sent;
				$last   = $today;
				$days[] = $late;
			}
		}

		$this->assertSame( array( 1, 8, 15 ), $days );
	}

	public function test_a_missed_day_is_caught_up_once(): void {
		$this->assertSame( ReminderPolicy::OVERDUE, $this->policy->due( '2026-12-20', self::DUE, '2026-12-01', '2026-12-11', 1 ) );
		$this->assertNull( $this->policy->due( '2026-12-21', self::DUE, '2026-12-01', '2026-12-20', 2 ) );
	}

	/**
	 * Regression (PR review): reminders that start late keep the interval
	 * instead of using up the maximum on consecutive days.
	 */
	public function test_late_start_keeps_the_interval_between_overdue_reminders(): void {
		$sent = 0;
		$last = null;
		$days = array();

		for ( $offset = 0; $offset < 30; $offset++ ) {
			$today = gmdate( 'Y-m-d', (int) strtotime( '2026-12-30 +' . $offset . ' days UTC' ) );

			if ( ReminderPolicy::OVERDUE === $this->policy->due( $today, self::DUE, '2026-12-01', $last, $sent ) ) {
				++$sent;
				$last   = $today;
				$days[] = $today;
			}
		}

		$this->assertSame( array( '2026-12-30', '2027-01-06', '2027-01-13' ), $days );
	}

	public function test_zero_turns_each_kind_off(): void {
		$off = new ReminderPolicy( 0, 0, 3 );

		$this->assertNull( $off->due( '2026-12-09', self::DUE, '2026-12-01', null, 0 ) );
		$this->assertNull( $off->due( '2026-12-11', self::DUE, '2026-12-01', null, 0 ) );
		$this->assertNull( ( new ReminderPolicy( 3, 7, 0 ) )->due( '2026-12-11', self::DUE, '2026-12-01', null, 0 ) );
	}

	public function test_days_between(): void {
		$this->assertSame( 3, ReminderPolicy::days_between( '2026-12-07', self::DUE ) );
		$this->assertSame( -1, ReminderPolicy::days_between( '2026-12-11', self::DUE ) );
		$this->assertSame( 31, ReminderPolicy::days_between( '2026-12-01', '2027-01-01' ) );
		$this->assertSame( 0, ReminderPolicy::days_between( 'not a date', self::DUE ) );
	}
}
