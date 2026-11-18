<?php
/**
 * Keeps portal clients out of WordPress's own screens.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Security;

use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Clients live under /portal/ only: no wp-admin, no admin bar, and a session
 * length suited to a financial area.
 */
final class ClientGuard {

	/** Session with "remember me", seconds. */
	private const REMEMBER = 14 * DAY_IN_SECONDS;

	/** Session without it, seconds. */
	private const SESSION = 12 * HOUR_IN_SECONDS;

	/**
	 * Register the hooks.
	 *
	 * @param Plugin $app Object graph.
	 */
	public static function register( Plugin $app ): void {
		add_action(
			'admin_init',
			static function () use ( $app ): void {
				if ( wp_doing_ajax() || ! $app->policy->is_client_only( get_current_user_id() ) ) {
					return;
				}

				wp_safe_redirect( Router::portal_url() );
				exit;
			}
		);

		add_filter(
			'show_admin_bar',
			static fn( bool $show ): bool => $show && ! $app->policy->is_client_only( get_current_user_id() )
		);

		add_filter(
			'login_redirect',
			static function ( string $redirect, string $requested, $user ) use ( $app ): string {
				return $user instanceof \WP_User && $app->policy->is_client_only( $user->ID ) ? Router::portal_url() : $redirect;
			},
			10,
			3
		);

		add_filter(
			'auth_cookie_expiration',
			static function ( int $length, int $user_id, bool $remember ) use ( $app ): int {
				if ( ! $app->policy->is_client_only( $user_id ) ) {
					return $length;
				}

				return $remember ? self::REMEMBER : self::SESSION;
			},
			10,
			3
		);
	}
}
