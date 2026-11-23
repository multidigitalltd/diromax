<?php
/**
 * Timeline table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The timeline of each case (spec 22): one "stage" row per stage that has a
 * planned start, the date it was first shown to the client, or a note
 * (its planned end is the stage's due date in the case), and one
 * "milestone" row per milestone.
 *
 * @phpstan-type ScheduleRow array{id:int, case_id:int, kind:string, stage:int, label:string, starts_on:string|null, ends_on:string|null, first_ends_on:string|null, note:string, done_on:string|null, sort:int}
 */
final class ScheduleRepository {

	/** Columns read back. */
	private const COLUMNS = 'id, case_id, kind, stage, label, starts_on, ends_on, first_ends_on, note, done_on, sort';

	/** Columns a caller may write. */
	private const WRITABLE = array( 'stage', 'label', 'starts_on', 'ends_on', 'first_ends_on', 'note', 'done_on' );

	/** Most milestones in one case. */
	public const MAX_MILESTONES = 60;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Every row of a case: by stage, the stage's own row first, then its
	 * milestones by date.
	 *
	 * @param int $case_id Case.
	 * @return ScheduleRow[]
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i WHERE case_id = %d ORDER BY stage, kind = 'milestone', ends_on IS NULL, ends_on, sort, id LIMIT 200",
				Schema::table( 'schedule' ),
				$case_id
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * One row.
	 *
	 * @param int $id Row.
	 * @return ScheduleRow|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'schedule' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Add a row.
	 *
	 * @param int                  $case_id Case.
	 * @param string               $kind    stage or milestone.
	 * @param array<string, mixed> $fields  Writable columns.
	 * @param string               $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $case_id, string $kind, array $fields, string $now ): int {
		$row = array_intersect_key( $fields, array_flip( self::WRITABLE ) ) + array(
			'case_id'    => $case_id,
			'kind'       => $kind,
			'created_at' => $now,
			'updated_at' => $now,
		);

		return false === $this->db->insert( Schema::table( 'schedule' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Change a row.
	 *
	 * @param int                  $id     Row.
	 * @param array<string, mixed> $fields Writable columns.
	 * @param string               $now    UTC DATETIME.
	 * @return bool
	 */
	public function update( int $id, array $fields, string $now ): bool {
		$fields = array_intersect_key( $fields, array_flip( self::WRITABLE ) );

		return false !== $this->db->update( Schema::table( 'schedule' ), $fields + array( 'updated_at' => $now ), array( 'id' => $id ) );
	}

	/**
	 * Delete a milestone.
	 *
	 * @param int $id Row.
	 */
	public function delete_milestone( int $id ): void {
		$this->db->delete(
			Schema::table( 'schedule' ),
			array(
				'id'   => $id,
				'kind' => 'milestone',
			)
		);
	}

	/**
	 * Milestones in a case.
	 *
	 * @param int $case_id Case.
	 * @return int
	 */
	public function count_milestones( int $case_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE case_id = %d AND kind = 'milestone'", Schema::table( 'schedule' ), $case_id ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return ScheduleRow
	 */
	private static function map( array $row ): array {
		$date = static fn( ?string $value ): ?string => null === $value || '' === $value ? null : $value;

		return array(
			'id'            => (int) $row['id'],
			'case_id'       => (int) $row['case_id'],
			'kind'          => (string) $row['kind'],
			'stage'         => (int) $row['stage'],
			'label'         => (string) $row['label'],
			'starts_on'     => $date( $row['starts_on'] ),
			'ends_on'       => $date( $row['ends_on'] ),
			'first_ends_on' => $date( $row['first_ends_on'] ),
			'note'          => (string) $row['note'],
			'done_on'       => $date( $row['done_on'] ),
			'sort'          => (int) $row['sort'],
		);
	}
}
