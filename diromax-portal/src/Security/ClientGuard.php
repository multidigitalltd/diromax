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
		// auth_redirect fires on every wp-admin screen right after authentication,
		// before WordPress's own capability check would answer 403 to a client.
		add_action(
			'auth_redirect',
			static function ( int $user_id ) use ( $app ): void {
				$target = self::admin_redirect( $app, $user_id );

				if ( null !== $target ) {
					wp_safe_redirect( $target );
					exit;
				}
			}
		);

		add_filter(
			'show_admin_bar',
			static fn( bool $show ): bool => $show && ! $app->policy->is_client_only( get_current_user_id() )
		);

		add_filter(
			'login_redirect',
			static function ( string $redirect, string $requested, $user ) use ( $app ): string {
				if ( ! $user instanceof \WP_User ) {
					return $redirect;
				}

				if ( $app->policy->is_client_only( $user->ID ) ) {
					return Router::portal_url();
				}

				// Project managers land in the management app unless a page was asked
				// for. The sign-in form sends admin_url() when nothing was asked for.
				$asked = ! in_array( $requested, array( '', 'wp-admin/', admin_url() ), true );

				return ! $asked && $app->policy->can_use_admin( $user->ID ) && ! user_can( $user, 'manage_options' ) ? Router::manage_url() : $redirect;
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

	/**
	 * Where a user who opened wp-admin should go instead, if anywhere.
	 *
	 * @param Plugin $app     Object graph.
	 * @param int    $user_id Signed-in user.
	 * @return string|null Portal URL for a client; null for everyone else.
	 */
	public static function admin_redirect( Plugin $app, int $user_id ): ?string {
		return ! wp_doing_ajax() && $app->policy->is_client_only( $user_id ) ? Router::portal_url() : null;
	}
}
