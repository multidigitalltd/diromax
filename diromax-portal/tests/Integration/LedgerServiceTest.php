<?php
/**
 * Ledger use cases end to end against the database.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Funding;

final class LedgerServiceTest extends PortalTestCase {

	public function test_spec_example_through_the_services(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$budget = array(
			'architecture.planned' => '55000',
			'consultants.planned'  => '18000',
			'fees.planned'         => '42000',
			'contractor.planned'   => '290000',
			'management.planned'   => '48000',
			'reserve.planned'      => '27000',
		);
		$this->assertTrue( $this->app->case_service->save_budget( $case, $budget, $manager ) );

		$entries = array(
			self::expense(
				array(
					'entry_date' => '2026-07-11',
					'amount'     => '4500',
					'category'   => 'consultants',
					'funding'    => 'client',
				)
			),
			array(
				'type'        => 'payment',
				'entry_date'  => '2026-07-20',
				'amount'      => '60000',
				'description' => 'העברה',
				'method'      => 'transfer',
				'visible'     => '1',
			),
			self::expense(
				array(
					'entry_date' => '2026-08-02',
					'amount'     => '38000',
					'category'   => 'architecture',
					'funding'    => 'project',
				)
			),
			self::expense(
				array(
					'entry_date' => '2026-09-15',
					'amount'     => '24000',
					'category'   => 'management',
					'funding'    => 'proceeds',
				)
			),
			self::expense(
				array(
					'entry_date' => '2026-10-28',
					'amount'     => '45000',
					'category'   => 'contractor',
					'funding'    => 'project',
					'status'     => 'committed',
				)
			),
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-05',
				'due_date'    => '2026-12-01',
				'amount'      => '40000',
				'description' => 'מקדמה',
				'pay_to'      => 'diromax',
				'visible'     => '1',
			),
			self::expense(
				array(
					'entry_date' => '2026-11-12',
					'amount'     => '12300',
					'category'   => 'fees',
					'funding'    => 'project',
				)
			),
		);

		foreach ( $entries as $input ) {
			$this->assertIsInt( $this->app->ledger_service->add( $case, $input, $manager ) );
		}

		$summary = $this->app->case_service->summary( $case, true );

		$this->assertSame( 48000000, $summary->budget );
		$this->assertSame( 7880000, $summary->cost_to_date );
		$this->assertSame( 4500000, $summary->committed );
		$this->assertSame( 970000, $summary->project_balance );
		$this->assertSame( 2400000, $summary->deferred );
		$this->assertSame( 4000000, $summary->open_requests );
		$this->assertSame( 1642, $summary->utilization_bps() );

		$cached = $this->app->cases->find( $case->id );
		$this->assertSame( 7880000, $cached->summary['cost_to_date'] ?? null );
	}

	public function test_hidden_entry_is_out_of_the_client_summary_but_in_the_managers(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$this->app->ledger_service->add( $case, self::expense( array( 'amount' => '100' ) ), $manager );
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'amount'  => '900',
					'visible' => '',
				)
			),
			$manager
		);

		$this->assertSame( 10000, $this->app->case_service->summary( $case, true )->cost_to_date );
		$this->assertSame( 100000, $this->app->case_service->summary( $case, false )->cost_to_date );
		$this->assertCount( 1, $this->app->ledger->for_case( $case->id, true ) );
	}

	public function test_other_manager_and_client_cannot_add_edit_or_void(): void {
		$manager = $this->manager();
		$other   = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );
		$id      = $this->app->ledger_service->add( $case, self::expense(), $manager );

		foreach ( array( $other, $client, 0 ) as $intruder ) {
			$this->assertSame( 'dx_forbidden', $this->app->ledger_service->add( $case, self::expense(), $intruder )->get_error_code() );
			$this->assertSame( 'dx_forbidden', $this->app->ledger_service->update( $case, $id, self::expense(), $intruder )->get_error_code() );
			$this->assertSame( 'dx_forbidden', $this->app->ledger_service->void( $case, $id, 'x', false, $intruder )->get_error_code() );
			$this->assertSame( 'dx_forbidden', $this->app->ledger_service->settle( $case, $id, array(), $intruder )->get_error_code() );
		}
	}

	public function test_entry_of_another_case_is_not_found(): void {
		$manager = $this->manager();
		$case_a  = $this->case_for( $manager );
		$case_b  = $this->case_for( $manager );
		$id      = $this->app->ledger_service->add( $case_b, self::expense(), $manager );

		$this->assertSame( 'dx_not_found', $this->app->ledger_service->update( $case_a, $id, self::expense(), $manager )->get_error_code() );
		$this->assertSame( 'dx_not_found', $this->app->ledger_service->void( $case_a, $id, 'x', false, $manager )->get_error_code() );
	}

	public function test_validation_errors_come_back_per_field(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$error   = $this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'amount'   => '-1',
					'category' => 'nope',
				)
			),
			$manager
		);

		$this->assertSame( 'dx_invalid', $error->get_error_code() );
		$this->assertSame(
			array(
				'amount'   => 'invalid',
				'category' => 'invalid',
			),
			$error->get_error_data()['fields']
		);
	}

	public function test_update_keeps_the_type_and_audits_the_change(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$id      = $this->app->ledger_service->add( $case, self::expense( array( 'status' => 'committed' ) ), $manager );

		$result = $this->app->ledger_service->update(
			$case,
			$id,
			self::expense(
				array(
					'type'   => 'payment',
					'status' => 'paid',
					'amount' => '13000',
				)
			),
			$manager
		);

		$this->assertTrue( $result );
		$row = $this->app->ledger->find( $id );
		$this->assertSame( EntryType::Expense, $row->entry->type );
		$this->assertSame( EntryStatus::Paid, $row->entry->status );
		$this->assertSame( 1300000, $row->entry->total );

		$actions = array_column( $this->app->audit->for_case( $case->id ), 'action' );
		$this->assertContains( 'ledger.updated', $actions );
	}

	public function test_void_removes_from_totals_and_cannot_repeat(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$id      = $this->app->ledger_service->add( $case, self::expense(), $manager );

		$this->assertSame( 'dx_invalid', $this->app->ledger_service->void( $case, $id, '  ', false, $manager )->get_error_code() );
		$this->assertTrue( $this->app->ledger_service->void( $case, $id, 'הוזן פעמיים', false, $manager ) );
		$this->assertSame( 0, $this->app->case_service->summary( $case, true )->cost_to_date );
		$this->assertSame( 'הוזן פעמיים', $this->app->ledger->find( $id )->void_reason );

		$again = $this->app->ledger_service->void( $case, $id, 'שוב', false, $manager );
		$this->assertSame( array( 'entry' => 'voided' ), $again->get_error_data()['fields'] );
		$this->assertSame( array( 'entry' => 'voided' ), $this->app->ledger_service->update( $case, $id, self::expense(), $manager )->get_error_data()['fields'] );
	}

	public function test_settling_a_supplier_request_records_a_client_direct_expense(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$request = $this->app->ledger_service->add(
			$case,
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-05',
				'due_date'    => '2026-12-01',
				'amount'      => '45000',
				'description' => 'מקדמה לקבלן',
				'pay_to'      => 'supplier',
				'payee'       => 'קבלן ראשי בע״מ',
				'visible'     => '1',
			),
			$manager
		);

		$entry_id = $this->app->ledger_service->settle(
			$case,
			$request,
			array(
				'entry_date' => '2026-11-20',
				'category'   => 'contractor',
				'reference'  => '7781',
			),
			$manager
		);

		$this->assertIsInt( $entry_id );
		$entry = $this->app->ledger->find( $entry_id )->entry;
		$this->assertSame( EntryType::Expense, $entry->type );
		$this->assertSame( Funding::Client, $entry->funding );
		$this->assertSame( 'קבלן ראשי בע״מ', $entry->payee );
		$this->assertSame( 4500000, $entry->total );
		$this->assertSame( $request, $entry->request_id );
		$this->assertSame( EntryStatus::Paid, $this->app->ledger->find( $request )->entry->status );

		$summary = $this->app->case_service->summary( $case, true );
		$this->assertSame( 4500000, $summary->cost_to_date );
		$this->assertSame( 0, $summary->open_requests );
		$this->assertFalse( $summary->has_project_account );

		$this->assertSame( array( 'entry' => 'settled' ), $this->app->ledger_service->settle( $case, $request, array( 'entry_date' => '2026-11-20' ), $manager )->get_error_data()['fields'] );
	}

	public function test_settling_a_diromax_request_records_a_payment_and_voiding_it_reopens(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$request = $this->app->ledger_service->add(
			$case,
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-05',
				'due_date'    => '2026-12-01',
				'amount'      => '40000',
				'description' => 'מקדמה',
				'pay_to'      => 'diromax',
				'visible'     => '1',
			),
			$manager
		);

		$payment = $this->app->ledger_service->settle(
			$case,
			$request,
			array(
				'entry_date' => '2026-11-21',
				'method'     => 'transfer',
			),
			$manager
		);
		$this->assertSame( EntryType::Payment, $this->app->ledger->find( $payment )->entry->type );
		$this->assertSame( 4000000, $this->app->case_service->summary( $case, true )->project_balance );

		$this->assertTrue( $this->app->ledger_service->void( $case, $payment, 'ההעברה חזרה', false, $manager ) );
		$this->assertSame( EntryStatus::Open, $this->app->ledger->find( $request )->entry->status );
		$this->assertSame( 4000000, $this->app->case_service->summary( $case, true )->open_requests );
	}

	public function test_settle_rejects_non_requests_and_invalid_dates(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$expense = $this->app->ledger_service->add( $case, self::expense(), $manager );
		$request = $this->app->ledger_service->add(
			$case,
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-05',
				'due_date'    => '2026-12-01',
				'amount'      => '1',
				'description' => 'x',
				'pay_to'      => 'diromax',
			),
			$manager
		);

		$this->assertSame( array( 'type' => 'invalid' ), $this->app->ledger_service->settle( $case, $expense, array(), $manager )->get_error_data()['fields'] );
		$this->assertSame( 'dx_invalid', $this->app->ledger_service->settle( $case, $request, array( 'entry_date' => 'bad' ), $manager )->get_error_code() );
		$this->assertSame( EntryStatus::Open, $this->app->ledger->find( $request )->entry->status );
	}

	public function test_visible_entry_with_notify_queues_an_event_and_request_sends_at_once(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->app->ledger_service->add( $case, self::expense( array( 'notify' => '' ) ), $manager );
		$this->app->ledger_service->add( $case, self::expense( array( 'visible' => '' ) ), $manager );

		$this->assertCount( 1, $this->app->events->pending_for_case( $case->id ) );
		$this->assertCount( 0, $this->mails );

		$this->app->ledger_service->add(
			$case,
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-18',
				'due_date'    => '2026-12-01',
				'amount'      => '40000',
				'description' => 'מקדמה',
				'pay_to'      => 'diromax',
				'visible'     => '1',
				'notify'      => '1',
			),
			$manager
		);

		$this->assertCount( 1, $this->mails );
		$this->assertCount( 0, $this->app->events->pending_for_case( $case->id ) );
		$this->assertStringContainsString( '2 עדכונים', $this->mails[0]['subject'] );
	}
}
