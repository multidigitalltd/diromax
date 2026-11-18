<?php
/**
 * Tests for SummaryCalculator, CaseSummary and LedgerEntry.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Funding;
use Diromax\Portal\Domain\SummaryCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\SummaryCalculator
 * @covers \Diromax\Portal\Domain\CaseSummary
 * @covers \Diromax\Portal\Domain\LedgerEntry
 */
final class SummaryCalculatorTest extends TestCase {

	public function test_spec_example_matches_to_the_agora(): void {
		$summary = SummaryCalculator::calculate( LedgerFixtures::spec_example(), LedgerFixtures::spec_budget(), '2026-11-18' );

		$this->assertSame( 48000000, $summary->budget );
		$this->assertSame( 7880000, $summary->cost_to_date );
		$this->assertSame( 4500000, $summary->committed );
		$this->assertSame( 12380000, $summary->forecast() );
		$this->assertSame( 1642, $summary->utilization_bps() );
		$this->assertSame( 2579, $summary->forecast_bps() );
		$this->assertSame( 970000, $summary->project_balance );
		$this->assertTrue( $summary->has_project_account );
		$this->assertSame( 2400000, $summary->deferred );
		$this->assertSame( 6000000, $summary->payments );
		$this->assertSame( 4000000, $summary->open_requests );
		$this->assertSame( 1, $summary->open_request_count );
		$this->assertSame( 0, $summary->overdue_count );
		$this->assertSame( 6, $summary->next_request_id );
	}

	public function test_category_lines_split_paid_and_committed(): void {
		$summary = SummaryCalculator::calculate( LedgerFixtures::spec_example(), LedgerFixtures::spec_budget(), '2026-11-18' );

		$this->assertSame(
			array(
				'planned'   => 5500000,
				'paid'      => 3800000,
				'committed' => 0,
			),
			$summary->categories['architecture']
		);
		$this->assertSame( 4500000, $summary->categories['contractor']['committed'] );
		$this->assertSame( 0, $summary->categories['reserve']['paid'] );
	}

	public function test_voided_entries_are_ignored_everywhere(): void {
		$entries   = LedgerFixtures::spec_example();
		$entries[] = LedgerFixtures::entry(
			array(
				'id'      => 8,
				'total'   => 999900,
				'funding' => Funding::Project,
				'voided'  => true,
			)
		);
		$entries[] = LedgerFixtures::entry(
			array(
				'id'       => 9,
				'type'     => EntryType::Request,
				'status'   => EntryStatus::Open,
				'due_date' => '2026-01-01',
				'total'    => 5000,
				'voided'   => true,
			)
		);

		$summary = SummaryCalculator::calculate( $entries, LedgerFixtures::spec_budget(), '2026-11-18' );

		$this->assertSame( 7880000, $summary->cost_to_date );
		$this->assertSame( 970000, $summary->project_balance );
		$this->assertSame( 1, $summary->open_request_count );
	}

	public function test_client_direct_only_case_has_no_project_account(): void {
		$entries = array(
			LedgerFixtures::entry( array( 'total' => 450000 ) ),
			LedgerFixtures::entry(
				array(
					'id'     => 2,
					'total'  => 100000,
					'status' => EntryStatus::Committed,
				)
			),
		);

		$summary = SummaryCalculator::calculate( $entries, array(), '2026-11-18' );

		$this->assertFalse( $summary->has_project_account );
		$this->assertSame( 0, $summary->project_balance );
		$this->assertSame( 450000, $summary->cost_to_date );
		$this->assertSame( 0, $summary->utilization_bps() );
	}

	public function test_refunds_and_partner_funding(): void {
		$entries = array(
			LedgerFixtures::entry(
				array(
					'type'    => EntryType::Payment,
					'total'   => 100000,
					'funding' => null,
				)
			),
			LedgerFixtures::entry(
				array(
					'id'      => 2,
					'type'    => EntryType::Refund,
					'total'   => 30000,
					'funding' => null,
				)
			),
			LedgerFixtures::entry(
				array(
					'id'      => 3,
					'total'   => 70000,
					'funding' => Funding::Partner,
				)
			),
		);

		$summary = SummaryCalculator::calculate( $entries, array(), '2026-11-18' );

		$this->assertSame( 70000, $summary->project_balance );
		$this->assertSame( 30000, $summary->refunds );
		$this->assertSame( 70000, $summary->partner_funded );
	}

	public function test_expense_without_category_goes_to_other(): void {
		$summary = SummaryCalculator::calculate( array( LedgerFixtures::entry( array( 'category' => '' ) ) ), array(), '2026-11-18' );

		$this->assertSame( 100, $summary->categories['other']['paid'] );
	}

	public function test_overdue_and_nearest_due_request(): void {
		$entries = array(
			LedgerFixtures::entry(
				array(
					'id'       => 1,
					'type'     => EntryType::Request,
					'status'   => EntryStatus::Open,
					'due_date' => '2026-12-20',
				)
			),
			LedgerFixtures::entry(
				array(
					'id'       => 2,
					'type'     => EntryType::Request,
					'status'   => EntryStatus::Open,
					'due_date' => '2026-11-01',
				)
			),
			LedgerFixtures::entry(
				array(
					'id'       => 3,
					'type'     => EntryType::Request,
					'status'   => EntryStatus::Paid,
					'due_date' => '2026-10-01',
				)
			),
		);

		$summary = SummaryCalculator::calculate( $entries, array(), '2026-11-18' );

		$this->assertSame( 2, $summary->open_request_count );
		$this->assertSame( 1, $summary->overdue_count );
		$this->assertSame( 2, $summary->next_request_id );
		$this->assertTrue( $entries[1]->is_overdue( '2026-11-18' ) );
		$this->assertFalse( $entries[2]->is_overdue( '2026-11-18' ) );
	}

	public function test_request_without_due_date_sorts_last(): void {
		$entries = array(
			LedgerFixtures::entry(
				array(
					'id'     => 1,
					'type'   => EntryType::Request,
					'status' => EntryStatus::Open,
				)
			),
			LedgerFixtures::entry(
				array(
					'id'       => 2,
					'type'     => EntryType::Request,
					'status'   => EntryStatus::Open,
					'due_date' => '2027-01-01',
				)
			),
		);

		$summary = SummaryCalculator::calculate( $entries, array(), '2026-11-18' );

		$this->assertSame( 2, $summary->next_request_id );
		$this->assertSame( 0, $summary->overdue_count );
	}

	public function test_empty_case(): void {
		$summary = SummaryCalculator::calculate( array(), array(), '2026-11-18' );

		$this->assertSame( 0, $summary->forecast() );
		$this->assertNull( $summary->next_request_id );
		$this->assertSame( array(), $summary->categories );
	}

	public function test_to_array_exposes_every_field(): void {
		$data = SummaryCalculator::calculate( LedgerFixtures::spec_example(), LedgerFixtures::spec_budget(), '2026-11-18' )->to_array();

		$this->assertSame( 7880000, $data['cost_to_date'] );
		$this->assertArrayHasKey( 'categories', $data );
		$this->assertCount( 14, $data );
	}
}
