<?php
/**
 * Admin screens.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\BudgetTemplate;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\MeetingInput;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\StageProgress;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Portal\Webhooks;
use Diromax\Portal\Service\Contact;
use Diromax\Portal\Service\ForecastService;
use Diromax\Portal\Service\InboundService;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\LeadLink;
use Diromax\Portal\Service\MeetingService;
use Diromax\Portal\Service\MessageService;
use Diromax\Portal\Service\MorningSummary;
use Diromax\Portal\Service\PhotoService;
use Diromax\Portal\Service\PlanService;
use Diromax\Portal\Service\Postmark;
use Diromax\Portal\Service\SignatureService;
use Diromax\Portal\Service\Stages;
use Diromax\Portal\Service\TaskService;
use Diromax\Portal\Settings;
use Diromax\Portal\Support\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Builds view models for the admin templates. No writes happen here.
 */
final class Pages {

	/** Cases per page in the management app's list. */
	private const PER_PAGE = 25;

	/** Most active cases the stage board shows. */
	private const BOARD_LIMIT = 500;

	/** Case screen tabs. */
	public const TABS = array( 'overview', 'tasks', 'meetings', 'details', 'members', 'budget', 'finance', 'plan', 'decisions', 'updates', 'photos', 'messages', 'documents', 'log' );

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Cases list, or one case when ?case= is present.
	 */
	public function cases(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$case_id = isset( $_GET['case'] ) ? absint( $_GET['case'] ) : 0;

		if ( $case_id > 0 ) {
			$this->case_screen( $case_id );
			return;
		}

		$table = new CasesTable( $this->app );
		$table->prepare_items();
		$view = array( 'flash' => Flash::take() );

		include DX_PORTAL_DIR . 'templates/admin/cases.php';
	}

	/**
	 * Manager dashboard (spec 15.8): what needs attention, across the user's cases.
	 */
	public function dashboard(): void {
		$user = get_current_user_id();

		if ( ! $this->app->policy->can_use_admin( $user ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$view = $this->dashboard_data( $user );

		include DX_PORTAL_DIR . 'templates/admin/dashboard.php';
	}

	/**
	 * Dashboard cards for a user: everything for an administrator, own cases for a manager.
	 *
	 * @param int $user User.
	 * @return array<string, mixed>
	 */
	public function dashboard_data( int $user ): array {
		$manager = $this->app->policy->can_manage_all( $user ) ? 0 : $user;
		$today   = $this->app->clock->today();
		$day     = static fn( int $days ): string => gmdate( 'Y-m-d', (int) strtotime( $today . ' ' . sprintf( '%+d', $days ) . ' days UTC' ) );
		$stale   = Settings::all()['stale_days'];
		$repo    = $this->app->dashboard;

		$overdue = $repo->requests_due( $manager, '1000-01-01', $day( -1 ) );
		$scope   = 0 === $manager ? null : $manager;
		$cases   = $this->app->cases->active_for( $scope, 20 );

		return array(
			'all'        => 0 === $manager,
			'today'      => $today,
			'stale_days' => $stale,
			'has_cases'  => array() !== $cases || $this->app->cases->any_for( $scope ),
			'cases'      => $cases,
			'totals'     => $this->app->cases->active_totals( $scope ) + self::overdue_totals( $repo->requests_due_total( $manager, '1000-01-01', $day( -1 ) ) ),
			'recent'     => $this->app->audit->recent( $manager, 8 ),
			'overdue'    => $overdue,
			'upcoming'   => $repo->requests_due( $manager, $today, $day( 7 ) ),
			'decisions'  => $repo->pending_decisions( $manager ),
			'actions'    => $repo->client_actions( $manager, $this->app->clock->mysql_in( -7 * DAY_IN_SECONDS ) ),
			'stale'      => $repo->stale_cases( $manager, $this->app->clock->mysql_in( -$stale * DAY_IN_SECONDS ) ),
			'quiet'      => $repo->never_signed_in( $manager, $this->app->clock->mysql_in( -3 * DAY_IN_SECONDS ) ),
			'failed'     => $repo->failed_mail( $manager ),
			'bounced'    => $repo->bounced_mail( $manager, $this->app->clock->mysql_in( -30 * DAY_IN_SECONDS ) ),
			'awaiting'   => $this->app->messages->awaiting_reply( $manager ),
			'late'       => ( new MorningSummary( $this->app ) )->late_stages( $scope ),
			'tasks'      => $this->app->tasks->open_for( $user, 0 === $manager, null, 20 ),
			'meetings'   => $this->app->meetings->starting_between( $this->app->clock->mysql(), $this->app->clock->mysql_in( 7 * DAY_IN_SECONDS ), $scope ),
			'drafts'     => $this->app->plan->drafts( $scope ),
		);
	}

	/**
	 * Overdue figures under the keys the dashboard reads.
	 *
	 * @param array{count:int, total:int} $overdue Count and sum of overdue requests.
	 * @return array{overdue:int, overdue_count:int}
	 */
	private static function overdue_totals( array $overdue ): array {
		return array(
			'overdue'       => $overdue['total'],
			'overdue_count' => $overdue['count'],
		);
	}

	/**
	 * Stage board: the active cases under each stage they are in.
	 */
	public function board(): void {
		$user = get_current_user_id();

		if ( ! $this->app->policy->can_use_admin( $user ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$view = $this->board_data( $user );

		include DX_PORTAL_DIR . 'templates/admin/board.php';
	}

	/**
	 * One column per stage with the active cases in progress there, longest
	 * in the stage first. A case in several stages is in each of their
	 * columns; a case with no stage in progress is listed apart.
	 *
	 * @param int $user User: every case for an administrator, own cases for a manager.
	 * @return array{columns: array<int, array{number:string, name:string, cards: array<int, array{case:CaseRecord, days:int|null, also:string, late:string}>}>, waiting: CaseRecord[], total:int, capped:bool}
	 */
	public function board_data( int $user ): array {
		$cases   = $this->app->cases->active_for( $this->app->policy->can_manage_all( $user ) ? null : $user, self::BOARD_LIMIT );
		$today   = $this->app->clock->today();
		$columns = array();
		$waiting = array();

		$cards = array();

		foreach ( $cases as $client_case ) {
			$active = StageProgress::active( $client_case->stages );

			if ( array() === $active ) {
				$waiting[] = $client_case;
				continue;
			}

			foreach ( $active as $number ) {
				$cards[ $number ][] = array(
					'case' => $client_case,
					'days' => StageProgress::days_in( $client_case->stages[ $number ], $today ),
					'also' => implode( ', ', array_map( array( Stages::class, 'number' ), array_diff( $active, array( $number ) ) ) ),
					'late' => StageProgress::is_late( $client_case->stages[ $number ], $today ) ? Labels::date( $client_case->stages[ $number ]['due'] ) : '',
				);
			}
		}

		foreach ( Stages::names() as $number => $name ) {
			$column = $cards[ $number ] ?? array();
			usort( $column, static fn( array $a, array $b ): int => ( $b['days'] ?? -1 ) <=> ( $a['days'] ?? -1 ) );

			$columns[ $number ] = array(
				'number' => Stages::number( $number ),
				'name'   => $name,
				'cards'  => $column,
			);
		}

		return array(
			'columns' => $columns,
			'waiting' => $waiting,
			'total'   => count( $cases ),
			'capped'  => count( $cases ) >= self::BOARD_LIMIT,
		);
	}

	/**
	 * New case form.
	 */
	public function new_case(): void {
		$user = get_current_user_id();

		if ( ! $this->app->policy->can_use_admin( $user ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$view = $this->new_case_data( $user );

		include DX_PORTAL_DIR . 'templates/admin/case-new.php';
	}

	/**
	 * View model of the new case form.
	 *
	 * @param int $user Staff user.
	 * @return array<string, mixed>
	 */
	public function new_case_data( int $user ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill; the lead is checked again on save.
		$lead = isset( $_GET['lead'] ) ? ( new LeadLink( $this->app ) )->prefill( absint( $_GET['lead'] ), $user ) : null;

		return array(
			'flash'    => Flash::take(),
			'managers' => $this->app->policy->can_manage_all( $user ) ? $this->app->case_service->managers() : array(),
			'user'     => $user,
			'lead'     => $lead,
			'prefill'  => $lead ?? array(),
		);
	}

	/**
	 * The payments forecast screen (spec 20.2): administrators only.
	 */
	public function forecast(): void {
		$view = ( new ForecastService( $this->app ) )->view( get_current_user_id() );

		if ( $view instanceof \WP_Error ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		include DX_PORTAL_DIR . 'templates/admin/forecast.php';
	}

	/**
	 * Settings screen.
	 */
	public function settings(): void {
		if ( ! $this->app->policy->can_manage_settings( get_current_user_id() ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$view = $this->settings_data();

		include DX_PORTAL_DIR . 'templates/admin/settings.php';
	}

	/**
	 * View model of the settings screen.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_data(): array {
		return array(
			'flash'       => Flash::take(),
			'settings'    => Settings::all(),
			'key_ready'   => $this->app->store->is_configured(),
			'fingerprint' => $this->app->store->fingerprint(),
			'key_status'  => $this->app->document_service->key_status(),
			'mail'        => self::mail_status(),
			'categories'  => BudgetTemplate::text( Settings::budget_template() ),
			'stages'      => Stages::names(),
		);
	}

	/**
	 * How the site sends and receives email and protects its forms (spec
	 * 17.5), for the settings screen. Secrets are reported as set or not,
	 * never shown.
	 *
	 * @return array<string, mixed>
	 */
	public static function mail_status(): array {
		$with_key = static fn( string $url, string $key ): string => (string) preg_replace( '#^(https?://)#', '$1postmark:' . $key . '@', $url );
		$fields   = array();

		foreach ( array_keys( Integrations::FIELDS ) as $name ) {
			$fields[ $name ] = array(
				'locked'     => Integrations::locked( $name ),
				'set'        => Integrations::is_set( $name ),
				'unreadable' => Integrations::unreadable( $name ),
				'value'      => in_array( $name, array( 'token', 'turnstile_secret' ), true ) ? '' : Integrations::get( $name ),
			);
		}

		$once = self::take_new_webhook_key();

		return array(
			'postmark'       => Postmark::configured(),
			'inbound'        => InboundService::enabled(),
			'webhook_key'    => Webhooks::configured(),
			'webhook_locked' => Integrations::webhook_locked(),
			'fields'         => $fields,
			'key_ready'      => Plugin::instance()->store->is_configured(),
			'events_url'     => $with_key( Webhooks::url( 'events' ), 'KEY' ),
			'inbound_url'    => $with_key( Webhooks::url( 'inbound' ), 'KEY' ),
			'new_key'        => '' === $once ? null : array(
				'events'  => $with_key( Webhooks::url( 'events' ), $once ),
				'inbound' => $with_key( Webhooks::url( 'inbound' ), $once ),
			),
		);
	}

	/**
	 * The webhook key just made by this user, once: it is kept sealed for ten
	 * minutes so the page after the redirect can show it, then forgotten.
	 *
	 * @return string '' when there is none.
	 */
	private static function take_new_webhook_key(): string {
		$slot   = Actions::NEW_KEY_SLOT . get_current_user_id();
		$sealed = get_transient( $slot );

		if ( ! is_string( $sealed ) ) {
			return '';
		}

		delete_transient( $slot );

		return (string) Plugin::instance()->store->unseal( $sealed, $slot );
	}

	/**
	 * One case.
	 *
	 * @param int $case_id Case.
	 */
	private function case_screen( int $case_id ): void {
		$user        = get_current_user_id();
		$client_case = $this->app->cases->find( $case_id );

		if ( null === $client_case || ! $this->app->policy->can_manage_case( $user, $client_case ) ) {
			wp_die( esc_html__( 'התיק לא נמצא, או שאין לך הרשאה אליו.', 'diromax-portal' ), '', array( 'response' => 404 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$view = $this->case_data( $client_case, $user, $tab );

		include DX_PORTAL_DIR . 'templates/admin/case.php';
	}

	/**
	 * View model of a case screen. The caller has checked access.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $user        Staff user.
	 * @param string     $tab         Requested tab.
	 * @return array<string, mixed>
	 */
	public function case_data( CaseRecord $client_case, int $user, string $tab ): array {
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'overview';

		return array_merge(
			array(
				'case'      => $client_case,
				'tab'       => $tab,
				'tabs'      => self::tab_labels(),
				'flash'     => Flash::take(),
				'portal'    => Router::case_url( $client_case ),
				'manager'   => get_userdata( $client_case->manager_id ),
				'statuses'  => Labels::case_statuses(),
				'can_all'   => $this->app->policy->can_manage_all( $user ),
				'can_purge' => $this->app->policy->can_delete_case( $user, $client_case ),
				'steps'     => CaseView::stages( $client_case, $this->app->clock->today() ),
				'counts'    => $this->tab_counts( $client_case ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: opens the add form.
				'adding'    => isset( $_GET['add'] ),
			),
			$this->tab_data( $client_case, $tab )
		);
	}

	/**
	 * View model of the cases list in the management app: the same search,
	 * status filter and paging as the wp-admin list.
	 *
	 * @param int $user Staff user.
	 * @return array<string, mixed>
	 */
	public function cases_data( int $user ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 0, 100 ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$result = $this->app->cases->search( $this->app->policy->can_manage_all( $user ) ? null : $user, $status, $search, $page, self::PER_PAGE );

		return array(
			'flash'    => Flash::take(),
			'items'    => $result['items'],
			'total'    => $result['total'],
			'page'     => $page,
			'pages'    => max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) ),
			'status'   => $status,
			'search'   => $search,
			'statuses' => Labels::case_statuses(),
			'managers' => $this->app->case_service->managers(),
		);
	}

	/**
	 * Data for the active tab only.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $tab  Tab.
	 * @return array<string, mixed>
	 */
	private function tab_data( CaseRecord $client_case, string $tab ): array {
		return match ( $tab ) {
			'overview'  => array(
				'summary' => $this->app->case_service->summary( $client_case, false ),
				'pending' => $this->app->notifier->pending( $client_case ),
				'members' => $this->app->members->for_case( $client_case->id ),
				'recent'  => array_slice( $this->app->audit->for_case( $client_case->id, 8 ), 0, 8 ),
				'setup'   => $this->setup_steps( $client_case ),
			),
			'details'   => array(
				'purge'          => $this->app->policy->can_delete_case( get_current_user_id(), $client_case ) ? $this->app->case_service->deletion_preview( $client_case, get_current_user_id() ) : null,
				'manager_phone'  => '' !== (string) get_user_meta( $client_case->manager_id, Contact::PHONE_META, true ),
				'stages'         => Stages::names(),
				'stage_statuses' => Labels::stage_statuses(),
				'managers'       => $this->app->case_service->managers(),
				'today'          => $this->app->clock->today(),
			),
			'members'   => array(
				'members' => $this->app->members->for_case( $client_case->id ),
				'roles'   => Labels::roles(),
				'modes'   => Labels::notify_modes(),
			),
			'budget'    => array(
				'tree'   => $this->app->budget->tree( $client_case->id ),
				'in_use' => $this->app->ledger->count_by_category( $client_case->id ),
				'lines'  => $this->app->case_service->summary( $client_case, false )->categories,
			),
			'finance'   => array(
				'rows'      => $this->app->ledger->for_case( $client_case->id, false ),
				'budget'    => $this->app->budget->tree( $client_case->id ),
				'payees'    => $this->app->ledger->payees(),
				'documents' => $this->documents_by_id( $client_case ),
				'today'     => $this->app->clock->today(),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: opens one request's "mark as paid" form.
				'settle'    => isset( $_GET['settle'] ) ? absint( $_GET['settle'] ) : 0,
			),
			'plan'      => ( new PlanService( $this->app ) )->for_staff( $client_case ) + array(
				'budget_total' => $this->app->budget->tree( $client_case->id )->total(),
				'stages'       => Stages::names(),
				'states'       => PlanService::state_labels(),
				'today'        => $this->app->clock->today(),
				'closed'       => CaseStatus::Closed === $client_case->status,
			),
			'decisions' => $this->decisions_data( $client_case ),
			'updates'   => array(
				'updates' => $this->app->updates->for_case( $client_case->id, false ),
				'stages'  => Stages::names(),
			),
			'documents' => array(
				'documents' => $this->app->documents->for_case( $client_case->id, false ),
				'kinds'     => Labels::document_kinds(),
				'requests'  => $this->app->doc_requests->for_case( $client_case->id, false ),
				'signed'    => $this->signatures_by_document( $client_case ),
				'signing'   => $this->app->sign_requests->for_case( $client_case->id ),
				'now'       => $this->app->clock->mysql(),
				'key_ready' => $this->app->store->is_configured(),
				'acks'      => $this->app->acknowledgements->for_case( $client_case->id ),
			),
			'tasks'     => array(
				'tasks'     => $this->app->tasks->for_case( $client_case->id ),
				'assignees' => ( new TaskService( $this->app ) )->assignees( $client_case ),
				'today'     => $this->app->clock->today(),
			),
			'meetings'  => array(
				'meetings' => ( new MeetingService( $this->app ) )->for_staff( $client_case ),
				'people'   => ( new MeetingService( $this->app ) )->invitable( $client_case ),
				'kinds'    => Labels::meeting_kinds(),
				'lengths'  => MeetingInput::LENGTHS,
				'today'    => $this->app->clock->today(),
				'closed'   => CaseStatus::Closed === $client_case->status,
			),
			'photos'    => array(
				'journal'   => ( new PhotoService( $this->app ) )->journal( $client_case, false ),
				'stage'     => StageProgress::lead( $client_case->stages ),
				'today'     => $this->app->clock->today(),
				'key_ready' => $this->app->store->is_configured(),
			),
			'messages'  => array(
				'messages' => ( new MessageService( $this->app ) )->thread( $client_case, get_current_user_id(), true ),
				'enabled'  => InboundService::enabled(),
			),
			'log'       => array(
				'audit' => $this->app->audit->for_case( $client_case->id, 300 ),
				'mail'  => $this->app->mail_log->for_case( $client_case->id, 200 ),
			),
			default     => array(),
		};
	}

	/**
	 * The steps that make a new case useful to the client, with where each
	 * one is done. Shown on the overview until all are done.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array{label:string, done:bool, url:string, action:string}>
	 */
	public function setup_steps( CaseRecord $client_case ): array {
		$members  = $this->app->members->for_case( $client_case->id );
		$signed   = array_filter( $members, static fn( $member ): bool => null !== $member->last_seen_at );
		$add      = static fn( string $tab ): string => add_query_arg( 'add', '1', Admin::case_url( $client_case->id, $tab ) );
		$has_kind = static fn( string $kind, array $documents ): bool => array() !== array_filter( $documents, static fn( $document ): bool => $kind === $document->kind );

		return array(
			array(
				'label'  => __( 'קובעים תקציב לפי סעיפים', 'diromax-portal' ),
				'done'   => $this->app->budget->tree( $client_case->id )->total() > 0,
				'url'    => Admin::case_url( $client_case->id, 'budget' ),
				'action' => __( 'לתקציב', 'diromax-portal' ),
			),
			array(
				'label'  => __( 'מוסיפים את הלקוח לתיק', 'diromax-portal' ),
				'done'   => array() !== $members,
				'url'    => Admin::case_url( $client_case->id, 'members' ),
				'action' => __( 'להוספת לקוח', 'diromax-portal' ),
			),
			array(
				'label'  => __( 'הלקוח נכנס לאזור האישי', 'diromax-portal' ),
				'done'   => array() !== $signed,
				'url'    => Admin::case_url( $client_case->id, 'members' ),
				'action' => __( 'לשליחת הזמנה', 'diromax-portal' ),
			),
			array(
				'label'  => __( 'מעלים את ההסכם החתום', 'diromax-portal' ),
				'done'   => $has_kind( 'agreement', $this->app->documents->for_case( $client_case->id, false ) ),
				'url'    => $add( 'documents' ),
				'action' => __( 'להעלאת ההסכם', 'diromax-portal' ),
			),
			array(
				'label'  => __( 'מפרסמים עדכון ראשון ללקוח', 'diromax-portal' ),
				'done'   => array() !== $this->app->updates->for_case( $client_case->id, false ),
				'url'    => $add( 'updates' ),
				'action' => __( 'לעדכון חדש', 'diromax-portal' ),
			),
		);
	}

	/**
	 * Small numbers next to tab names: open requests and pending decisions.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<string, int>
	 */
	private function tab_counts( CaseRecord $client_case ): array {
		$pending = 0;

		foreach ( $this->app->decisions->for_case( $client_case->id ) as $decision ) {
			$pending += 'pending' === $decision->status->value ? 1 : 0;
		}

		return array(
			'finance'   => (int) ( $client_case->summary['open_request_count'] ?? 0 ),
			'decisions' => $pending,
			'members'   => count( $this->app->members->for_case( $client_case->id ) ),
			'tasks'     => $this->app->tasks->open_count( $client_case->id ),
			'messages'  => $this->app->messages->count_for_case( $client_case->id ),
			'photos'    => $this->app->photos->count_for_case( $client_case->id, false ),
			'meetings'  => $this->app->meetings->count_upcoming( $client_case->id, $this->app->clock->mysql() ),
			'plan'      => $this->app->plan->count_drafts( $client_case->id ),
		);
	}

	/**
	 * Valid signatures of each document of the case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array<int, array<string, mixed>>> Document ID => signatures.
	 */
	private function signatures_by_document( CaseRecord $client_case ): array {
		$service = new SignatureService( $this->app );
		$list    = array();

		foreach ( $this->app->documents->for_case( $client_case->id, false ) as $document ) {
			$signed = $service->of_document( $document );

			if ( array() !== $signed ) {
				$list[ $document->id ] = $signed;
			}
		}

		return $list;
	}

	/**
	 * Data of the decisions tab.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<string, mixed>
	 */
	private function decisions_data( CaseRecord $client_case ): array {
		$decisions = $this->app->decisions->for_case( $client_case->id );
		$names     = array();

		foreach ( array_unique( array_filter( array_map( static fn( $d ): ?int => $d->answered_by, $decisions ) ) ) as $user_id ) {
			$user              = get_userdata( (int) $user_id );
			$names[ $user_id ] = $user ? (string) $user->display_name : '';
		}

		return array(
			'decisions' => $decisions,
			'names'     => $names,
			'documents' => $this->app->documents->for_case( $client_case->id, true ),
		);
	}

	/**
	 * Documents of the case keyed by ID.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, \Diromax\Portal\Data\DocumentRecord>
	 */
	private function documents_by_id( CaseRecord $client_case ): array {
		$list = array();

		foreach ( $this->app->documents->for_case( $client_case->id, false ) as $document ) {
			$list[ $document->id ] = $document;
		}

		return $list;
	}

	/**
	 * Tab labels.
	 *
	 * @return array<string, string>
	 */
	private static function tab_labels(): array {
		return array(
			'overview'  => __( 'סקירה', 'diromax-portal' ),
			'tasks'     => __( 'משימות', 'diromax-portal' ),
			'meetings'  => __( 'פגישות', 'diromax-portal' ),
			'details'   => __( 'פרטי התיק', 'diromax-portal' ),
			'members'   => __( 'לקוחות', 'diromax-portal' ),
			'budget'    => __( 'תקציב', 'diromax-portal' ),
			'finance'   => __( 'כספים', 'diromax-portal' ),
			'plan'      => __( 'תוכנית תשלומים', 'diromax-portal' ),
			'decisions' => __( 'החלטות', 'diromax-portal' ),
			'updates'   => __( 'עדכונים', 'diromax-portal' ),
			'photos'    => __( 'צילומים', 'diromax-portal' ),
			'messages'  => __( 'הודעות', 'diromax-portal' ),
			'documents' => __( 'הסכם ומסמכים', 'diromax-portal' ),
			'log'       => __( 'יומן', 'diromax-portal' ),
		);
	}

	/**
	 * Money for templates.
	 *
	 * @param int $agorot Agorot.
	 * @return string
	 */
	public static function money( int $agorot ): string {
		return Money::format( $agorot );
	}
}
