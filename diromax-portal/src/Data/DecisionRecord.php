<?php
/**
 * Decision record.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\DecisionStatus;

defined( 'ABSPATH' ) || exit;

/**
 * One row of the decisions table.
 */
final readonly class DecisionRecord {

	/**
	 * Constructor.
	 *
	 * @param int            $id              ID.
	 * @param int            $case_id         Case.
	 * @param string         $title           Title.
	 * @param string         $body            What exactly is being decided.
	 * @param int|null       $amount          Amount including VAT, agorot.
	 * @param int|null       $document_id     Attached document.
	 * @param string|null    $due_date        Answer requested by, Y-m-d.
	 * @param DecisionStatus $status          Status.
	 * @param string         $fingerprint     SHA-256 of what was shown when answered.
	 * @param string         $answer_note     The client's note.
	 * @param int|null       $answered_by     Who answered.
	 * @param string|null    $answered_at     When, UTC.
	 * @param string         $withdraw_reason Why the manager withdrew it.
	 * @param int            $created_by      Author.
	 * @param string         $created_at      Created, UTC.
	 */
	public function __construct(
		public int $id,
		public int $case_id,
		public string $title,
		public string $body,
		public ?int $amount,
		public ?int $document_id,
		public ?string $due_date,
		public DecisionStatus $status,
		public string $fingerprint,
		public string $answer_note,
		public ?int $answered_by,
		public ?string $answered_at,
		public string $withdraw_reason,
		public int $created_by,
		public string $created_at,
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			case_id: (int) $row['case_id'],
			title: (string) $row['title'],
			body: (string) $row['body'],
			amount: null === $row['amount'] ? null : (int) $row['amount'],
			document_id: null === $row['document_id'] ? null : (int) $row['document_id'],
			due_date: $row['due_date'] ?? null,
			status: DecisionStatus::tryFrom( (string) $row['status'] ) ?? DecisionStatus::Withdrawn,
			fingerprint: (string) $row['fingerprint'],
			answer_note: (string) $row['answer_note'],
			answered_by: null === $row['answered_by'] ? null : (int) $row['answered_by'],
			answered_at: $row['answered_at'] ?? null,
			withdraw_reason: (string) $row['withdraw_reason'],
			created_by: (int) $row['created_by'],
			created_at: (string) $row['created_at'],
		);
	}

	/**
	 * Still waiting for the client?
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return DecisionStatus::Pending === $this->status;
	}
}
