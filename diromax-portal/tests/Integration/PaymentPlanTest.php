<?php
/**
 * A case's payment plan: payments due on a stage, a date or by hand, made
 * drafts when due, sent as requests only after the manager approves
 * (spec 21).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Service\MorningSummary;
use Diromax\Portal\Service\PlanService;
use Diromax\Portal\Settings;
use WP_Error;

final class PaymentPlanTest extends PortalTestCase {

	/**
	 * A payment form, with overrides.
	 *
	 * @param array<string, string> $overrides Fields.
	 * @return array<string, string>
	 */
	private static function payment( array $overrides = array() ): array {
		return array_merge(
			array(
				'label'        => 'תשלום שני, עם קבלת ההיתר',
				'trigger_on'   => 'stage',
				'stage'        => '2',
				'trigger_date' => '',
				'kind'         => 'percent',
				'value'        => '10',
				'pay_to'       => 'diromax',
				'payee'        => '',
				'days'         => '7',
			),
			$overrides
		);
	}

	/**
	 * A case with a client and a budget of 200,000 ₪.
	 *
	 * @return array{0:CaseRecord, 1:int, 2:int} Case, manager, client.
	 */
	private function planned_case(): array {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );
		$this->assertTrue( $this->app->case_service->save_budget( $case, array( 'consultants.planned' => '200000' ), $manager ) );

		return array( $this->fresh( $case ), $manager, $client );
	}

	/**
	 * The case as stored now.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return CaseRecord
	 */
	private function fresh( CaseRecord $client_case ): CaseRecord {
		$found = $this->app->cases->find( $client_case->id );
		$this->assertNotNull( $found );

		return $found;
	}

	/**
	 * The plan's ID, checked to be a number.
	 *
	 * @param int|WP_Error $result Service result.
	 * @return int
	 */
	private function id_of( int|WP_Error $result ): int {
		$this->assertIsInt( $result, $result instanceof WP_Error ? (string) wp_json_encode( $result->get_error_data() ) : '' );

		return (int) $result;
	}

	public function test_a_stage_done_makes_a_draft_and_nothing_reaches_the_client_until_approved(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$id                 = $this->id_of( $plan->add( $case, self::payment(), $manager ) );

		$this->assertSame( 20000000, $plan->base( $case ), 'The base is the budget total.' );
		$this->assertSame( 2000000, $plan->for_staff( $case )['rows'][0]['amount'], '10% of 200,000 ₪.' );

		// Moving on to stage 3 marks stages 1 and 2 done.
		$case = $this->app->case_service->move_to_stage( $case, 3, false, $manager );
		$this->assertInstanceOf( CaseRecord::class, $case );

		$payment = $this->app->plan->find( $id );
		$this->assertSame( array( 'draft', 2000000, gmdate( 'Y-m-d', self::NOW + 7 * DAY_IN_SECONDS ) ), array( $payment['status'] ?? '', $payment['draft_amount'] ?? 0, $payment['draft_due'] ?? '' ) );
		$this->assertSame( 1, $this->app->plan->count_drafts( $case->id ) );
		$this->assertSame( array(), $this->app->ledger->for_case( $case->id, false ), 'A draft is not a request.' );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 'planned', $plan->for_client( $case )[0]['state'], 'For the client, a draft is still planned.' );
		$this->assertSame( 'plan.drafted', $this->app->audit->for_case( $case->id, 1 )[0]['action'] );

		// A budget change after the draft does not change it.
		$this->assertTrue( $this->app->case_service->save_budget( $case, array( 'consultants.planned' => '300000' ), $manager ) );
		$this->assertSame( 2000000, $plan->for_staff( $this->fresh( $case ) )['rows'][0]['amount'] );
	}

	public function test_approving_sends_the_request_the_manager_checked_and_payment_shows_it_paid(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$id                 = $this->id_of( $plan->add( $case, self::payment(), $manager ) );
		$case               = $this->app->case_service->move_to_stage( $case, 3, false, $manager );
		$this->assertInstanceOf( CaseRecord::class, $case );

		$request = $this->id_of(
			$plan->approve(
				$case,
				$id,
				array(
					'amount'      => '21,000',
					'due_date'    => '2026-11-30',
					'description' => 'תשלום שני',
					'notify'      => '1',
				),
				$manager
			)
		);

		$row = $this->app->ledger->find( $request );
		$this->assertNotNull( $row );
		$this->assertSame( array( EntryType::Request, EntryStatus::Open, 2100000, '2026-11-30', true ), array( $row->entry->type, $row->entry->status, $row->entry->total, $row->entry->due_date, $row->entry->visible ) );
		$this->assertSame( array( 'sent', $request ), array( $this->app->plan->find( $id )['status'] ?? '', $this->app->plan->find( $id )['request_id'] ?? 0 ) );
		$this->assertSame( array( 'sent', '21,000 ₪' ), array( $plan->for_client( $case )[0]['state'], $plan->for_client( $case )[0]['amount'] ) );
		$this->assertInstanceOf( WP_Error::class, $plan->approve( $case, $id, array( 'amount' => '1' ), $manager ), 'A draft is sent once.' );

		$this->assertIsInt(
			$this->app->ledger_service->settle(
				$case,
				$request,
				array(
					'entry_date' => '2026-11-18',
					'method'     => 'transfer',
				),
				$manager
			)
		);
		$this->assertSame( array( 'paid', 'שולם' ), array( $plan->for_client( $case )[0]['state'], $plan->for_client( $case )[0]['state_label'] ) );
	}

	public function test_a_wrong_amount_keeps_the_draft_with_the_error_on_its_field(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$id                 = $this->id_of( $plan->add( $case, self::payment( array( 'trigger_on' => 'manual' ) ), $manager ) );
		$this->assertTrue( $plan->draft_now( $case, $id, $manager ) );

		$result = $plan->approve(
			$case,
			$id,
			array(
				'amount'      => '0',
				'due_date'    => '2026-11-30',
				'description' => 'מקדמה',
			),
			$manager
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'amount', (array) $result->get_error_data()['fields'] );
		$this->assertSame( 'draft', $this->app->plan->find( $id )['status'] ?? '' );
	}

	public function test_dated_payments_fall_due_in_the_daily_run_and_at_once_when_the_day_has_passed(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$later              = $this->id_of(
			$plan->add(
				$case,
				self::payment(
					array(
						'trigger_on'   => 'date',
						'trigger_date' => '2026-11-20',
						'kind'         => 'fixed',
						'value'        => '5000',
					)
				),
				$manager
			)
		);
		$past               = $this->id_of(
			$plan->add(
				$case,
				self::payment(
					array(
						'trigger_on'   => 'date',
						'trigger_date' => '2026-11-01',
						'kind'         => 'fixed',
						'value'        => '3000',
					)
				),
				$manager
			)
		);

		$this->assertSame( 'draft', $this->app->plan->find( $past )['status'] ?? '', 'Its day has passed: a draft at once.' );
		$this->assertSame( 0, $plan->run_dates() );
		$this->assertSame( 'planned', $this->app->plan->find( $later )['status'] ?? '' );

		$this->clock->advance( 2 * DAY_IN_SECONDS );
		$this->assertSame( 1, $plan->run_dates() );
		$this->assertSame( array( 'draft', 500000 ), array( $this->app->plan->find( $later )['status'] ?? '', $this->app->plan->find( $later )['draft_amount'] ?? 0 ) );
		$this->assertSame( 0, $plan->run_dates(), 'Once only.' );
	}

	public function test_a_case_on_hold_waits(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$id                 = $this->id_of(
			$plan->add(
				$case,
				self::payment(
					array(
						'trigger_on'   => 'date',
						'trigger_date' => '2026-11-20',
					)
				),
				$manager
			)
		);
		$this->assertInstanceOf( CaseRecord::class, $this->app->case_service->set_status( $case, 'on_hold', $manager ) );

		$this->clock->advance( 5 * DAY_IN_SECONDS );
		$this->assertSame( 0, $plan->run_dates() );
		$this->assertSame( 'planned', $this->app->plan->find( $id )['status'] ?? '' );
	}

	public function test_by_hand_back_to_the_plan_cancel_and_delete(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$id                 = $this->id_of(
			$plan->add(
				$case,
				self::payment(
					array(
						'trigger_on' => 'manual',
						'kind'       => 'fixed',
						'value'      => '12,500.50',
					)
				),
				$manager
			)
		);

		$this->assertTrue( $plan->draft_now( $case, $id, $manager ) );
		$this->assertSame( 1250050, $this->app->plan->find( $id )['draft_amount'] ?? 0 );
		$this->assertInstanceOf( WP_Error::class, $plan->update( $case, $id, self::payment(), $manager ), 'A draft is changed on approval, not here.' );
		$this->assertInstanceOf( WP_Error::class, $plan->delete( $case, $id, $manager ), 'A draft is cancelled first.' );

		$this->assertTrue( $plan->withdraw( $case, $id, true, $manager ) );
		$this->assertSame( 'planned', $this->app->plan->find( $id )['status'] ?? '' );
		$this->assertTrue( $plan->withdraw( $case, $id, false, $manager ) );
		$this->assertSame( 'cancelled', $this->app->plan->find( $id )['status'] ?? '' );
		$this->assertSame(
			array(
				'percent' => 0,
				'fixed'   => 0,
				'total'   => 0,
			),
			$plan->for_staff( $case )['totals'],
			'Cancelled payments are left out.'
		);
		$this->assertSame( array(), $plan->for_client( $case ) );

		$this->assertTrue( $plan->delete( $case, $id, $manager ) );
		$this->assertNull( $this->app->plan->find( $id ) );
	}

	public function test_the_base_can_be_set_for_the_case(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$this->id_of( $plan->add( $case, self::payment( array( 'value' => '25' ) ), $manager ) );

		$this->assertTrue( $plan->set_base( $case, '80,000', $manager ) );
		$case = $this->fresh( $case );
		$this->assertSame( array( 8000000, true, 2000000 ), array( $plan->base( $case ), $plan->for_staff( $case )['base_set'], $plan->for_staff( $case )['totals']['total'] ) );

		$this->assertTrue( $plan->set_base( $case, '', $manager ) );
		$this->assertSame( 20000000, $plan->base( $this->fresh( $case ) ), 'Empty: the budget total again.' );
		$this->assertInstanceOf( WP_Error::class, $plan->set_base( $case, 'abc', $manager ) );
	}

	public function test_bad_input_and_a_closed_case_are_refused(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );

		$result = $plan->add(
			$case,
			self::payment(
				array(
					'value'  => '120',
					'pay_to' => 'supplier',
				)
			),
			$manager
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			array(
				'value' => 'percent',
				'payee' => 'required',
			),
			$result->get_error_data()['fields']
		);

		$this->assertInstanceOf( CaseRecord::class, $this->app->case_service->set_status( $case, 'closed', $manager ) );
		$closed = $plan->add( $this->fresh( $case ), self::payment(), $manager );
		$this->assertInstanceOf( WP_Error::class, $closed );
		$this->assertSame( array( 'label' => 'case_closed' ), $closed->get_error_data()['fields'] );
	}

	public function test_other_managers_clients_and_other_cases_are_refused(): void {
		[ $case, $manager, $client ] = $this->planned_case();
		$plan                        = new PlanService( $this->app );
		$id                          = $this->id_of( $plan->add( $case, self::payment( array( 'trigger_on' => 'manual' ) ), $manager ) );
		$other                       = $this->manager();
		[ $elsewhere, $its_manager ] = $this->planned_case();

		foreach ( array( $other, $client ) as $actor ) {
			$this->assertSame( 'dx_forbidden', $this->code( $plan->add( $case, self::payment(), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $plan->update( $case, $id, self::payment(), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $plan->delete( $case, $id, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $plan->set_base( $case, '1000', $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $plan->draft_now( $case, $id, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $plan->approve( $case, $id, array(), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $plan->withdraw( $case, $id, false, $actor ) ) );
		}

		// A payment of one case, reached through another the actor manages.
		$this->assertSame( 'dx_not_found', $this->code( $plan->draft_now( $elsewhere, $id, $its_manager ) ) );
		$this->assertSame( 'dx_not_found', $this->code( $plan->update( $elsewhere, $id, self::payment(), $its_manager ) ) );
		$this->assertSame( 'dx_not_found', $this->code( $plan->delete( $elsewhere, $id, $its_manager ) ) );
		$this->assertSame( 'dx_not_found', $this->code( $plan->withdraw( $elsewhere, $id, false, $its_manager ) ) );
		$this->assertSame( 'planned', $this->app->plan->find( $id )['status'] ?? '' );
		$this->assertSame( array(), $this->app->plan->drafts( $its_manager ), 'Each manager sees the drafts of their cases.' );
	}

	public function test_new_cases_start_from_the_office_template(): void {
		Settings::save(
			array(
				'plan_template' => array(
					array(
						'label'      => 'מקדמה בחתימה',
						'trigger_on' => 'manual',
						'stage'      => 0,
						'kind'       => 'fixed',
						'amount'     => 1000000,
						'percent'    => 0,
						'pay_to'     => 'diromax',
						'payee'      => '',
						'days'       => 7,
					),
					array(
						'label'      => 'עם קבלת ההיתר',
						'trigger_on' => 'stage',
						'stage'      => 4,
						'kind'       => 'percent',
						'amount'     => 0,
						'percent'    => 3000,
						'pay_to'     => 'diromax',
						'payee'      => '',
						'days'       => 14,
					),
					array(
						'label'      => 'ספק בלי שם: לא נשמר',
						'trigger_on' => 'stage',
						'stage'      => 2,
						'kind'       => 'fixed',
						'amount'     => 100,
						'percent'    => 0,
						'pay_to'     => 'supplier',
						'payee'      => '',
						'days'       => 14,
					),
				),
			)
		);

		$this->assertCount( 2, Settings::all()['plan_template'] );
		$case = $this->case_for( $this->manager() );
		$rows = $this->app->plan->for_case( $case->id );

		$this->assertSame( array( array( 'מקדמה בחתימה', 'manual', 0, 'fixed', 1000000, 0 ), array( 'עם קבלת ההיתר', 'stage', 4, 'percent', 0, 3000 ) ), array_map( static fn( array $row ): array => array( $row['label'], $row['trigger_on'], $row['stage'], $row['kind'], $row['amount'], $row['percent'] ), $rows ) );
		$this->assertSame( array( 0, 1 ), array_column( $rows, 'sort' ) );
	}

	public function test_the_tab_the_dashboard_and_the_morning_summary_show_drafts(): void {
		[ $case, $manager ] = $this->planned_case();
		$plan               = new PlanService( $this->app );
		$id                 = $this->id_of( $plan->add( $case, self::payment( array( 'trigger_on' => 'manual' ) ), $manager ) );
		$this->assertTrue( $plan->draft_now( $case, $id, $manager ) );
		wp_set_current_user( $manager );

		$pages = new Pages( $this->app );
		$view  = $pages->case_data( $case, $manager, 'plan' );
		$this->assertSame( array( 'plan', 1, 'תוכנית תשלומים' ), array( $view['tab'], $view['counts']['plan'], $view['tabs']['plan'] ) );
		$this->assertSame( 'draft', $view['rows'][0]['state'] );

		$this->assertSame( $id, $pages->dashboard_data( $manager )['drafts'][0]['payment']['id'] );

		( new MorningSummary( $this->app ) )->run();
		$mine = array_values( array_filter( $this->mails, static fn( array $mail ): bool => get_userdata( $manager )->user_email === $mail['to'] ) );
		$this->assertCount( 1, $mine );
		$this->assertStringContainsString( 'דרישת תשלום 1 מחכה לאישור שלך', (string) $mine[0]['message'] );
	}

	public function test_deleting_a_case_removes_its_plan(): void {
		[ $case, $manager ] = $this->planned_case();
		$id                 = $this->id_of( ( new PlanService( $this->app ) )->add( $case, self::payment(), $manager ) );

		$this->assertIsInt( $this->app->case_service->delete( $case, $this->admin() ) );
		$this->assertNull( $this->app->plan->find( $id ) );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'plan' ), $case->id ) ) );
	}

	/**
	 * The error code of a refusal, or '' when it was allowed.
	 *
	 * @param mixed $result Service result.
	 * @return string
	 */
	private function code( mixed $result ): string {
		return $result instanceof WP_Error ? (string) $result->get_error_code() : '';
	}
}
