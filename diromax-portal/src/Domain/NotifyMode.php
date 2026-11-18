<?php
/**
 * How a member receives case emails.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Email preference of one member in one case (spec 15.3).
 *
 * Stored as two flags so phase 1 rows keep their meaning: notify = 0 is Off,
 * notify = 1 with digest = 0 is Instant, notify = 1 with digest = 1 is Daily.
 */
enum NotifyMode: string {

	/** Batched email a few minutes after each change. */
	case Instant = 'instant';

	/** One email a day; action items still leave at once. */
	case Daily = 'daily';

	/** No case email at all. */
	case Off = 'off';

	/**
	 * Mode from the stored flags.
	 *
	 * @param bool $notify Receives email.
	 * @param bool $digest Daily digest.
	 * @return self
	 */
	public static function from_flags( bool $notify, bool $digest ): self {
		return match ( true ) {
			! $notify => self::Off,
			$digest   => self::Daily,
			default   => self::Instant,
		};
	}

	/**
	 * Flags to store for a mode.
	 *
	 * @param NotifyMode $mode Mode.
	 * @return array{notify:int, digest:int}
	 */
	public static function flags( NotifyMode $mode ): array {
		return array(
			'notify' => self::Off === $mode ? 0 : 1,
			'digest' => self::Daily === $mode ? 1 : 0,
		);
	}
}
