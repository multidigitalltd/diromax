<?php
/**
 * Cloudflare Turnstile.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Security;

use Diromax\Portal\Data\Logger;
use Diromax\Portal\Support\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side Turnstile check for the portal's sign-in form (standard 15.3).
 *
 * Active only when both keys are defined in wp-config.php
 * (from the settings screen, or DX_TURNSTILE_SITE_KEY and DX_TURNSTILE_SECRET_KEY
 * in wp-config.php; see Integrations). The secret never reaches
 * the browser. Any failure, including Cloudflare being unreachable, rejects.
 */
final class Turnstile {

	/** Action name bound into the token. */
	public const ACTION = 'dx_portal_login';

	/** Siteverify endpoint. */
	private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Are both keys configured?
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return '' !== self::site_key() && '' !== self::secret();
	}

	/**
	 * Public site key for the widget.
	 *
	 * @return string
	 */
	public static function site_key(): string {
		return Integrations::get( 'turnstile_site' );
	}

	/**
	 * Verify a widget token.
	 *
	 * @param string $token Token from the form.
	 * @return bool
	 */
	public static function verify( string $token ): bool {
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 5,
				'body'    => array(
					'secret'   => self::secret(),
					'response' => $token,
					'remoteip' => RateLimiter::client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Logger::warning( 'turnstile.unreachable' );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body )
			&& true === ( $body['success'] ?? false )
			&& self::ACTION === ( $body['action'] ?? '' )
			&& wp_parse_url( home_url(), PHP_URL_HOST ) === ( $body['hostname'] ?? '' );
	}

	/**
	 * Secret key.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return Integrations::get( 'turnstile_secret' );
	}
}
