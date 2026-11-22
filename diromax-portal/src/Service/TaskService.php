<?php
/**
 * Team tasks.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\EntryValidator;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Internal to-dos of a case (spec 16.2). Only staff who may manage the case
 * add, close or delete its tasks, and a task is always owned by someone
 * who may manage the case.
 */
final class TaskService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Add a task.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       title, assignee_id ('' for the actor), due_date ('' for none).
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error Task ID.
	 */
	public function add( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$title    = Text::line( $input['title'] ?? '' );
		$due      = trim( $input['due_date'] ?? '' );
		$assignee = '' === trim( $input['assignee_id'] ?? '' ) ? $actor : absint( $input['assignee_id'] );
		$errors   = array();

		if ( '' === $title ) {
			$errors['title'] = 'required';
		} elseif ( Text::length( $title ) > 200 ) {
			$errors['title'] = 'too_long';
		}

		if ( '' !== $due && ! EntryValidator::is_date( $due ) ) {
			$errors['due_date'] = 'invalid';
		}

		if ( ! $this->app->policy->can_manage_case( $assignee, $client_case ) ) {
			$errors['assignee_id'] = 'invalid';
		}

		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		$now = $this->app->clock->mysql();
		$id  = $this->app->tasks->insert( $client_case->id, $title, $assignee, '' === $due ? null : $due, $actor, $now );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record(
			'task.added',
			$client_case->id,
			'task',
			$id,
			array(
				'title'    => $title,
				'assignee' => $assignee,
				'due'      => '' === $due ? null : $due,
			),
			$now
		);

		return $id;
	}

	/**
	 * Mark a task done, or open it again.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Task.
	 * @param bool       $done        Done (true) or open again (false).
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function set_done( CaseRecord $client_case, int $id, bool $done, int $actor ): bool|WP_Error {
		$task = $this->task_of( $client_case, $id, $actor );

		if ( $task instanceof WP_Error ) {
			return $task;
		}

		$now = $this->app->clock->mysql();

		if ( ! $this->app->tasks->set_done( $id, $done ? $now : null, $done ? $actor : null ) ) {
			return Errors::storage();
		}

		$this->app->audit->record( $done ? 'task.done' : 'task.reopened', $client_case->id, 'task', $id, array( 'title' => $task['title'] ), $now );

		return true;
	}

	/**
	 * Delete a task.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Task.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function delete( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		$task = $this->task_of( $client_case, $id, $actor );

		if ( $task instanceof WP_Error ) {
			return $task;
		}

		if ( ! $this->app->tasks->delete( $id ) ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'task.deleted', $client_case->id, 'task', $id, array( 'title' => $task['title'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Staff who may own a task in this case: those who may manage it.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, string> User ID => name.
	 */
	public function assignees( CaseRecord $client_case ): array {
		return array_filter(
			$this->app->case_service->managers(),
			fn( int $user_id ): bool => $this->app->policy->can_manage_case( $user_id, $client_case ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * The task, when the actor may manage the case and the task is in it.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Task.
	 * @param int        $actor       Acting user.
	 * @return array{id:int, case_id:int, title:string, assignee_id:int, due_date:string|null, done_at:string|null, done_by:int|null, created_by:int, created_at:string}|WP_Error
	 */
	private function task_of( CaseRecord $client_case, int $id, int $actor ): array|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$task = $this->app->tasks->find( $id );

		return null === $task || $task['case_id'] !== $client_case->id ? Errors::not_found() : $task;
	}
}
