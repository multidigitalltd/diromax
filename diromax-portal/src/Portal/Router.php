<?php
/**
 * Portal routes and URLs.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;

defined( 'ABSPATH' ) || exit;

/**
 * The portal's URL space: /portal/, /portal/login/, /portal/logout/,
 * /portal/case/{id}/ and /portal/doc/{id}/. Works without pretty permalinks
 * too, through query arguments.
 */
final class Router {

	/** Query var holding the view. */
	public const VIEW = 'dx_portal';

	/** Query var holding the object's public ID. */
	public const ARG = 'dx_portal_id';

	/** Views the router accepts. */
	public const VIEWS = array( 'home', 'login', 'logout', 'case', 'doc' );

	/**
	 * Register the rewrite rules.
	 */
	public static function add_rules(): void {
		$base = self::base();

		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . self::VIEW . '=home', 'top' );
		add_rewrite_rule( '^' . $base . '/(login|logout)/?$', 'index.php?' . self::VIEW . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/(case|doc)/([a-f0-9]{24})/?$', 'index.php?' . self::VIEW . '=$matches[1]&' . self::ARG . '=$matches[2]', 'top' );
	}

	/**
	 * Add the query vars.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::VIEW;
		$vars[] = self::ARG;

		return $vars;
	}

	/**
	 * Portal home.
	 *
	 * @return string
	 */
	public static function portal_url(): string {
		return self::url( 'home' );
	}

	/**
	 * Login page, optionally carrying a one-time token.
	 *
	 * @param string $token Token, or '' for the request form.
	 * @return string
	 */
	public static function login_url( string $token = '' ): string {
		$url = self::url( 'login' );

		return '' === $token ? $url : add_query_arg( 't', rawurlencode( $token ), $url );
	}

	/**
	 * Logout endpoint (POST only).
	 *
	 * @return string
	 */
	public static function logout_url(): string {
		return self::url( 'logout' );
	}

	/**
	 * A case, optionally on a tab.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $tab  finance, documents or updates.
	 * @return string
	 */
	public static function case_url( CaseRecord $client_case, string $tab = '' ): string {
		$url = self::url( 'case', $client_case->public_id );

		return in_array( $tab, array( 'finance', 'documents', 'updates' ), true ) ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * A document download.
	 *
	 * @param DocumentRecord $document Document.
	 * @return string
	 */
	public static function document_url( DocumentRecord $document ): string {
		return self::url( 'doc', $document->public_id );
	}

	/**
	 * URL slug of the portal; filterable for a different path.
	 *
	 * @return string
	 */
	public static function base(): string {
		$base = sanitize_title( (string) apply_filters( 'dx_portal_base', 'portal' ) );

		return '' === $base ? 'portal' : $base;
	}

	/**
	 * Pretty URL when permalinks are on, query arguments otherwise.
	 *
	 * @param string $view View.
	 * @param string $id   Public ID.
	 * @return string
	 */
	private static function url( string $view, string $id = '' ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$args = array( self::VIEW => $view );

			if ( '' !== $id ) {
				$args[ self::ARG ] = $id;
			}

			return add_query_arg( $args, home_url( '/' ) );
		}

		$path = 'home' === $view ? '' : $view . '/' . ( '' === $id ? '' : $id . '/' );

		return home_url( '/' . self::base() . '/' . $path );
	}
}
