<?php
/**
 * Admin form handlers.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\BudgetTemplate;
use Diromax\Portal\Domain\PaymentPlan;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Service\CsvExport;
use Diromax\Portal\Service\DecisionService;
use Diromax\Portal\Service\DemoCase;
use Diromax\Portal\Service\DocRequestService;
use Diromax\Portal\Service\Errors;
use Diromax\Portal\Service\ForecastService;
use Diromax\Portal\Service\MeetingService;
use Diromax\Portal\Service\MessageService;
use Diromax\Portal\Service\PhotoService;
use Diromax\Portal\Service\PlanService;
use Diromax\Portal\Service\Postmark;
use Diromax\Portal\Service\ReminderService;
use Diromax\Portal\Service\ScheduleService;
use Diromax\Portal\Service\SignatureService;
use Diromax\Portal\Service\Stages;
use Diromax\Portal\Service\TaskService;
use Diromax\Portal\Service\UpdateService;
use Diromax\Portal\Service\VisitService;
use Diromax\Portal\Settings;
use Diromax\Portal\Support\Integrations;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One admin-post handler per form (post/redirect/get).
 *
 * Each handler checks the nonce bound to its action and case, then calls a
 * service; every service checks AccessPolicy itself before touching anything.
 * Input is unslashed and sanitized here and validated by the service.
 */
final class Actions {

	/** Action => [single-line fields, multi-line fields]. */
	private const ACTIONS = array(
		'create_case'         => array( array( 'title', 'property_type', 'track', 'scope', 'fee_terms', 'signed_on', 'manager_id', 'lead_id' ), array() ),
		'update_case'         => array( array( 'title', 'property_type', 'track', 'scope', 'fee_terms', 'signed_on', 'manager_id' ), array() ),
		'case_status'         => array( array( 'status' ), array() ),
		'add_member'          => array( array( 'email', 'name', 'role', 'notify', 'invite' ), array() ),
		'remove_member'       => array( array( 'user_id' ), array() ),
		'member_mode'         => array( array( 'user_id', 'mode' ), array() ),
		'invite'              => array( array( 'user_id' ), array() ),
		'save_budget'         => array( array(), array() ),
		'add_entry'           => array( array( 'type', 'status', 'entry_date', 'due_date', 'amount', 'vat_mode', 'category', 'payee', 'description', 'funding', 'pay_to', 'method', 'reference', 'visible', 'notify' ), array( 'payment_details', 'internal_note' ) ),
		'update_entry'        => array( array( 'entry_id', 'status', 'entry_date', 'due_date', 'amount', 'vat_mode', 'category', 'payee', 'description', 'funding', 'pay_to', 'method', 'reference', 'visible', 'notify' ), array( 'payment_details', 'internal_note' ) ),
		'void_entry'          => array( array( 'entry_id', 'reason', 'notify' ), array() ),
		'settle_request'      => array( array( 'entry_id', 'entry_date', 'category', 'method', 'reference', 'notify' ), array() ),
		'send_reminder'       => array( array( 'entry_id' ), array() ),
		'add_update'          => array( array( 'title', 'stage', 'stage_mode', 'visible', 'notify' ), array( 'body' ) ),
		'upload_document'     => array( array( 'kind', 'title', 'visible', 'notify', 'sign_required' ), array() ),
		'request_document'    => array( array( 'title' ), array( 'note' ) ),
		'cancel_doc_request'  => array( array( 'request_id' ), array() ),
		'request_signature'   => array( array( 'document_id', 'signer_name', 'signer_email' ), array() ),
		'cancel_signature'    => array( array( 'request_id' ), array() ),
		'add_decision'        => array( array( 'title', 'amount', 'document_id', 'due_date', 'notify' ), array( 'body' ) ),
		'withdraw_decision'   => array( array( 'decision_id', 'reason' ), array() ),
		'document_visibility' => array( array( 'document_id', 'visible' ), array() ),
		'delete_document'     => array( array( 'document_id' ), array() ),
		'send_now'            => array( array(), array() ),
		'cancel_pending'      => array( array(), array() ),
		'delete_case'         => array( array( 'confirm' ), array() ),
		'add_task'            => array( array( 'title', 'assignee_id', 'due_date' ), array() ),
		'task_done'           => array( array( 'task_id', 'done' ), array() ),
		'delete_task'         => array( array( 'task_id' ), array() ),
		'upload_photos'       => array( array( 'stage', 'taken_on', 'caption', 'visible', 'notify' ), array() ),
		'photo_visibility'    => array( array( 'photo_id', 'visible' ), array() ),
		'delete_photo'        => array( array( 'photo_id' ), array() ),
		'add_meeting'         => array( array( 'title', 'kind', 'date', 'time', 'minutes', 'location', 'notify' ), array( 'note' ) ),
		'update_meeting'      => array( array( 'meeting_id', 'title', 'kind', 'date', 'time', 'minutes', 'location', 'notify' ), array( 'note' ) ),
		'cancel_meeting'      => array( array( 'meeting_id', 'notify' ), array() ),
		'reply_message'       => array( array(), array( 'body' ) ),
		'plan_add'            => array( array( 'label', 'trigger_on', 'stage', 'trigger_date', 'kind', 'value', 'pay_to', 'payee', 'days' ), array() ),
		'plan_update'         => array( array( 'payment_id', 'label', 'trigger_on', 'stage', 'trigger_date', 'kind', 'value', 'pay_to', 'payee', 'days' ), array() ),
		'plan_delete'         => array( array( 'payment_id' ), array() ),
		'plan_base'           => array( array( 'base' ), array() ),
		'plan_draft'          => array( array( 'payment_id' ), array() ),
		'plan_approve'        => array( array( 'payment_id', 'amount', 'due_date', 'description', 'notify' ), array() ),
		'plan_withdraw'       => array( array( 'payment_id', 'back' ), array() ),
		'save_schedule'       => array( array( 'note', 'visible', 'notify' ), array() ),
		'compute_schedule'    => array( array( 'start_on' ), array() ),
		'add_milestone'       => array( array( 'label', 'stage', 'date' ), array() ),
		'update_milestone'    => array( array( 'milestone_id', 'label', 'stage', 'date', 'note' ), array() ),
		'delete_milestone'    => array( array( 'milestone_id' ), array() ),
		'milestone_done'      => array( array( 'milestone_id', 'done' ), array() ),
		'add_visit'           => array( array( 'visit_on', 'attendees', 'visible', 'notify' ), array( 'summary' ) ),
		'update_visit'        => array( array( 'visit_id', 'visit_on', 'attendees', 'visible', 'notify' ), array( 'summary' ) ),
		'delete_visit'        => array( array( 'visit_id' ), array() ),
		'add_finding'         => array( array( 'visit_id', 'title', 'location', 'severity', 'supplier', 'due_on', 'visible' ), array() ),
		'update_finding'      => array( array( 'finding_id', 'title', 'location', 'severity', 'supplier', 'due_on', 'visible' ), array() ),
		'fix_finding'         => array( array( 'finding_id', 'fix_note' ), array() ),
		'reopen_finding'      => array( array( 'finding_id' ), array() ),
		'delete_finding'      => array( array( 'finding_id' ), array() ),
	);

	/** Transient prefix holding a new webhook key until the next page shows it. */
	public const NEW_KEY_SLOT = 'dx_portal_new_webhook_key_';

	/**
	 * The form was posted from the dashboard: go back there after it.
	 *
	 * @var bool
	 */
	private bool $to_dashboard = false;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Register the admin-post hooks.
	 */
	public function register(): void {
		foreach ( array_keys( self::ACTIONS ) as $action ) {
			add_action( 'admin_post_dx_portal_' . $action, fn() => $this->handle( $action ) );
		}

		add_action( 'admin_post_dx_portal_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_dx_portal_test_mail', array( $this, 'test_mail' ) );
		add_action( 'admin_post_dx_portal_save_integrations', array( $this, 'save_integrations' ) );
		add_action( 'admin_post_dx_portal_new_webhook_key', array( $this, 'new_webhook_key' ) );
		add_action( 'admin_post_dx_portal_demo_case', array( $this, 'demo_case' ) );
		add_action( 'admin_post_dx_portal_download', array( $this, 'download' ) );
		add_action( 'admin_post_dx_portal_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_dx_portal_export', array( $this, 'export' ) );
		add_action( 'admin_post_dx_portal_forecast_csv', array( $this, 'forecast_csv' ) );
		add_action( 'admin_post_dx_portal_certificate', array( $this, 'certificate' ) );
	}

	/**
	 * Common prologue: nonce, case, input; then the action; then the redirect.
	 *
	 * @param string $action Action key.
	 */
	private function handle( string $action ): void {
		[ $lines, $blocks ] = self::ACTIONS[ $action ];

		$case_id = isset( $_POST['case_id'] ) ? absint( $_POST['case_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the next line, bound to this case.
		check_admin_referer( 'dx_portal_' . $action . '_' . $case_id );
		self::return_to_sender();
		$this->to_dashboard = isset( $_POST['dx_back'] ) && 'dashboard' === sanitize_key( wp_unslash( $_POST['dx_back'] ) );

		$actor = get_current_user_id();
		$input = self::input( $lines, $blocks );

		if ( 'create_case' === $action ) {
			$this->create_case( $input, $actor );
			return;
		}

		$client_case = $this->app->cases->find( $case_id );

		if ( null === $client_case || ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			wp_die( esc_html__( 'אין הרשאה לתיק הזה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		match ( $action ) {
			'update_case'         => $this->update_case( $client_case, $input, $actor ),
			'case_status'         => $this->case_status( $client_case, $input, $actor ),
			'add_member'          => $this->add_member( $client_case, $input, $actor ),
			'remove_member'       => $this->remove_member( $client_case, $input, $actor ),
			'member_mode'         => $this->member_mode( $client_case, $input, $actor ),
			'invite'              => $this->invite( $client_case, $input, $actor ),
			'save_budget'         => $this->save_budget( $client_case, $actor ),
			'add_entry'           => $this->add_entry( $client_case, $input, $actor ),
			'update_entry'        => $this->update_entry( $client_case, $input, $actor ),
			'void_entry'          => $this->void_entry( $client_case, $input, $actor ),
			'settle_request'      => $this->settle_request( $client_case, $input, $actor ),
			'send_reminder'       => $this->finish( $client_case, 'finance', ( new ReminderService( $this->app ) )->send_now( $client_case, absint( $input['entry_id'] ), $actor ), __( 'התזכורת נשלחה ללקוח.', 'diromax-portal' ) ),
			'add_update'          => $this->add_update( $client_case, $input, $actor ),
			'upload_document'     => $this->upload_document( $client_case, $input, $actor ),
			'add_decision'        => $this->finish( $client_case, 'decisions', ( new DecisionService( $this->app ) )->create( $client_case, $input, $actor ), __( 'הבקשה נשלחה ללקוח.', 'diromax-portal' ), 'decision', $input ),
			'withdraw_decision'   => $this->finish( $client_case, 'decisions', ( new DecisionService( $this->app ) )->withdraw( $client_case, absint( $input['decision_id'] ), $input['reason'], $actor ), __( 'הבקשה בוטלה.', 'diromax-portal' ), 'withdraw-' . absint( $input['decision_id'] ), $input ),
			'document_visibility' => $this->document_visibility( $client_case, $input, $actor ),
			'delete_document'     => $this->delete_document( $client_case, $input, $actor ),
			'send_now'            => $this->send_now( $client_case, $actor ),
			'cancel_pending'      => $this->cancel_pending( $client_case, $actor ),
			'delete_case'         => $this->delete_case( $client_case, $input, $actor ),
			'request_document'    => $this->finish( $client_case, 'documents', ( new DocRequestService( $this->app ) )->create( $client_case, $input, $actor ), __( 'הבקשה נשלחה ללקוח.', 'diromax-portal' ), 'docrequest', $input ),
			'cancel_doc_request'  => $this->finish( $client_case, 'documents', ( new DocRequestService( $this->app ) )->cancel( $client_case, absint( $input['request_id'] ), $actor ), __( 'הבקשה בוטלה.', 'diromax-portal' ) ),
			'request_signature'   => $this->finish( $client_case, 'documents', ( new SignatureService( $this->app ) )->request( $client_case, absint( $input['document_id'] ), $input, $actor ), __( 'בקשת החתימה נשלחה במייל.', 'diromax-portal' ), 'signrequest', $input ),
			'cancel_signature'    => $this->finish( $client_case, 'documents', ( new SignatureService( $this->app ) )->cancel( $client_case, absint( $input['request_id'] ), $actor ), __( 'בקשת החתימה בוטלה, והקישור כבר לא עובד.', 'diromax-portal' ) ),
			'add_task'            => $this->finish( $client_case, 'tasks', ( new TaskService( $this->app ) )->add( $client_case, $input, $actor ), __( 'המשימה נוספה.', 'diromax-portal' ), 'task', $input ),
			'task_done'           => $this->finish( $client_case, 'tasks', ( new TaskService( $this->app ) )->set_done( $client_case, absint( $input['task_id'] ), '1' === $input['done'], $actor ), '1' === $input['done'] ? __( 'המשימה סומנה כבוצעה.', 'diromax-portal' ) : __( 'המשימה חזרה לפתוחות.', 'diromax-portal' ) ),
			'delete_task'         => $this->finish( $client_case, 'tasks', ( new TaskService( $this->app ) )->delete( $client_case, absint( $input['task_id'] ), $actor ), __( 'המשימה נמחקה.', 'diromax-portal' ) ),
			'reply_message'       => $this->finish( $client_case, 'messages', ( new MessageService( $this->app ) )->reply( $client_case, $actor, $input['body'] ), __( 'התשובה נשלחה ללקוחות ונשמרה בשיחה.', 'diromax-portal' ), 'message', $input ),
			'upload_photos'       => $this->upload_photos( $client_case, $input, $actor ),
			'photo_visibility'    => $this->finish( $client_case, 'photos', ( new PhotoService( $this->app ) )->set_visible( $client_case, absint( $input['photo_id'] ), '1' === $input['visible'], $actor ), '1' === $input['visible'] ? __( 'הצילום מוצג ללקוח.', 'diromax-portal' ) : __( 'הצילום הוסתר מהלקוח.', 'diromax-portal' ) ),
			'delete_photo'        => $this->finish( $client_case, 'photos', ( new PhotoService( $this->app ) )->delete( $client_case, absint( $input['photo_id'] ), $actor ), __( 'הצילום נמחק.', 'diromax-portal' ) ),
			'add_meeting'         => $this->finish( $client_case, 'meetings', ( new MeetingService( $this->app ) )->create( $client_case, $input, self::participants(), $actor ), '1' === $input['notify'] ? __( 'הפגישה נקבעה, וההזמנות נשלחו.', 'diromax-portal' ) : __( 'הפגישה נקבעה.', 'diromax-portal' ), 'meeting', self::meeting_old( $input ) ),
			'update_meeting'      => $this->finish( $client_case, 'meetings', ( new MeetingService( $this->app ) )->update( $client_case, absint( $input['meeting_id'] ), $input, self::participants(), $actor ), __( 'הפגישה עודכנה.', 'diromax-portal' ), 'meeting' . absint( $input['meeting_id'] ), self::meeting_old( $input ) ),
			'cancel_meeting'      => $this->finish( $client_case, 'meetings', ( new MeetingService( $this->app ) )->cancel( $client_case, absint( $input['meeting_id'] ), '1' === $input['notify'], $actor ), __( 'הפגישה בוטלה.', 'diromax-portal' ) ),
			'plan_add', 'plan_update', 'plan_delete', 'plan_base', 'plan_draft', 'plan_approve', 'plan_withdraw' => $this->plan( $action, $client_case, $input, $actor ),
			'save_schedule'       => $this->save_schedule( $client_case, $input, $actor ),
			'compute_schedule'    => $this->finish( $client_case, 'schedule', ( new ScheduleService( $this->app ) )->compute( $client_case, $input['start_on'], $actor ), __( 'התאריכים חושבו לפי משכי השלבים. בודקים, משנים לפי הצורך, ושומרים.', 'diromax-portal' ), 'compute', $input ),
			'add_milestone'       => $this->finish( $client_case, 'schedule', ( new ScheduleService( $this->app ) )->add_milestone( $client_case, $input, $actor ), __( 'אבן הדרך נוספה.', 'diromax-portal' ), 'milestone', $input ),
			'update_milestone'    => $this->finish( $client_case, 'schedule', ( new ScheduleService( $this->app ) )->update_milestone( $client_case, absint( $input['milestone_id'] ), $input, $actor ), __( 'אבן הדרך עודכנה.', 'diromax-portal' ), 'milestone' . absint( $input['milestone_id'] ), $input ),
			'delete_milestone'    => $this->finish( $client_case, 'schedule', ( new ScheduleService( $this->app ) )->delete_milestone( $client_case, absint( $input['milestone_id'] ), $actor ), __( 'אבן הדרך נמחקה.', 'diromax-portal' ) ),
			'add_visit'           => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->add_visit( $client_case, $input, $actor ), '1' === $input['notify'] && '1' === $input['visible'] ? __( 'הביקור נשמר, והלקוח יקבל עליו עדכון במייל.', 'diromax-portal' ) : __( 'הביקור נשמר. עכשיו אפשר להוסיף לו ממצאים.', 'diromax-portal' ), 'visit', $input ),
			'update_visit'        => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->update_visit( $client_case, absint( $input['visit_id'] ), $input, $actor ), __( 'הביקור עודכן.', 'diromax-portal' ), 'visit' . absint( $input['visit_id'] ), $input ),
			'delete_visit'        => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->delete_visit( $client_case, absint( $input['visit_id'] ), $actor ), __( 'הביקור והממצאים שלו נמחקו.', 'diromax-portal' ) ),
			'add_finding'         => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->add_finding( $client_case, absint( $input['visit_id'] ), $input, self::file( 'photo' ), $actor ), __( 'הממצא נוסף.', 'diromax-portal' ), 'finding' . absint( $input['visit_id'] ), $input ),
			'update_finding'      => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->update_finding( $client_case, absint( $input['finding_id'] ), $input, $actor ), __( 'הממצא עודכן.', 'diromax-portal' ), 'edit' . absint( $input['finding_id'] ), $input ),
			'fix_finding'         => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->fix( $client_case, absint( $input['finding_id'] ), $input['fix_note'], self::file( 'after_photo' ), $actor ), __( 'הממצא סומן כתוקן.', 'diromax-portal' ), 'fix' . absint( $input['finding_id'] ), $input ),
			'reopen_finding'      => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->reopen( $client_case, absint( $input['finding_id'] ), $actor ), __( 'הממצא נפתח מחדש.', 'diromax-portal' ) ),
			'delete_finding'      => $this->finish( $client_case, 'visits', ( new VisitService( $this->app ) )->delete_finding( $client_case, absint( $input['finding_id'] ), $actor ), __( 'הממצא נמחק.', 'diromax-portal' ) ),
			'milestone_done'      => $this->finish( $client_case, 'schedule', ( new ScheduleService( $this->app ) )->set_done( $client_case, absint( $input['milestone_id'] ), '1' === $input['done'], $actor ), '1' === $input['done'] ? __( 'אבן הדרך סומנה כהושגה.', 'diromax-portal' ) : __( 'אבן הדרך חזרה לפתוחות.', 'diromax-portal' ) ),
			default               => wp_die( esc_html__( 'פעולה לא מוכרת.', 'diromax-portal' ), '', array( 'response' => 400 ) ),
		};
	}

	/**
	 * Save the timeline: each stage's planned start and end (start[N],
	 * end[N]), the note on moved dates, publication and email (spec 22).
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       note, visible, notify.
	 * @param int                   $actor       Actor.
	 */
	private function save_schedule( CaseRecord $client_case, array $input, int $actor ): never {
		$starts  = self::stage_dates( 'start' );
		$ends    = self::stage_dates( 'end' );
		$visible = '1' === $input['visible'];
		$notify  = '1' === $input['notify'];
		$result  = ( new ScheduleService( $this->app ) )->save(
			$client_case,
			$starts,
			$ends,
			array(
				'note'    => $input['note'],
				'visible' => $visible,
				'notify'  => $notify,
			),
			$actor
		);
		$old     = $input;

		foreach ( array(
			'start' => $starts,
			'end'   => $ends,
		) as $field => $dates ) {
			foreach ( $dates as $stage => $date ) {
				$old[ $field . '-' . $stage ] = $date;
			}
		}

		$message = match ( true ) {
			$notify && $visible => __( 'לוח הזמנים נשמר, והלקוח יקבל עליו עדכון במייל.', 'diromax-portal' ),
			$visible            => __( 'לוח הזמנים נשמר, והוא מוצג ללקוח.', 'diromax-portal' ),
			default             => __( 'לוח הזמנים נשמר. הוא עדיין לא מוצג ללקוח.', 'diromax-portal' ),
		};

		$this->finish( $client_case, 'schedule', $result, $message, 'schedule', $old );
	}

	/**
	 * A date per stage from a field like start[N], each checked by the service.
	 *
	 * @param string $field POST field.
	 * @return array<int, string>
	 */
	private static function stage_dates( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in handle(); each value is sanitized below.
		$raw   = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();
		$dates = array();

		foreach ( (array) $raw as $stage => $value ) {
			$stage = absint( $stage );

			if ( $stage >= 1 && $stage <= Stages::COUNT ) {
				$dates[ $stage ] = sanitize_text_field( is_string( $value ) ? $value : '' );
			}
		}

		return $dates;
	}

	/**
	 * The payment plan's forms (spec 21).
	 *
	 * @param string                $action      Action key.
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Fields.
	 * @param int                   $actor       Actor.
	 */
	private function plan( string $action, CaseRecord $client_case, array $input, int $actor ): never {
		$plan    = new PlanService( $this->app );
		$id      = absint( $input['payment_id'] ?? 0 );
		$drafts  = $this->app->plan->count_drafts( $client_case->id );
		$back    = '1' === ( $input['back'] ?? '' );
		$success = array(
			'plan_add'      => __( 'התשלום נוסף לתוכנית.', 'diromax-portal' ),
			'plan_update'   => __( 'התשלום עודכן.', 'diromax-portal' ),
			'plan_delete'   => __( 'התשלום נמחק מהתוכנית.', 'diromax-portal' ),
			'plan_base'     => __( 'הבסיס נשמר, והאחוזים חושבו מחדש.', 'diromax-portal' ),
			'plan_draft'    => __( 'נוצרה טיוטה של דרישת תשלום. בודקים אותה ומאשרים.', 'diromax-portal' ),
			'plan_approve'  => '1' === ( $input['notify'] ?? '' ) ? __( 'דרישת התשלום נשלחה ללקוח, ונרשמה בכספים.', 'diromax-portal' ) : __( 'דרישת התשלום נרשמה בכספים, בלי מייל ללקוח.', 'diromax-portal' ),
			'plan_withdraw' => $back ? __( 'התשלום חזר לתוכנית.', 'diromax-portal' ) : __( 'התשלום בוטל.', 'diromax-portal' ),
		)[ $action ];
		$result  = match ( $action ) {
			'plan_add'      => $plan->add( $client_case, $input, $actor ),
			'plan_update'   => $plan->update( $client_case, $id, $input, $actor ),
			'plan_delete'   => $plan->delete( $client_case, $id, $actor ),
			'plan_base'     => $plan->set_base( $client_case, $input['base'], $actor ),
			'plan_draft'    => $plan->draft_now( $client_case, $id, $actor ),
			'plan_approve'  => $plan->approve( $client_case, $id, $input, $actor ),
			default         => $plan->withdraw( $client_case, $id, $back, $actor ),
		};
		$form = match ( $action ) {
			'plan_add'     => 'plan',
			'plan_update'  => 'plan' . $id,
			'plan_base'    => 'base',
			'plan_approve' => 'approve' . $id,
			default        => '',
		};

		if ( in_array( $action, array( 'plan_add', 'plan_update' ), true ) ) {
			$success .= $this->drafts_note( $client_case, $drafts );
		}

		$this->finish( $client_case, 'plan', $result, $success, $form, $input );
	}

	/**
	 * A sentence on the drafts a change made, if any: a stage marked done, or a
	 * payment whose time had come.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $before      Drafts before the change.
	 * @return string '' or a sentence with a leading space.
	 */
	private function drafts_note( CaseRecord $client_case, int $before ): string {
		$made = $this->app->plan->count_drafts( $client_case->id ) - $before;

		/* translators: %d: number of drafts. */
		return $made > 0 ? ' ' . sprintf( _n( 'נוצרה %d טיוטה של דרישת תשלום, והיא מחכה לאישור בלשונית "תוכנית תשלומים".', 'נוצרו %d טיוטות של דרישות תשלום, והן מחכות לאישור בלשונית "תוכנית תשלומים".', $made, 'diromax-portal' ), $made ) : '';
	}

	/**
	 * The signature certificate of a document or decision: every signature
	 * with its evidence, ID number and drawing included, for staff only.
	 */
	public function certificate(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified on the next lines, bound to the case.
		$case_id = isset( $_GET['case_id'] ) ? absint( $_GET['case_id'] ) : 0;
		$type    = isset( $_GET['subject'] ) && 'decision' === sanitize_key( wp_unslash( $_GET['subject'] ) ) ? 'decision' : 'document';
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		check_admin_referer( 'dx_portal_certificate_' . $case_id );

		$actor       = get_current_user_id();
		$client_case = $this->app->cases->find( $case_id );

		if ( null === $client_case || ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			wp_die( esc_html__( 'אין הרשאה לתיק הזה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$service  = new SignatureService( $this->app );
		$document = 'document' === $type ? $this->app->documents->find( $id ) : null;
		$decision = 'decision' === $type ? $this->app->decisions->find( $id ) : null;
		$title    = null !== $document ? $document->title : (string) $decision?->title;
		$current  = null !== $document ? $document->sha256 : (string) $decision?->fingerprint;

		if ( ( null === $document && null === $decision ) || ( null !== $document ? $document->case_id : (int) $decision?->case_id ) !== $client_case->id ) {
			wp_die( esc_html__( 'לא נמצא.', 'diromax-portal' ), '', array( 'response' => 404 ) );
		}

		$rows = array_map(
			fn( array $signature ): array => array(
				'row'       => $signature,
				'valid'     => hash_equals( $current, (string) $signature['sha256'] ),
				'id_number' => (string) $service->id_number( $signature ),
				'image'     => (string) $service->image( $signature ),
			),
			$this->app->signatures->for_subject( $client_case->id, $type, $id )
		);

		$back = Admin::case_url( $client_case->id, 'decision' === $type ? 'decisions' : 'documents' );
		$this->app->audit->record( 'signature.viewed', $client_case->id, $type, $id, array(), $this->app->clock->mysql() );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		include DX_PORTAL_DIR . 'templates/admin/certificate.php';
		exit;
	}

	/**
	 * Create the sample case (dashboard button on a fresh install).
	 */
	public function demo_case(): void {
		check_admin_referer( 'dx_portal_demo_case' );
		self::return_to_sender();

		$result = ( new DemoCase( $this->app ) )->create( get_current_user_id() );

		if ( $result instanceof WP_Error ) {
			Flash::set( 'error', $result->get_error_message() );
			self::redirect( Admin::page_url( Admin::PAGE_DASHBOARD ) );
		}

		Flash::set( 'success', __( 'נוצר תיק לדוגמה עם תקציב, תנועות, דרישות תשלום, עדכונים והחלטה. אין בו לקוחות, ולכן לא נשלח שום מייל. כדי למחוק אותו: סוגרים את התיק בלשונית "פרטי התיק", ומנהל מערכת מוחק אותו שם.', 'diromax-portal' ) );
		self::redirect( Admin::case_url( $result->id ) );
	}

	/**
	 * Open a case.
	 *
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function create_case( array $input, int $actor ): void {
		$result = $this->app->case_service->create( $input, $actor );

		if ( $result instanceof WP_Error ) {
			Flash::set( 'error', $result->get_error_message(), Errors::fields( $result ), $input, 'case' );
			self::redirect( Admin::page_url( Admin::PAGE_NEW ) );
		}

		Flash::set( 'success', __( 'התיק נפתח. עכשיו אפשר להוסיף את הלקוחות ואת התקציב.', 'diromax-portal' ) );
		self::redirect( Admin::case_url( $result->id, 'members' ) );
	}

	/**
	 * Save details and stages.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function update_case( CaseRecord $client_case, array $input, int $actor ): void {
		$input['stage_status'] = self::stage_pairs( 'stage_status', 'sanitize_key' );
		$input['stage_due']    = self::stage_pairs( 'stage_due', 'sanitize_text_field' );
		$drafts                = $this->app->plan->count_drafts( $client_case->id );
		$result                = $this->app->case_service->update_details( $client_case, $input, $actor );

		$this->finish( $client_case, 'details', $result, __( 'פרטי התיק נשמרו.', 'diromax-portal' ) . $this->drafts_note( $client_case, $drafts ), 'details', $input );
	}

	/**
	 * A per-stage field (stage_status[N] or stage_due[N]) as "N:value,…" for
	 * the service, which checks every stage number and value.
	 *
	 * @param string   $field    POST field.
	 * @param callable $sanitize Sanitizer for each value.
	 * @return string
	 */
	private static function stage_pairs( string $field, callable $sanitize ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in handle(); each value is sanitized below.
		$raw   = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();
		$pairs = array();

		foreach ( (array) $raw as $number => $value ) {
			$pairs[] = absint( $number ) . ':' . str_replace( array( ',', ':' ), '', (string) $sanitize( is_string( $value ) ? $value : '' ) );
		}

		return implode( ',', $pairs );
	}

	/**
	 * Change status.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function case_status( CaseRecord $client_case, array $input, int $actor ): void {
		$this->finish( $client_case, 'details', $this->app->case_service->set_status( $client_case, $input['status'], $actor ), __( 'סטטוס התיק עודכן.', 'diromax-portal' ) );
	}

	/**
	 * Add a member.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function add_member( CaseRecord $client_case, array $input, int $actor ): void {
		$message = '1' === $input['invite'] ? __( 'הלקוח נוסף לתיק ונשלחה אליו הזמנה.', 'diromax-portal' ) : __( 'הלקוח נוסף לתיק.', 'diromax-portal' );
		$this->finish( $client_case, 'members', $this->app->case_service->add_member( $client_case, $input, $actor ), $message, 'member', $input );
	}

	/**
	 * Remove a member.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function remove_member( CaseRecord $client_case, array $input, int $actor ): void {
		$this->finish( $client_case, 'members', $this->app->case_service->remove_member( $client_case, absint( $input['user_id'] ), $actor ), __( 'הלקוח הוסר מהתיק.', 'diromax-portal' ) );
	}

	/**
	 * Change a member's email mode.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Fields.
	 * @param int                   $actor       Actor.
	 */
	private function member_mode( CaseRecord $client_case, array $input, int $actor ): void {
		$this->finish( $client_case, 'members', $this->app->case_service->set_member_mode( $client_case, absint( $input['user_id'] ), $input['mode'], $actor ), __( 'העדפת המיילים נשמרה.', 'diromax-portal' ) );
	}

	/**
	 * Re-send an invitation.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function invite( CaseRecord $client_case, array $input, int $actor ): void {
		$user_id = absint( $input['user_id'] );
		$result  = $this->app->members->is_member( $client_case->id, $user_id ) && $this->app->policy->can_manage_case( $actor, $client_case )
			? $this->app->login->invite( $user_id, $client_case )
			: Errors::not_found();

		$this->finish( $client_case, 'members', false === $result ? Errors::storage() : $result, __( 'ההזמנה נשלחה שוב.', 'diromax-portal' ) );
	}

	/**
	 * Save the budget tree (spec 19.2).
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Actor.
	 */
	private function save_budget( CaseRecord $client_case, int $actor ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$raw  = isset( $_POST['budget'] ) && is_array( $_POST['budget'] ) ? wp_unslash( $_POST['budget'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in budget_input().
		$move = isset( $_POST['move'] ) ? sanitize_text_field( wp_unslash( $_POST['move'] ) ) : '';
		// phpcs:enable
		$input = self::budget_input( is_array( $raw ) ? $raw : array(), $move );

		$this->finish( $client_case, 'budget', $this->app->case_service->save_budget( $client_case, $input, $actor ), '' !== $input['move'] ? __( 'הסדר עודכן והתקציב נשמר.', 'diromax-portal' ) : __( 'התקציב נשמר.', 'diromax-portal' ), 'budget', $input );
	}

	/**
	 * The budget form as flat fields: "{key}.label", "{key}.planned",
	 * "{key}.remove", "add.{section}.label", "add.*.label", "move".
	 *
	 * @param array<mixed> $raw  $_POST['budget'], unslashed.
	 * @param string       $move "{key}:up" or "{key}:down" from the arrow buttons.
	 * @return array<string, string>
	 */
	private static function budget_input( array $raw, string $move ): array {
		$input = array( 'move' => 1 === preg_match( '/^[a-z0-9_-]{1,40}:(up|down)$/', $move ) ? $move : '' );

		foreach ( array( 'lines', 'add' ) as $group ) {
			$rows = isset( $raw[ $group ] ) && is_array( $raw[ $group ] ) ? $raw[ $group ] : array();

			foreach ( array_slice( $rows, 0, 2000, true ) as $key => $fields ) {
				$key = '*' === $key ? '*' : sanitize_key( (string) $key );

				if ( '' === $key || ! is_array( $fields ) ) {
					continue;
				}

				$prefix = 'add' === $group ? 'add.' . $key : $key;

				foreach ( array( 'label', 'planned', 'remove' ) as $name ) {
					if ( isset( $fields[ $name ] ) && is_string( $fields[ $name ] ) ) {
						$input[ $prefix . '.' . $name ] = sanitize_text_field( $fields[ $name ] );
					}
				}
			}
		}

		return $input;
	}

	/**
	 * Add a ledger entry.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function add_entry( CaseRecord $client_case, array $input, int $actor ): void {
		$file   = self::file( 'file' );
		$result = $this->app->ledger_service->add( $client_case, $input, $actor, $file );

		$this->finish( $client_case, 'finance', $result, __( 'התנועה נשמרה. אפשר להזין את הבאה.', 'diromax-portal' ), 'entry', $input );
	}

	/**
	 * Edit a ledger entry.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function update_entry( CaseRecord $client_case, array $input, int $actor ): void {
		$result = $this->app->ledger_service->update( $client_case, absint( $input['entry_id'] ), $input, $actor );

		$this->finish( $client_case, 'finance', $result, __( 'התנועה עודכנה.', 'diromax-portal' ), 'entry-' . absint( $input['entry_id'] ), $input );
	}

	/**
	 * Void a ledger entry.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function void_entry( CaseRecord $client_case, array $input, int $actor ): void {
		$result = $this->app->ledger_service->void( $client_case, absint( $input['entry_id'] ), $input['reason'], '1' === $input['notify'], $actor );

		$this->finish( $client_case, 'finance', $result, __( 'התנועה בוטלה ויצאה מהחישובים.', 'diromax-portal' ), 'void-' . absint( $input['entry_id'] ), $input );
	}

	/**
	 * Settle a payment request.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function settle_request( CaseRecord $client_case, array $input, int $actor ): void {
		$result = $this->app->ledger_service->settle( $client_case, absint( $input['entry_id'] ), $input, $actor );

		$this->finish( $client_case, 'finance', $result, __( 'הדרישה סומנה כשולמה ונרשמה התנועה המתאימה.', 'diromax-portal' ), 'settle-' . absint( $input['entry_id'] ), $input );
	}

	/**
	 * Publish a progress update.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function add_update( CaseRecord $client_case, array $input, int $actor ): void {
		$drafts = $this->app->plan->count_drafts( $client_case->id );
		$result = ( new UpdateService( $this->app ) )->publish( $client_case, $input, self::files( 'files' ), $actor );

		$this->finish( $client_case, 'updates', $result, __( 'העדכון פורסם.', 'diromax-portal' ) . $this->drafts_note( $client_case, $drafts ), 'update', $input );
	}

	/**
	 * Upload a document.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function upload_document( CaseRecord $client_case, array $input, int $actor ): void {
		$file   = self::file( 'file' );
		$result = null === $file
			? Errors::invalid( array( 'file' => 'missing' ) )
			: $this->app->document_service->upload( $client_case, $file, 'client' === $input['kind'] ? 'other' : $input['kind'], $input['title'], '1' === $input['visible'], $actor, '1' === $input['notify'], null, '1' === $input['sign_required'] );

		$this->finish( $client_case, 'documents', $result, __( 'המסמך הועלה ונשמר מוצפן.', 'diromax-portal' ), 'document', $input );
	}

	/**
	 * Add photos to the journal.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Fields.
	 * @param int                   $actor       Actor.
	 */
	private function upload_photos( CaseRecord $client_case, array $input, int $actor ): void {
		$result = ( new PhotoService( $this->app ) )->upload( $client_case, self::files( 'photos' ), $input, $actor );

		if ( is_array( $result ) ) {
			$message = 1 === $result['stored']
				? __( 'הצילום נוסף ליומן.', 'diromax-portal' )
				/* translators: %d: number of photos. */
				: sprintf( __( '%d צילומים נוספו ליומן.', 'diromax-portal' ), $result['stored'] );

			if ( array() !== $result['failed'] ) {
				/* translators: %s: file names. */
				$message .= ' ' . sprintf( __( 'לא התקבלו (סוג או גודל לא מתאים): %s.', 'diromax-portal' ), implode( ', ', $result['failed'] ) );
			}

			$result = true;
		}

		$this->finish( $client_case, 'photos', $result, $message ?? '', 'photos', $input );
	}

	/**
	 * Show or hide a document.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function document_visibility( CaseRecord $client_case, array $input, int $actor ): void {
		$document = $this->app->documents->find( absint( $input['document_id'] ) );
		$result   = null === $document ? Errors::not_found() : $this->app->document_service->set_visible( $client_case, $document, '1' === $input['visible'], $actor );

		$this->finish( $client_case, 'documents', $result, __( 'הנראות של המסמך עודכנה.', 'diromax-portal' ) );
	}

	/**
	 * Delete a document.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function delete_document( CaseRecord $client_case, array $input, int $actor ): void {
		$document = $this->app->documents->find( absint( $input['document_id'] ) );
		$result   = null === $document ? Errors::not_found() : $this->app->document_service->delete( $client_case, $document, $actor );

		$this->finish( $client_case, 'documents', $result, __( 'המסמך נמחק.', 'diromax-portal' ) );
	}

	/**
	 * Send the pending batch now.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Actor.
	 */
	private function send_now( CaseRecord $client_case, int $actor ): void {
		$result  = $this->app->notifier->send_now( $client_case, $actor );
		$message = 1 === $result
			? __( 'נשלח מייל אחד.', 'diromax-portal' )
			/* translators: %d: emails sent. */
			: sprintf( __( 'נשלחו %d מיילים.', 'diromax-portal' ), is_int( $result ) ? $result : 0 );

		$this->finish( $client_case, 'overview', $result, $message );
	}

	/**
	 * Cancel the pending batch.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Actor.
	 */
	private function cancel_pending( CaseRecord $client_case, int $actor ): void {
		$this->finish( $client_case, 'overview', $this->app->notifier->cancel( $client_case, $actor ), __( 'העדכונים הממתינים בוטלו ולא יישלחו.', 'diromax-portal' ) );
	}

	/**
	 * Delete a case for good, open or closed. Requires typing the case number.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function delete_case( CaseRecord $client_case, array $input, int $actor ): void {
		if ( $input['confirm'] !== $client_case->number ) {
			$this->finish( $client_case, 'details', Errors::invalid( array( 'confirm' => 'invalid' ) ), '', 'delete' );
		}

		$result = $this->app->case_service->delete( $client_case, $actor );

		if ( $result instanceof WP_Error ) {
			$this->finish( $client_case, 'details', $result, '', 'delete' );
		}

		Flash::set(
			'success',
			0 === $result
				? __( 'התיק נמחק לצמיתות, כולל המסמכים והצילומים.', 'diromax-portal' )
				/* translators: %d: number of client accounts. */
				: sprintf( _n( 'התיק נמחק לצמיתות, כולל המסמכים והצילומים, וגם %d חשבון לקוח שלא היה לו תיק אחר.', 'התיק נמחק לצמיתות, כולל המסמכים והצילומים, וגם %d חשבונות לקוח שלא היה להם תיק אחר.', $result, 'diromax-portal' ), $result )
		);
		self::redirect( Admin::page_url( Admin::PAGE_CASES ) );
	}

	/**
	 * Save settings.
	 */
	public function save_settings(): void {
		check_admin_referer( 'dx_portal_save_settings' );
		self::return_to_sender();

		if ( ! $this->app->policy->can_manage_settings( get_current_user_id() ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$raw   = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$input = array(
			'vat_rate'         => isset( $raw['vat_rate'] ) ? (string) (int) round( (float) sanitize_text_field( (string) $raw['vat_rate'] ) * 100 ) : '',
			'quiet_minutes'    => sanitize_text_field( (string) ( $raw['quiet_minutes'] ?? '' ) ),
			'max_wait_minutes' => sanitize_text_field( (string) ( $raw['max_wait_minutes'] ?? '' ) ),
			'hide_amounts'     => isset( $raw['hide_amounts'] ) ? '1' : '',
			'payment_details'  => sanitize_textarea_field( (string) ( $raw['payment_details'] ?? '' ) ),
			'access_months'    => sanitize_text_field( (string) ( $raw['access_months'] ?? '' ) ),
			'monthly_summary'  => isset( $raw['monthly_summary'] ) ? '1' : '0',
			'morning_summary'  => isset( $raw['morning_summary'] ) ? '1' : '0',
		);

		foreach ( array( 'daily_hour', 'remind_before_days', 'remind_overdue_every', 'remind_overdue_max', 'stale_days' ) as $number ) {
			$input[ $number ] = sanitize_text_field( (string) ( $raw[ $number ] ?? '' ) );
		}

		// The default budget: sections, and sub-sections on "- " lines (spec 19.2).
		$input += Settings::template_settings( BudgetTemplate::parse( sanitize_textarea_field( (string) ( $raw['categories'] ?? '' ) ), Settings::budget_template() ) );

		// Typical weeks of each stage, for a new timeline (spec 22.5); Settings keeps 0–104.
		$input['schedule_weeks'] = array_map( static fn( mixed $weeks ): string => is_string( $weeks ) ? sanitize_text_field( $weeks ) : '', isset( $raw['schedule_weeks'] ) && is_array( $raw['schedule_weeks'] ) ? $raw['schedule_weeks'] : array() );

		// The payment plan of a new case (spec 21.5): rows that fail the checks are reported, not saved.
		[ $input['plan_template'], $skipped ] = self::plan_rows( isset( $raw['plan'] ) && is_array( $raw['plan'] ) ? $raw['plan'] : array() );

		$before = Settings::all();
		$after  = Settings::save( $input );

		$this->app->audit->record( 'settings.updated', null, 'settings', null, array_diff_assoc( array_map( 'wp_json_encode', $after ), array_map( 'wp_json_encode', $before ) ), $this->app->clock->mysql() );

		if ( array() === $skipped ) {
			Flash::set( 'success', __( 'ההגדרות נשמרו.', 'diromax-portal' ) );
			self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) );
		}

		/* translators: %s: the rows left out and why. */
		Flash::set( 'error', sprintf( __( 'ההגדרות נשמרו, אבל שורות בתבנית תוכנית התשלומים לא נשמרו: %s', 'diromax-portal' ), implode( ' ', $skipped ) ) );
		self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) . '#dxa-plan-template' );
	}

	/**
	 * The payment plan template as typed on the settings screen, one row per
	 * payment. Empty rows are dropped; a row that fails the checks is left
	 * out and named, with what to fix.
	 *
	 * @param array<mixed> $rows Raw rows (unslashed).
	 * @return array{0:array<int, array<string, mixed>>, 1:array<int, string>} Rows to save; row number => sentence.
	 */
	private static function plan_rows( array $rows ): array {
		$columns = array(
			'label'  => __( 'שם התשלום', 'diromax-portal' ),
			'stage'  => __( 'מתי', 'diromax-portal' ),
			'kind'   => __( 'סוג', 'diromax-portal' ),
			'value'  => __( 'אחוז או סכום', 'diromax-portal' ),
			'pay_to' => __( 'למי', 'diromax-portal' ),
			'payee'  => __( 'שם הספק', 'diromax-portal' ),
			'days'   => __( 'ימים לתשלום', 'diromax-portal' ),
		);
		$keep    = array();
		$skipped = array();

		foreach ( array_values( array_slice( $rows, 0, 20 ) ) as $index => $row ) {
			$text  = static fn( string $key ): string => is_array( $row ) && is_string( $row[ $key ] ?? null ) ? sanitize_text_field( $row[ $key ] ) : '';
			$when  = $text( 'when' );
			$input = array(
				'label'      => $text( 'label' ),
				'trigger_on' => 'manual' === $when ? 'manual' : 'stage',
				'stage'      => str_starts_with( $when, 'stage:' ) ? substr( $when, 6 ) : '',
				'kind'       => sanitize_key( $text( 'kind' ) ),
				'value'      => $text( 'value' ),
				'pay_to'     => sanitize_key( $text( 'pay_to' ) ),
				'payee'      => $text( 'payee' ),
				'days'       => $text( 'days' ),
			);

			if ( '' === $input['label'] && '' === $input['value'] ) {
				continue;
			}

			$checked = PaymentPlan::validate( $input, Stages::COUNT, false );

			if ( null !== $checked['fields'] ) {
				$keep[] = $checked['fields'];
				continue;
			}

			$field = (string) array_key_first( $checked['errors'] );
			/* translators: 1: row number, 2: column, 3: what is wrong. */
			$skipped[ $index + 1 ] = sprintf( __( 'שורה %1$d (%2$s): %3$s', 'diromax-portal' ), $index + 1, $columns[ $field ] ?? $columns['stage'], Messages::field( $field, $checked['errors'][ $field ] ) );
		}

		return array( $keep, $skipped );
	}

	/**
	 * Save the Postmark and Turnstile settings (spec 17.5). Secrets are
	 * sealed before they are stored; empty secret fields keep what is saved.
	 */
	public function save_integrations(): void {
		check_admin_referer( 'dx_portal_save_integrations' );
		self::return_to_sender();

		if ( ! $this->app->policy->can_manage_settings( get_current_user_id() ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$raw   = isset( $_POST['integrations'] ) && is_array( $_POST['integrations'] ) ? wp_unslash( $_POST['integrations'] ) : array();
		$input = array();

		foreach ( array_keys( Integrations::FIELDS ) as $name ) {
			// Secrets are compared and sealed as typed: sanitize_text_field() only trims and drops tags, which no valid key has.
			$input[ $name ]            = sanitize_text_field( (string) ( $raw[ $name ] ?? '' ) );
			$input[ $name . '_clear' ] = isset( $raw[ $name . '_clear' ] ) ? '1' : '';
		}

		$result = Integrations::save( $input );

		if ( $result instanceof WP_Error ) {
			Flash::set( 'error', __( 'ההגדרות לא נשמרו. בודקים את השדות המסומנים.', 'diromax-portal' ), Errors::fields( $result ), array_diff_key( $input, array_flip( array( 'token', 'turnstile_secret' ) ) ), 'integrations' );
		} else {
			// Which settings changed, never their values.
			$this->app->audit->record( 'settings.integrations', null, 'settings', null, array( 'changed' => $result ), $this->app->clock->mysql() );
			Flash::set( 'success', __( 'ההגדרות נשמרו.', 'diromax-portal' ) );
		}

		self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) . '#dxa-mail-title' );
	}

	/**
	 * Make a new webhook key; the next page shows it once, in the two URLs.
	 */
	public function new_webhook_key(): void {
		check_admin_referer( 'dx_portal_new_webhook_key' );
		self::return_to_sender();

		$user = get_current_user_id();

		if ( ! $this->app->policy->can_manage_settings( $user ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		if ( Integrations::webhook_locked() || ! $this->app->store->is_configured() ) {
			Flash::set( 'error', __( 'אי אפשר ליצור מפתח כאן: המפתח קבוע ב-wp-config.php, או שמפתח ההצפנה DX_PORTAL_KEY חסר.', 'diromax-portal' ) );
			self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) . '#dxa-mail-title' );
		}

		$key = Integrations::new_webhook_key();
		set_transient( self::NEW_KEY_SLOT . $user, $this->app->store->seal( $key, self::NEW_KEY_SLOT . $user ), 10 * MINUTE_IN_SECONDS );
		$this->app->audit->record( 'settings.webhook_key', null, 'settings', null, array(), $this->app->clock->mysql() );
		Flash::set( 'success', __( 'נוצר מפתח webhook חדש. המפתח הקודם כבר לא עובד: מעדכנים את שתי הכתובות ב-Postmark.', 'diromax-portal' ) );
		self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) . '#dxa-mail-title' );
	}

	/**
	 * Send a test email to the signed-in administrator (spec 17.1).
	 */
	public function test_mail(): void {
		check_admin_referer( 'dx_portal_test_mail' );
		self::return_to_sender();

		$user = wp_get_current_user();

		if ( ! $this->app->policy->can_manage_settings( (int) $user->ID ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		if ( ! $this->app->limiter->attempt( 'test_mail', (string) $user->ID, 5, HOUR_IN_SECONDS ) ) {
			Flash::set( 'error', __( 'נשלחו כבר כמה מיילי בדיקה בשעה האחרונה. אפשר לנסות שוב מאוחר יותר.', 'diromax-portal' ) );
			self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) );
		}

		$ok = $this->app->notifier->send_to_staff( (int) $user->ID, (string) $user->user_email, $this->app->renderer->test_mail( (string) $user->display_name, Postmark::configured() ), 'test' );

		Flash::set(
			$ok ? 'success' : 'error',
			$ok
				/* translators: %s: email address. */
				? sprintf( __( 'מייל בדיקה נשלח אל %s. אם לא הגיע תוך כמה דקות, בודקים בתיקיית הספאם וביומן של Postmark.', 'diromax-portal' ), (string) $user->user_email )
				: __( 'השליחה נכשלה. הפרטים ביומן השגיאות של האתר (mail.postmark_failed).', 'diromax-portal' )
		);
		self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) );
	}

	/**
	 * Manager download of a document (hidden ones included).
	 */
	public function download(): void {
		$document_id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( 'dx_portal_download_' . $document_id );

		$document    = $this->app->documents->find( $document_id );
		$client_case = null === $document ? null : $this->app->cases->find( $document->case_id );

		if ( null === $document || null === $client_case || is_wp_error( $this->app->document_service->send( $client_case, $document, get_current_user_id() ) ) ) {
			wp_die( esc_html__( 'המסמך לא נמצא.', 'diromax-portal' ), '', array( 'response' => 404 ) );
		}

		exit;
	}

	/**
	 * Download the case's finance table as CSV (every entry, staff columns).
	 */
	public function export(): void {
		$case_id = isset( $_GET['case_id'] ) ? absint( $_GET['case_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( 'dx_portal_export_' . $case_id );

		$client_case = $this->app->cases->find( $case_id );
		$csv         = null === $client_case ? Errors::not_found() : ( new CsvExport( $this->app ) )->build( $client_case, get_current_user_id(), true );

		if ( $csv instanceof WP_Error || null === $client_case ) {
			wp_die( esc_html__( 'התיק לא נמצא, או שאין לך הרשאה אליו.', 'diromax-portal' ), '', array( 'response' => 404 ) );
		}

		CsvExport::output( $csv, CsvExport::filename( $client_case, $this->app->clock->today() ) );
		exit;
	}

	/**
	 * The payments forecast as CSV (spec 20.2): administrators only.
	 */
	public function forecast_csv(): void {
		check_admin_referer( 'dx_portal_forecast_csv' );

		$csv = ( new ForecastService( $this->app ) )->csv( get_current_user_id() );

		if ( $csv instanceof WP_Error ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		CsvExport::output( $csv, 'diromax-forecast-' . $this->app->clock->today() . '.csv' );
		exit;
	}

		/**
		 * Preview of the pending email.
		 */
	public function preview(): void {
		$case_id = isset( $_GET['case_id'] ) ? absint( $_GET['case_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( 'dx_portal_preview_' . $case_id );

		$client_case = $this->app->cases->find( $case_id );
		$html        = null === $client_case ? Errors::not_found() : $this->app->notifier->preview( $client_case, get_current_user_id() );

		if ( $html instanceof WP_Error ) {
			wp_die( esc_html( $html->get_error_message() ), '', array( 'response' => 403 ) );
		}

		if ( '' === $html ) {
			wp_die( esc_html__( 'אין עדכונים שממתינים לשליחה.', 'diromax-portal' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		// nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- The email HTML from MailRenderer, which escapes every value; no request data reaches it unescaped.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by MailRenderer, which escapes every value.
		exit;
	}

	/**
	 * Flash the result and go back to the tab.
	 *
	 * @param CaseRecord            $client_case    Case.
	 * @param string                $tab     Tab.
	 * @param mixed                 $result  Service result.
	 * @param string                $success Success message.
	 * @param string                $form    Form key for field errors.
	 * @param array<string, string> $old     Submitted values.
	 */
	private function finish( CaseRecord $client_case, string $tab, mixed $result, string $success, string $form = '', array $old = array() ): never {
		if ( $result instanceof WP_Error ) {
			Flash::set( 'error', $result->get_error_message(), Errors::fields( $result ), $old, $form );
		} else {
			Flash::set( 'success', $success );
		}

		if ( $this->to_dashboard ) {
			self::redirect( Admin::page_url( Admin::PAGE_DASHBOARD ) );
		}

		self::redirect( Admin::case_url( $client_case->id, $tab ) . ( '' !== $form && $result instanceof WP_Error ? '#form-' . $form : '' ) );
	}

	/**
	 * Send the user back to the interface the form came from: the management
	 * app on the site, or wp-admin. The form's own referer field says which.
	 */
	private static function return_to_sender(): void {
		Admin::use_front( Router::is_manage_url( (string) wp_get_referer() ) );
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url URL.
	 * @return never
	 */
	private static function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Read and sanitize the named POST fields.
	 *
	 * @param string[] $lines  Single-line fields.
	 * @param string[] $blocks Multi-line fields.
	 * @return array<string, string>
	 */
	private static function input( array $lines, array $blocks ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called after check_admin_referer().
		$input = array();

		foreach ( $lines as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}

		foreach ( $blocks as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		// phpcs:enable

		return $input;
	}

	/**
	 * A meeting form's values to show again after an error, the ticked
	 * clients included: losing them would uninvite everyone on the next
	 * save (PR #16 review).
	 *
	 * @param array<string, string> $input Form fields.
	 * @return array<string, string>
	 */
	private static function meeting_old( array $input ): array {
		return $input + array( 'participants' => implode( ',', self::participants() ) );
	}

		/**
		 * The clients ticked on a meeting form.
		 *
		 * @return int[]
		 */
	private static function participants(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Called after check_admin_referer(); each value goes through absint() below.
		$raw = isset( $_POST['participants'] ) && is_array( $_POST['participants'] ) ? wp_unslash( $_POST['participants'] ) : array();
		// phpcs:enable

		return array_values( array_filter( array_map( 'absint', array_slice( $raw, 0, 50 ) ) ) );
	}

		/**
		 * One uploaded file, or null when none was chosen.
		 *
		 * @param string $field Field name.
		 * @return array<string, mixed>|null
		 */
	private static function file( string $field ): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Verified in handle(); DocumentService validates the file itself.
		$file = $_FILES[ $field ] ?? null;

		if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return null;
		}

		return $file;
	}

	/**
	 * Several uploaded files from a multiple input.
	 *
	 * @param string $field Field name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function files( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Verified in handle(); DocumentService validates each file.
		$raw = $_FILES[ $field ] ?? null;

		if ( ! is_array( $raw ) || ! is_array( $raw['name'] ?? null ) ) {
			return array();
		}

		$files = array();

		foreach ( array_keys( $raw['name'] ) as $index ) {
			if ( UPLOAD_ERR_NO_FILE === ( $raw['error'][ $index ] ?? UPLOAD_ERR_NO_FILE ) ) {
				continue;
			}

			$files[] = array(
				'name'     => $raw['name'][ $index ],
				'type'     => $raw['type'][ $index ] ?? '',
				'tmp_name' => $raw['tmp_name'][ $index ] ?? '',
				'error'    => $raw['error'][ $index ],
				'size'     => (int) ( $raw['size'][ $index ] ?? 0 ),
			);
		}

		return $files;
	}
}
