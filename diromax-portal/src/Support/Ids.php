<?php
/**
 * Random public identifiers.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Non-sequential identifiers for URLs and file names (standard 4.7.9).
 *
 * They hide row counts and make URLs unguessable. They are not an access
 * control: every request is still checked by AccessPolicy.
 */
final class Ids {

	/**
	 * A 24-character hex identifier (96 random bits).
	 *
	 * @return string
	 */
	public static function public_id(): string {
		return bin2hex( random_bytes( 12 ) );
	}

	/**
	 * Is the value shaped like a public identifier?
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_public_id( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{24}$/', $value );
	}
}
