<?php
/**
 * Stage progress rules.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The seven stages of a case and how they change.
 *
 * Each stage has its own status, so several stages can be in progress at
 * once (planning goes on while the neighbours sign). The case keeps one
 * "lead" stage number for lists, sorting and email, derived from the
 * statuses by lead().
 *
 * States are keyed 1–7: array{status:string, started:string|null, done:string|null},
 * where the dates are Y-m-d.
 */
final class StageProgress {

	/** Number of stages. */
	public const COUNT = 7;

	/**
	 * Read "1:done,2:active,…" into statuses. Null when any part is not a
	 * stage number with a known status, or a stage appears twice.
	 *
	 * @param string $raw Pairs separated by commas.
	 * @return array<int, StageStatus>|null
	 */
	public static function parse( string $raw ): ?array {
		$statuses = array();

		foreach ( explode( ',', $raw ) as $pair ) {
			if ( '' === trim( $pair ) ) {
				continue;
			}

			$parts  = explode( ':', $pair );
			$number = 2 === count( $parts ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
			$status = 2 === count( $parts ) ? StageStatus::tryFrom( $parts[1] ) : null;

			if ( $number < 1 || $number > self::COUNT || null === $status || isset( $statuses[ $number ] ) ) {
				return null;
			}

			$statuses[ $number ] = $status;
		}

		return $statuses;
	}

	/**
	 * Give stages the statuses chosen for them, dating what started or
	 * finished today. Stages not listed keep their state.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages   Current states.
	 * @param array<int, StageStatus>                                                 $statuses New statuses by stage.
	 * @param string                                                                  $today    Y-m-d.
	 * @return array<int, array{status:string, started:string|null, done:string|null}>
	 */
	public static function set( array $stages, array $statuses, string $today ): array {
		foreach ( $statuses as $number => $status ) {
			if ( isset( $stages[ $number ] ) ) {
				$stages[ $number ] = self::with_status( $stages[ $number ], $status, $today );
			}
		}

		return $stages;
	}

	/**
	 * Move on to a stage: the stages before it are done (those marked not
	 * relevant stay so), it is in progress, the stages after it have not
	 * started yet.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages Current states.
	 * @param int                                                                     $stage  Stage, 1–7.
	 * @param string                                                                  $today  Y-m-d.
	 * @return array<int, array{status:string, started:string|null, done:string|null}>
	 */
	public static function advance( array $stages, int $stage, string $today ): array {
		$statuses = array();

		foreach ( array_keys( $stages ) as $number ) {
			if ( $number === $stage ) {
				$statuses[ $number ] = StageStatus::Active;
			} elseif ( StageStatus::Skipped->value !== $stages[ $number ]['status'] ) {
				$statuses[ $number ] = $number < $stage ? StageStatus::Done : StageStatus::Pending;
			}
		}

		return self::set( $stages, $statuses, $today );
	}

	/**
	 * Start a stage alongside the others: only that stage changes.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages Current states.
	 * @param int                                                                     $stage  Stage, 1–7.
	 * @param string                                                                  $today  Y-m-d.
	 * @return array<int, array{status:string, started:string|null, done:string|null}>
	 */
	public static function start( array $stages, int $stage, string $today ): array {
		return self::set( $stages, array( $stage => StageStatus::Active ), $today );
	}

	/**
	 * Stages in progress, in order.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages States.
	 * @return int[]
	 */
	public static function active( array $stages ): array {
		return self::with( $stages, StageStatus::Active );
	}

	/**
	 * The case's lead stage: the furthest stage in progress; with none in
	 * progress, the first that has not started; with none left, the last
	 * one done; and 1 for a case that has done nothing yet.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages States.
	 * @return int
	 */
	public static function lead( array $stages ): int {
		$active  = self::active( $stages );
		$pending = self::with( $stages, StageStatus::Pending );
		$done    = self::with( $stages, StageStatus::Done );

		if ( array() !== $active ) {
			return max( $active );
		}

		if ( array() !== $pending ) {
			return min( $pending );
		}

		return array() !== $done ? max( $done ) : 1;
	}

	/**
	 * Is every stage either done or not relevant?
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages States.
	 * @return bool
	 */
	public static function finished( array $stages ): bool {
		return array() !== self::with( $stages, StageStatus::Done )
			&& array() === self::active( $stages )
			&& array() === self::with( $stages, StageStatus::Pending );
	}

	/**
	 * Whole days a stage has been in progress, or null when it is not.
	 *
	 * @param array{status:string, started:string|null, done:string|null} $state One stage.
	 * @param string                                                      $today Y-m-d.
	 * @return int|null
	 */
	public static function days_in( array $state, string $today ): ?int {
		if ( StageStatus::Active->value !== $state['status'] || null === $state['started'] ) {
			return null;
		}

		$from = strtotime( $state['started'] . ' 00:00:00 UTC' );
		$to   = strtotime( $today . ' 00:00:00 UTC' );

		return false === $from || false === $to ? null : max( 0, intdiv( $to - $from, 86400 ) );
	}

	/**
	 * Stage numbers with a status, in order.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages States.
	 * @param StageStatus                                                             $status Status.
	 * @return int[]
	 */
	private static function with( array $stages, StageStatus $status ): array {
		$numbers = array();

		foreach ( $stages as $number => $state ) {
			if ( $status->value === $state['status'] ) {
				$numbers[] = $number;
			}
		}

		return $numbers;
	}

	/**
	 * One stage with a new status. A stage that starts or finishes is
	 * dated today; dates already set are kept, so saving the same
	 * statuses again changes nothing. A stage back to "not started" loses
	 * its dates.
	 *
	 * @param array{status:string, started:string|null, done:string|null} $state  Current state.
	 * @param StageStatus                                                 $status New status.
	 * @param string                                                      $today  Y-m-d.
	 * @return array{status:string, started:string|null, done:string|null}
	 */
	private static function with_status( array $state, StageStatus $status, string $today ): array {
		$state['status'] = $status->value;

		switch ( $status ) {
			case StageStatus::Pending:
				$state['started'] = null;
				$state['done']    = null;
				break;
			case StageStatus::Active:
				$state['started'] ??= $today;
				$state['done']      = null;
				break;
			case StageStatus::Done:
				$state['started'] ??= $today;
				$state['done']    ??= $today;
				break;
			case StageStatus::Skipped:
				$state['done'] = null;
				break;
		}

		return $state;
	}
}
