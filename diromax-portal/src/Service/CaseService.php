<?php
/**
 * Case use cases.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\CaseFieldsValidator;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\CaseSummary;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Domain\Money;
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
	 * @param array<string, string> $input       Details; manager_id; stage_status as "1:done,2:active,…" (stages left out keep their status).
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

		if ( null === $statuses ) {
			$checked['errors']['stages'] = 'invalid';
		}

		if ( $this->app->policy->can_manage_all( $actor ) && isset( $input['manager_id'] ) ) {
			$manager = $this->resolve_manager( $input['manager_id'], $actor );

			if ( null === $manager ) {
				$checked['errors']['manager_id'] = 'invalid';
			} else {
				$fields['manager_id'] = $manager;
			}
		}

		if ( $checked['errors'] || null === $statuses ) {
			return Errors::invalid( $checked['errors'] );
		}

		$stages           = StageProgress::set( $client_case->stages, $statuses, $this->app->clock->today() );
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
	 * Replace the budget lines.
	 *
	 * @param CaseRecord            $client_case  Case.
	 * @param array<string, string> $input Category key => amount as typed.
	 * @param int                   $actor Acting user.
	 * @return true|WP_Error
	 */
	public function save_budget( CaseRecord $client_case, array $input, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$lines  = array();
		$errors = array();

		foreach ( array_keys( Settings::categories() ) as $key ) {
			$raw = trim( $input[ $key ] ?? '' );

			if ( '' === $raw ) {
				continue;
			}

			$amount = Money::parse( $raw );

			if ( null === $amount || $amount > Money::MAX ) {
				$errors[ $key ] = 'invalid';
				continue;
			}

			$lines[ $key ] = $amount;
		}

		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		$before = $this->app->budget->planned( $client_case->id );

		if ( ! $this->app->budget->replace( $client_case->id, $lines ) ) {
			return Errors::storage();
		}

		$this->app->audit->record(
			'budget.updated',
			$client_case->id,
			'case',
			$client_case->id,
			array(
				'before' => $before,
				'after'  => $lines,
			),
			$this->app->clock->mysql()
		);
		$this->refresh_summary( $client_case->id );

		return true;
	}

	/**
	 * Permanently delete a closed case with its files.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Acting user.
	 * @return true|WP_Error
	 */
	public function delete( CaseRecord $client_case, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_delete_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		foreach ( $this->app->documents->stored_names( $client_case->id ) as $name ) {
			$this->app->store->delete( $name );
		}

		$this->app->cases->delete( $client_case->id );
		$this->app->audit->record( 'case.deleted', null, 'case', $client_case->id, array( 'number' => $client_case->number ), $this->app->clock->mysql() );

		return true;
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

		return SummaryCalculator::calculate( $entries, $this->app->budget->planned( $client_case->id ), $this->app->clock->today() );
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
