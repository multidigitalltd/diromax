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
 *
 * Standard 4.5: at least 15 characters (the password is the only factor),
 * no composition rules, checked against leaked passwords, and a change needs
 * re-authentication bound to the current session.
 */
final class PasswordService {

	/** Session key: Unix time the session was created, by the plugin's clock. */
	public const SESSION_KEY = 'dx_portal_auth_at';

	/** User meta: the client chose a password. */
	public const SET_META = 'dx_portal_password_set';

	/** Minimum length when the password is the only factor (standard 4.5.2, NIST SP 800-63B-4). */
	public const MIN_LENGTH = 15;

	/** Fewer distinct characters than this reads as a pattern, not a password. */
	private const MIN_DISTINCT = 5;

	/** Pwned Passwords range API (k-anonymity: only 5 hex digits of the SHA-1 leave the site). */
	private const BREACH_API = 'https://api.pwnedpasswords.com/range/';

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

		$this->app->audit->record( 'portal.signed_in', null, 'user', $user->ID, array( 'via' => 'password' ), $this->app->clock->mysql() );

		return $user->ID;
	}

	/**
	 * Set or change the signed-in client's password.
	 *
	 * Allowed when this very session was created in the last 15 minutes, or
	 * with the current password. A fresh sign-in on another device does not
	 * count for an older session. Every other session of the account ends,
	 * and a notice goes by email.
	 *
	 * @param int    $user_id  Signed-in user.
	 * @param string $token    The session token of this request.
	 * @param string $password New password.
	 * @param string $current  Current password, when the session is not fresh.
	 * @return true|WP_Error
	 */
	public function change( int $user_id, string $token, string $password, string $current ): bool|WP_Error {
		$user = get_userdata( $user_id );

		if ( ! $user || ! $this->eligible( $user_id ) ) {
			return Errors::forbidden();
		}

		if ( ! $this->recently_signed_in( $user_id, $token ) ) {
			$allowed = $this->app->limiter->attempt( 'password-change', (string) $user_id, self::PER_EMAIL, self::WINDOW );

			if ( ! $allowed || '' === $current || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
				return new WP_Error( 'dx_reauth', __( 'כדי לשנות את הסיסמה צריך להזין את הסיסמה הנוכחית, או להיכנס מחדש עם קישור למייל.', 'diromax-portal' ), array( 'fields' => array( 'current' => 'invalid' ) ) );
			}
		}

		$problem = self::weakness( $password, (string) $user->user_email ) ?? ( $this->breached( $password ) ? 'breached' : null );

		if ( null !== $problem ) {
			return Errors::invalid( array( 'password' => $problem ) );
		}

		wp_set_password( $password, $user_id );
		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		update_user_meta( $user_id, self::SET_META, 1 );
		$this->app->audit->record( 'portal.password_changed', null, 'user', $user_id, array(), $this->app->clock->mysql() );
		Mailer::send( (string) $user->user_email, $this->app->renderer->password_changed( (string) $user->display_name ), null );

		return true;
	}

	/**
	 * Stamp every new session with its creation time (attach_session_information).
	 *
	 * WordPress creates a session on every sign-in, by link, by password or
	 * through wp-login.php, so the stamp means "signed in at", per session.
	 *
	 * @param array<string, mixed> $session Session data.
	 * @return array<string, mixed>
	 */
	public function stamp_session( array $session ): array {
		$session[ self::SESSION_KEY ] = $this->app->clock->now();

		return $session;
	}

	/**
	 * Was this session created in the last 15 minutes?
	 *
	 * @param int    $user_id User.
	 * @param string $token   Session token of the request.
	 * @return bool
	 */
	public function recently_signed_in( int $user_id, string $token ): bool {
		$session = '' === $token ? null : \WP_Session_Tokens::get_instance( $user_id )->get( $token );

		return is_array( $session ) && (int) ( $session[ self::SESSION_KEY ] ?? 0 ) >= $this->app->clock->now() - self::RECENT;
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
	 * @return string|null too_short, too_long, is_email or pattern.
	 */
	public static function weakness( string $password, string $email ): ?string {
		$length   = mb_strlen( $password );
		$distinct = count( array_unique( mb_str_split( mb_strtolower( $password ) ) ) );

		return match ( true ) {
			$length < self::MIN_LENGTH                                                  => 'too_short',
			$length > self::MAX_LENGTH                                                  => 'too_long',
			'' !== $email && false !== mb_stripos( $password, (string) strstr( $email, '@', true ) ) && strlen( (string) strstr( $email, '@', true ) ) >= 4 => 'is_email',
			$distinct < self::MIN_DISTINCT                                              => 'pattern',
			default                                                                     => null,
		};
	}

	/**
	 * Is the password in the Pwned Passwords corpus (standard 4.5.4)?
	 *
	 * Only the first five hex digits of its SHA-1 leave the site; the match is
	 * made here, against the returned suffixes. When the service cannot be
	 * reached the check is skipped and logged, so an outage never locks
	 * clients out of choosing a password. The dx_portal_password_breached
	 * filter can answer instead (a local list, or tests).
	 *
	 * @param string $password Password.
	 * @return bool
	 */
	private function breached( string $password ): bool {
		$answer = apply_filters( 'dx_portal_password_breached', null, $password );

		if ( is_bool( $answer ) ) {
			return $answer;
		}

		// SHA-1 is what the k-anonymity protocol is built on; it is not used to store anything.
		$hash     = strtoupper( sha1( $password ) );
		$response = wp_remote_get(
			self::BREACH_API . substr( $hash, 0, 5 ),
			array(
				'timeout'    => 3,
				'user-agent' => 'password-check',
				'headers'    => array( 'Add-Padding' => 'true' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Logger::warning( 'password.breach_check_unavailable' );
			return false;
		}

		$lines = preg_split( '/\R/', wp_remote_retrieve_body( $response ) );

		foreach ( false === $lines ? array() : $lines as $line ) {
			$parts = explode( ':', trim( $line ) );

			if ( 2 === count( $parts ) && hash_equals( substr( $hash, 5 ), $parts[0] ) && (int) $parts[1] > 0 ) {
				return true;
			}
		}

		return false;
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
