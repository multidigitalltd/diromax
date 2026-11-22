<?php
/**
 * Postmark webhooks.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Data\Logger;
use Diromax\Portal\Plugin;
use Diromax\Portal\Security\RateLimiter;
use Diromax\Portal\Service\InboundService;
use Diromax\Portal\Support\MailConfig;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Two REST endpoints Postmark calls (spec 17.2, 17.3):
 *   POST /wp-json/diromax-portal/v1/postmark/events   bounces and spam complaints
 *   POST /wp-json/diromax-portal/v1/postmark/inbound  client replies
 *
 * Postmark does not sign its requests, so each one must carry HTTP Basic
 * credentials, written into the webhook URL in Postmark: user name
 * "postmark", password DX_POSTMARK_WEBHOOK_KEY from wp-config.php, at
 * least 32 characters.
 * Without the key the endpoints refuse everything. Failed attempts are rate
 * limited per address. The endpoints never sign in a WordPress user.
 */
final class Webhooks {

	/** REST namespace. */
	public const NAMESPACE = 'diromax-portal/v1';

	/** Shortest accepted key. */
	public const MIN_KEY = 32;

	/** Failed attempts per address per window. */
	private const FAILURES = 20;

	/** Failure window, seconds. */
	private const WINDOW = 600;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Register.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		// The Basic credentials are for this endpoint, not a WordPress application password.
		add_filter( 'application_password_is_api_request', array( self::class, 'not_for_application_passwords' ) );
		// All-In-One Security can refuse REST requests from visitors; Postmark is one. The key guards these routes.
		add_filter( 'aios_whitelisted_rest_routes', array( self::class, 'allow_in_aios' ) );
	}

	/**
	 * Keep the webhook namespace reachable when AIOS blocks REST for visitors.
	 *
	 * @param mixed $routes Whitelisted REST namespaces.
	 * @return array<int, string>
	 */
	public static function allow_in_aios( $routes ): array {
		$routes   = is_array( $routes ) ? array_values( array_filter( $routes, 'is_string' ) ) : array();
		$routes[] = 'diromax-portal';  // /wp-json/diromax-portal/v1/postmark/...
		$routes[] = '/diromax-portal'; // ?rest_route=/diromax-portal/v1/postmark/..., without pretty permalinks.

		return array_values( array_unique( $routes ) );
	}

	/**
	 * The routes.
	 */
	public function routes(): void {
		foreach ( array(
			'events'  => 'events',
			'inbound' => 'inbound',
		) as $path => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/postmark/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'authorized' ),
				)
			);
		}
	}

	/**
	 * Is the webhook key configured?
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return '' !== self::key();
	}

	/**
	 * URL of an endpoint, without credentials.
	 *
	 * @param string $path events or inbound.
	 * @return string
	 */
	public static function url( string $path ): string {
		return rest_url( self::NAMESPACE . '/postmark/' . $path );
	}

	/**
	 * Does the request carry the key?
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorized( WP_REST_Request $request ): bool|WP_Error {
		$ip = RateLimiter::client_ip();

		if ( $this->app->limiter->exhausted( 'webhook_fail', $ip, self::FAILURES ) ) {
			return new WP_Error( 'dx_rate_limited', 'Too many attempts.', array( 'status' => 429 ) );
		}

		$key = self::key();

		if ( '' !== $key && hash_equals( $key, self::password( $request ) ) ) {
			return true;
		}

		$this->app->limiter->attempt( 'webhook_fail', $ip, PHP_INT_MAX, self::WINDOW );
		Logger::warning( 'webhook.refused', array( 'configured' => '' !== $key ) );

		return new WP_Error( 'dx_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
	}

	/**
	 * A bounce or a spam complaint: mark the email in the mail log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function events( WP_REST_Request $request ): WP_REST_Response {
		$event = $request->get_json_params();

		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$type   = is_string( $event['RecordType'] ?? null ) ? $event['RecordType'] : '';
		$status = match ( $type ) {
			'Bounce'        => 'bounced',
			'SpamComplaint' => 'complaint',
			default         => '',
		};

		if ( '' === $status ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$id     = is_string( $event['MessageID'] ?? null ) ? mb_substr( $event['MessageID'], 0, 64 ) : '';
		$reason = trim( ( is_string( $event['Type'] ?? null ) ? $event['Type'] : $type ) . ': ' . ( is_string( $event['Description'] ?? null ) ? $event['Description'] : '' ) );
		$marked = $this->app->mail_log->mark( $id, $status, sanitize_text_field( $reason ) );

		Logger::warning(
			'mail.' . $status,
			array(
				'rows' => $marked,
				'type' => is_string( $event['Type'] ?? null ) ? sanitize_key( $event['Type'] ) : '',
			)
		);

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * A client's reply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function inbound( WP_REST_Request $request ): WP_REST_Response {
		$mail = $request->get_json_params();

		if ( ! is_array( $mail ) ) {
			// 403: Postmark stops retrying a message that can never be read.
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		try {
			$result = ( new InboundService( $this->app ) )->receive( $mail );
		} catch ( \Throwable $error ) {
			Logger::error(
				'inbound.crashed',
				array(
					'error' => get_class( $error ),
					'where' => basename( $error->getFile() ) . ':' . $error->getLine(),
				)
			);
			$result = new WP_Error( 'dx_inbound_failed' );
		}

		if ( $result instanceof WP_Error ) {
			// Anything else than 200 makes Postmark try again later.
			return new WP_REST_Response( array( 'ok' => false ), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'result' => $result,
			),
			200
		);
	}

	/**
	 * Turn WordPress's application-password check off for these endpoints:
	 * their Basic credentials name no WordPress user, and the check would
	 * refuse the request before it reached them.
	 *
	 * @param bool $is_api_request Core's answer.
	 * @return bool
	 */
	public static function not_for_application_passwords( $is_api_request ): bool {
		return self::is_webhook_request() ? false : (bool) $is_api_request;
	}

	/**
	 * Is this request for one of the endpoints?
	 *
	 * @return bool
	 */
	private static function is_webhook_request(): bool {
		$route = '/' . self::NAMESPACE . '/postmark/';
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$query = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; nothing is changed.

		return str_starts_with( $query, $route ) || str_contains( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' . rest_get_url_prefix() . $route );
	}

	/**
	 * The Basic password sent with the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string '' when none.
	 */
	private static function password( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );

		if ( preg_match( '/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim( $header ), $match ) ) {
			$pair = base64_decode( $match[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials are base64 (RFC 7617).

			if ( is_string( $pair ) && str_contains( $pair, ':' ) ) {
				return explode( ':', $pair, 2 )[1];
			}
		}

		return isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with hash_equals only; sanitizing would change the secret.
	}

	/**
	 * The configured key, '' when missing or too short.
	 *
	 * @return string
	 */
	private static function key(): string {
		$key = MailConfig::get( 'webhook_key' );

		return strlen( $key ) >= self::MIN_KEY ? $key : '';
	}
}
