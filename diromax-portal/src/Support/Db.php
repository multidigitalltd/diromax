<?php
/**
 * Prepared write queries.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

use Diromax\Portal\Data\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a prepared UPDATE/DELETE/INSERT and reports affected rows, handling the
 * case where wpdb::prepare() refuses the query instead of passing null on.
 */
final class Db {

	/**
	 * Prepare and run a write query.
	 *
	 * @param \wpdb                 $db    Database.
	 * @param literal-string        $query SQL with placeholders; never built from input.
	 * @param string|int|float|null ...$args Values for the placeholders.
	 * @return int Affected rows, or -1 when the query could not be prepared or failed.
	 */
	public static function write( \wpdb $db, string $query, string|int|float|null ...$args ): int {
		$sql = $db->prepare( $query, ...$args );

		if ( null === $sql ) {
			Logger::error( 'db.prepare_failed' );
			return -1;
		}

		$result = $db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared on the line above; custom tables have no API.

		return false === $result ? -1 : (int) $result;
	}
}
