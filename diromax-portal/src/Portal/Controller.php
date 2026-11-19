<?php
/**
 * Portal controller.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Plugin;
use Diromax\Portal\Service\AgreementService;
use Diromax\Portal\Service\CsvExport;
use Diromax\Portal\Service\DecisionService;
use Diromax\Portal\Service\Errors;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\PasswordService;
use Diromax\Portal\Security\Turnstile;
use Diromax\Portal\Support\Ids;
use WP_Error;

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
			'logout'  => $this->logout(),
			'account' => $this->account(),
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

		if ( 'post' === $method && 'password' === $step ) {
			$this->password_sign_in();
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
	 * Sign in with email and password (spec 15.10).
	 */
	private function password_sign_in(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified on the first line below.
		$nonce    = isset( $_POST['_dx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dx_nonce'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is compared as typed, never stored or echoed.
		$remember = isset( $_POST['remember'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['remember'] ) );
		$captcha  = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		// phpcs:enable

		if ( ! wp_verify_nonce( $nonce, 'dx_portal_password_login' ) ) {
			$this->render( 'login', array( 'state' => 'expired' ), __( 'כניסה לאזור האישי', 'diromax-portal' ) );
			return;
		}

		if ( Turnstile::enabled() && ! Turnstile::verify( $captcha ) ) {
			$this->render( 'login', array( 'state' => 'captcha' ), __( 'כניסה לאזור האישי', 'diromax-portal' ) );
			return;
		}

		$result = ( new PasswordService( $this->app ) )->sign_in( $email, $password, $remember );

		if ( is_wp_error( $result ) ) {
			$this->render(
				'login',
				array(
					'state' => 'password_failed',
					'email' => $email,
				),
				__( 'כניסה לאזור האישי', 'diromax-portal' )
			);
			return;
		}

		$this->redirect( Router::portal_url() );
	}

	/**
	 * The client's account: email preference per case, and the password.
	 */
	private function account(): void {
		$user = $this->require_login();

		if ( $this->app->policy->can_use_admin( $user ) ) {
			$this->redirect( admin_url( 'profile.php' ) );
			return;
		}

		$cases = array_values(
			array_filter(
				$this->app->cases->for_member( $user ),
				fn( CaseRecord $client_case ): bool => $this->app->policy->can_view_case( $user, $client_case )
			)
		);

		if ( 'post' === self::method() ) {
			$this->redirect( add_query_arg( 'done', $this->account_action( $cases, $user ), Router::account_url() ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A message code from an allowlist.
		$done  = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$modes = array();

		foreach ( $cases as $client_case ) {
			foreach ( $this->app->members->for_case( $client_case->id ) as $member ) {
				if ( $member->user_id === $user ) {
					$modes[] = array(
						'id'    => $client_case->public_id,
						'title' => $client_case->title,
						'mode'  => $member->mode()->value,
						'url'   => Router::case_url( $client_case ),
					);
				}
			}
		}

		$this->render(
			'account',
			array(
				'modes'        => $modes,
				'labels'       => Labels::notify_modes(),
				'has_password' => PasswordService::has_password( $user ),
				'recent'       => ( new PasswordService( $this->app ) )->recently_signed_in( $user, wp_get_session_token() ),
				'min'          => PasswordService::MIN_LENGTH,
				'notice'       => self::notice( $done ),
				'self'         => Router::account_url(),
			),
			__( 'החשבון שלי', 'diromax-portal' )
		);
	}

	/**
	 * Handle a form on the account page.
	 *
	 * @param CaseRecord[] $cases Cases the user may see.
	 * @param int          $user  User.
	 * @return string Result code for notice().
	 */
	private function account_action( array $cases, int $user ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each branch verifies its own nonce.
		$step  = isset( $_POST['dx_step'] ) ? sanitize_key( wp_unslash( $_POST['dx_step'] ) ) : '';
		$nonce = isset( $_POST['_dx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dx_nonce'] ) ) : '';

		if ( 'modes' === $step && wp_verify_nonce( $nonce, 'dx_portal_modes' ) ) {
			$posted = isset( $_POST['mode'] ) && is_array( $_POST['mode'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['mode'] ) ) : array();

			foreach ( $cases as $client_case ) {
				$mode = (string) ( $posted[ $client_case->public_id ] ?? '' );

				if ( '' !== $mode && is_wp_error( $this->app->case_service->set_member_mode( $client_case, $user, $mode, $user ) ) ) {
					return 'failed';
				}
			}

			return 'saved';
		}

		if ( 'password' === $step && wp_verify_nonce( $nonce, 'dx_portal_password' ) ) {
			// Passwords are compared and hashed as typed, never stored raw or echoed.
			$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
			$repeat   = isset( $_POST['password2'] ) && is_string( $_POST['password2'] ) ? wp_unslash( $_POST['password2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
			$current  = isset( $_POST['current'] ) && is_string( $_POST['current'] ) ? wp_unslash( $_POST['current'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.

			if ( $password !== $repeat ) {
				return 'mismatch';
			}

			$result = ( new PasswordService( $this->app ) )->change( $user, wp_get_session_token(), $password, $current );

			return match ( true ) {
				true === $result                           => 'password_set',
				'dx_reauth' === $result->get_error_code()  => 'reauth',
				'dx_invalid' === $result->get_error_code() => 'weak',
				default                                    => 'failed',
			};
		}
		// phpcs:enable

		return 'expired';
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
			$this->redirect( admin_url( 'admin.php?page=dx-dashboard' ) );
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

		if ( 'post' === self::method() ) {
			$this->case_action( $client_case, $user );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The export branch verifies its own nonce.
		if ( isset( $_GET['export'] ) && 'csv' === sanitize_key( wp_unslash( $_GET['export'] ) ) ) {
			$this->export( $client_case, $user );
			return;
		}

		$this->record_view( $user, $client_case );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only tab selection and a message code from an allowlist.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'finance';
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		// phpcs:enable
		$view = ( new CaseView( $this->app ) )->build( $client_case, $user, $tab );

		$view['self']   = Router::case_url( $client_case, (string) $view['tab'] );
		$view['notice'] = self::notice( $done );
		$view['export'] = add_query_arg(
			array(
				'export' => 'csv',
				'_dxn'   => wp_create_nonce( 'dx_portal_export_' . $client_case->id ),
			),
			Router::case_url( $client_case )
		);

		$this->render( 'case', $view, $client_case->title );
	}

	/**
	 * A form posted from the case page: answer a decision or confirm the agreement.
	 *
	 * Post/redirect/get: the result comes back as an allowlisted message code.
	 *
	 * @param CaseRecord $client_case Case the viewer may see.
	 * @param int        $user        Viewer.
	 */
	private function case_action( CaseRecord $client_case, int $user ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each branch verifies its own nonce, bound to the object.
		$step   = isset( $_POST['dx_step'] ) ? sanitize_key( wp_unslash( $_POST['dx_step'] ) ) : '';
		$nonce  = isset( $_POST['_dx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dx_nonce'] ) ) : '';
		$object = absint( $_POST['decision'] ?? ( $_POST['document'] ?? 0 ) );
		$ok     = isset( $_POST['confirm'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['confirm'] ) );
		$answer = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable

		[ $tab, $result ] = match ( $step ) {
			'decide'      => array( 'decisions', wp_verify_nonce( $nonce, 'dx_portal_decide_' . $object ) ? ( new DecisionService( $this->app ) )->answer( $client_case, $object, $user, $answer, $note, $ok ) : 'expired' ),
			'acknowledge' => array( 'documents', ! wp_verify_nonce( $nonce, 'dx_portal_ack_' . $object ) ? 'expired' : ( $ok ? ( new AgreementService( $this->app ) )->acknowledge( $client_case, $object, $user ) : 'confirm' ) ),
			default       => array( 'finance', 'failed' ),
		};

		if ( $result instanceof WP_Error && in_array( $result->get_error_code(), array( 'dx_not_found', 'dx_forbidden' ), true ) ) {
			$this->not_found();
			return;
		}

		$code = match ( true ) {
			true === $result && 'documents' === $tab => 'acknowledged',
			true === $result                         => 'approve' === $answer ? 'approved' : 'declined',
			is_string( $result )                     => $result,
			'dx_not_pending' === $result->get_error_code() => 'closed',
			isset( Errors::fields( $result )['confirm'] )  => 'confirm',
			default                                  => 'failed',
		};

		$this->redirect( add_query_arg( 'done', $code, Router::case_url( $client_case, $tab ) ) );
	}

	/**
	 * Download the client's view of the finance table.
	 *
	 * @param CaseRecord $client_case Case the viewer may see.
	 * @param int        $user        Viewer.
	 */
	private function export( CaseRecord $client_case, int $user ): void {
		$nonce = isset( $_GET['_dxn'] ) ? sanitize_text_field( wp_unslash( $_GET['_dxn'] ) ) : '';
		$csv   = wp_verify_nonce( $nonce, 'dx_portal_export_' . $client_case->id ) ? ( new CsvExport( $this->app ) )->build( $client_case, $user, false ) : null;

		if ( ! is_string( $csv ) ) {
			$this->redirect( add_query_arg( 'done', 'expired', Router::case_url( $client_case ) ) );
			return;
		}

		CsvExport::output( $csv, CsvExport::filename( $client_case, $this->app->clock->today() ) );
	}

	/**
	 * Message for a result code, or null.
	 *
	 * @param string $code Code from the query string.
	 * @return array{type:string, text:string}|null
	 */
	private static function notice( string $code ): ?array {
		$ok = array(
			'approved'     => __( 'תודה. האישור נרשם בתיק ונשלח למנהל הפרויקט.', 'diromax-portal' ),
			'declined'     => __( 'התשובה נרשמה בתיק ונשלחה למנהל הפרויקט.', 'diromax-portal' ),
			'acknowledged' => __( 'תודה. אישור הקריאה נרשם.', 'diromax-portal' ),
			'saved'        => __( 'השינויים נשמרו.', 'diromax-portal' ),
			'password_set' => __( 'הסיסמה נשמרה. שאר החיבורים לחשבון נותקו, ונשלח אליך מייל אישור.', 'diromax-portal' ),
		);
		$no = array(
			'confirm'  => __( 'כדי לאשר צריך לסמן שקראת את הפרטים.', 'diromax-portal' ),
			'closed'   => __( 'ההחלטה כבר נענתה או בוטלה, ולכן לא נשמר שינוי.', 'diromax-portal' ),
			'expired'  => __( 'פג תוקף הטופס. רעננו את העמוד ונסו שוב.', 'diromax-portal' ),
			'failed'   => __( 'לא הצלחנו לשמור. נסו שוב, או פנו למנהל הפרויקט.', 'diromax-portal' ),
			'mismatch' => __( 'שתי הסיסמאות לא זהות. אפשר להקליד שוב.', 'diromax-portal' ),
			/* translators: %d: minimum length. */
			'weak'     => sprintf( __( 'הסיסמה לא מתאימה: צריך לפחות %d תווים, בלי כתובת המייל ובלי רצף חוזר, ולא סיסמה שדלפה בעבר. משפט קצר שקל לזכור עובד טוב.', 'diromax-portal' ), PasswordService::MIN_LENGTH ),
			'reauth'   => __( 'כדי לשנות את הסיסמה צריך להזין את הסיסמה הנוכחית, או להיכנס מחדש עם קישור למייל.', 'diromax-portal' ),
		);

		return match ( true ) {
			isset( $ok[ $code ] ) => array(
				'type' => 'ok',
				'text' => $ok[ $code ],
			),
			isset( $no[ $code ] ) => array(
				'type' => 'error',
				'text' => $no[ $code ],
			),
			default               => null,
		};
	}

	/**
	 * HTTP method, lower case.
	 *
	 * @return string
	 */
	private static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
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
