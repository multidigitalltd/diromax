<?php
/**
 * Admin form handlers.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Service\CsvExport;
use Diromax\Portal\Service\DecisionService;
use Diromax\Portal\Service\DemoCase;
use Diromax\Portal\Service\DocRequestService;
use Diromax\Portal\Service\Errors;
use Diromax\Portal\Service\Postmark;
use Diromax\Portal\Service\ReminderService;
use Diromax\Portal\Service\SignatureService;
use Diromax\Portal\Service\TaskService;
use Diromax\Portal\Service\UpdateService;
use Diromax\Portal\Settings;
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
	);

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
		add_action( 'admin_post_dx_portal_demo_case', array( $this, 'demo_case' ) );
		add_action( 'admin_post_dx_portal_download', array( $this, 'download' ) );
		add_action( 'admin_post_dx_portal_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_dx_portal_export', array( $this, 'export' ) );
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
			default               => wp_die( esc_html__( 'פעולה לא מוכרת.', 'diromax-portal' ), '', array( 'response' => 400 ) ),
		};
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
		$this->finish( $client_case, 'details', $this->app->case_service->update_details( $client_case, $input, $actor ), __( 'פרטי התיק נשמרו.', 'diromax-portal' ), 'details', $input );
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
	 * Save the budget.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Actor.
	 */
	private function save_budget( CaseRecord $client_case, int $actor ): void {
		$lines = array();

		foreach ( array_keys( Settings::categories() ) as $key ) {
			$lines[ $key ] = isset( $_POST['budget'][ $key ] ) ? sanitize_text_field( wp_unslash( $_POST['budget'][ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		}

		$this->finish( $client_case, 'budget', $this->app->case_service->save_budget( $client_case, $lines, $actor ), __( 'התקציב נשמר.', 'diromax-portal' ), 'budget', $lines );
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
		$result = ( new UpdateService( $this->app ) )->publish( $client_case, $input, self::files( 'files' ), $actor );

		$this->finish( $client_case, 'updates', $result, __( 'העדכון פורסם.', 'diromax-portal' ), 'update', $input );
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
	 * Delete a closed case for good. Requires typing the case number.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Fields.
	 * @param int                   $actor Actor.
	 */
	private function delete_case( CaseRecord $client_case, array $input, int $actor ): void {
		if ( $input['confirm'] !== $client_case->number ) {
			$this->finish( $client_case, 'details', Errors::invalid( array( 'confirm' => 'invalid' ) ), '' );
		}

		$result = $this->app->case_service->delete( $client_case, $actor );

		if ( $result instanceof WP_Error ) {
			$this->finish( $client_case, 'details', $result, '' );
		}

		Flash::set( 'success', __( 'התיק נמחק לצמיתות, כולל המסמכים.', 'diromax-portal' ) );
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
			'categories'       => self::categories_input( (string) ( $raw['categories'] ?? '' ) ),
			'monthly_summary'  => isset( $raw['monthly_summary'] ) ? '1' : '0',
			'morning_summary'  => isset( $raw['morning_summary'] ) ? '1' : '0',
		);

		foreach ( array( 'daily_hour', 'remind_before_days', 'remind_overdue_every', 'remind_overdue_max', 'stale_days' ) as $number ) {
			$input[ $number ] = sanitize_text_field( (string) ( $raw[ $number ] ?? '' ) );
		}

		$before = Settings::all();
		$after  = Settings::save( $input );

		$this->app->audit->record( 'settings.updated', null, 'settings', null, array_diff_assoc( array_map( 'wp_json_encode', $after ), array_map( 'wp_json_encode', $before ) ), $this->app->clock->mysql() );
		Flash::set( 'success', __( 'ההגדרות נשמרו.', 'diromax-portal' ) );
		self::redirect( Admin::page_url( Admin::PAGE_SETTINGS ) );
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
	private function finish( CaseRecord $client_case, string $tab, mixed $result, string $success, string $form = '', array $old = array() ): void {
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

	/**
	 * Categories from the settings textarea: one "key=Label" per line.
	 *
	 * @param string $text Textarea value.
	 * @return array<string, string>
	 */
	private static function categories_input( string $text ): array {
		$categories = array();

		$lines = preg_split( '/\R/', sanitize_textarea_field( $text ) );

		foreach ( false === $lines ? array() : $lines as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );

			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$categories[ $parts[0] ] = $parts[1];
			}
		}

		return $categories;
	}
}
