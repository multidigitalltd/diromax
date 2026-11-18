<?php
/**
 * Agreement acknowledgements table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * "I have read the agreement" confirmations (spec 15.6): one per user and
 * document, with the SHA-256 of the file that was confirmed.
 */
final class AcknowledgementRepository {

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Record a confirmation; a second one by the same user is ignored.
	 *
	 * @param DocumentRecord $document Document.
	 * @param int            $user_id  User.
	 * @param string         $now      UTC DATETIME.
	 * @return bool True when this call recorded it.
	 */
	public function add( DocumentRecord $document, int $user_id, string $now ): bool {
		$inserted = Db::write(
			$this->db,
			'INSERT IGNORE INTO %i (document_id, user_id, case_id, sha256, created_at) VALUES (%d, %d, %d, %s, %s)',
			Schema::table( 'acknowledgements' ),
			$document->id,
			$user_id,
			$document->case_id,
			$document->sha256,
			$now
		);

		return 1 === $inserted;
	}

	/**
	 * Confirmations in a case, by document.
	 *
	 * @param int $case_id Case.
	 * @return array<int, array<int, array{user_id:int, name:string, sha256:string, created_at:string}>> Document ID => list.
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT a.document_id, a.user_id, a.sha256, a.created_at, u.display_name FROM %i a LEFT JOIN %i u ON u.ID = a.user_id WHERE a.case_id = %d ORDER BY a.created_at',
				Schema::table( 'acknowledgements' ),
				$this->db->users,
				$case_id
			),
			ARRAY_A
		);

		$list = array();

		foreach ( (array) $rows as $row ) {
			$list[ (int) $row['document_id'] ][] = array(
				'user_id'    => (int) $row['user_id'],
				'name'       => (string) ( $row['display_name'] ?? '' ),
				'sha256'     => (string) $row['sha256'],
				'created_at' => (string) $row['created_at'],
			);
		}

		return $list;
	}

	/**
	 * Has the user confirmed this document?
	 *
	 * @param int $document_id Document.
	 * @param int $user_id     User.
	 * @return bool
	 */
	public function exists( int $document_id, int $user_id ): bool {
		return null !== $this->db->get_var(
			$this->db->prepare( 'SELECT 1 FROM %i WHERE document_id = %d AND user_id = %d', Schema::table( 'acknowledgements' ), $document_id, $user_id )
		);
	}

	/**
	 * Confirmations by a user (privacy export).
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, string|null>>
	 */
	public function by_user( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT c.number, d.title, a.sha256, a.created_at FROM %i a INNER JOIN %i d ON d.id = a.document_id INNER JOIN %i c ON c.id = a.case_id WHERE a.user_id = %d ORDER BY a.created_at',
				Schema::table( 'acknowledgements' ),
				Schema::table( 'documents' ),
				Schema::table( 'cases' ),
				$user_id
			),
			ARRAY_A
		);

		return (array) $rows;
	}
}
