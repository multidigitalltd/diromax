<?php
/**
 * The office's payments forecast (spec 20.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Service\ForecastService;

final class ForecastTest extends PortalTestCase {

	/**
	 * Add an open request.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $manager     Its manager.
	 * @param string     $due         Due date.
	 * @param string     $amount      Shekels.
	 * @param string     $pay_to      diromax or supplier.
	 * @param string     $visible     '1' or ''.
	 * @param string     $text        Description.
	 */
	private function request( CaseRecord $client_case, int $manager, string $due, string $amount, string $pay_to, string $visible = '1', string $text = 'דרישה' ): void {
		$this->assertIsInt(
			$this->app->ledger_service->add(
				$client_case,
				array(
					'type'        => 'request',
					'entry_date'  => '2026-11-01',
					'due_date'    => $due,
					'amount'      => $amount,
					'description' => $text,
					'pay_to'      => $pay_to,
					'payee'       => 'supplier' === $pay_to ? 'קבלן השלד' : '',
					'visible'     => $visible,
				),
				$manager
			)
		);
	}

	public function test_the_administrator_sees_every_active_case_by_month(): void {
		$first  = $this->manager();
		$second = $this->manager();
		$case_a = $this->case_for( $first );
		$case_b = $this->case_for( $second );
		$closed = $this->case_for( $second );

		$this->request( $case_a, $first, '2026-11-10', '1000', 'diromax' );
		$this->request( $case_a, $first, '2026-11-25', '2000', 'supplier' );
		$this->request( $case_b, $second, '2026-12-05', '3000', 'diromax' );
		$this->request( $case_b, $second, '2027-03-01', '4000', 'supplier' );
		$this->request( $case_b, $second, '2026-11-25', '9999', 'diromax', '' );
		$this->request( $closed, $second, '2026-11-25', '8888', 'diromax' );
		$this->app->case_service->set_status( $closed, 'closed', $second );
		$this->assertTrue( $this->app->case_service->save_budget( $case_a, array( 'fees.planned' => '500' ), $first ) );
		$this->assertIsInt( $this->app->ledger_service->add( $case_a, self::expense( array( 'amount' => '800' ) ), $first ) );

		$view = ( new ForecastService( $this->app ) )->view( $this->admin() );
		$this->assertIsArray( $view );
		$buckets = array_column( $view['buckets'], null, 'key' );

		$this->assertSame( array( 'overdue', '2026-11', '2026-12', '2027-01', 'later', 'undated' ), array_keys( $buckets ) );
		$this->assertSame( array( 1, 100000 ), array( $buckets['overdue']['count'], $buckets['overdue']['diromax'] ) );
		$this->assertSame( array( 1, 200000 ), array( $buckets['2026-11']['count'], $buckets['2026-11']['suppliers'] ) );
		$this->assertSame( 300000, $buckets['2026-12']['diromax'] );
		$this->assertSame( 400000, $buckets['later']['total'] );
		$this->assertSame( 1000000, $view['total']['total'], 'Hidden requests and closed cases are left out.' );
		$this->assertSame( 'נובמבר 2026', $buckets['2026-11']['label'] );
		$this->assertSame( array( 9 ), array_column( $view['overdue'], 'days' ) );
		$this->assertSame( array( $case_a->id ), array_column( array_filter( $view['variance'], static fn( array $row ): bool => $row['over'] ), 'case_id' ), 'The case that spent over its budget.' );
	}

	public function test_only_administrators_get_the_screen_and_the_csv(): void {
		$manager = $this->manager();
		$service = new ForecastService( $this->app );

		foreach ( array( $manager, $this->client(), 0 ) as $user ) {
			$this->assertSame( 'dx_forbidden', ( $service->view( $user ) )->get_error_code() );
			$this->assertSame( 'dx_forbidden', ( $service->csv( $user ) )->get_error_code() );
		}
	}

	public function test_the_csv_has_every_open_request_with_formulas_defused_and_is_audited(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$this->request( $case, $manager, '2026-11-10', '1234.5', 'supplier', '1', '=HYPERLINK("http://x")' );

		$csv = ( new ForecastService( $this->app ) )->csv( $this->admin() );

		$this->assertIsString( $csv );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( '"מספר תיק",תיק,תיאור,"למי משלמים","לתשלום עד","ימי איחור",תקופה,סכום', $csv );
		$this->assertStringContainsString( "'=HYPERLINK", $csv, 'A spreadsheet does not run it.' );
		$this->assertStringContainsString( '"קבלן השלד",2026-11-10,9,באיחור,1234.50', $csv );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}dx_audit WHERE action = 'export.forecast'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion on the plugin's own table.
	}
}
