<?php
/**
 * Cases table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\CaseStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cases. No authorization here: callers check AccessPolicy.
 */
final class CaseRepository {

	/** Columns returned by every read. */
	private const COLUMNS = 'id, public_id, number, title, property_type, track, scope, fee_terms, signed_on, manager_id, status, stage, stages, summary, created_at, closed_at, lead_id, plan_base, schedule_visible';

	/** COLUMNS with the c. alias, for joins. */
	private const CASE_COLUMNS = 'c.id, c.public_id, c.number, c.title, c.property_type, c.track, c.scope, c.fee_terms, c.signed_on, c.manager_id, c.status, c.stage, c.stages, c.summary, c.created_at, c.closed_at, c.lead_id, c.plan_base, c.schedule_visible';

	/** Columns update() may write. */
	private const WRITABLE = array( 'title', 'property_type', 'track', 'scope', 'fee_terms', 'signed_on', 'manager_id', 'status', 'stage', 'stages', 'closed_at', 'lead_id', 'plan_base', 'schedule_visible' );

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Case by ID.
	 *
	 * @param int $id ID.
	 * @return CaseRecord|null
	 */
	public function find( int $id ): ?CaseRecord {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'cases' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? CaseRecord::from_row( $row ) : null;
	}

	/**
	 * Case by public identifier.
	 *
	 * @param string $public_id Public ID.
	 * @return CaseRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?CaseRecord {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE public_id = %s', Schema::table( 'cases' ), $public_id ),
			ARRAY_A
		);

		return is_array( $row ) ? CaseRecord::from_row( $row ) : null;
	}

	/**
	 * Insert a case.
	 *
	 * @param array<string, string|int|null> $fields    Validated fields (title, property_type, track, scope, fee_terms, signed_on, manager_id).
	 * @param string                         $public_id Public ID.
	 * @param string                         $now       UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( array $fields, string $public_id, string $now ): int {
		$data = array_merge(
			array_intersect_key( $fields, array_flip( self::WRITABLE ) ),
			array(
				'public_id'  => $public_id,
				'number'     => $public_id,
				'status'     => CaseStatus::Active->value,
				'stage'      => 1,
				'stages'     => (string) wp_json_encode( CaseRecord::default_stages() ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		if ( false === $this->db->insert( Schema::table( 'cases' ), $data ) ) {
			return 0;
		}

		$id = (int) $this->db->insert_id;

		// The human number derives from the ID, so it is unique without a counter.
		$this->db->update(
			Schema::table( 'cases' ),
			array( 'number' => substr( $now, 0, 4 ) . '-' . str_pad( (string) $id, 3, '0', STR_PAD_LEFT ) ),
			array( 'id' => $id )
		);

		return $id;
	}

	/**
	 * Update allowed columns.
	 *
	 * @param int                            $id     ID.
	 * @param array<string, string|int|null> $fields Column => value; unknown columns are dropped.
	 * @param string                         $now    UTC DATETIME.
	 * @return bool
	 */
	public function update( int $id, array $fields, string $now ): bool {
		$data               = array_intersect_key( $fields, array_flip( self::WRITABLE ) );
		$data['updated_at'] = $now;

		return false !== $this->db->update( Schema::table( 'cases' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Store the cached manager summary.
	 *
	 * @param int                  $id      ID.
	 * @param array<string, mixed> $summary Summary array.
	 */
	public function save_summary( int $id, array $summary ): void {
		$this->db->update( Schema::table( 'cases' ), array( 'summary' => (string) wp_json_encode( $summary ) ), array( 'id' => $id ) );
	}

	/**
	 * Delete a case row; foreign keys remove its dependants.
	 *
	 * @param int $id ID.
	 */
	public function delete( int $id ): void {
		$this->db->delete( Schema::table( 'cases' ), array( 'id' => $id ) );
	}

	/**
	 * Paged list for the admin screen.
	 *
	 * @param int|null $manager_id Only this manager's cases; null for all.
	 * @param string   $status     Status filter, '' for all.
	 * @param string   $search     Free-text search on title and number.
	 * @param int      $page       Page, from 1.
	 * @param int      $per_page   Page size, 1–100.
	 * @return array{items: CaseRecord[], total: int}
	 */
	public function search( ?int $manager_id, string $status, string $search, int $page, int $per_page ): array {
		$where  = array( '1 = 1' );
		$params = array( Schema::table( 'cases' ) );

		if ( null !== $manager_id ) {
			$where[]  = 'manager_id = %d';
			$params[] = $manager_id;
		}

		if ( null !== CaseStatus::tryFrom( $status ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$like     = '%' . $this->db->esc_like( $search ) . '%';
			$where[]  = '(title LIKE %s OR number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$filter   = implode( ' AND ', $where );

		// $filter holds only the fixed fragments above; every value is a placeholder.
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE {$filter}", ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $this->db->get_results(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE {$filter} ORDER BY status = 'closed', updated_at DESC LIMIT %d OFFSET %d", ...array_merge( $params, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'items' => array_map( array( CaseRecord::class, 'from_row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Active cases for the dashboard, most recently changed first, with
	 * their cached summaries: every case for an administrator (null), only
	 * the manager's own otherwise.
	 *
	 * @param int|null $manager_id Manager, or null for all.
	 * @param int      $limit      Maximum rows, 1–500.
	 * @return CaseRecord[]
	 */
	public function active_for( ?int $manager_id, int $limit = 200 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i WHERE status = 'active' AND ( %d = 0 OR manager_id = %d ) ORDER BY updated_at DESC, id DESC LIMIT %d",
				Schema::table( 'cases' ),
				(int) $manager_id,
				(int) $manager_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		return array_map( array( CaseRecord::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Totals across every active case in scope, from the cached summaries,
	 * computed in the database so no list limit cuts them short.
	 *
	 * @param int|null $manager_id Manager, or null for all.
	 * @return array{cases:int, budget:int, cost:int, committed:int, open:int, open_count:int}
	 */
	public function active_totals( ?int $manager_id ): array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT COUNT(*) AS cases,
				        COALESCE(SUM(CAST(JSON_EXTRACT(summary, '$.budget') AS SIGNED)), 0) AS budget,
				        COALESCE(SUM(CAST(JSON_EXTRACT(summary, '$.cost_to_date') AS SIGNED)), 0) AS cost,
				        COALESCE(SUM(CAST(JSON_EXTRACT(summary, '$.committed') AS SIGNED)), 0) AS committed,
				        COALESCE(SUM(CAST(JSON_EXTRACT(summary, '$.open_requests') AS SIGNED)), 0) AS open,
				        COALESCE(SUM(CAST(JSON_EXTRACT(summary, '$.open_request_count') AS SIGNED)), 0) AS open_count
				   FROM %i WHERE status = 'active' AND ( %d = 0 OR manager_id = %d )",
				Schema::table( 'cases' ),
				(int) $manager_id,
				(int) $manager_id
			),
			ARRAY_A
		);

		return array(
			'cases'      => (int) ( $row['cases'] ?? 0 ),
			'budget'     => (int) ( $row['budget'] ?? 0 ),
			'cost'       => (int) ( $row['cost'] ?? 0 ),
			'committed'  => (int) ( $row['committed'] ?? 0 ),
			'open'       => (int) ( $row['open'] ?? 0 ),
			'open_count' => (int) ( $row['open_count'] ?? 0 ),
		);
	}

	/**
	 * Does the user see any case at all, in any status?
	 *
	 * @param int|null $manager_id Manager, or null for all.
	 * @return bool
	 */
	public function any_for( ?int $manager_id ): bool {
		return null !== $this->db->get_var(
			$this->db->prepare(
				'SELECT id FROM %i WHERE ( %d = 0 OR manager_id = %d ) LIMIT 1',
				Schema::table( 'cases' ),
				(int) $manager_id,
				(int) $manager_id
			)
		);
	}

	/**
	 * Every active case (monthly summary).
	 *
	 * @return CaseRecord[]
	 */
	public function active(): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE status = 'active' ORDER BY id LIMIT 2000", Schema::table( 'cases' ) ),
			ARRAY_A
		);

		return array_map( array( CaseRecord::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Cases a user is a member of, newest first.
	 *
	 * @param int $user_id User.
	 * @return CaseRecord[]
	 */
	public function for_member( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::CASE_COLUMNS . " FROM %i c INNER JOIN %i m ON m.case_id = c.id WHERE m.user_id = %d ORDER BY c.status = 'closed', c.updated_at DESC LIMIT 50",
				Schema::table( 'cases' ),
				Schema::table( 'case_members' ),
				$user_id
			),
			ARRAY_A
		);

		return array_map( array( CaseRecord::class, 'from_row' ), (array) $rows );
	}
}
