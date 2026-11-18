<?php
/**
 * Portal controller.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Plugin;
use Diromax\Portal\Security\Turnstile;
use Diromax\Portal\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Handles every /portal/ request: headers, authentication, authorization,
 * then a view model handed to a template. Nothing here is cacheable.
 */
final class Controller {

	/** How often a client's view of a case is recorded, seconds. */
	private const SEEN_THROTTLE = HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Hooked to template_redirect.
	 */
	public function dispatch(): void {
		$view = (string) get_query_var( Router::VIEW );

		if ( '' === $view ) {
			return;
		}

		self::send_headers();

		match ( $view ) {
			'login'  => $this->login(),
			'logout' => $this->logout(),
			'home'   => $this->home(),
			'case'   => $this->case_page(),
			'doc'    => $this->document(),
			default  => $this->not_found(),
		};

		exit;
	}

	/**
	 * Sign-in: request form, link confirmation, and both POSTs.
	 */
	private function login(): void {
		header( 'Referrer-Policy: no-referrer' );

		// phpcs:disable WordPress.Security.NonceVerification -- Each POST branch verifies its own nonce below.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		$step   = isset( $_POST['dx_step'] ) ? sanitize_key( wp_unslash( $_POST['dx_step'] ) ) : '';
		$token  = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
		// phpcs:enable

		if ( 'post' === $method && 'confirm' === $step ) {
			$this->confirm_link( $token );
			return;
		}

		if ( 'post' === $method && 'request' === $step ) {
			$this->request_link();
			return;
		}

		if ( '' !== $token ) {
			$this->render(
				'login',
				array(
					'state' => $this->app->login->is_valid( $token ) ? 'confirm' : 'invalid',
					'token' => $token,
				),
				__( 'כניסה לאזור האישי', 'diromax-portal' )
			);
			return;
		}

		if ( is_user_logged_in() ) {
			$this->redirect( Router::portal_url() );
			return;
		}

		$this->render( 'login', array( 'state' => 'form' ), __( 'כניסה לאזור האישי', 'diromax-portal' ) );
	}

	/**
	 * POST: send a sign-in link. The answer never says whether the address is known.
	 */
	private function request_link(): void {
		$nonce = isset( $_POST['_dx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dx_nonce'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$trap  = isset( $_POST['dx_website'] ) ? sanitize_text_field( wp_unslash( $_POST['dx_website'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'dx_portal_login' ) ) {
			$this->render( 'login', array( 'state' => 'expired' ), __( 'כניסה לאזור האישי', 'diromax-portal' ) );
			return;
		}

		if ( Turnstile::enabled() ) {
			$captcha = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';

			if ( ! Turnstile::verify( $captcha ) ) {
				$this->render( 'login', array( 'state' => 'captcha' ), __( 'כניסה לאזור האישי', 'diromax-portal' ) );
				return;
			}
		}

		// Bots fill the hidden field; they get the same answer as everyone.
		if ( '' === $trap ) {
			$this->app->login->request_link( $email );
		}

		$this->render( 'login', array( 'state' => 'sent' ), __( 'בדקו את תיבת הדואר', 'diromax-portal' ) );
	}

	/**
	 * POST: spend the token and sign in.
	 *
	 * @param string $token Token.
	 */
	private function confirm_link( string $token ): void {
		$nonce    = isset( $_POST['_dx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dx_nonce'] ) ) : '';
		$remember = isset( $_POST['remember'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['remember'] ) );

		if ( ! wp_verify_nonce( $nonce, 'dx_portal_confirm' ) ) {
			$this->render( 'login', array( 'state' => 'expired' ), __( 'כניסה לאזור האישי', 'diromax-portal' ) );
			return;
		}

		$result = $this->app->login->consume( $token, $remember );

		if ( is_wp_error( $result ) ) {
			$this->render( 'login', array( 'state' => 'invalid' ), __( 'כניסה לאזור האישי', 'diromax-portal' ) );
			return;
		}

		$this->redirect( Router::portal_url() );
	}

	/**
	 * POST: sign out.
	 */
	private function logout(): void {
		$nonce = isset( $_POST['_dx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dx_nonce'] ) ) : '';

		if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'dx_portal_logout' ) ) {
			wp_logout();
			$this->redirect( add_query_arg( 'out', '1', Router::login_url() ) );
			return;
		}

		$this->redirect( Router::portal_url() );
	}

	/**
	 * The client's cases; straight into the case when there is only one.
	 */
	private function home(): void {
		$user = $this->require_login();

		if ( $this->app->policy->can_use_admin( $user ) ) {
			$this->redirect( admin_url( 'admin.php?page=dx-cases' ) );
			return;
		}

		$cases = array_values(
			array_filter(
				$this->app->cases->for_member( $user ),
				fn( $client_case ): bool => $this->app->policy->can_view_case( $user, $client_case )
			)
		);

		if ( 1 === count( $cases ) ) {
			$this->redirect( Router::case_url( $cases[0] ) );
			return;
		}

		$this->render( 'cases', CaseView::list( $cases ), __( 'התיקים שלי', 'diromax-portal' ) );
	}

	/**
	 * One case.
	 */
	private function case_page(): void {
		$user        = $this->require_login();
		$id          = (string) get_query_var( Router::ARG );
		$client_case = Ids::is_public_id( $id ) ? $this->app->cases->find_by_public_id( $id ) : null;

		// Someone else's case answers exactly like a missing one (standard 4.7.3).
		if ( null === $client_case || ! $this->app->policy->can_view_case( $user, $client_case ) ) {
			$this->not_found();
			return;
		}

		$this->record_view( $user, $client_case );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'finance';
		$view = ( new CaseView( $this->app ) )->build( $client_case, $user, $tab );

		$this->render( 'case', $view, $client_case->title );
	}

	/**
	 * A document download.
	 */
	private function document(): void {
		$user        = $this->require_login();
		$id          = (string) get_query_var( Router::ARG );
		$document    = Ids::is_public_id( $id ) ? $this->app->documents->find_by_public_id( $id ) : null;
		$client_case = null === $document ? null : $this->app->cases->find( $document->case_id );

		if ( null === $document || null === $client_case || is_wp_error( $this->app->document_service->send( $client_case, $document, $user ) ) ) {
			$this->not_found();
		}
	}

	/**
	 * Remember that a member opened the case: last-seen time and an audit entry, once an hour.
	 *
	 * @param int                             $user User.
	 * @param \Diromax\Portal\Data\CaseRecord $client_case Case.
	 */
	private function record_view( int $user, \Diromax\Portal\Data\CaseRecord $client_case ): void {
		if ( ! $this->app->members->is_member( $client_case->id, $user ) ) {
			return;
		}

		$key = 'dx_portal_seen_' . $client_case->id . '_' . $user;

		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, self::SEEN_THROTTLE );
		$now = $this->app->clock->mysql();
		$this->app->members->mark_seen( $client_case->id, $user, $now );
		$this->app->audit->record( 'portal.case_viewed', $client_case->id, 'case', $client_case->id, array(), $now );
	}

	/**
	 * Current user, or a redirect to the sign-in page.
	 *
	 * @return int
	 */
	private function require_login(): int {
		$user = get_current_user_id();

		if ( $user <= 0 ) {
			$this->redirect( Router::login_url() );
			exit;
		}

		return $user;
	}

	/**
	 * 404 with the portal's own page.
	 */
	private function not_found(): void {
		status_header( 404 );
		$this->render( 'not-found', array(), __( 'הדף לא נמצא', 'diromax-portal' ), false );
	}

	/**
	 * Render a portal template inside the theme's header and footer.
	 *
	 * @param string               $template Template name under templates/portal.
	 * @param array<string, mixed> $view     View model.
	 * @param string               $title    Document title.
	 * @param bool                 $ok       Send a 200 status.
	 */
	private function render( string $template, array $view, string $title, bool $ok = true ): void {
		if ( $ok ) {
			status_header( 200 );
		}

		Assets::enqueue( 'login' === $template && Turnstile::enabled() );

		add_filter(
			'pre_get_document_title',
			static fn(): string => $title . ' · ' . get_bloginfo( 'name' )
		);
		add_filter( 'body_class', static fn( array $classes ): array => array_merge( $classes, array( 'dx-portal-page' ) ) );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		$view['user']   = wp_get_current_user();
		$view['logout'] = Router::logout_url();

		get_header();
		include DX_PORTAL_DIR . 'templates/portal/' . $template . '.php';
		get_footer();
	}

	/**
	 * Redirect within the site.
	 *
	 * @param string $url Target.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
	}

	/**
	 * Headers for every portal response (standard 4.9, spec 9).
	 */
	private static function send_headers(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The cache plugins' shared constant.
		}

		do_action( 'litespeed_control_set_nocache', 'diromax portal' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		nocache_headers();

		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
	}
}
