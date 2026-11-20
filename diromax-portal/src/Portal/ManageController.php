<?php
/**
 * The management app on the site.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\Flash;
use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Plugin;
use Diromax\Portal\Support\AssetFile;

defined( 'ABSPATH' ) || exit;

/**
 * /portal/manage/: the same screens as wp-admin, without WordPress around
 * them, for staff who manage cases from the site or a phone.
 *
 * Nothing new is trusted here. Staff sign in through WordPress (with
 * two-factor), every screen checks the same capability and case access as
 * wp-admin, and every form posts to the same admin-post handlers with the
 * same nonces. The handlers send the user back here because the form's
 * referer is an app URL.
 */
final class ManageController {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Handle a request under /portal/manage/. The caller sent the no-cache
	 * headers and exits after this returns.
	 */
	public function dispatch(): void {
		$user   = get_current_user_id();
		$screen = (string) get_query_var( Router::SCREEN );
		$screen = in_array( $screen, Router::SCREENS, true ) ? $screen : 'dashboard';

		// The home-screen manifest is fetched without cookies and holds nothing private.
		if ( 'manifest' === $screen ) {
			self::manifest();
			return;
		}

		if ( 0 === $user ) {
			wp_safe_redirect( wp_login_url( self::requested_url( $screen ) ) );
			return;
		}

		// Clients and other accounts have nothing here: their place is the portal.
		if ( ! $this->app->policy->can_use_admin( $user ) ) {
			wp_safe_redirect( Router::portal_url() );
			return;
		}

		Admin::use_front( true );
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$pages = new Pages( $this->app );

		match ( $screen ) {
			'cases'    => $this->render( 'manage/cases', $pages->cases_data( $user ), __( 'כל התיקים', 'diromax-portal' ) ),
			'new'      => $this->render( 'admin/case-new', $pages->new_case_data( $user ), __( 'תיק חדש', 'diromax-portal' ) ),
			'settings' => $this->settings( $pages, $user ),
			'case'     => $this->case_screen( $pages, $user ),
			default    => $this->render( 'admin/dashboard', $pages->dashboard_data( $user ) + array( 'flash' => Flash::take() ), __( 'לוח בקרה', 'diromax-portal' ) ),
		};
	}

	/**
	 * Settings: administrators only, as in wp-admin.
	 *
	 * @param Pages $pages View models.
	 * @param int   $user  User.
	 */
	private function settings( Pages $pages, int $user ): void {
		if ( ! $this->app->policy->can_manage_settings( $user ) ) {
			$this->not_found();
			return;
		}

		$this->render( 'admin/settings', $pages->settings_data(), __( 'הגדרות', 'diromax-portal' ) );
	}

	/**
	 * One case. A case the user may not manage answers like a missing one.
	 *
	 * @param Pages $pages View models.
	 * @param int   $user  User.
	 */
	private function case_screen( Pages $pages, int $user ): void {
		$client_case = $this->app->cases->find( absint( get_query_var( Router::CASE_ID ) ) );

		if ( null === $client_case || ! $this->app->policy->can_manage_case( $user, $client_case ) ) {
			$this->not_found();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		$this->render( 'admin/case', $pages->case_data( $client_case, $user, $tab ), $client_case->title );
	}

	/**
	 * 404 inside the app.
	 */
	private function not_found(): void {
		$this->render( 'manage/not-found', array(), __( 'לא נמצא', 'diromax-portal' ), 404 );
	}

	/**
	 * Print a screen inside the app's own page, without the theme.
	 *
	 * @param string               $template Template under templates/, without .php.
	 * @param array<string, mixed> $view     View model.
	 * @param string               $title    Page title.
	 * @param int                  $status   HTTP status.
	 */
	private function render( string $template, array $view, string $title, int $status = 200 ): void {
		status_header( $status );
		Admin::register_assets();

		$view['notices'] = ( new Admin( $this->app ) )->config_notices( get_current_user_id() );
		$document_title  = $title . ' · ' . __( 'תיקי לקוחות', 'diromax-portal' ) . ' · ' . get_bloginfo( 'name' );
		$template_file   = DX_PORTAL_DIR . 'templates/' . $template . '.php';

		include DX_PORTAL_DIR . 'templates/manage/shell.php';
	}

	/**
	 * Web app manifest: the app can be added to a phone's home screen and
	 * opens full screen, like an app.
	 */
	private static function manifest(): void {
		$pretty = '' !== (string) get_option( 'permalink_structure' );
		$icon   = static fn( int $size, string $purpose ): array => array(
			'src'     => AssetFile::url( 'assets/img/app-icon-' . $size . '.png' ),
			'sizes'   => $size . 'x' . $size,
			'type'    => 'image/png',
			'purpose' => $purpose,
		);

		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );

		// JSON for the browser, not HTML: wp_json_encode() escapes it for its context.
		echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document, encoded by wp_json_encode().
			array(
				'name'             => get_bloginfo( 'name' ) . ' · ' . __( 'ניהול תיקים', 'diromax-portal' ),
				'short_name'       => get_bloginfo( 'name' ),
				'lang'             => 'he',
				'dir'              => 'rtl',
				'start_url'        => Router::manage_url(),
				'scope'            => $pretty ? Router::manage_url() : home_url( '/' ),
				'display'          => 'standalone',
				'background_color' => '#f4f4f2',
				'theme_color'      => '#15171c',
				'icons'            => array( $icon( 192, 'any' ), $icon( 512, 'any' ), $icon( 192, 'maskable' ), $icon( 512, 'maskable' ) ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * The URL that was asked for, to come back to after signing in.
	 *
	 * @param string $screen Screen.
	 * @return string
	 */
	private static function requested_url( string $screen ): string {
		return Router::manage_url( $screen, absint( get_query_var( Router::CASE_ID ) ) );
	}
}
