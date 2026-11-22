<?php
/**
 * Sections and sub-sections per case (spec 19.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Domain\BudgetTemplate;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\CsvExport;
use Diromax\Portal\Settings;

final class BudgetTreeTest extends PortalTestCase {

	/**
	 * A case with a sub-section "structure-new" under "consultants" and an
	 * expense charged to it.
	 *
	 * @param int      $manager Manager.
	 * @param int|null $client  Client.
	 * @return array{0:CaseRecord, 1:string} Case and the new sub-section's key.
	 */
	private function case_with_sub_section( int $manager, ?int $client = null ): array {
		$case = $this->case_for( $manager, $client );

		$this->assertTrue(
			$this->app->case_service->save_budget(
				$case,
				array(
					'consultants.planned'        => '1000',
					'add.consultants.label'      => 'יועץ תנועה',
					'add.consultants.planned'    => '4000',
					'consultants-survey.planned' => '2000',
				),
				$manager
			)
		);

		$tree = $this->app->budget->tree( $case->id );
		$key  = (string) ( array_values( array_filter( $tree->children( 'consultants' ), static fn( array $line ): bool => 'יועץ תנועה' === $line['label'] ) )[0]['key'] ?? '' );
		$this->assertMatchesRegularExpression( '/^b[0-9a-f]{8}$/', $key );

		return array( $case, $key );
	}

	public function test_a_new_case_starts_from_the_default_budget(): void {
		$case = $this->case_for( $this->manager() );
		$tree = $this->app->budget->tree( $case->id );

		$this->assertSame( Settings::budget_template()->keys(), $tree->keys() );
		$this->assertSame( 'יועצים ומדידות › קונסטרוקציה', $tree->label( 'consultants-structure' ) );
		$this->assertSame( 0, $tree->total() );
	}

	public function test_entries_on_a_sub_section_roll_up_and_the_client_sees_both_levels(): void {
		$manager            = $this->manager();
		$client             = $this->client();
		[ $case, $traffic ] = $this->case_with_sub_section( $manager, $client );

		$this->assertIsInt(
			$this->app->ledger_service->add(
				$case,
				self::expense(
					array(
						'category' => $traffic,
						'amount'   => '1500',
					)
				),
				$manager
			)
		);
		$this->assertIsInt(
			$this->app->ledger_service->add(
				$case,
				self::expense(
					array(
						'category' => 'consultants',
						'amount'   => '300',
					)
				),
				$manager
			)
		);

		$summary = $this->app->case_service->summary( $case, true );
		$this->assertSame( 700000, $summary->budget );
		$this->assertSame( 700000, $summary->categories['consultants']['planned'] );
		$this->assertSame( 180000, $summary->categories['consultants']['paid'] );
		$this->assertSame( 150000, $summary->categories[ $traffic ]['paid'] );

		$view  = ( new CaseView( $this->app ) )->build( $case, $client, 'finance' );
		$lines = array_column( $view['categories'], null, 'key' );
		$this->assertSame( array( 'consultants' ), array_keys( $lines ), 'Lines with no amount and nothing spent are left out.' );
		$this->assertSame(
			array( 'consultants-survey', $traffic, 'consultants' ),
			array_column( $lines['consultants']['children'], 'key' ),
			'Sub-sections, then the general part of the section.'
		);
		$this->assertSame( 'יועצים ומדידות (כללי)', $lines['consultants']['children'][2]['label'] );
		$this->assertContains( 'יועצים ומדידות › יועץ תנועה', array_column( $view['entries'], 'category' ) );
		$this->assertSame( array( 'consultants' ), array_values( array_unique( array_column( $view['entries'], 'category_id' ) ) ), 'The client filters by section.' );
		$this->assertSame( array( 'consultants' => 'יועצים ומדידות' ), $view['filters']['categories'] );
	}

	public function test_removing_a_sub_section_moves_its_entries_to_the_section(): void {
		$manager            = $this->manager();
		[ $case, $traffic ] = $this->case_with_sub_section( $manager );
		$entry              = $this->app->ledger_service->add( $case, self::expense( array( 'category' => $traffic ) ), $manager );
		$this->assertIsInt( $entry );

		$this->assertTrue( $this->app->case_service->save_budget( $case, array( $traffic . '.remove' => '1' ), $manager ) );

		$this->assertNull( $this->app->budget->tree( $case->id )->find( $traffic ) );
		$this->assertSame( 'consultants', $this->app->ledger->find( $entry )?->entry->category );
		$audit = $this->app->audit->for_case( $case->id, 1 )[0];
		$this->assertSame( 'budget.updated', $audit['action'] );
		$this->assertStringContainsString( $traffic, (string) wp_json_encode( $audit['details'] ) );
	}

	public function test_a_section_with_entries_cannot_be_removed(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$this->assertIsInt( $this->app->ledger_service->add( $case, self::expense( array( 'category' => 'fees-permit' ) ), $manager ) );

		$result = $this->app->case_service->save_budget( $case, array( 'fees.remove' => '1' ), $manager );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'fees.remove' => 'in_use' ), $result->get_error_data()['fields'] );
		$this->assertNotNull( $this->app->budget->tree( $case->id )->find( 'fees-permit' ) );
	}

	public function test_only_the_case_manager_may_change_the_budget(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		foreach ( array( $client, $this->manager(), 0 ) as $intruder ) {
			$result = $this->app->case_service->save_budget( $case, array( 'add.*.label' => 'Intruder' ), $intruder );
			$this->assertSame( 'dx_forbidden', $result instanceof \WP_Error ? $result->get_error_code() : 'saved' );
		}

		$this->assertSame( Settings::budget_template()->keys(), $this->app->budget->tree( $case->id )->keys() );
	}

	public function test_an_entry_cannot_use_a_line_of_another_case(): void {
		$manager       = $this->manager();
		[ , $traffic ] = $this->case_with_sub_section( $manager );
		$other         = $this->case_for( $manager );

		$result = $this->app->ledger_service->add( $other, self::expense( array( 'category' => $traffic ) ), $manager );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid', $result->get_error_data()['fields']['category'] );
	}

	public function test_the_default_is_edited_as_text_and_only_new_cases_follow_it(): void {
		$manager = $this->manager();
		$before  = $this->case_for( $manager );

		Settings::save( Settings::template_settings( BudgetTemplate::parse( "אדריכלות ותכנון\n- הדמיות\nפיקוח", Settings::budget_template() ) ) + Settings::all() );
		$after = $this->case_for( $manager );

		$this->assertSame( array( 'architecture', 'supervision' ), array_column( Settings::budget_template()->sections(), 'key' ), 'Keys of kept lines stay.' );
		$this->assertSame( 'אדריכלות ותכנון › הדמיות', $this->app->budget->tree( $after->id )->label( $this->app->budget->tree( $after->id )->children( 'architecture' )[0]['key'] ) );
		$this->assertContains( 'reserve', $this->app->budget->tree( $before->id )->keys(), 'Existing cases keep their budget.' );
	}

	public function test_upgrade_gives_old_cases_every_section_with_names_and_keeps_amounts(): void {
		global $wpdb;

		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$this->assertIsInt( $this->app->ledger_service->add( $case, self::expense(), $manager ) );
		$table = Schema::table( 'budget' );

		// The case as schema 6 left it: one unnamed row per section with an amount, and an entry on a key that is gone.
		$wpdb->delete( $table, array( 'case_id' => $case->id ) );
		$wpdb->insert(
			$table,
			array(
				'case_id'  => $case->id,
				'category' => 'fees',
				'planned'  => 4200000,
				'sort'     => 0,
			),
			array( '%d', '%s', '%d', '%d' )
		);
		$wpdb->update( Schema::table( 'ledger' ), array( 'category' => 'retired' ), array( 'case_id' => $case->id ) );
		$wpdb->update( $table, array( 'parent' => '' ), array( 'case_id' => $case->id ), array( '%s' ), array( '%d' ) );

		$repository = new \Diromax\Portal\Data\BudgetRepository( $wpdb );
		$repository->adopt_sections( Settings::categories() );
		$repository->adopt_sections( Settings::categories() );

		$tree = $repository->tree( $case->id );
		$this->assertSame( array_merge( array_keys( Settings::categories() ), array( 'retired' ) ), $tree->keys(), 'Every section once, in the settings order, then the old key.' );
		$this->assertSame( 4200000, $tree->total() );
		$this->assertSame( 'אגרות והיטלים', $tree->label( 'fees' ) );
		$this->assertSame( 'retired', $tree->label( 'retired' ) );
	}

	public function test_the_csv_has_the_section_and_the_sub_section(): void {
		$manager            = $this->manager();
		[ $case, $traffic ] = $this->case_with_sub_section( $manager );
		$this->assertIsInt( $this->app->ledger_service->add( $case, self::expense( array( 'category' => $traffic ) ), $manager ) );

		$csv = ( new CsvExport( $this->app ) )->build( $case, $manager, true );

		$this->assertIsString( $csv );
		$this->assertStringContainsString( '"סעיף בתקציב","סעיף משנה"', $csv );
		$this->assertStringContainsString( '"יועצים ומדידות","יועץ תנועה"', $csv );
	}
}
