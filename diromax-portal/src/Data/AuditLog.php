<?php
/**
 * Audit log.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Who did what, when (standard 4.11.7, 15.1.4).
 *
 * Every change to a case, its money or its documents, every document download,
 * every settings change and every portal sign-in is recorded. Details hold IDs,
 * codes and before/after values of changed fields — never tokens or passwords.
 */
final class AuditLog {

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Record an action by the current user.
	 *
	 * @param string               $action      Dotted action code, e.g. ledger.created.
	 * @param int|null             $case_id     Case, when the action concerns one.
	 * @param string               $object_type Object type, e.g. ledger.
	 * @param int|null             $object_id   Object ID.
	 * @param array<string, mixed> $details     Changed fields and context.
	 * @param string               $now         UTC DATETIME.
	 */
	public function record( string $action, ?int $case_id, string $object_type, ?int $object_id, array $details, string $now ): void {
		$ok = $this->db->insert(
			Schema::table( 'audit' ),
			array(
				'case_id'     => $case_id,
				'user_id'     => get_current_user_id(),
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'details'     => array() === $details ? null : (string) wp_json_encode( $details ),
				'created_at'  => $now,
			)
		);

		if ( false === $ok ) {
			Logger::error( 'audit.write_failed', array( 'action' => $action ) );
		}
	}

	/**
	 * Recent entries of a case, newest first, with the actor's name.
	 *
	 * @param int $case_id Case.
	 * @param int $limit   Maximum rows, 1–500.
	 * @return array<int, array{action:string, object_type:string, object_id:int|null, details:array<string, mixed>, created_at:string, user:string}>
	 */
	public function for_case( int $case_id, int $limit = 200 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT a.action, a.object_type, a.object_id, a.details, a.created_at, u.display_name
				   FROM %i a LEFT JOIN %i u ON u.ID = a.user_id
				  WHERE a.case_id = %d ORDER BY a.id DESC LIMIT %d',
				Schema::table( 'audit' ),
				$this->db->users,
				$case_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		$entries = array();

		foreach ( (array) $rows as $row ) {
			$details   = json_decode( (string) $row['details'], true );
			$entries[] = array(
				'action'      => (string) $row['action'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => null === $row['object_id'] ? null : (int) $row['object_id'],
				'details'     => is_array( $details ) ? $details : array(),
				'created_at'  => (string) $row['created_at'],
				'user'        => (string) ( $row['display_name'] ?? '' ),
			);
		}

		return $entries;
	}

	/**
	 * Recent entries across cases, newest first: every case for an
	 * administrator (manager 0), only the manager's own cases otherwise.
	 *
	 * @param int $manager Manager user ID, or 0 for all cases.
	 * @param int $limit   Maximum rows, 1–100.
	 * @return array<int, array{action:string, case_id:int, case_title:string, number:string, created_at:string, user:string}>
	 */
	public function recent( int $manager, int $limit = 12 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT a.action, a.case_id, a.created_at, c.title, c.number, u.display_name
				   FROM %i a INNER JOIN %i c ON c.id = a.case_id LEFT JOIN %i u ON u.ID = a.user_id
				  WHERE ( %d = 0 OR c.manager_id = %d ) AND a.action <> %s
				  ORDER BY a.id DESC LIMIT %d',
				Schema::table( 'audit' ),
				Schema::table( 'cases' ),
				$this->db->users,
				$manager,
				$manager,
				'document.downloaded',
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'action'     => (string) $row['action'],
				'case_id'    => (int) $row['case_id'],
				'case_title' => (string) $row['title'],
				'number'     => (string) $row['number'],
				'created_at' => (string) $row['created_at'],
				'user'       => (string) ( $row['display_name'] ?? '' ),
			),
			(array) $rows
		);
	}

	/**
	 * Delete entries not tied to a case that are older than the cut-off (retention).
	 *
	 * @param string $before UTC DATETIME.
	 * @return int Rows deleted.
	 */
	public function purge_unattached( string $before ): int {
		return max( 0, Db::write( $this->db, 'DELETE FROM %i WHERE case_id IS NULL AND created_at < %s LIMIT 5000', Schema::table( 'audit' ), $before ) );
	}
}
