<?php
/**
 * Passwordless sign-in and invitations.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Security\RateLimiter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One-time sign-in links (spec 7.1, standard 4.5–4.6).
 *
 * - 32 random bytes per token; only an HMAC of it is stored.
 * - Single use, enforced atomically in the database; 15 minutes for a login
 *   link, 72 hours for an invitation.
 * - The link opens a confirmation page and the token is spent by a POST, so
 *   mail scanners that prefetch links cannot burn it.
 * - The response to a link request is the same whether or not the address is
 *   known, and the email is sent after the response is flushed, so timing does
 *   not reveal it either.
 * - Rate limited per address and per device.
 */
final class LoginService {

	/** Login link lifetime, seconds. */
	public const LOGIN_TTL = 900;

	/** Invitation link lifetime, seconds. */
	public const INVITE_TTL = 259200;

	/** Link requests allowed per address per window. */
	private const PER_EMAIL = 3;

	/** Link requests allowed per device per window. */
	private const PER_IP = 10;

	/** Rate-limit window, seconds. */
	private const WINDOW = 900;

	/** Shape of a token. */
	private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

	/**
	 * Mail to send once the response is out.
	 *
	 * @var array<int, callable(): void>
	 */
	private array $deferred = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Handle a "send me a link" request. Always succeeds from the caller's view.
	 *
	 * @param string $email Address as typed.
	 */
	public function request_link( string $email ): void {
		$email = sanitize_email( $email );

		if ( ! $this->app->limiter->attempt( 'login_ip', RateLimiter::client_ip(), self::PER_IP, self::WINDOW )
			|| ! $this->app->limiter->attempt( 'login_email', $email, self::PER_EMAIL, self::WINDOW ) ) {
			Logger::warning( 'login.rate_limited' );
			return;
		}

		$user = is_email( $email ) ? get_user_by( 'email', $email ) : false;

		if ( ! $user || ! $this->eligible( (int) $user->ID ) ) {
			return;
		}

		$user_id = (int) $user->ID;
		$token   = $this->issue( $user_id, 'login', self::LOGIN_TTL );

		$this->defer(
			function () use ( $user_id, $token ): void {
				$this->app->notifier->send_login_link( $user_id, Router::login_url( $token ) );
			}
		);
	}

	/**
	 * Send an invitation to a new member.
	 *
	 * @param int        $user_id Member.
	 * @param CaseRecord $client_case    Case they were added to.
	 * @return bool Whether the email went out.
	 */
	public function invite( int $user_id, CaseRecord $client_case ): bool {
		$token = $this->issue( $user_id, 'invite', self::INVITE_TTL );
		$sent  = $this->app->notifier->send_invitation( $user_id, $client_case, Router::login_url( $token ) );
		$now   = $this->app->clock->mysql();

		if ( $sent ) {
			$this->app->members->mark_invited( $client_case->id, $user_id, $now );
		}

		$this->app->audit->record( 'member.invited', $client_case->id, 'user', $user_id, array( 'sent' => $sent ), $now );

		return $sent;
	}

	/**
	 * Is the token valid right now? Does not spend it.
	 *
	 * @param string $token Token from the link.
	 * @return bool
	 */
	public function is_valid( string $token ): bool {
		return 1 === preg_match( self::TOKEN_PATTERN, $token )
			&& null !== $this->app->tokens->find_valid( self::hash( $token ), $this->app->clock->mysql() );
	}

	/**
	 * Spend the token and sign the user in.
	 *
	 * @param string $token    Token from the confirmation form.
	 * @param bool   $remember Keep the session for 14 days.
	 * @return int|WP_Error Signed-in user ID.
	 */
	public function consume( string $token, bool $remember ): int|WP_Error {
		$invalid = new WP_Error( 'dx_link_invalid', __( 'הקישור פג תוקף או שכבר נעשה בו שימוש. אפשר לבקש קישור חדש.', 'diromax-portal' ) );

		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return $invalid;
		}

		$now = $this->app->clock->mysql();
		$row = $this->app->tokens->find_valid( self::hash( $token ), $now );

		if ( null === $row || ! $this->app->tokens->consume( $row['id'], $now ) || ! $this->eligible( $row['user_id'] ) ) {
			Logger::warning( 'login.link_rejected' );
			return $invalid;
		}

		$user = get_userdata( $row['user_id'] );

		if ( ! $user ) {
			return $invalid;
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired so security plugins see the sign-in.

		( new PasswordService( $this->app ) )->mark_signed_in( $user->ID );
		$this->app->audit->record( 'portal.signed_in', null, 'user', $user->ID, array( 'via' => $row['purpose'] ), $now );

		return $user->ID;
	}

	/**
	 * May this account sign in to the portal? A client with at least one case.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	private function eligible( int $user_id ): bool {
		return $this->app->policy->is_client_only( $user_id ) && $this->app->members->case_count( $user_id ) > 0;
	}

	/**
	 * Create and store a token; return the plain value for the link.
	 *
	 * @param int    $user_id User.
	 * @param string $purpose login or invite.
	 * @param int    $ttl     Lifetime, seconds.
	 * @return string
	 */
	private function issue( int $user_id, string $purpose, int $ttl ): string {
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes.

		$this->app->tokens->add( $user_id, $purpose, self::hash( $token ), $this->app->clock->mysql_in( $ttl ), $this->app->clock->mysql() );

		return $token;
	}

	/**
	 * Keyed hash of a token; the key is the site's auth salt.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Run work after the response has been sent to the browser.
	 *
	 * @param callable(): void $work Work.
	 */
	private function defer( callable $work ): void {
		if ( array() === $this->deferred ) {
			add_action( 'shutdown', array( $this, 'flush_deferred' ), 1 );
		}

		$this->deferred[] = $work;
	}

	/**
	 * Hooked to shutdown: finish the response, then send the queued mail.
	 */
	public function flush_deferred(): void {
		if ( function_exists( 'fastcgi_finish_request' ) && ! defined( 'WP_TESTS_DOMAIN' ) ) {
			fastcgi_finish_request();
		}

		$work           = $this->deferred;
		$this->deferred = array();

		foreach ( $work as $job ) {
			$job();
		}
	}
}
