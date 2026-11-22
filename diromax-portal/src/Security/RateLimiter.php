<?php
/**
 * Fixed-window rate limiter.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Security;

use Diromax\Portal\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Counts attempts per bucket and subject in transients (standard 4.7.14).
 *
 * The subject (an email or an IP address) is only ever stored as a salted
 * hash inside the transient key, and the transient expires with the window —
 * the same privacy model as the site's lead form.
 */
final class RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Clock.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Count one attempt; tell whether it is within the limit.
	 *
	 * @param string $bucket  What is limited, e.g. login_email.
	 * @param string $subject Who is limited (hashed before storage).
	 * @param int    $limit   Attempts allowed per window.
	 * @param int    $window  Window length, seconds.
	 * @return bool True when allowed.
	 */
	public function attempt( string $bucket, string $subject, int $limit, int $window ): bool {
		$key   = self::key( $bucket, $subject );
		$state = get_transient( $key );
		$now   = $this->clock->now();

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['until'] ) || (int) $state['until'] <= $now ) {
			$state = array(
				'count' => 0,
				'until' => $now + $window,
			);
		}

		if ( (int) $state['count'] >= $limit ) {
			return false;
		}

		++$state['count'];
		set_transient( $key, $state, max( 1, (int) $state['until'] - $now ) );

		return true;
	}

	/**
	 * Has the subject used up the bucket, without counting a new attempt?
	 * For buckets that count failures only.
	 *
	 * @param string $bucket  Action name.
	 * @param string $subject Who.
	 * @param int    $limit   Attempts allowed in the window.
	 * @return bool
	 */
	public function exhausted( string $bucket, string $subject, int $limit ): bool {
		$state = get_transient( self::key( $bucket, $subject ) );

		return is_array( $state ) && (int) ( $state['count'] ?? 0 ) >= $limit && (int) ( $state['until'] ?? 0 ) > $this->clock->now();
	}

	/**
	 * Transient key of a bucket and subject.
	 *
	 * @param string $bucket  Action name.
	 * @param string $subject Who.
	 * @return string
	 */
	private static function key( string $bucket, string $subject ): string {
		return 'dx_portal_rl_' . substr( wp_hash( $bucket . '|' . strtolower( $subject ), 'nonce' ), 0, 32 );
	}

	/**
	 * Client address, as REMOTE_ADDR. Forwarded headers are not trusted (4.1.7);
	 * the site's existing dx_client_ip filter lets a trusted proxy setup override it.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return (string) apply_filters( 'dx_client_ip', $ip ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Shared with the theme on purpose.
	}
}
