<?php
/**
 * Password sign-in for portal clients.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\Logger;
use Diromax\Portal\Plugin;
use Diromax\Portal\Security\RateLimiter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * An optional password next to the email link (spec 15.10, decision 8).
 *
 * Clients only: staff sign in through WordPress with two-factor
 * authentication, and this form must never become a way around it. Every
 * failure gets the same message, and attempts are limited per address and
 * per IP. Passwords are stored by WordPress (bcrypt since 6.8).
 */
final class PasswordService {

	/** User meta: Unix time of the last sign-in (link or password). */
	public const AUTH_META = 'dx_portal_auth_at';

	/** User meta: the client chose a password. */
	public const SET_META = 'dx_portal_password_set';

	/** Minimum length (NIST SP 800-63B: length, no composition rules). */
	public const MIN_LENGTH = 10;

	/** Maximum length. */
	private const MAX_LENGTH = 200;

	/** A sign-in this recent allows a change without the current password, seconds. */
	private const RECENT = 15 * MINUTE_IN_SECONDS;

	/** Attempts per address per window. */
	private const PER_EMAIL = 5;

	/** Attempts per IP per window. */
	private const PER_IP = 20;

	/** Rate-limit window, seconds. */
	private const WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * A bcrypt hash of a random string, checked when the address is unknown so
	 * that the answer takes as long as for a real account.
	 */
	private const DUMMY_HASH = '$2y$10$gqSGBjVhBh/DKCEd7YSj8um/9DZoPopDUixv.eY1iKB8U/vQ2dn8y';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Sign a client in with email and password.
	 *
	 * @param string $email    Address.
	 * @param string $password Password.
	 * @param bool   $remember Long session.
	 * @return int|WP_Error User ID.
	 */
	public function sign_in( string $email, string $password, bool $remember ): int|WP_Error {
		$email = strtolower( trim( $email ) );

		$allowed = $this->app->limiter->attempt( 'password-email', $email, self::PER_EMAIL, self::WINDOW )
			&& $this->app->limiter->attempt( 'password-ip', RateLimiter::client_ip(), self::PER_IP, self::WINDOW );

		if ( ! $allowed ) {
			Logger::warning( 'login.password_rate_limited' );
			return self::failed();
		}

		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		$valid = $user && '' !== $password
			? wp_check_password( $password, $user->user_pass, $user->ID )
			: wp_check_password( $password, self::DUMMY_HASH );

		if ( ! $user || ! $valid || ! $this->eligible( $user->ID ) ) {
			Logger::warning( 'login.password_rejected' );
			return self::failed();
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired so security plugins see the sign-in.

		$this->mark_signed_in( $user->ID );
		$this->app->audit->record( 'portal.signed_in', null, 'user', $user->ID, array( 'via' => 'password' ), $this->app->clock->mysql() );

		return $user->ID;
	}

	/**
	 * Set or change the signed-in client's password.
	 *
	 * Allowed within 15 minutes of a sign-in, or with the current password.
	 * Every other session of the account ends, and a notice goes by email.
	 *
	 * @param int    $user_id Signed-in user.
	 * @param string $password New password.
	 * @param string $current Current password, when not signed in recently.
	 * @return true|WP_Error
	 */
	public function change( int $user_id, string $password, string $current ): bool|WP_Error {
		$user = get_userdata( $user_id );

		if ( ! $user || ! $this->eligible( $user_id ) ) {
			return Errors::forbidden();
		}

		if ( ! $this->recently_signed_in( $user_id ) ) {
			$allowed = $this->app->limiter->attempt( 'password-change', (string) $user_id, self::PER_EMAIL, self::WINDOW );

			if ( ! $allowed || '' === $current || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
				return new WP_Error( 'dx_reauth', __( 'כדי לשנות את הסיסמה צריך להזין את הסיסמה הנוכחית, או להיכנס מחדש עם קישור למייל.', 'diromax-portal' ), array( 'fields' => array( 'current' => 'invalid' ) ) );
			}
		}

		$problem = self::weakness( $password, (string) $user->user_email );

		if ( null !== $problem ) {
			return Errors::invalid( array( 'password' => $problem ) );
		}

		wp_set_password( $password, $user_id );
		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		update_user_meta( $user_id, self::SET_META, 1 );
		$this->mark_signed_in( $user_id );
		$this->app->audit->record( 'portal.password_changed', null, 'user', $user_id, array(), $this->app->clock->mysql() );
		Mailer::send( (string) $user->user_email, $this->app->renderer->password_changed( (string) $user->display_name ), null );

		return true;
	}

	/**
	 * Record a successful sign-in, by link or by password.
	 *
	 * @param int $user_id User.
	 */
	public function mark_signed_in( int $user_id ): void {
		update_user_meta( $user_id, self::AUTH_META, $this->app->clock->now() );
	}

	/**
	 * Did the user sign in within the last 15 minutes?
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function recently_signed_in( int $user_id ): bool {
		return (int) get_user_meta( $user_id, self::AUTH_META, true ) >= $this->app->clock->now() - self::RECENT;
	}

	/**
	 * Has the client set a password?
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function has_password( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::SET_META, true );
	}

	/**
	 * Why a new password is not acceptable, or null.
	 *
	 * @param string $password Password.
	 * @param string $email    The account's address.
	 * @return string|null too_short, too_long or is_email.
	 */
	public static function weakness( string $password, string $email ): ?string {
		$length = mb_strlen( $password );

		return match ( true ) {
			$length < self::MIN_LENGTH                                        => 'too_short',
			$length > self::MAX_LENGTH                                        => 'too_long',
			'' !== $email && 0 === strcasecmp( trim( $password ), $email ) => 'is_email',
			default                                                           => null,
		};
	}

	/**
	 * Only clients with at least one case use the portal's own sign-in.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	private function eligible( int $user_id ): bool {
		return $this->app->policy->is_client_only( $user_id ) && $this->app->members->case_count( $user_id ) > 0;
	}

	/**
	 * The one message for every failed sign-in.
	 *
	 * @return WP_Error
	 */
	private static function failed(): WP_Error {
		return new WP_Error( 'dx_login_failed', __( 'כתובת המייל או הסיסמה לא נכונות. אפשר גם לבקש קישור כניסה למייל.', 'diromax-portal' ) );
	}
}
