<?php
/**
 * Team tasks table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Internal to-dos of a case (spec 16.2): a title, someone on staff who
 * owns it, and an optional due date. Clients never see them.
 *
 * @phpstan-type TaskRow array{id:int, case_id:int, title:string, assignee_id:int, due_date:string|null, done_at:string|null, done_by:int|null, created_by:int, created_at:string}
 * @phpstan-type OpenTask array{id:int, case_id:int, title:string, assignee_id:int, due_date:string|null, case_title:string, number:string}
 */
final class TaskRepository {

	/** Columns read back. */
	private const COLUMNS = 'id, case_id, title, assignee_id, due_date, done_at, done_by, created_by, created_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Add a task.
	 *
	 * @param int         $case_id  Case.
	 * @param string      $title    Title.
	 * @param int         $assignee Staff member who owns it.
	 * @param string|null $due      Y-m-d, or null.
	 * @param int         $actor    Who added it.
	 * @param string      $now      UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $case_id, string $title, int $assignee, ?string $due, int $actor, string $now ): int {
		$ok = $this->db->insert(
			Schema::table( 'tasks' ),
			array(
				'case_id'     => $case_id,
				'title'       => $title,
				'assignee_id' => $assignee,
				'due_date'    => $due,
				'created_by'  => $actor,
				'created_at'  => $now,
			)
		);

		return false === $ok ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * One task.
	 *
	 * @param int $id Task.
	 * @return TaskRow|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'tasks' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Tasks of a case: open ones first, by due date (none last), then done ones, newest first.
	 *
	 * @param int $case_id Case.
	 * @return TaskRow[]
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE case_id = %d ORDER BY done_at IS NOT NULL, done_at DESC, due_date IS NULL, due_date, id LIMIT 500',
				Schema::table( 'tasks' ),
				$case_id
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Open tasks in a case.
	 *
	 * @param int $case_id Case.
	 * @return int
	 */
	public function open_count( int $case_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d AND done_at IS NULL', Schema::table( 'tasks' ), $case_id )
		);
	}

	/**
	 * Open tasks owned by a user in active cases, overdue first, then by due
	 * date, undated last. With $until, only those due by that day.
	 *
	 * @param int         $user_id Staff member.
	 * @param string|null $until   Y-m-d, or null for every open task.
	 * @param int         $limit   Maximum rows.
	 * @return OpenTask[]
	 */
	public function open_for( int $user_id, ?string $until = null, int $limit = 50 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT t.id, t.case_id, t.title, t.assignee_id, t.due_date, c.title AS case_title, c.number
				FROM %i t INNER JOIN %i c ON c.id = t.case_id
				WHERE t.assignee_id = %d AND t.done_at IS NULL AND c.status = 'active' AND ( %s = '' OR ( t.due_date IS NOT NULL AND t.due_date <= %s ) )
				ORDER BY t.due_date IS NULL, t.due_date, t.id LIMIT %d",
				Schema::table( 'tasks' ),
				Schema::table( 'cases' ),
				$user_id,
				(string) $until,
				(string) $until,
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'case_id'     => (int) $row['case_id'],
				'title'       => (string) $row['title'],
				'assignee_id' => (int) $row['assignee_id'],
				'due_date'    => null === $row['due_date'] ? null : (string) $row['due_date'],
				'case_title'  => (string) $row['case_title'],
				'number'      => (string) $row['number'],
			),
			(array) $rows
		);
	}

	/**
	 * Mark done (with who and when) or open again (both null).
	 *
	 * @param int         $id      Task.
	 * @param string|null $done_at UTC DATETIME, or null to reopen.
	 * @param int|null    $done_by User, or null to reopen.
	 * @return bool
	 */
	public function set_done( int $id, ?string $done_at, ?int $done_by ): bool {
		return false !== $this->db->update(
			Schema::table( 'tasks' ),
			array(
				'done_at' => $done_at,
				'done_by' => $done_by,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Delete a task.
	 *
	 * @param int $id Task.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return false !== $this->db->delete( Schema::table( 'tasks' ), array( 'id' => $id ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return TaskRow
	 */
	private static function map( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'case_id'     => (int) $row['case_id'],
			'title'       => (string) $row['title'],
			'assignee_id' => (int) $row['assignee_id'],
			'due_date'    => null === $row['due_date'] ? null : (string) $row['due_date'],
			'done_at'     => null === $row['done_at'] ? null : (string) $row['done_at'],
			'done_by'     => null === $row['done_by'] ? null : (int) $row['done_by'],
			'created_by'  => (int) $row['created_by'],
			'created_at'  => (string) $row['created_at'],
		);
	}
}
