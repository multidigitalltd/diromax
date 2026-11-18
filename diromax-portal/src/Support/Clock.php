<?php
/**
 * Clock.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Current time, injectable so tests never depend on the real clock (standard 7.2.8).
 */
class Clock {

	/**
	 * Fixed time for tests; null means the real clock.
	 *
	 * @var int|null
	 */
	private ?int $fixed;

	/**
	 * Constructor.
	 *
	 * @param int|null $fixed Unix time to freeze at, or null for the real clock.
	 */
	public function __construct( ?int $fixed = null ) {
		$this->fixed = $fixed;
	}

	/**
	 * Unix time.
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->fixed ?? time();
	}

	/**
	 * Current UTC time for a DATETIME column.
	 *
	 * @return string
	 */
	public function mysql(): string {
		return gmdate( 'Y-m-d H:i:s', $this->now() );
	}

	/**
	 * UTC DATETIME at an offset from now.
	 *
	 * @param int $seconds Offset in seconds.
	 * @return string
	 */
	public function mysql_in( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', $this->now() + $seconds );
	}

	/**
	 * Today's date in the site's time zone, Y-m-d.
	 *
	 * @return string
	 */
	public function today(): string {
		return ( new \DateTimeImmutable( '@' . $this->now() ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/**
	 * Move a frozen clock forward (tests only).
	 *
	 * @param int $seconds Seconds to advance.
	 */
	public function advance( int $seconds ): void {
		$this->fixed = $this->now() + $seconds;
	}
}
