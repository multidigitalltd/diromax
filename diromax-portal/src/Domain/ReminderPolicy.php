<?php
/**
 * When an open payment request earns a reminder.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rule for automatic payment reminders (spec 15.2).
 *
 * Before the due date: one reminder inside the window of $before_days days,
 * unless one was already sent inside that window. After it: on the first day
 * late, then every $overdue_every days, up to $overdue_max reminders, and
 * never sooner than $overdue_every days after the previous overdue one, so
 * reminders that start late (feature turned on, worker down) do not bunch
 * up on consecutive days. Never two on the same day, and never on the day
 * the request was created.
 * Dates are Y-m-d strings in the site's time zone.
 */
final readonly class ReminderPolicy {

	/** Reminder before the due date. */
	public const BEFORE = 'before';

	/** Reminder after the due date. */
	public const OVERDUE = 'overdue';

	/**
	 * Constructor.
	 *
	 * @param int $before_days   Days before the due date that open the window; 0 turns it off.
	 * @param int $overdue_every Days between overdue reminders; 0 turns them off.
	 * @param int $overdue_max   Overdue reminders at most.
	 */
	public function __construct(
		private int $before_days,
		private int $overdue_every,
		private int $overdue_max,
	) {}

	/**
	 * Which reminder is due today, if any.
	 *
	 * @param string      $today         Today.
	 * @param string      $due_date      The request's due date.
	 * @param string      $created_on    The day the request was created.
	 * @param string|null $last_reminder The day of the last reminder of any kind.
	 * @param int         $overdue_sent  Overdue reminders sent so far.
	 * @return string|null BEFORE, OVERDUE or null.
	 */
	public function due( string $today, string $due_date, string $created_on, ?string $last_reminder, int $overdue_sent ): ?string {
		if ( $last_reminder === $today || $created_on >= $today ) {
			return null;
		}

		$days_left = self::days_between( $today, $due_date );

		if ( $days_left >= 0 ) {
			return $this->before_due( $days_left, $due_date, $last_reminder ) ? self::BEFORE : null;
		}

		return $this->overdue_due( -$days_left, $overdue_sent, $last_reminder, $today ) ? self::OVERDUE : null;
	}

	/**
	 * Inside the window before the due date, with no reminder in it yet?
	 *
	 * @param int         $days_left     Days until the due date.
	 * @param string      $due_date      Due date.
	 * @param string|null $last_reminder Last reminder day.
	 * @return bool
	 */
	private function before_due( int $days_left, string $due_date, ?string $last_reminder ): bool {
		if ( 0 === $this->before_days || $days_left > $this->before_days ) {
			return false;
		}

		return null === $last_reminder || self::days_between( $last_reminder, $due_date ) > $this->before_days;
	}

	/**
	 * Has the next overdue reminder come due?
	 *
	 * @param int         $days_late     Days past the due date, at least 1.
	 * @param int         $overdue_sent  Overdue reminders sent so far.
	 * @param string|null $last_reminder Day of the last reminder of any kind.
	 * @param string      $today         Today.
	 * @return bool
	 */
	private function overdue_due( int $days_late, int $overdue_sent, ?string $last_reminder, string $today ): bool {
		if ( 0 === $this->overdue_every || $overdue_sent >= $this->overdue_max || $days_late < 1 + $overdue_sent * $this->overdue_every ) {
			return false;
		}

		// The first overdue reminder may follow a "before" one closely; later ones keep the interval.
		return 0 === $overdue_sent || null === $last_reminder || self::days_between( $last_reminder, $today ) >= $this->overdue_every;
	}

	/**
	 * Whole days from one date to another (negative when $to is earlier).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	public static function days_between( string $from, string $to ): int {
		$start = strtotime( $from . ' 00:00:00 UTC' );
		$end   = strtotime( $to . ' 00:00:00 UTC' );

		if ( false === $start || false === $end ) {
			return 0;
		}

		return intdiv( $end - $start, 86400 );
	}
}
