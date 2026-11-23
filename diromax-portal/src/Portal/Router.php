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

	/** Query var holding the staff screen under /portal/manage/. */
	public const SCREEN = 'dx_manage';

	/** Query var holding the case ID on a staff case screen. */
	public const CASE_ID = 'dx_manage_case';

	/** Staff screens. */
	public const SCREENS = array( 'dashboard', 'cases', 'board', 'new', 'settings', 'forecast', 'case', 'manifest' );

	/** Bump when the rules change, so an updated plugin refreshes them once. */
	public const RULES_VERSION = '7';

	/** Option holding the rules version last flushed. */
	private const RULES_OPTION = 'dx_portal_rules';

	/** Tabs of the client's case page. */
	public const CASE_TABS = array( 'finance', 'schedule', 'documents', 'updates', 'decisions', 'photos', 'messages' );

	/** Views the router accepts. */
	public const VIEWS = array( 'home', 'login', 'logout', 'account', 'case', 'doc', 'manage', 'report', 'sign', 'signfile', 'photo', 'thumb', 'meeting' );

	/**
	 * Register the rewrite rules.
	 */
	public static function add_rules(): void {
		$base = self::base();

		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . self::VIEW . '=home', 'top' );
		add_rewrite_rule( '^' . $base . '/(login|logout|account)/?$', 'index.php?' . self::VIEW . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/(case|doc)/([a-f0-9]{24})/?$', 'index.php?' . self::VIEW . '=$matches[1]&' . self::ARG . '=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $base . '/case/([a-f0-9]{24})/report/?$', 'index.php?' . self::VIEW . '=report&' . self::ARG . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/photo/([a-f0-9]{24})/?$', 'index.php?' . self::VIEW . '=photo&' . self::ARG . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/photo/([a-f0-9]{24})/thumb/?$', 'index.php?' . self::VIEW . '=thumb&' . self::ARG . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/meeting/([a-f0-9]{24})/?$', 'index.php?' . self::VIEW . '=meeting&' . self::ARG . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/sign/([A-Za-z0-9_-]{43})/?$', 'index.php?' . self::VIEW . '=sign&' . self::ARG . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/sign/([A-Za-z0-9_-]{43})/file/?$', 'index.php?' . self::VIEW . '=signfile&' . self::ARG . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/manage/?$', 'index.php?' . self::VIEW . '=manage&' . self::SCREEN . '=dashboard', 'top' );
		add_rewrite_rule( '^' . $base . '/manage/manifest\\.webmanifest$', 'index.php?' . self::VIEW . '=manage&' . self::SCREEN . '=manifest', 'top' );
		add_rewrite_rule( '^' . $base . '/manage/(cases|board|new|settings|forecast)/?$', 'index.php?' . self::VIEW . '=manage&' . self::SCREEN . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/manage/case/([0-9]{1,10})/?$', 'index.php?' . self::VIEW . '=manage&' . self::SCREEN . '=case&' . self::CASE_ID . '=$matches[1]', 'top' );

		// Replacing the plugin by upload does not run activation: refresh once per rules version.
		if ( self::RULES_VERSION !== get_option( self::RULES_OPTION ) ) {
			flush_rewrite_rules( false );
			update_option( self::RULES_OPTION, self::RULES_VERSION, false );
		}
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
		$vars[] = self::SCREEN;
		$vars[] = self::CASE_ID;

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
	 * The client's account page: password and email preferences.
	 *
	 * @return string
	 */
	public static function account_url(): string {
		return self::url( 'account' );
	}

	/**
	 * A staff screen of the management app, outside wp-admin.
	 *
	 * @param string $screen  dashboard, cases, new, settings or case.
	 * @param int    $case_id Case, for the case screen.
	 * @param string $tab     Case tab.
	 * @return string
	 */
	public static function manage_url( string $screen = 'dashboard', int $case_id = 0, string $tab = '' ): string {
		$screen = in_array( $screen, self::SCREENS, true ) ? $screen : 'dashboard';

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$args = array(
				self::VIEW   => 'manage',
				self::SCREEN => $screen,
			);

			if ( 'case' === $screen ) {
				$args[ self::CASE_ID ] = $case_id;
			}

			$url = add_query_arg( $args, home_url( '/' ) );
		} else {
			$path = match ( $screen ) {
				'dashboard' => '',
				'manifest'  => 'manifest.webmanifest',
				'case'      => 'case/' . $case_id . '/',
				default     => $screen . '/',
			};
			$url = home_url( '/' . self::base() . '/manage/' . $path );
		}

		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * Is this URL a screen of the management app? Used to send the user
	 * back to the app, not to wp-admin, after a form is handled.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_manage_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		// A form's referer field holds a path; a full URL must be this site's.
		if ( ! is_array( $parts ) || ( isset( $parts['host'] ) && wp_parse_url( home_url(), PHP_URL_HOST ) !== $parts['host'] ) ) {
			return false;
		}

		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		if ( 'manage' === ( $query[ self::VIEW ] ?? '' ) ) {
			return true;
		}

		$base = (string) wp_parse_url( home_url( '/' . self::base() . '/manage/' ), PHP_URL_PATH );

		return str_starts_with( trailingslashit( (string) ( $parts['path'] ?? '' ) ), $base );
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

		return in_array( $tab, self::CASE_TABS, true ) ? add_query_arg( 'tab', $tab, $url ) : $url;
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
	 * A site photo, or its thumbnail.
	 *
	 * @param string $public_id Photo public ID.
	 * @param bool   $thumb     The thumbnail.
	 * @return string
	 */
	public static function photo_url( string $public_id, bool $thumb ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return self::url( $thumb ? 'thumb' : 'photo', $public_id );
		}

		return self::url( 'photo', $public_id ) . ( $thumb ? 'thumb/' : '' );
	}

	/**
	 * A meeting's calendar file (spec 20.1).
	 *
	 * @param string $public_id Meeting public ID.
	 * @return string
	 */
	public static function meeting_url( string $public_id ): string {
		return self::url( 'meeting', $public_id );
	}

	/**
	 * The printable report of a case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return string
	 */
	public static function report_url( CaseRecord $client_case ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return self::url( 'report', $client_case->public_id );
		}

		return self::url( 'case', $client_case->public_id ) . 'report/';
	}

	/**
	 * A signing link.
	 *
	 * @param string $token Link token.
	 * @return string
	 */
	public static function sign_url( string $token ): string {
		return self::url( 'sign', $token );
	}

	/**
	 * The document behind a signing link.
	 *
	 * @param string $token Link token.
	 * @return string
	 */
	public static function sign_file_url( string $token ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return self::url( 'signfile', $token );
		}

		return self::url( 'sign', $token ) . 'file/';
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
