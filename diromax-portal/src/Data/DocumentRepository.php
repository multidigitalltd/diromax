<?php
/**
 * Documents table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Document metadata. File bytes are handled by Security\DocumentStore.
 */
final class DocumentRepository {

	/** Columns returned by every read. */
	private const COLUMNS = 'id, public_id, case_id, update_id, kind, title, original_name, stored_name, mime, size, sha256, key_version, visible, created_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Insert metadata for a stored file.
	 *
	 * @param array{public_id:string, case_id:int, update_id:int|null, kind:string, title:string, original_name:string, stored_name:string, mime:string, size:int, sha256:string, key_version:int, visible:bool} $doc Fields.
	 * @param int                                                                                                                                                                                                $user_id Uploader.
	 * @param string                                                                                                                                                                                             $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( array $doc, int $user_id, string $now ): int {
		$doc['visible']     = $doc['visible'] ? 1 : 0;
		$doc['uploaded_by'] = $user_id;
		$doc['created_at']  = $now;

		return false === $this->db->insert( Schema::table( 'documents' ), $doc ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Document by ID.
	 *
	 * @param int $id ID.
	 * @return DocumentRecord|null
	 */
	public function find( int $id ): ?DocumentRecord {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'documents' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? DocumentRecord::from_row( $row ) : null;
	}

	/**
	 * Document by public identifier.
	 *
	 * @param string $public_id Public ID.
	 * @return DocumentRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?DocumentRecord {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE public_id = %s', Schema::table( 'documents' ), $public_id ),
			ARRAY_A
		);

		return is_array( $row ) ? DocumentRecord::from_row( $row ) : null;
	}

	/**
	 * Documents of a case, newest first.
	 *
	 * @param int  $case_id      Case.
	 * @param bool $visible_only Only those shown to the client.
	 * @return DocumentRecord[]
	 */
	public function for_case( int $case_id, bool $visible_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE case_id = %d AND visible >= %d ORDER BY created_at DESC, id DESC LIMIT 1000',
				Schema::table( 'documents' ),
				$case_id,
				$visible_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( DocumentRecord::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Stored file names of every document of a case (for deleting the files).
	 *
	 * @param int $case_id Case.
	 * @return string[]
	 */
	public function stored_names( int $case_id ): array {
		$names = $this->db->get_col(
			$this->db->prepare( 'SELECT stored_name FROM %i WHERE case_id = %d', Schema::table( 'documents' ), $case_id )
		);

		return array_map( 'strval', (array) $names );
	}

	/**
	 * Show or hide a document from the client.
	 *
	 * @param int  $id      Document.
	 * @param bool $visible Visibility.
	 */
	public function set_visible( int $id, bool $visible ): void {
		$this->db->update( Schema::table( 'documents' ), array( 'visible' => $visible ? 1 : 0 ), array( 'id' => $id ) );
	}

	/**
	 * Delete metadata.
	 *
	 * @param int $id Document.
	 */
	public function delete( int $id ): void {
		$this->db->delete( Schema::table( 'documents' ), array( 'id' => $id ) );
	}
}
