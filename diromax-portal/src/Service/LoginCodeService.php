<?php
/**
 * One-time sign-in codes by email.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\Logger;
use Diromax\Portal\Plugin;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Every sign-in with a password needs a second step (decision 16): a
 * 6-digit code sent to the account's email address. It covers staff on
 * wp-login.php (LoginGate) and clients on the portal's password form.
 * Signing in through an emailed link needs no code: the link already proves
 * the address.
 *
 * The challenge is held server-side under a random token; the code is
 * stored only as a hash bound to that token. A code lasts 10 minutes and
 * allows 5 wrong tries; a challenge sends at most 3 codes per quarter hour.
 *
 * Emergency switch: define( 'DX_PORTAL_EMAIL_CODE_OFF', true ) in
 * wp-config.php turns codes off while the site cannot send email. Every
 * sign-in in that state is logged as a warning.
 */
final class LoginCodeService {

	/** Seconds a code is valid. */
	public const TTL = 600;

	/** Wrong codes before the challenge ends. */
	public const ATTEMPTS = 5;

	/** Codes sent per challenge in one window. */
	public const SENDS = 3;

	/** The sending window, seconds. */
	private const WINDOW = 900;

	/** Challenges a user may start per window. */
	private const STARTS = 10;

	/** Shape of a challenge token. */
	public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

	/** Transient prefix. */
	private const KEY = 'dx_portal_lc_';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Are codes required? Off only with the emergency switch.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$off = defined( 'DX_PORTAL_EMAIL_CODE_OFF' ) && (bool) constant( 'DX_PORTAL_EMAIL_CODE_OFF' );

		return (bool) apply_filters( 'dx_portal_email_code_enabled', ! $off );
	}

	/**
	 * Record a sign-in that skipped the code because the switch is off.
	 *
	 * @param WP_User $user User.
	 * @param string  $via  Where: wp-login or portal.
	 */
	public static function log_bypass( WP_User $user, string $via ): void {
		Logger::warning(
			'login.code_bypassed',
			array(
				'user' => $user->ID,
				'via'  => $via,
			)
		);
	}

	/**
	 * Start a challenge and email its first code.
	 *
	 * @param WP_User $user     User who gave the right password.
	 * @param bool    $remember Long session.
	 * @param string  $redirect Where to go after.
	 * @param string  $via      wp-login or portal.
	 * @return string|WP_Error Challenge token.
	 */
	public function start( WP_User $user, bool $remember, string $redirect, string $via ): string|WP_Error {
		if ( ! $this->app->limiter->attempt( 'login_code_start', (string) $user->ID, self::STARTS, self::WINDOW ) ) {
			Logger::warning( 'login.code_rate_limited', array( 'user' => $user->ID ) );
			return self::too_many();
		}

		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes.
		$state = array(
			'user'     => $user->ID,
			'remember' => $remember,
			'redirect' => $redirect,
			'via'      => $via,
			'code'     => '',
			'expires'  => 0,
			'attempts' => 0,
			'sent'     => 0,
			'window'   => 0,
		);

		$sent = $this->send( $token, $state );

		return $sent instanceof WP_Error ? $sent : $token;
	}

	/**
	 * Send a new code for an open challenge.
	 *
	 * @param string $token Challenge token.
	 * @return true|WP_Error
	 */
	public function resend( string $token ): bool|WP_Error {
		$state = $this->state( $token );

		if ( null === $state ) {
			return self::expired();
		}

		$sent = $this->send( $token, $state );

		return $sent instanceof WP_Error ? $sent : true;
	}

	/**
	 * Check a code. On success the challenge ends and the caller signs the
	 * user in; five wrong codes end it too.
	 *
	 * @param string $token Challenge token.
	 * @param string $code  Code as typed.
	 * @return array{user:WP_User, remember:bool, redirect:string, via:string}|WP_Error
	 */
	public function verify( string $token, string $code ): array|WP_Error {
		$state = $this->state( $token );

		if ( null === $state || '' === $state['code'] || $state['expires'] <= $this->app->clock->now() ) {
			return self::expired();
		}

		$code = (string) preg_replace( '/\D/', '', $code );

		if ( ! hash_equals( $state['code'], self::hash( $code, $token ) ) ) {
			++$state['attempts'];
			Logger::warning( 'login.code_wrong', array( 'user' => $state['user'] ) );

			if ( $state['attempts'] >= self::ATTEMPTS ) {
				delete_transient( self::key( $token ) );
				return self::expired();
			}

			$this->save( $token, $state );

			return new WP_Error( 'dx_code_wrong', __( 'הקוד לא נכון. בודקים שוב במייל.', 'diromax-portal' ) );
		}

		delete_transient( self::key( $token ) );
		$user = get_userdata( $state['user'] );

		if ( ! $user instanceof WP_User ) {
			return self::expired();
		}

		return array(
			'user'     => $user,
			'remember' => $state['remember'],
			'redirect' => $state['redirect'],
			'via'      => $state['via'],
		);
	}

	/**
	 * The address the code went to, mostly hidden, for the code screen.
	 *
	 * @param string $token Challenge token.
	 * @return string '' when the challenge is gone.
	 */
	public function masked_email( string $token ): string {
		$state = $this->state( $token );
		$user  = null === $state ? false : get_userdata( $state['user'] );

		if ( ! $user ) {
			return '';
		}

		$parts = explode( '@', (string) $user->user_email, 2 );

		return 2 === count( $parts ) ? mb_substr( $parts[0], 0, 2 ) . '•••@' . $parts[1] : '';
	}

	/**
	 * Make, store and email a code.
	 *
	 * @param string               $token Challenge token.
	 * @param array<string, mixed> $state Challenge.
	 * @return true|WP_Error
	 */
	private function send( string $token, array $state ): bool|WP_Error {
		$now   = $this->app->clock->now();
		$fresh = (int) $state['window'] <= $now - self::WINDOW;
		$sent  = $fresh ? 0 : (int) $state['sent'];

		if ( $sent >= self::SENDS ) {
			return self::too_many();
		}

		$user = get_userdata( (int) $state['user'] );

		if ( ! $user ) {
			return self::expired();
		}

		$code              = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$state['code']     = self::hash( $code, $token );
		$state['expires']  = $now + self::TTL;
		$state['attempts'] = 0;
		$state['sent']     = $sent + 1;
		$state['window']   = $fresh ? $now : (int) $state['window'];
		$this->save( $token, $state );

		$message = $this->app->renderer->login_code( (string) $user->display_name, $code );

		if ( ! Mailer::send( (string) $user->user_email, $message, null ) ) {
			Logger::error( 'login.code_mail_failed', array( 'user' => $user->ID ) );
			return new WP_Error( 'dx_code_unsent', __( 'לא הצלחנו לשלוח את הקוד למייל. נסו שוב בעוד רגע.', 'diromax-portal' ) );
		}

		return true;
	}

	/**
	 * The open challenge behind a token.
	 *
	 * @param string $token Challenge token.
	 * @return array{user:int, remember:bool, redirect:string, via:string, code:string, expires:int, attempts:int, sent:int, window:int}|null
	 */
	private function state( string $token ): ?array {
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}

		$state = get_transient( self::key( $token ) );

		if ( ! is_array( $state ) || ! isset( $state['user'] ) ) {
			return null;
		}

		return array(
			'user'     => (int) $state['user'],
			'remember' => (bool) ( $state['remember'] ?? false ),
			'redirect' => (string) ( $state['redirect'] ?? '' ),
			'via'      => (string) ( $state['via'] ?? '' ),
			'code'     => (string) ( $state['code'] ?? '' ),
			'expires'  => (int) ( $state['expires'] ?? 0 ),
			'attempts' => (int) ( $state['attempts'] ?? 0 ),
			'sent'     => (int) ( $state['sent'] ?? 0 ),
			'window'   => (int) ( $state['window'] ?? 0 ),
		);
	}

	/**
	 * Store a challenge for the length of a sending window.
	 *
	 * @param string               $token Challenge token.
	 * @param array<string, mixed> $state Challenge.
	 */
	private function save( string $token, array $state ): void {
		set_transient( self::key( $token ), $state, self::WINDOW );
	}

	/**
	 * Transient key: the token itself is never stored.
	 *
	 * @param string $token Challenge token.
	 * @return string
	 */
	private static function key( string $token ): string {
		return self::KEY . substr( hash( 'sha256', $token ), 0, 40 );
	}

	/**
	 * Hash of a code, bound to its challenge.
	 *
	 * @param string $code  Code.
	 * @param string $token Challenge token.
	 * @return string
	 */
	private static function hash( string $code, string $token ): string {
		return hash_hmac( 'sha256', $code, $token . wp_salt( 'auth' ) );
	}

	/**
	 * The challenge is over.
	 *
	 * @return WP_Error
	 */
	private static function expired(): WP_Error {
		return new WP_Error( 'dx_code_expired', __( 'הקוד פג או שהוזן שגוי יותר מדי פעמים. נכנסים מחדש, ונשלח קוד חדש.', 'diromax-portal' ) );
	}

	/**
	 * Too many codes.
	 *
	 * @return WP_Error
	 */
	private static function too_many(): WP_Error {
		return new WP_Error( 'dx_rate_limited', __( 'נשלחו כבר כמה קודים ברבע השעה האחרונה. אפשר לנסות שוב בעוד כמה דקות.', 'diromax-portal' ) );
	}
}
