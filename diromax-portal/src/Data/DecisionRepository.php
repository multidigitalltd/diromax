<?php
/**
 * Decisions table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\DecisionStatus;
use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Decisions the project manager puts to the client (spec 15.5).
 *
 * Every state change is a conditional UPDATE on status = 'pending', so an
 * answer can never overwrite another answer or a withdrawal.
 */
final class DecisionRepository {

	/** Selected columns. */
	private const COLUMNS = 'id, case_id, title, body, amount, document_id, due_date, status, fingerprint, answer_note, answered_by, answered_at, withdraw_reason, created_by, created_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Insert a pending decision.
	 *
	 * @param int                                                                                           $case_id Case.
	 * @param array{title:string, body:string, amount:int|null, document_id:int|null, due_date:string|null} $fields  Validated fields.
	 * @param int                                                                                           $user_id Author.
	 * @param string                                                                                        $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $case_id, array $fields, int $user_id, string $now ): int {
		$ok = $this->db->insert(
			Schema::table( 'decisions' ),
			array_merge(
				$fields,
				array(
					'case_id'    => $case_id,
					'status'     => DecisionStatus::Pending->value,
					'created_by' => $user_id,
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);

		return false === $ok ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Decision by ID.
	 *
	 * @param int $id Decision.
	 * @return DecisionRecord|null
	 */
	public function find( int $id ): ?DecisionRecord {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'decisions' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? DecisionRecord::from_row( $row ) : null;
	}

	/**
	 * Decisions of a case: pending first, then newest.
	 *
	 * @param int $case_id Case.
	 * @return DecisionRecord[]
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i WHERE case_id = %d ORDER BY status <> 'pending', created_at DESC, id DESC LIMIT 300",
				Schema::table( 'decisions' ),
				$case_id
			),
			ARRAY_A
		);

		return array_map( array( DecisionRecord::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Record the client's answer, only while still pending.
	 *
	 * @param int            $id          Decision.
	 * @param DecisionStatus $status      Approved or Declined.
	 * @param string         $note        The client's note.
	 * @param int            $user_id     Who answered.
	 * @param string         $fingerprint SHA-256 of what was shown.
	 * @param string         $now         UTC DATETIME.
	 * @return bool False when it was no longer pending.
	 */
	public function answer( int $id, DecisionStatus $status, string $note, int $user_id, string $fingerprint, string $now ): bool {
		return 1 === Db::write(
			$this->db,
			"UPDATE %i SET status = %s, answer_note = %s, answered_by = %d, answered_at = %s, fingerprint = %s, updated_at = %s WHERE id = %d AND status = 'pending'",
			Schema::table( 'decisions' ),
			$status->value,
			$note,
			$user_id,
			$now,
			$fingerprint,
			$now,
			$id
		);
	}

	/**
	 * Withdraw a pending decision.
	 *
	 * @param int    $id     Decision.
	 * @param string $reason Why.
	 * @param string $now    UTC DATETIME.
	 * @return bool False when it was no longer pending.
	 */
	public function withdraw( int $id, string $reason, string $now ): bool {
		return 1 === Db::write(
			$this->db,
			"UPDATE %i SET status = 'withdrawn', withdraw_reason = %s, updated_at = %s WHERE id = %d AND status = 'pending'",
			Schema::table( 'decisions' ),
			$reason,
			$now,
			$id
		);
	}

	/**
	 * Is the document attached to any decision?
	 *
	 * @param int $document_id Document.
	 * @return bool
	 */
	public function uses_document( int $document_id ): bool {
		return null !== $this->db->get_var(
			$this->db->prepare( 'SELECT 1 FROM %i WHERE document_id = %d LIMIT 1', Schema::table( 'decisions' ), $document_id )
		);
	}

	/**
	 * Decisions answered by a user (privacy export).
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, string|null>>
	 */
	public function answered_by( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT c.number, d.title, d.status, d.answer_note, d.answered_at FROM %i d INNER JOIN %i c ON c.id = d.case_id WHERE d.answered_by = %d ORDER BY d.answered_at',
				Schema::table( 'decisions' ),
				Schema::table( 'cases' ),
				$user_id
			),
			ARRAY_A
		);

		return (array) $rows;
	}
}
