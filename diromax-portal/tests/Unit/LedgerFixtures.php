<?php
/**
 * Ledger fixtures shared by the unit tests.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Funding;
use Diromax\Portal\Domain\LedgerEntry;
use Diromax\Portal\Domain\PayTo;

/**
 * Builds entries; the sample case is the one in spec section 5.
 */
final class LedgerFixtures {

	/**
	 * One entry with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Constructor arguments to override.
	 */
	public static function entry( array $overrides = array() ): LedgerEntry {
		$args = array_merge(
			array(
				'id'          => 1,
				'case_id'     => 1,
				'type'        => EntryType::Expense,
				'status'      => EntryStatus::Paid,
				'entry_date'  => '2026-11-12',
				'due_date'    => null,
				'category'    => 'fees',
				'payee'       => 'Supplier',
				'description' => 'Item',
				'net'         => 0,
				'vat'         => 0,
				'total'       => 100,
				'funding'     => Funding::Client,
				'pay_to'      => null,
				'visible'     => true,
				'voided'      => false,
			),
			$overrides
		);

		return new LedgerEntry( ...$args );
	}

	/**
	 * The worked example from spec section 5.
	 *
	 * @return list<LedgerEntry>
	 */
	public static function spec_example(): array {
		return array(
			self::entry(
				array(
					'id'         => 1,
					'entry_date' => '2026-07-11',
					'category'   => 'consultants',
					'total'      => 450000,
					'funding'    => Funding::Client,
				)
			),
			self::entry(
				array(
					'id'         => 2,
					'entry_date' => '2026-07-20',
					'type'       => EntryType::Payment,
					'category'   => '',
					'total'      => 6000000,
					'funding'    => null,
				)
			),
			self::entry(
				array(
					'id'         => 3,
					'entry_date' => '2026-08-02',
					'category'   => 'architecture',
					'total'      => 3800000,
					'funding'    => Funding::Project,
				)
			),
			self::entry(
				array(
					'id'         => 4,
					'entry_date' => '2026-09-15',
					'category'   => 'management',
					'total'      => 2400000,
					'funding'    => Funding::Proceeds,
				)
			),
			self::entry(
				array(
					'id'         => 5,
					'entry_date' => '2026-10-28',
					'status'     => EntryStatus::Committed,
					'category'   => 'contractor',
					'total'      => 4500000,
					'funding'    => Funding::Project,
				)
			),
			self::entry(
				array(
					'id'         => 6,
					'entry_date' => '2026-11-05',
					'type'       => EntryType::Request,
					'status'     => EntryStatus::Open,
					'due_date'   => '2026-12-01',
					'category'   => '',
					'total'      => 4000000,
					'funding'    => null,
					'pay_to'     => PayTo::Diromax,
				)
			),
			self::entry(
				array(
					'id'         => 7,
					'entry_date' => '2026-11-12',
					'category'   => 'fees',
					'total'      => 1230000,
					'funding'    => Funding::Project,
				)
			),
		);
	}

	/**
	 * Budget lines of the sample case (480,000 ₪).
	 *
	 * @return array<string, int>
	 */
	public static function spec_budget(): array {
		return array(
			'architecture' => 5500000,
			'consultants'  => 1800000,
			'fees'         => 4200000,
			'contractor'   => 29000000,
			'management'   => 4800000,
			'reserve'      => 2700000,
		);
	}
}
