<?php
/**
 * Case use cases.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\MemberRecord;
use Diromax\Portal\Domain\BudgetTree;
use Diromax\Portal\Domain\CaseFieldsValidator;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\CaseSummary;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Domain\StageProgress;
use Diromax\Portal\Domain\SummaryCalculator;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Security\Capabilities;
use Diromax\Portal\Settings;
use Diromax\Portal\Support\Ids;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creating and changing cases, their members, stages and budget.
 *
 * Every public method takes the acting user, checks AccessPolicy first and
 * writes an audit entry for each change.
 */
final class CaseService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Open a new case.
	 *
	 * @param array<string, string> $input Details and manager_id.
	 * @param int                   $actor Acting user.
	 * @return CaseRecord|WP_Error
	 */
	public function create( array $input, int $actor ): CaseRecord|WP_Error {
		if ( ! $this->app->policy->can_use_admin( $actor ) ) {
			return Errors::forbidden();
		}

		$checked = CaseFieldsValidator::validate( $input );
		$manager = $this->resolve_manager( $input['manager_id'] ?? '', $actor );
		$leads   = new LeadLink( $this->app );
		$lead_id = absint( $input['lead_id'] ?? 0 );

		if ( null === $manager ) {
			$checked['errors']['manager_id'] = 'invalid';
		}

		if ( 0 !== $lead_id && null === $leads->usable( $lead_id, $actor ) ) {
			$checked['errors']['lead_id'] = 'invalid';
		}

		if ( $checked['errors'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$fields               = $checked['fields'];
		$fields['manager_id'] = (int) $manager;
		$fields['lead_id']    = 0 === $lead_id ? null : $lead_id;
		$now                  = $this->app->clock->mysql();
		$id                   = $this->app->cases->insert( $fields, Ids::public_id(), $now );
		$client_case          = $id ? $this->app->cases->find( $id ) : null;

		if ( null === $client_case ) {
			return Errors::storage();
		}

		if ( 0 !== $lead_id ) {
			$leads->link( $lead_id, $client_case );
		}

		// Every case starts from the office's default budget (spec 19.2).
		$this->app->budget->seed( $client_case->id, Settings::budget_template() );

		$this->app->audit->record(
			'case.created',
			$client_case->id,
			'case',
			$client_case->id,
			array_filter(
				array(
					'manager_id' => $client_case->manager_id,
					'lead_id'    => $lead_id,
				)
			),
			$now
		);
		$this->refresh_summary( $client_case->id );

		return $client_case;
	}

	/**
	 * Change a case's details, manager and stages.
	 *
	 * Each stage gets its own status, so several can be in progress at once;
	 * the case's lead stage follows from them.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Details; manager_id; stage_status as "1:done,2:active,…" and stage_due as "1:2027-01-31,2:,…" (stages left out keep theirs).
	 * @param int                   $actor       Acting user.
	 * @return CaseRecord|WP_Error
	 */
	public function update_details( CaseRecord $client_case, array $input, int $actor ): CaseRecord|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$checked  = CaseFieldsValidator::validate( $input );
		$fields   = $checked['fields'];
		$statuses = StageProgress::parse( $input['stage_status'] ?? '' );
		$dues     = StageProgress::parse_dues( $input['stage_due'] ?? '' );

		if ( null === $statuses ) {
			$checked['errors']['stages'] = 'invalid';
		}

		if ( null === $dues ) {
			$checked['errors']['stage_due'] = 'invalid';
		}

		if ( $this->app->policy->can_manage_all( $actor ) && isset( $input['manager_id'] ) ) {
			$manager = $this->resolve_manager( $input['manager_id'], $actor );

			if ( null === $manager ) {
				$checked['errors']['manager_id'] = 'invalid';
			} else {
				$fields['manager_id'] = $manager;
			}
		}

		if ( $checked['errors'] || null === $statuses || null === $dues ) {
			return Errors::invalid( $checked['errors'] );
		}

		$stages           = StageProgress::with_dues( StageProgress::set( $client_case->stages, $statuses, $this->app->clock->today() ), $dues );
		$fields['stage']  = StageProgress::lead( $stages );
		$fields['stages'] = (string) wp_json_encode( $stages );

		return $this->save( $client_case, $fields, $actor, 'case.updated' );
	}

	/**
	 * Change the stages from a progress update: move on to a stage (the
	 * earlier ones are done), or start it alongside those in progress.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $stage       Stage, 1–7.
	 * @param bool       $parallel    Start it alongside the others.
	 * @param int        $actor       Acting user.
	 * @return CaseRecord|WP_Error
	 */
	public function move_to_stage( CaseRecord $client_case, int $stage, bool $parallel, int $actor ): CaseRecord|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		if ( $stage < 1 || $stage > Stages::COUNT ) {
			return Errors::invalid( array( 'stage' => 'invalid' ) );
		}

		// Work from the stored stages: the caller's record may be older than another change.
		$client_case = $this->app->cases->find( $client_case->id ) ?? $client_case;
		$today       = $this->app->clock->today();
		$stages      = $parallel
			? StageProgress::start( $client_case->stages, $stage, $today )
			: StageProgress::advance( $client_case->stages, $stage, $today );

		if ( $stages === $client_case->stages ) {
			return $client_case;
		}

		return $this->save(
			$client_case,
			array(
				'stage'  => StageProgress::lead( $stages ),
				'stages' => (string) wp_json_encode( $stages ),
			),
			$actor,
			'case.stage_changed'
		);
	}

	/**
	 * Change status: active, on hold, closed.
	 *
	 * @param CaseRecord $client_case   Case.
	 * @param string     $status New status.
	 * @param int        $actor  Acting user.
	 * @return CaseRecord|WP_Error
	 */
	public function set_status( CaseRecord $client_case, string $status, int $actor ): CaseRecord|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$new = CaseStatus::tryFrom( $status );

		if ( null === $new ) {
			return Errors::invalid( array( 'status' => 'invalid' ) );
		}

		return $this->save(
			$client_case,
			array(
				'status'    => $new->value,
				'closed_at' => CaseStatus::Closed === $new ? $this->app->clock->mysql() : null,
			),
			$actor,
			'case.status_changed'
		);
	}

	/**
	 * Add a person to a case, creating their portal account when needed.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input email, name, role, notify ('1'), invite ('1').
	 * @param int                   $actor Acting user.
	 * @return int|WP_Error Member's user ID.
	 */
	public function add_member( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$email = sanitize_email( $input['email'] ?? '' );
		$name  = mb_substr( Text::line( $input['name'] ?? '' ), 0, 80 );
		$role  = MemberRole::tryFrom( $input['role'] ?? '' );

		$errors = array();

		if ( ! is_email( $email ) ) {
			$errors['email'] = 'invalid';
		}

		if ( '' === $name ) {
			$errors['name'] = 'required';
		}

		if ( null === $role ) {
			$errors['role'] = 'invalid';
		}

		if ( $errors || null === $role ) {
			return Errors::invalid( $errors );
		}

		$user_id = $this->client_account( $email, $name );

		if ( $user_id instanceof WP_Error ) {
			return $user_id;
		}

		$now    = $this->app->clock->mysql();
		$notify = isset( $input['notify'] ) && '1' === $input['notify'];
		$this->app->members->upsert( $client_case->id, $user_id, $role, $notify, $now );
		$this->app->audit->record( 'member.added', $client_case->id, 'user', $user_id, array( 'role' => $role->value ), $now );

		if ( '1' === ( $input['invite'] ?? '' ) ) {
			$this->app->login->invite( $user_id, $client_case );
		}

		return $user_id;
	}

	/**
	 * Remove a person from a case.
	 *
	 * @param CaseRecord $client_case    Case.
	 * @param int        $user_id Member.
	 * @param int        $actor   Acting user.
	 * @return true|WP_Error
	 */
	public function remove_member( CaseRecord $client_case, int $user_id, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$this->app->members->remove( $client_case->id, $user_id );
		$this->app->audit->record( 'member.removed', $client_case->id, 'user', $user_id, array(), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Change how a member receives case email (spec 15.3).
	 *
	 * The case manager may change anyone in the case; a client only themself.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $user_id     Member.
	 * @param string     $mode        instant, daily or off.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function set_member_mode( CaseRecord $client_case, int $user_id, string $mode, int $actor ): bool|WP_Error {
		$allowed = $this->app->policy->can_manage_case( $actor, $client_case )
			|| ( $actor === $user_id && $this->app->policy->can_view_case( $actor, $client_case ) );

		if ( ! $allowed ) {
			return Errors::forbidden();
		}

		$notify = NotifyMode::tryFrom( $mode );

		if ( null === $notify ) {
			return Errors::invalid( array( 'mode' => 'invalid' ) );
		}

		$now = $this->app->clock->mysql();

		if ( ! $this->app->members->set_mode( $client_case->id, $user_id, $notify, $now ) ) {
			return Errors::not_found();
		}

		$this->app->audit->record( 'member.mode', $client_case->id, 'user', $user_id, array( 'mode' => $notify->value ), $now );

		return true;
	}

	/**
	 * Change a case's budget from the budget form (spec 19.2): rename,
	 * set amounts, add and remove sections and sub-sections, reorder.
	 * Entries of a removed sub-section move to its section first.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Flat form fields, as BudgetTree::edit() reads them.
	 * @param int                   $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function save_budget( CaseRecord $client_case, array $input, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$before = $this->app->budget->tree( $client_case->id );
		$taken  = array_fill_keys( $before->keys(), true );
		$result = $before->edit(
			$input,
			$this->app->ledger->count_by_category( $client_case->id ),
			static function () use ( &$taken ): string {
				do {
					$key = 'b' . bin2hex( random_bytes( 4 ) );
				} while ( isset( $taken[ $key ] ) );

				$taken[ $key ] = true;

				return $key;
			}
		);

		if ( array() !== $result['errors'] ) {
			return Errors::invalid( $result['errors'] );
		}

		// Before the lines go: the section each moves to is kept either way.
		foreach ( $result['moved'] as $from => $to ) {
			$this->app->ledger->recategorize( $client_case->id, $from, $to );
		}

		if ( ! $this->app->budget->save( $client_case->id, $result['tree'] ) ) {
			return Errors::storage();
		}

		$this->app->audit->record(
			'budget.updated',
			$client_case->id,
			'case',
			$client_case->id,
			array_filter(
				array(
					'changed' => self::budget_diff( $before, $result['tree'] ),
					'moved'   => $result['moved'],
				)
			),
			$this->app->clock->mysql()
		);
		$this->refresh_summary( $client_case->id );

		return true;
	}

	/**
	 * Lines that changed, for the audit log: key => [before, after], each
	 * "label: agorot" or null when the line did not exist.
	 *
	 * @param BudgetTree $before Old budget.
	 * @param BudgetTree $after  New budget.
	 * @return array<string, array{0:string|null, 1:string|null}>
	 */
	private static function budget_diff( BudgetTree $before, BudgetTree $after ): array {
		$describe = static fn( ?array $line ): ?string => null === $line ? null : $line['label'] . ': ' . $line['planned'];
		$diff     = array();

		foreach ( array_unique( array_merge( $before->keys(), $after->keys() ) ) as $key ) {
			$old = $describe( $before->find( $key ) );
			$new = $describe( $after->find( $key ) );

			if ( $old !== $new ) {
				$diff[ $key ] = array( $old, $new );
			}
		}

		return $diff;
	}

	/**
	 * What deleting a case removes, for the confirmation on the case screen.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $actor       Acting user.
	 * @return array{entries:int, documents:int, photos:int, messages:int, accounts:array<int, array{name:string, email:string}>}
	 */
	public function deletion_preview( CaseRecord $client_case, int $actor ): array {
		return array(
			'entries'   => array_sum( $this->app->ledger->count_by_category( $client_case->id ) ) + $this->app->ledger->count_without_category( $client_case->id ),
			'documents' => count( $this->app->documents->stored_names( $client_case->id ) ),
			'photos'    => $this->app->photos->count_for_case( $client_case->id, false ),
			'messages'  => $this->app->messages->count_for_case( $client_case->id ),
			'accounts'  => array_map(
				static fn( MemberRecord $member ): array => array(
					'name'  => $member->name,
					'email' => $member->email,
				),
				$this->clients_only_here( $client_case, $actor )
			),
		);
	}

	/**
	 * Permanently delete a case, open or closed (spec 19.1): its rows, its
	 * encrypted files, and the accounts of clients who have no other case.
	 * Staff accounts are never deleted. The audit log keeps who deleted
	 * which case number, and when.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $actor       Acting user.
	 * @return int|WP_Error Client accounts deleted.
	 */
	public function delete( CaseRecord $client_case, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_delete_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		// Read before the rows go: membership is what makes an account the case's own.
		$accounts = $this->clients_only_here( $client_case, $actor );

		foreach ( array_merge( $this->app->documents->stored_names( $client_case->id ), $this->app->photos->stored_names( $client_case->id ) ) as $name ) {
			$this->app->store->delete( $name );
		}

		if ( null !== $client_case->lead_id ) {
			( new LeadLink( $this->app ) )->unlink( $client_case->lead_id, $client_case->id );
		}

		$this->app->cases->delete( $client_case->id );
		$deleted = $this->delete_accounts( $accounts );

		$this->app->audit->record(
			'case.deleted',
			null,
			'case',
			$client_case->id,
			array(
				'number'   => $client_case->number,
				'status'   => $client_case->status->value,
				'accounts' => $deleted,
			),
			$this->app->clock->mysql()
		);

		return $deleted;
	}

	/**
	 * Client accounts that belong to this case and nothing else: portal
	 * clients only, in no other case, never the person deleting.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $actor       Acting user.
	 * @return MemberRecord[]
	 */
	private function clients_only_here( CaseRecord $client_case, int $actor ): array {
		return array_values(
			array_filter(
				$this->app->members->for_case( $client_case->id ),
				fn( MemberRecord $member ): bool => $member->user_id !== $actor
					&& ! is_super_admin( $member->user_id )
					&& $this->app->policy->is_client_only( $member->user_id )
					&& 1 === $this->app->members->case_count( $member->user_id )
			)
		);
	}

	/**
	 * Delete client accounts left without a case. Each is checked again,
	 * now that the case is gone.
	 *
	 * @param MemberRecord[] $accounts Candidates.
	 * @return int Deleted.
	 */
	private function delete_accounts( array $accounts ): int {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$deleted = 0;

		foreach ( $accounts as $member ) {
			if ( 0 !== $this->app->members->case_count( $member->user_id ) || ! $this->app->policy->is_client_only( $member->user_id ) ) {
				continue;
			}

			$this->app->mail_log->anonymize_user( $member->user_id );

			if ( wp_delete_user( $member->user_id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Summary for the manager (every entry) or the client (visible entries).
	 *
	 * @param CaseRecord $client_case         Case.
	 * @param bool       $visible_only Client view.
	 * @return CaseSummary
	 */
	public function summary( CaseRecord $client_case, bool $visible_only ): CaseSummary {
		$entries = array_map(
			static fn( $row ) => $row->entry,
			$this->app->ledger->for_case( $client_case->id, $visible_only )
		);

		return SummaryCalculator::calculate( $entries, $this->app->budget->tree( $client_case->id ), $this->app->clock->today() );
	}

	/**
	 * Recompute and cache the manager summary shown in the case list.
	 *
	 * @param int $case_id Case.
	 */
	public function refresh_summary( int $case_id ): void {
		$client_case = $this->app->cases->find( $case_id );

		if ( null !== $client_case ) {
			$this->app->cases->save_summary( $case_id, $this->summary( $client_case, false )->to_array() );
		}
	}

	/**
	 * Users who may be assigned as project manager.
	 *
	 * @return array<int, string> User ID => display name.
	 */
	public function managers(): array {
		$users = get_users(
			array(
				'capability' => Capabilities::MANAGE_CASES,
				'fields'     => array( 'ID', 'display_name' ),
				'number'     => 200,
				'orderby'    => 'display_name',
			)
		);

		$list = array();

		foreach ( $users as $user ) {
			$list[ (int) $user->ID ] = (string) $user->display_name;
		}

		return $list;
	}

	/**
	 * Persist changes and audit them with before/after values.
	 *
	 * @param CaseRecord                     $client_case   Case.
	 * @param array<string, string|int|null> $fields Changed columns.
	 * @param int                            $actor  Acting user.
	 * @param string                         $action Audit action.
	 * @return CaseRecord|WP_Error
	 */
	private function save( CaseRecord $client_case, array $fields, int $actor, string $action ): CaseRecord|WP_Error {
		$now = $this->app->clock->mysql();

		if ( ! $this->app->cases->update( $client_case->id, $fields, $now ) ) {
			return Errors::storage();
		}

		$after = $this->app->cases->find( $client_case->id );

		if ( null === $after ) {
			return Errors::storage();
		}

		$this->app->audit->record( $action, $client_case->id, 'case', $client_case->id, Errors::diff( $client_case, $after, array_keys( $fields ) ), $now );

		return $after;
	}

	/**
	 * Manager to assign: a staff member when the actor may choose, the actor otherwise.
	 *
	 * @param string $raw   Requested manager ID.
	 * @param int    $actor Acting user.
	 * @return int|null
	 */
	private function resolve_manager( string $raw, int $actor ): ?int {
		if ( ! $this->app->policy->can_manage_all( $actor ) ) {
			return $actor;
		}

		$id = '' === $raw ? $actor : absint( $raw );

		return $this->app->policy->can_use_admin( $id ) ? $id : null;
	}

	/**
	 * The portal account for an email: existing client, or a new one.
	 *
	 * Staff accounts are refused — they reach cases through the admin screens,
	 * and a portal invitation must never become a way into a staff account.
	 *
	 * @param string $email Email.
	 * @param string $name  Display name.
	 * @return int|WP_Error
	 */
	private function client_account( string $email, string $name ): int|WP_Error {
		$user = get_user_by( 'email', $email );

		if ( $user ) {
			return $this->app->policy->is_client_only( (int) $user->ID )
				? (int) $user->ID
				: Errors::invalid( array( 'email' => 'staff_account' ) );
		}

		$id = wp_insert_user(
			array(
				'user_login'   => 'client_' . bin2hex( random_bytes( 6 ) ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 40, true, true ),
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => Capabilities::ROLE_CLIENT,
			)
		);

		return is_wp_error( $id ) ? Errors::storage() : (int) $id;
	}
}
