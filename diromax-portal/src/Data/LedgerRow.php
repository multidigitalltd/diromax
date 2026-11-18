<?php
/**
 * Full ledger row.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Funding;
use Diromax\Portal\Domain\LedgerEntry;
use Diromax\Portal\Domain\PayTo;

defined( 'ABSPATH' ) || exit;

/**
 * A ledger row with every column the screens use. The domain calculator gets
 * the slimmer LedgerEntry from to_entry().
 */
final readonly class LedgerRow {

	/**
	 * Constructor.
	 *
	 * @param LedgerEntry $entry           Calculation fields.
	 * @param int         $vat_rate        VAT rate used, basis points.
	 * @param string      $method          Payment method.
	 * @param string      $reference       Reference.
	 * @param string      $payment_details Payment details of a request.
	 * @param string      $internal_note   Internal note; never sent to the client.
	 * @param int         $created_by      Author.
	 * @param string      $created_at      UTC.
	 * @param string      $updated_at      UTC.
	 * @param string      $void_reason     Why it was voided.
	 */
	public function __construct(
		public LedgerEntry $entry,
		public int $vat_rate,
		public string $method,
		public string $reference,
		public string $payment_details,
		public string $internal_note,
		public int $created_by,
		public string $created_at,
		public string $updated_at,
		public string $void_reason,
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$voided_at   = $row['voided_at'] ?? null;
		$request_id  = $row['request_id'] ?? null;
		$document_id = $row['document_id'] ?? null;

		$entry = new LedgerEntry(
			id: (int) $row['id'],
			case_id: (int) $row['case_id'],
			type: EntryType::tryFrom( (string) $row['type'] ) ?? EntryType::Expense,
			status: EntryStatus::tryFrom( (string) $row['status'] ) ?? EntryStatus::Paid,
			entry_date: (string) $row['entry_date'],
			due_date: $row['due_date'] ?? null,
			category: (string) $row['category'],
			payee: (string) $row['payee'],
			description: (string) $row['description'],
			net: (int) $row['net'],
			vat: (int) $row['vat'],
			total: (int) $row['total'],
			funding: Funding::tryFrom( (string) $row['funding'] ),
			pay_to: PayTo::tryFrom( (string) $row['pay_to'] ),
			visible: '1' === (string) $row['visible'],
			voided: null !== $voided_at,
			request_id: null === $request_id ? null : (int) $request_id,
			document_id: null === $document_id ? null : (int) $document_id,
		);

		return new self(
			entry: $entry,
			vat_rate: (int) $row['vat_rate'],
			method: (string) $row['method'],
			reference: (string) $row['reference'],
			payment_details: (string) ( $row['payment_details'] ?? '' ),
			internal_note: (string) ( $row['internal_note'] ?? '' ),
			created_by: (int) $row['created_by'],
			created_at: (string) $row['created_at'],
			updated_at: (string) $row['updated_at'],
			void_reason: (string) $row['void_reason'],
		);
	}
}
