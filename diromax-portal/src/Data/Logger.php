<?php
/**
 * Server-side structured log.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one JSON line per event to the PHP error log (standard 4.11.6).
 *
 * Never put secrets, tokens, email addresses or free text from users here:
 * context carries IDs and codes only (4.11.8).
 */
final class Logger {

	/**
	 * Log a warning.
	 *
	 * @param string               $event   Dotted event code.
	 * @param array<string, mixed> $context IDs and codes only.
	 */
	public static function warning( string $event, array $context = array() ): void {
		self::write( 'warning', $event, $context );
	}

	/**
	 * Log an error.
	 *
	 * @param string               $event   Dotted event code.
	 * @param array<string, mixed> $context IDs and codes only.
	 */
	public static function error( string $event, array $context = array() ): void {
		self::write( 'error', $event, $context );
	}

	/**
	 * Write the line.
	 *
	 * @param string               $level   Severity.
	 * @param string               $event   Event code.
	 * @param array<string, mixed> $context Context.
	 */
	private static function write( string $level, string $event, array $context ): void {
		$line = wp_json_encode(
			array(
				'time'    => gmdate( 'c' ),
				'level'   => $level,
				'source'  => 'diromax-portal',
				'event'   => $event,
				'user'    => get_current_user_id(),
				'context' => $context,
			)
		);

		error_log( (string) $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The structured server log (4.11.6), not debug output.
	}
}
