<?php
/**
 * Several stages in progress at once: how a case's place is told in lists,
 * the stepper, email and the stage board.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\CaseCells;
use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Service\Stages;

final class StageBoardTest extends PortalTestCase {

	public function tear_down(): void {
		Admin::use_front( false );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A case of the manager with the given stage statuses.
	 *
	 * @param int    $manager  Manager.
	 * @param string $statuses "1:done,2:active,…".
	 * @return CaseRecord
	 */
	private function case_with( int $manager, string $statuses ): CaseRecord {
		$updated = $this->app->case_service->update_details(
			$this->case_for( $manager ),
			array(
				'title'        => 'רח׳ הגפן ' . wp_rand( 1, 999 ) . ', רמת גן',
				'stage_status' => $statuses,
			),
			$manager
		);

		$this->assertInstanceOf( CaseRecord::class, $updated );

		return $updated;
	}

	public function test_where_a_case_stands_in_a_few_words(): void {
		$manager = $this->manager();

		$this->assertSame( 'הבא: 01 · ' . Stages::name( 1 ), Stages::summary( $this->case_for( $manager )->stages ), 'A new case.' );
		$this->assertSame( '03 · ' . Stages::name( 3 ), Stages::summary( $this->case_with( $manager, '1:done,2:done,3:active' )->stages ) );
		$this->assertSame( 'שלבים 03, 04 בתהליך', Stages::summary( $this->case_with( $manager, '1:done,2:done,3:active,4:active' )->stages ) );
		$this->assertSame( 'הבא: 04 · ' . Stages::name( 4 ), Stages::summary( $this->case_with( $manager, '1:done,2:done,3:done' )->stages ) );
		$this->assertSame( 'כל השלבים הושלמו', Stages::summary( $this->case_with( $manager, '1:done,2:done,3:done,4:skipped,5:done,6:done,7:done' )->stages ) );
	}

	public function test_the_stepper_and_strip_show_every_stage_in_progress(): void {
		$case  = $this->case_with( $this->manager(), '1:done,2:skipped,3:active,4:active' );
		$steps = CaseView::stages( $case );

		$this->assertSame( array( 'done', 'skipped', 'active', 'active', 'pending' ), array_slice( array_column( $steps, 'status' ), 0, 5 ) );
		$this->assertSame( array(), array_keys( array_filter( array_column( $steps, 'current' ) ) ), 'With two in progress, neither claims to be the one current step.' );
		$this->assertSame( 'מאז ' . gmdate( 'd.m.Y', self::NOW ), $steps[2]['when'] );
		$this->assertStringStartsWith( 'הושלם ', $steps[0]['when'] );
		$this->assertSame( '', $steps[4]['when'] );

		$this->assertSame( 2, substr_count( CaseCells::strip( $case ), 'is-now' ) );
		$this->assertSame( 1, substr_count( CaseCells::strip( $case ), 'is-skip' ) );

		$single = $this->case_with( $this->manager(), '1:done,2:active' );
		$this->assertTrue( CaseView::stages( $single )[1]['current'], 'One stage in progress is the current step.' );
	}

	public function test_the_board_puts_a_case_under_each_stage_in_progress(): void {
		$manager  = $this->manager();
		$parallel = $this->case_with( $manager, '1:done,2:done,3:active,4:active' );
		$between  = $this->case_with( $manager, '1:done,2:done' );
		$other    = $this->case_with( $this->manager(), '1:active' );

		$view = ( new Pages( $this->app ) )->board_data( $manager );
		$ids  = static fn( array $column ): array => array_map( static fn( array $card ): int => $card['case']->id, $column['cards'] );

		$this->assertSame( array( $parallel->id ), $ids( $view['columns'][3] ) );
		$this->assertSame( array( $parallel->id ), $ids( $view['columns'][4] ) );
		$this->assertSame( '04', $view['columns'][3]['cards'][0]['also'] );
		$this->assertSame( 0, $view['columns'][3]['cards'][0]['days'], 'Started today.' );
		$this->assertSame( array( $between->id ), array_map( static fn( CaseRecord $c ): int => $c->id, $view['waiting'] ) );
		$this->assertSame( array(), $ids( $view['columns'][1] ), 'Another manager\'s case is not on this board.' );
		$this->assertSame( 2, $view['total'] );

		$everything = ( new Pages( $this->app ) )->board_data( $this->admin() );
		$this->assertSame( array( $other->id ), $ids( $everything['columns'][1] ), 'An administrator sees every case.' );
	}

	public function test_the_board_renders_in_wp_admin_and_in_the_app(): void {
		$manager = $this->manager();
		$this->case_with( $manager, '1:done,2:active,3:active' );
		wp_set_current_user( $manager );

		ob_start();
		( new Pages( $this->app ) )->board();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-current="page">לוח שלבים</a>', $html );
		$this->assertSame( 7, substr_count( $html, 'class="dxa-board__col' ) );
		$this->assertStringContainsString( 'במקביל גם בשלב 03', $html );
		$this->assertStringContainsString( 'page=dx-cases&#038;case=', $html );

		Admin::use_front( true );
		$this->assertSame( Router::manage_url( 'board' ), Admin::page_url( Admin::PAGE_BOARD ) );
		$this->assertStringContainsString( 'dx_manage=board', Router::manage_url( 'board' ), 'Not the dashboard: board is a known screen.' );
	}

	/**
	 * Regression: after a failed update (say, a bad attachment) the form
	 * came back with "no stage" and the earlier-stages-done mode, so a
	 * retry could close stages that were meant to stay in progress.
	 */
	public function test_a_failed_update_keeps_its_stage_and_mode(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		wp_set_current_user( $manager );

		\Diromax\Portal\Admin\Flash::set(
			'error',
			'x',
			array( 'files' => 'type' ),
			array(
				'title'      => 'הוגשו תוכניות',
				'stage'      => '4',
				'stage_mode' => 'parallel',
			),
			'update'
		);

		$view = ( new Pages( $this->app ) )->case_data( $case, $manager, 'updates' );
		ob_start();
		include DX_PORTAL_DIR . 'templates/admin/tabs/updates.php';
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<option value="4" selected=\'selected\'>/', $html );
		$this->assertMatchesRegularExpression( '/<option value="parallel" selected=\'selected\'>/', $html );
	}
}
