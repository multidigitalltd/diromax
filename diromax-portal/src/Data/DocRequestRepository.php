<?php
/**
 * Document requests table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * What the project manager asked the clients to upload (spec 16.4): open
 * until a client uploads it ("done") or the manager cancels it.
 *
 * @phpstan-type DocRequest array{id:int, case_id:int, title:string, note:string, status:string, document_id:int|null, created_by:int, created_at:string, closed_at:string|null}
 */
final class DocRequestRepository {

	/** Columns read back. */
	private const COLUMNS = 'id, case_id, title, note, status, document_id, created_by, created_at, closed_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Add a request.
	 *
	 * @param int    $case_id Case.
	 * @param string $title   What is needed.
	 * @param string $note    Explanation.
	 * @param int    $actor   Manager.
	 * @param string $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $case_id, string $title, string $note, int $actor, string $now ): int {
		$ok = $this->db->insert(
			Schema::table( 'doc_requests' ),
			array(
				'case_id'    => $case_id,
				'title'      => $title,
				'note'       => $note,
				'created_by' => $actor,
				'created_at' => $now,
			)
		);

		return false === $ok ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * One request.
	 *
	 * @param int $id Request.
	 * @return DocRequest|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'doc_requests' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Requests of a case, open first, newest first.
	 *
	 * @param int  $case_id   Case.
	 * @param bool $open_only Only open requests.
	 * @return DocRequest[]
	 */
	public function for_case( int $case_id, bool $open_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i WHERE case_id = %d AND ( %d = 0 OR status = 'open' ) ORDER BY status = 'open' DESC, id DESC LIMIT 200",
				Schema::table( 'doc_requests' ),
				$case_id,
				$open_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Close an open request: done with a document, or cancelled. Only one
	 * close wins, so a request is never fulfilled twice.
	 *
	 * @param int      $id          Request.
	 * @param string   $status      done or cancelled.
	 * @param int|null $document_id Document that answered it.
	 * @param string   $now         UTC DATETIME.
	 * @return bool Whether this call closed it.
	 */
	public function close( int $id, string $status, ?int $document_id, string $now ): bool {
		$changed = null === $document_id
			? Db::write( $this->db, "UPDATE %i SET status = %s, document_id = NULL, closed_at = %s WHERE id = %d AND status = 'open'", Schema::table( 'doc_requests' ), $status, $now, $id )
			: Db::write( $this->db, "UPDATE %i SET status = %s, document_id = %d, closed_at = %s WHERE id = %d AND status = 'open'", Schema::table( 'doc_requests' ), $status, $document_id, $now, $id );

		return 1 === $changed;
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return DocRequest
	 */
	private static function map( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'case_id'     => (int) $row['case_id'],
			'title'       => (string) $row['title'],
			'note'        => (string) $row['note'],
			'status'      => (string) $row['status'],
			'document_id' => null === $row['document_id'] ? null : (int) $row['document_id'],
			'created_by'  => (int) $row['created_by'],
			'created_at'  => (string) $row['created_at'],
			'closed_at'   => null === $row['closed_at'] ? null : (string) $row['closed_at'],
		);
	}
}
