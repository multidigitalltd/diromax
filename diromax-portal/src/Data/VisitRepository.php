<?php
/**
 * Site visits and findings tables.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Site visits of each case and the findings noted on them (spec 23).
 *
 * @phpstan-type VisitRow array{id:int, case_id:int, visit_on:string, attendees:string, summary:string, visible:bool, created_by:int}
 * @phpstan-type FindingRow array{id:int, case_id:int, visit_id:int|null, title:string, location:string, severity:string, supplier:string, due_on:string|null, status:string, fixed_on:string|null, fix_note:string, visible:bool, photo_id:int|null, after_photo_id:int|null}
 */
final class VisitRepository {

	/** Visit columns read back. */
	private const VISIT_COLUMNS = 'id, case_id, visit_on, attendees, summary, visible, created_by';

	/** Finding columns read back. */
	private const FINDING_COLUMNS = 'f.id, f.case_id, f.visit_id, f.title, f.location, f.severity, f.supplier, f.due_on, f.status, f.fixed_on, f.fix_note, f.visible, f.photo_id, f.after_photo_id';

	/** Finding columns a caller may write. */
	private const FINDING_WRITABLE = array( 'title', 'location', 'severity', 'supplier', 'due_on', 'status', 'fixed_on', 'fix_note', 'visible', 'photo_id', 'after_photo_id' );

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Record a visit.
	 *
	 * @param int                                                                    $case_id Case.
	 * @param array{visit_on:string, attendees:string, summary:string, visible:bool} $fields  Fields.
	 * @param int                                                                    $author  Staff member.
	 * @param string                                                                 $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert_visit( int $case_id, array $fields, int $author, string $now ): int {
		$row = array(
			'case_id'    => $case_id,
			'visit_on'   => $fields['visit_on'],
			'attendees'  => $fields['attendees'],
			'summary'    => $fields['summary'],
			'visible'    => $fields['visible'] ? 1 : 0,
			'created_by' => $author,
			'created_at' => $now,
			'updated_at' => $now,
		);

		return false === $this->db->insert( Schema::table( 'visits' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Change a visit.
	 *
	 * @param int                                                                    $id     Visit.
	 * @param array{visit_on:string, attendees:string, summary:string, visible:bool} $fields Fields.
	 * @param string                                                                 $now    UTC DATETIME.
	 * @return bool
	 */
	public function update_visit( int $id, array $fields, string $now ): bool {
		return false !== $this->db->update(
			Schema::table( 'visits' ),
			array(
				'visit_on'   => $fields['visit_on'],
				'attendees'  => $fields['attendees'],
				'summary'    => $fields['summary'],
				'visible'    => $fields['visible'] ? 1 : 0,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Delete a visit; its findings go with it (foreign key).
	 *
	 * @param int $id Visit.
	 */
	public function delete_visit( int $id ): void {
		$this->db->delete( Schema::table( 'visits' ), array( 'id' => $id ) );
	}

	/**
	 * One visit.
	 *
	 * @param int $id Visit.
	 * @return VisitRow|null
	 */
	public function find_visit( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::VISIT_COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'visits' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::visit_row( $row ) : null;
	}

	/**
	 * A case's visits, newest first.
	 *
	 * @param int  $case_id      Case.
	 * @param bool $visible_only Only those shown to the client.
	 * @return VisitRow[]
	 */
	public function visits_for( int $case_id, bool $visible_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::VISIT_COLUMNS . ' FROM %i WHERE case_id = %d AND visible >= %d ORDER BY visit_on DESC, id DESC LIMIT 200',
				Schema::table( 'visits' ),
				$case_id,
				$visible_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'visit_row' ), (array) $rows );
	}

	/**
	 * Record a finding.
	 *
	 * @param int                  $case_id  Case.
	 * @param int                  $visit_id Visit.
	 * @param array<string, mixed> $fields   Writable columns.
	 * @param string               $now      UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert_finding( int $case_id, int $visit_id, array $fields, string $now ): int {
		$row = self::finding_fields( $fields ) + array(
			'case_id'    => $case_id,
			'visit_id'   => $visit_id,
			'status'     => 'open',
			'created_at' => $now,
			'updated_at' => $now,
		);

		return false === $this->db->insert( Schema::table( 'findings' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Change a finding.
	 *
	 * @param int                  $id     Finding.
	 * @param array<string, mixed> $fields Writable columns.
	 * @param string               $now    UTC DATETIME.
	 * @return bool
	 */
	public function update_finding( int $id, array $fields, string $now ): bool {
		return false !== $this->db->update( Schema::table( 'findings' ), self::finding_fields( $fields ) + array( 'updated_at' => $now ), array( 'id' => $id ) );
	}

	/**
	 * Delete a finding.
	 *
	 * @param int $id Finding.
	 */
	public function delete_finding( int $id ): void {
		$this->db->delete( Schema::table( 'findings' ), array( 'id' => $id ) );
	}

	/**
	 * One finding.
	 *
	 * @param int $id Finding.
	 * @return FindingRow|null
	 */
	public function find_finding( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::FINDING_COLUMNS . ' FROM %i f WHERE f.id = %d', Schema::table( 'findings' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::finding_row( $row ) : null;
	}

	/**
	 * A case's findings: open first, by due date, then fixed ones.
	 *
	 * @param int  $case_id      Case.
	 * @param bool $visible_only Only those shown to the client, in visits shown to the client.
	 * @return FindingRow[]
	 */
	public function findings_for( int $case_id, bool $visible_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::FINDING_COLUMNS . " FROM %i f INNER JOIN %i v ON v.id = f.visit_id
				  WHERE f.case_id = %d AND f.visible >= %d AND v.visible >= %d
				  ORDER BY f.status = 'fixed', f.due_on IS NULL, f.due_on, f.id LIMIT 500",
				Schema::table( 'findings' ),
				Schema::table( 'visits' ),
				$case_id,
				$visible_only ? 1 : 0,
				$visible_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'finding_row' ), (array) $rows );
	}

	/**
	 * Open findings past their due date, in active cases, with their case.
	 *
	 * @param int|null $manager Only this manager's cases; null for all.
	 * @param string   $today   Y-m-d.
	 * @return array<int, array{finding:FindingRow, case_title:string}>
	 */
	public function overdue( ?int $manager, string $today ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::FINDING_COLUMNS . ", c.title AS case_title FROM %i f INNER JOIN %i c ON c.id = f.case_id
				  WHERE f.status = 'open' AND f.due_on < %s AND c.status = 'active' AND (%d = 0 OR c.manager_id = %d)
				  ORDER BY f.due_on, f.id LIMIT 200",
				Schema::table( 'findings' ),
				Schema::table( 'cases' ),
				$today,
				(int) $manager,
				(int) $manager
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'finding'    => self::finding_row( $row ),
				'case_title' => (string) $row['case_title'],
			),
			(array) $rows
		);
	}

	/**
	 * Open findings in a case.
	 *
	 * @param int $case_id Case.
	 * @return int
	 */
	public function count_open( int $case_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE case_id = %d AND status = 'open'", Schema::table( 'findings' ), $case_id ) );
	}

	/**
	 * Writable finding columns as stored.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private static function finding_fields( array $fields ): array {
		$fields = array_intersect_key( $fields, array_flip( self::FINDING_WRITABLE ) );

		if ( array_key_exists( 'visible', $fields ) ) {
			$fields['visible'] = $fields['visible'] ? 1 : 0;
		}

		return $fields;
	}

	/**
	 * Typed visit.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return VisitRow
	 */
	private static function visit_row( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'case_id'    => (int) $row['case_id'],
			'visit_on'   => (string) $row['visit_on'],
			'attendees'  => (string) $row['attendees'],
			'summary'    => (string) $row['summary'],
			'visible'    => '1' === (string) $row['visible'],
			'created_by' => (int) $row['created_by'],
		);
	}

	/**
	 * Typed finding.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return FindingRow
	 */
	private static function finding_row( array $row ): array {
		$id   = static fn( ?string $value ): ?int => null === $value || '' === $value ? null : (int) $value;
		$date = static fn( ?string $value ): ?string => null === $value || '' === $value ? null : $value;

		return array(
			'id'             => (int) $row['id'],
			'case_id'        => (int) $row['case_id'],
			'visit_id'       => $id( $row['visit_id'] ),
			'title'          => (string) $row['title'],
			'location'       => (string) $row['location'],
			'severity'       => (string) $row['severity'],
			'supplier'       => (string) $row['supplier'],
			'due_on'         => $date( $row['due_on'] ),
			'status'         => (string) $row['status'],
			'fixed_on'       => $date( $row['fixed_on'] ),
			'fix_note'       => (string) $row['fix_note'],
			'visible'        => '1' === (string) $row['visible'],
			'photo_id'       => $id( $row['photo_id'] ),
			'after_photo_id' => $id( $row['after_photo_id'] ),
		);
	}
}
