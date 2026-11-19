<?php
/**
 * Tests for MonthlyActivity.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\LedgerEntry;
use Diromax\Portal\Domain\MonthlyActivity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\MonthlyActivity
 */
final class MonthlyActivityTest extends TestCase {

	public function test_counts_only_entries_inside_the_month(): void {
		$entries = array(
			self::entry( EntryType::Expense, EntryStatus::Paid, '2026-10-31', 100000 ),
			self::entry( EntryType::Expense, EntryStatus::Paid, '2026-11-01', 250000 ),
			self::entry( EntryType::Expense, EntryStatus::Paid, '2026-11-30', 50000 ),
			self::entry( EntryType::Expense, EntryStatus::Paid, '2026-12-01', 900000 ),
			self::entry( EntryType::Expense, EntryStatus::Committed, '2026-11-12', 1200000 ),
			self::entry( EntryType::Payment, EntryStatus::Paid, '2026-11-05', 2000000 ),
			self::entry( EntryType::Request, EntryStatus::Open, '2026-11-20', 300000 ),
			self::entry( EntryType::Refund, EntryStatus::Paid, '2026-11-21', 10000 ),
			self::entry( EntryType::Expense, EntryStatus::Paid, '2026-11-15', 777700, true ),
		);

		$month = MonthlyActivity::from( $entries, '2026-11-01', '2026-12-01' );

		$this->assertSame( 2, $month->expenses );
		$this->assertSame( 300000, $month->expense_total );
		$this->assertSame( 1, $month->commitments );
		$this->assertSame( 1200000, $month->commitment_total );
		$this->assertSame( 1, $month->payments );
		$this->assertSame( 2000000, $month->payment_total );
		$this->assertSame( 1, $month->requests );
		$this->assertSame( 300000, $month->request_total );
		$this->assertFalse( $month->is_empty() );
	}

	public function test_an_empty_month(): void {
		$this->assertTrue( MonthlyActivity::from( array(), '2026-11-01', '2026-12-01' )->is_empty() );
	}

	private static function entry( EntryType $type, EntryStatus $status, string $date, int $total, bool $voided = false ): LedgerEntry {
		return new LedgerEntry(
			id: 1,
			case_id: 1,
			type: $type,
			status: $status,
			entry_date: $date,
			due_date: null,
			category: '',
			payee: '',
			description: 'x',
			net: $total,
			vat: 0,
			total: $total,
			funding: null,
			pay_to: null,
			visible: true,
			voided: $voided,
		);
	}
}
