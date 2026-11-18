<?php
/**
 * Ledger entry value object.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One row of a case ledger, as the calculator and the views need it.
 *
 * Immutable. Built by the ledger repository from a database row; the domain
 * never sees raw rows.
 */
final readonly class LedgerEntry {

	/**
	 * Constructor.
	 *
	 * @param int          $id           Entry ID.
	 * @param int          $case_id      Case ID.
	 * @param EntryType    $type         Entry type.
	 * @param EntryStatus  $status       Entry status.
	 * @param string       $entry_date   Date of the entry, Y-m-d.
	 * @param string|null  $due_date     Due date for a request, Y-m-d.
	 * @param string       $category     Budget category key ('' when none).
	 * @param string       $payee        Supplier or payee name.
	 * @param string       $description  Short description shown to the client.
	 * @param int          $net          Net amount, agorot.
	 * @param int          $vat          VAT, agorot.
	 * @param int          $total        Total, agorot.
	 * @param Funding|null $funding      Funding source (expenses only).
	 * @param PayTo|null   $pay_to       Request addressee (requests only).
	 * @param bool         $visible      Shown to the client.
	 * @param bool         $voided       Cancelled; excluded from every total.
	 * @param int|null     $request_id   Request this entry settles, if any.
	 * @param int|null     $document_id  Attached document, if any.
	 */
	public function __construct(
		public int $id,
		public int $case_id,
		public EntryType $type,
		public EntryStatus $status,
		public string $entry_date,
		public ?string $due_date,
		public string $category,
		public string $payee,
		public string $description,
		public int $net,
		public int $vat,
		public int $total,
		public ?Funding $funding,
		public ?PayTo $pay_to,
		public bool $visible,
		public bool $voided,
		public ?int $request_id = null,
		public ?int $document_id = null,
	) {}

	/**
	 * Is this an open payment request?
	 *
	 * @return bool
	 */
	public function is_open_request(): bool {
		return EntryType::Request === $this->type && EntryStatus::Open === $this->status && ! $this->voided;
	}

	/**
	 * Is this an open request whose due date has passed?
	 *
	 * @param string $today Today, Y-m-d, in the site's time zone.
	 * @return bool
	 */
	public function is_overdue( string $today ): bool {
		return $this->is_open_request() && null !== $this->due_date && $this->due_date < $today;
	}
}
