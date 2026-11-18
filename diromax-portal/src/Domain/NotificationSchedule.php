<?php
/**
 * When pending notifications go out.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The batching rule from spec section 8.2, as a pure function.
 *
 * A case's pending events are sent together when the case has been quiet for
 * the quiet window, or when the oldest event has waited the maximum time —
 * whichever comes first. An immediate event (a payment request) sends at once.
 */
final class NotificationSchedule {

	/** Delays before the 1st, 2nd and 3rd retry of a failed send, in seconds. */
	public const RETRY_DELAYS = array( 300, 1800, 7200 );

	/**
	 * Are the pending events of a case due?
	 *
	 * @param int[] $created   Creation times of the pending events (Unix seconds).
	 * @param bool  $immediate Whether any of them must go out at once.
	 * @param int   $now       Current time (Unix seconds).
	 * @param int   $quiet     Quiet window, seconds.
	 * @param int   $max_wait  Maximum wait for the oldest event, seconds.
	 * @return bool
	 */
	public static function is_due( array $created, bool $immediate, int $now, int $quiet, int $max_wait ): bool {
		if ( array() === $created ) {
			return false;
		}

		if ( $immediate ) {
			return true;
		}

		return ( $now - max( $created ) >= $quiet ) || ( $now - min( $created ) >= $max_wait );
	}

	/**
	 * When to retry after a failed attempt, or null when attempts are exhausted.
	 *
	 * @param int $attempts Attempts made so far, >= 1.
	 * @param int $now      Current time (Unix seconds).
	 * @return int|null
	 */
	public static function next_attempt( int $attempts, int $now ): ?int {
		$index = $attempts - 1;

		return isset( self::RETRY_DELAYS[ $index ] ) ? $now + self::RETRY_DELAYS[ $index ] : null;
	}
}
