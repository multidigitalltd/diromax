<?php
/**
 * The sample case, the dashboard totals and the activity feed across cases.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Service\DemoCase;
use WP_Error;

final class DashboardUpgradeTest extends PortalTestCase {

	public function test_the_sample_case_has_every_kind_of_record_and_sends_nothing(): void {
		$manager = $this->manager();

		$case = ( new DemoCase( $this->app ) )->create( $manager );

		$this->assertInstanceOf( CaseRecord::class, $case );
		$this->assertTrue( DemoCase::is_demo( $case ) );
		$this->assertSame( $manager, $case->manager_id );
		$this->assertSame( 5, $case->stage, 'The last update moved the case to stage 5.' );
		$this->assertCount( 8, $this->app->ledger->for_case( $case->id, false ) );
		$this->assertSame( 48000000, array_sum( $this->app->budget->planned( $case->id ) ) );
		$this->assertCount( 3, $this->app->updates->for_case( $case->id, false ) );
		$this->assertCount( 1, $this->app->decisions->for_case( $case->id ) );
		$this->assertSame( array(), $this->app->members->for_case( $case->id ), 'No clients, so nobody gets email.' );

		$summary = $case->summary ?? array();
		$this->assertSame( 4650000, $summary['open_requests'], 'Two open requests: 6,500 + 40,000.' );
		$this->assertSame( 4500000, $summary['committed'] );

		$view = ( new Pages( $this->app ) )->dashboard_data( $manager );
		$this->assertSame( array( 'השלמת אגרות לוועדה' ), array_column( $view['overdue'], 'description' ), 'One request is four days late.' );
		$this->assertSame( array( 'בחירת ספק חלונות' ), array_column( $view['decisions'], 'title' ) );

		$this->app->notifier->process_case( $case->id, true );
		$this->assertSame( array(), $this->mails );
	}

	public function test_only_staff_can_create_a_sample_case(): void {
		$this->assertInstanceOf( WP_Error::class, ( new DemoCase( $this->app ) )->create( $this->client() ) );
		$this->assertInstanceOf( WP_Error::class, ( new DemoCase( $this->app ) )->create( 0 ) );
		$this->assertFalse( $this->app->cases->any_for( null ) );
	}

	public function test_the_totals_count_active_cases_only(): void {
		$manager = $this->manager();
		$open    = $this->case_with_budget( $manager, '100000', '20000' );
		$second  = $this->case_with_budget( $manager, '50000', '5000' );
		$closed  = $this->case_with_budget( $manager, '900000', '900' );
		$this->app->case_service->set_status( $closed, CaseStatus::Closed->value, $manager );

		$view = ( new Pages( $this->app ) )->dashboard_data( $manager );

		$this->assertTrue( $view['has_cases'] );
		$this->assertSame( 2, $view['totals']['cases'] );
		$this->assertSame( 15000000, $view['totals']['budget'] );
		$this->assertSame( 2500000, $view['totals']['cost'] );
		$this->assertEqualsCanonicalizing( array( $open->id, $second->id ), array_map( static fn( CaseRecord $c ): int => $c->id, $view['cases'] ) );
	}

	public function test_a_fresh_install_shows_the_welcome_panel(): void {
		$view = ( new Pages( $this->app ) )->dashboard_data( $this->manager() );

		$this->assertFalse( $view['has_cases'] );
		$this->assertSame( 0, $view['totals']['cases'] );
		$this->assertSame( array(), $view['recent'] );
	}

	/**
	 * Standard 4.7.3: the feed never shows another manager's case.
	 */
	public function test_the_activity_feed_shows_a_manager_only_their_cases(): void {
		$mine_manager  = $this->manager();
		$other_manager = $this->manager();
		$mine          = $this->case_for( $mine_manager );
		$theirs        = $this->case_for( $other_manager );

		$own = ( new Pages( $this->app ) )->dashboard_data( $mine_manager )['recent'];
		$all = ( new Pages( $this->app ) )->dashboard_data( $this->admin() )['recent'];

		$this->assertSame( array( $mine->id ), array_values( array_unique( array_column( $own, 'case_id' ) ) ) );
		$this->assertEqualsCanonicalizing( array( $mine->id, $theirs->id ), array_values( array_unique( array_column( $all, 'case_id' ) ) ) );
		$this->assertSame( 'התיק נפתח', \Diromax\Portal\Service\Labels::audit_actions()[ $own[0]['action'] ] );
	}

	/**
	 * A case with a budget in one category and one paid expense.
	 *
	 * @param int    $manager Manager.
	 * @param string $budget  Budget, shekels.
	 * @param string $spent   Paid expense, shekels.
	 * @return CaseRecord
	 */
	private function case_with_budget( int $manager, string $budget, string $spent ): CaseRecord {
		$case = $this->case_for( $manager );
		$this->assertTrue( $this->app->case_service->save_budget( $case, array( 'fees' => $budget ), $manager ) );
		$this->assertIsInt(
			$this->app->ledger_service->add(
				$case,
				self::expense(
					array(
						'amount' => $spent,
						'notify' => '',
					)
				),
				$manager
			)
		);

		$fresh = $this->app->cases->find( $case->id );
		$this->assertInstanceOf( CaseRecord::class, $fresh );

		return $fresh;
	}
}
