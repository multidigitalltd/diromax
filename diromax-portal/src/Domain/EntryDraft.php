<?php
/**
 * Validated ledger entry, ready to store.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Output of EntryValidator. Every field is already checked and normalised;
 * the repository writes it as is.
 */
final readonly class EntryDraft {

	/**
	 * Constructor.
	 *
	 * @param EntryType    $type            Type.
	 * @param EntryStatus  $status          Status.
	 * @param string       $entry_date      Y-m-d.
	 * @param string|null  $due_date        Y-m-d, requests only.
	 * @param string       $category        Budget category key or ''.
	 * @param string       $payee           Supplier / payee.
	 * @param string       $description     Description.
	 * @param int          $net             Net, agorot.
	 * @param int          $vat             VAT, agorot.
	 * @param int          $total           Total, agorot.
	 * @param int          $vat_rate        VAT rate used, basis points.
	 * @param Funding|null $funding         Funding source, expenses only.
	 * @param PayTo|null   $pay_to          Addressee, requests only.
	 * @param string       $method          Payment method, payments only.
	 * @param string       $reference       Reference (transfer number, cheque).
	 * @param string       $payment_details How to pay, requests only.
	 * @param string       $internal_note   Never shown to the client.
	 * @param bool         $visible         Shown to the client.
	 * @param bool         $notify          Email the client about it.
	 */
	public function __construct(
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
		public int $vat_rate,
		public ?Funding $funding,
		public ?PayTo $pay_to,
		public string $method,
		public string $reference,
		public string $payment_details,
		public string $internal_note,
		public bool $visible,
		public bool $notify,
	) {}
}
