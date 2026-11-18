<?php
/**
 * CSV export, the manager dashboard and opening a case from a lead
 * (spec 15.7–15.9).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Service\CsvExport;
use Diromax\Portal\Service\DecisionService;
use Diromax\Portal\Service\LeadLink;
use WP_Error;

final class ManagerToolsTest extends PortalTestCase {

	public function tear_down(): void {
		unregister_post_type( LeadLink::POST_TYPE );
		parent::tear_down();
	}

	public function test_the_client_csv_has_only_what_the_client_sees_and_defuses_formulas(): void {
		[ $manager, $case, $client ] = $this->case_with_client();
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'description' => '=HYPERLINK("http://x")',
					'amount'      => '12300.5',
				)
			),
			$manager
		);
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'description'   => 'הוצאה פנימית',
					'visible'       => '0',
					'internal_note' => 'סוד',
				)
			),
			$manager
		);

		$csv = ( new CsvExport( $this->app ) )->build( $case, $client, false );

		$this->assertIsString( $csv );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"http://x\"\")\"", $csv );
		$this->assertStringContainsString( '12300.50', $csv );
		$this->assertStringNotContainsString( 'הוצאה פנימית', $csv );
		$this->assertStringNotContainsString( 'סוד', $csv );
		$this->assertStringNotContainsString( 'הערה פנימית', $csv, 'No staff columns for a client.' );
		$this->assertCount( 2, array_filter( explode( "\n", $csv ) ) );
	}

	public function test_the_staff_csv_has_every_entry_and_the_staff_columns(): void {
		[ $manager, $case ] = $this->case_with_client();
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'description'   => 'הוצאה פנימית',
					'visible'       => '0',
					'internal_note' => 'לבדוק מול הספק',
				)
			),
			$manager
		);

		$csv = ( new CsvExport( $this->app ) )->build( $case, $manager, true );

		$this->assertStringContainsString( 'הוצאה פנימית', $csv );
		$this->assertStringContainsString( 'לבדוק מול הספק', $csv );
		$this->assertStringContainsString( 'גלוי ללקוח', $csv );
	}

	public function test_strangers_cannot_export(): void {
		[ $manager, $case, $client ] = $this->case_with_client();

		$this->assertInstanceOf( WP_Error::class, ( new CsvExport( $this->app ) )->build( $case, $this->client(), false ) );
		$this->assertInstanceOf( WP_Error::class, ( new CsvExport( $this->app ) )->build( $case, $this->manager(), true ) );
		$this->assertInstanceOf( WP_Error::class, ( new CsvExport( $this->app ) )->build( $case, $client, true ), 'A client never gets the staff export.' );
	}

	public function test_csv_helpers(): void {
		$this->assertSame( "'+972", CsvExport::cell( '+972' ) );
		$this->assertSame( "'-5", CsvExport::cell( '-5' ) );
		$this->assertSame( "'@SUM(A1)", CsvExport::cell( '@SUM(A1)' ) );
		$this->assertSame( 'רגיל', CsvExport::cell( 'רגיל' ) );
		$this->assertSame( '', CsvExport::cell( '' ) );
		$this->assertSame( '12300.50', CsvExport::amount( 1230050 ) );
		$this->assertSame( '0.07', CsvExport::amount( 7 ) );
		$this->assertSame( '-1.00', CsvExport::amount( -100 ) );
	}

	/**
	 * Spec 15.11: a project manager sees only their own cases on the dashboard.
	 */
	public function test_the_dashboard_shows_a_manager_only_their_cases(): void {
		[ $mine_manager, $mine ]    = $this->case_with_client();
		[ $other_manager, $theirs ] = $this->case_with_client();
		$this->overdue_request( $mine, $mine_manager, 'שלי' );
		$this->overdue_request( $theirs, $other_manager, 'של אחר' );

		$pages = new Pages( $this->app );
		$own   = $pages->dashboard_data( $mine_manager );
		$all   = $pages->dashboard_data( $this->admin() );

		$this->assertSame( array( 'שלי' ), array_column( $own['overdue'], 'description' ) );
		$this->assertFalse( $own['all'] );
		$this->assertEqualsCanonicalizing( array( 'שלי', 'של אחר' ), array_column( $all['overdue'], 'description' ) );
		$this->assertTrue( $all['all'] );
	}

	public function test_the_dashboard_cards(): void {
		$this->clock->advance( -30 * DAY_IN_SECONDS );
		[ $manager, $stale, $client ] = $this->case_with_client();
		$fresh                        = $this->case_for( $manager );
		$this->app->members->mark_invited( $stale->id, $client, $this->clock->mysql() );
		$this->clock->advance( 30 * DAY_IN_SECONDS );

		( new \Diromax\Portal\Service\UpdateService( $this->app ) )->publish(
			$fresh,
			array(
				'title'   => 'התחלנו',
				'body'    => 'x',
				'stage'   => '',
				'visible' => '1',
				'notify'  => '',
			),
			array(),
			$manager
		);
		$decision = ( new DecisionService( $this->app ) )->create(
			$stale,
			array(
				'title' => 'בחירת קבלן',
				'body'  => 'פרטים',
			),
			$manager
		);
		$this->assertIsInt( $decision );
		$this->app->ledger_service->add(
			$stale,
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-19',
				'due_date'    => '2026-11-23',
				'amount'      => '500',
				'description' => 'בקרוב',
				'pay_to'      => 'diromax',
				'visible'     => '1',
			),
			$manager
		);

		$view = ( new Pages( $this->app ) )->dashboard_data( $manager );

		$this->assertSame( array( 'בקרוב' ), array_column( $view['upcoming'], 'description' ) );
		$this->assertSame( array( 'בחירת קבלן' ), array_column( $view['decisions'], 'title' ) );
		$this->assertSame( array( (string) $stale->id ), array_column( $view['stale'], 'case_id' ), 'Only the case with no update in 21 days.' );
		$this->assertSame( array( (string) $stale->id ), array_column( $view['quiet'], 'case_id' ) );

		$this->assertTrue( ( new DecisionService( $this->app ) )->answer( $stale, $decision, $client, 'decline', '', false ) );
		$this->assertSame( array( 'decision' ), array_column( ( new Pages( $this->app ) )->dashboard_data( $manager )['actions'], 'kind' ) );
	}

	public function test_a_case_opened_from_a_lead_is_linked_both_ways_once(): void {
		$this->register_leads();
		$admin = $this->admin();
		$lead  = $this->lead();

		$prefill = ( new LeadLink( $this->app ) )->prefill( $lead, $admin );
		$this->assertSame( 'רח׳ הגפן 3, רחובות', $prefill['title'] );
		$this->assertSame( 'בית פרטי', $prefill['property_type'] );
		$this->assertSame( 'רונית', $prefill['name'] );

		$case = $this->app->case_service->create(
			array(
				'title'      => $prefill['title'],
				'lead_id'    => (string) $lead,
				'manager_id' => (string) $admin,
			),
			$admin
		);

		$this->assertInstanceOf( CaseRecord::class, $case );
		$this->assertSame( $lead, $case->lead_id );
		$this->assertSame( (string) $case->id, get_post_meta( $lead, LeadLink::META, true ) );

		$again = $this->app->case_service->create(
			array(
				'title'      => 'שוב',
				'lead_id'    => (string) $lead,
				'manager_id' => (string) $admin,
			),
			$admin
		);
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertArrayHasKey( 'lead_id', $again->get_error_data()['fields'] );
		$this->assertNull( ( new LeadLink( $this->app ) )->prefill( $lead, $admin ) );
	}

	public function test_a_user_who_cannot_edit_the_lead_cannot_use_it(): void {
		$this->register_leads();
		$manager = $this->manager();
		$lead    = $this->lead();

		$this->assertNull( ( new LeadLink( $this->app ) )->prefill( $lead, $manager ) );
		$this->assertInstanceOf(
			WP_Error::class,
			$this->app->case_service->create(
				array(
					'title'   => 'x',
					'lead_id' => (string) $lead,
				),
				$manager
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$this->app->case_service->create(
				array(
					'title'   => 'x',
					'lead_id' => (string) self::factory()->post->create(),
				),
				$this->admin()
			),
			'A post that is not a lead.'
		);
	}

	public function test_lead_row_actions(): void {
		$this->register_leads();
		$admin = $this->admin();
		wp_set_current_user( $admin );
		$lead  = $this->lead();
		$links = new LeadLink( $this->app );

		$this->assertArrayHasKey( 'dx_open_case', $links->row_actions( array(), get_post( $lead ) ) );

		$case = $this->app->case_service->create(
			array(
				'title'      => 'x',
				'lead_id'    => (string) $lead,
				'manager_id' => (string) $admin,
			),
			$admin
		);
		$this->assertInstanceOf( CaseRecord::class, $case );
		$actions = $links->row_actions( array(), get_post( $lead ) );
		$this->assertArrayNotHasKey( 'dx_open_case', $actions );
		$this->assertStringContainsString( $case->number, $actions['dx_case'] );

		wp_set_current_user( $this->client() );
		$this->assertSame( array(), $links->row_actions( array(), get_post( $lead ) ) );
	}

	/**
	 * A case with one client.
	 *
	 * @return array{0:int, 1:CaseRecord, 2:int}
	 */
	private function case_with_client(): array {
		$manager = $this->manager();
		$client  = $this->client();

		return array( $manager, $this->case_for( $manager, $client ), $client );
	}

	/**
	 * An open request ten days late.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $manager     Manager.
	 * @param string     $description Description.
	 */
	private function overdue_request( CaseRecord $client_case, int $manager, string $description ): void {
		$id = $this->app->ledger_service->add(
			$client_case,
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-01',
				'due_date'    => '2026-11-09',
				'amount'      => '1000',
				'description' => $description,
				'pay_to'      => 'diromax',
				'visible'     => '1',
			),
			$manager
		);
		$this->assertIsInt( $id );
	}

	/**
	 * The theme's lead post type, as the theme registers it.
	 */
	private function register_leads(): void {
		register_post_type(
			LeadLink::POST_TYPE,
			array(
				'public'          => false,
				'show_ui'         => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * A lead with the theme's meta keys.
	 *
	 * @return int
	 */
	private function lead(): int {
		$id = self::factory()->post->create(
			array(
				'post_type'  => LeadLink::POST_TYPE,
				'post_title' => 'רונית — 050-0000000',
			)
		);

		update_post_meta( $id, 'dx_name', 'רונית' );
		update_post_meta( $id, 'dx_phone', '050-0000000' );
		update_post_meta( $id, 'dx_address', 'רח׳ הגפן 3, רחובות' );
		update_post_meta( $id, 'dx_type', 'בית פרטי' );

		return $id;
	}
}
