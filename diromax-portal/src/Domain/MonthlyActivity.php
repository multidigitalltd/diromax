<?php
/**
 * What happened in a case during one month.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Counts and totals of ledger entries dated inside a period, for the monthly
 * summary email (spec 15.4). Voided entries are left out.
 */
final readonly class MonthlyActivity {

	/**
	 * Constructor.
	 *
	 * @param int $expenses        Paid expenses.
	 * @param int $expense_total   Their total, agorot.
	 * @param int $commitments     New commitments.
	 * @param int $commitment_total Their total, agorot.
	 * @param int $payments        Payments received.
	 * @param int $payment_total   Their total, agorot.
	 * @param int $requests        Payment requests issued.
	 * @param int $request_total   Their total, agorot.
	 */
	public function __construct(
		public int $expenses = 0,
		public int $expense_total = 0,
		public int $commitments = 0,
		public int $commitment_total = 0,
		public int $payments = 0,
		public int $payment_total = 0,
		public int $requests = 0,
		public int $request_total = 0,
	) {}

	/**
	 * Tally the entries dated from $from (inclusive) to $to (exclusive).
	 *
	 * @param LedgerEntry[] $entries Entries.
	 * @param string        $from    First day, Y-m-d.
	 * @param string        $to      Day after the last, Y-m-d.
	 * @return self
	 */
	public static function from( array $entries, string $from, string $to ): self {
		$tally = array_fill_keys( array( 'expenses', 'expense_total', 'commitments', 'commitment_total', 'payments', 'payment_total', 'requests', 'request_total' ), 0 );

		foreach ( $entries as $entry ) {
			if ( $entry->voided || $entry->entry_date < $from || $entry->entry_date >= $to ) {
				continue;
			}

			$bucket = match ( $entry->type ) {
				EntryType::Expense => EntryStatus::Committed === $entry->status ? 'commitment' : 'expense',
				EntryType::Payment => 'payment',
				EntryType::Request => 'request',
				EntryType::Refund  => null,
			};

			if ( null !== $bucket ) {
				++$tally[ $bucket . 's' ];
				$tally[ $bucket . '_total' ] += $entry->total;
			}
		}

		return new self( ...$tally );
	}

	/**
	 * Nothing happened at all?
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->expenses + $this->commitments + $this->payments + $this->requests;
	}
}
