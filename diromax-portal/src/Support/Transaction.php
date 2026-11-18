<?php
/**
 * Database transactions.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a multi-step write atomically (standard 6.4.6).
 *
 * In production it opens a real transaction. Inside an outer transaction —
 * the WordPress test suite wraps every test in one — a nested START
 * TRANSACTION would silently commit it, so a savepoint is used instead. The
 * test bootstrap switches modes with the dx_portal_nested_transactions filter.
 */
final class Transaction {

	/**
	 * Run the callback; commit when it returns true, roll back otherwise or on an exception.
	 *
	 * @param \wpdb            $db       Database.
	 * @param callable(): bool $callback Work to do.
	 * @return bool What the callback returned (false on exception).
	 * @throws \Throwable Rethrows after rolling back.
	 */
	public static function run( \wpdb $db, callable $callback ): bool {
		$nested = (bool) apply_filters( 'dx_portal_nested_transactions', false );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Transaction control statements have no API.
		$db->query( $nested ? 'SAVEPOINT dx_portal' : 'START TRANSACTION' );

		try {
			$ok = $callback();
		} catch ( \Throwable $error ) {
			$db->query( $nested ? 'ROLLBACK TO SAVEPOINT dx_portal' : 'ROLLBACK' );
			throw $error;
		}

		if ( $ok ) {
			$db->query( $nested ? 'RELEASE SAVEPOINT dx_portal' : 'COMMIT' );
		} else {
			$db->query( $nested ? 'ROLLBACK TO SAVEPOINT dx_portal' : 'ROLLBACK' );
		}
		// phpcs:enable

		return $ok;
	}
}
