<?php
/**
 * Stage due dates, team tasks and the staff morning summary (spec 16.1–16.3).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\MorningSummary;
use Diromax\Portal\Service\TaskService;
use Diromax\Portal\Settings;
use WP_Error;

final class TasksAndDueDatesTest extends PortalTestCase {

	/**
	 * Yesterday, by the frozen clock.
	 */
	private function yesterday(): string {
		return gmdate( 'Y-m-d', self::NOW - DAY_IN_SECONDS );
	}

	/**
	 * A case with stage 3 in progress, due yesterday.
	 *
	 * @param int $manager Manager.
	 * @return CaseRecord
	 */
	private function late_case( int $manager ): CaseRecord {
		$updated = $this->app->case_service->update_details(
			$this->case_for( $manager ),
			array(
				'title'        => 'רח׳ הגפן 3, רמת גן',
				'stage_status' => '1:done,2:done,3:active',
				'stage_due'    => '3:' . $this->yesterday() . ',4:2030-01-01',
			),
			$manager
		);

		$this->assertInstanceOf( CaseRecord::class, $updated );

		return $updated;
	}

	public function test_stage_due_dates_are_saved_and_a_late_stage_is_flagged_for_staff_only(): void {
		$manager = $this->manager();
		$case    = $this->late_case( $manager );

		$this->assertSame( $this->yesterday(), $case->stages[3]['due'] );
		$this->assertSame( '2030-01-01', $case->stages[4]['due'] );

		$staff = CaseView::stages( $case, $this->clock->today() );
		$this->assertTrue( $staff[2]['late'] );
		$this->assertFalse( $staff[3]['late'], 'Due in the future.' );
		$this->assertSame( '01.01.2030', $staff[3]['due'] );

		$client = CaseView::stages( $case );
		$this->assertSame( array( '' ), array_unique( array_column( $client, 'due' ) ), 'The client never sees due dates.' );
		$this->assertSame( array( false ), array_unique( array_column( $client, 'late' ) ) );

		$cleared = $this->app->case_service->update_details(
			$case,
			array(
				'title'     => $case->title,
				'stage_due' => '3:',
			),
			$manager
		);
		$this->assertInstanceOf( CaseRecord::class, $cleared );
		$this->assertNull( $cleared->stages[3]['due'] );

		$bad = $this->app->case_service->update_details(
			$case,
			array(
				'title'     => $case->title,
				'stage_due' => '3:2027-02-30',
			),
			$manager
		);
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( 'invalid', $bad->get_error_data()['fields']['stage_due'] );
	}

	public function test_late_stages_reach_the_dashboard_and_the_board_of_their_manager_only(): void {
		$manager = $this->manager();
		$case    = $this->late_case( $manager );
		$pages   = new Pages( $this->app );

		$late = $pages->dashboard_data( $manager )['late'];
		$this->assertCount( 1, $late );
		$this->assertSame( $case->id, $late[0]['case_id'] );
		$this->assertSame( 1, $late[0]['days'] );

		$this->assertSame( array(), $pages->dashboard_data( $this->manager() )['late'], 'Another manager does not see it.' );
		$this->assertCount( 1, $pages->dashboard_data( $this->admin() )['late'], 'An administrator does.' );

		$card = $pages->board_data( $manager )['columns'][3]['cards'][0];
		$this->assertSame( gmdate( 'd.m.Y', self::NOW - DAY_IN_SECONDS ), $card['late'] );
	}

	public function test_tasks_are_added_closed_reopened_and_deleted_with_an_audit_trail(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$tasks   = new TaskService( $this->app );

		$id = $tasks->add(
			$case,
			array(
				'title'       => 'לתאם מדידה',
				'assignee_id' => '',
				'due_date'    => $this->yesterday(),
			),
			$manager
		);
		$this->assertIsInt( $id );
		$this->assertSame( $manager, $this->app->tasks->find( $id )['assignee_id'] ?? null, 'Without an owner, the one who added it.' );
		$this->assertSame( 1, $this->app->tasks->open_count( $case->id ) );

		$mine = ( new Pages( $this->app ) )->dashboard_data( $manager )['tasks'];
		$this->assertSame( array( 'לתאם מדידה' ), array_column( $mine, 'title' ) );

		$this->assertTrue( $tasks->set_done( $case, $id, true, $manager ) );
		$this->assertNotNull( $this->app->tasks->find( $id )['done_at'] ?? null );
		$this->assertSame( 0, $this->app->tasks->open_count( $case->id ) );
		$this->assertTrue( $tasks->set_done( $case, $id, false, $manager ) );
		$reopened = $this->app->tasks->find( $id );
		$this->assertNotNull( $reopened );
		$this->assertNull( $reopened['done_at'] );
		$this->assertTrue( $tasks->delete( $case, $id, $manager ) );
		$this->assertNull( $this->app->tasks->find( $id ) );

		$this->assertSame(
			array( 'task.deleted', 'task.reopened', 'task.done', 'task.added' ),
			array_slice( array_column( $this->app->audit->for_case( $case->id ), 'action' ), 0, 4 )
		);
	}

	public function test_task_input_and_ownership_are_checked(): void {
		$manager = $this->manager();
		$other   = $this->manager();
		$case    = $this->case_for( $manager );
		$tasks   = new TaskService( $this->app );

		$result = $tasks->add(
			$case,
			array(
				'title'       => '',
				'assignee_id' => (string) $other,
				'due_date'    => '31/12/2026',
			),
			$manager
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			array(
				'title'       => 'required',
				'due_date'    => 'invalid',
				'assignee_id' => 'invalid',
			),
			$result->get_error_data()['fields'],
			'A manager of another case cannot own a task here.'
		);

		$this->assertSame( 'dx_forbidden', $tasks->add( $case, array( 'title' => 'x' ), $other )->get_error_code(), 'Negative: another manager cannot add.' );
		$this->assertSame( 'dx_forbidden', $tasks->add( $case, array( 'title' => 'x' ), $this->client() )->get_error_code(), 'Negative: a client cannot add.' );

		$id = $tasks->add( $case, array( 'title' => 'שלי' ), $manager );
		$this->assertIsInt( $id );
		$this->assertSame( 'dx_forbidden', $tasks->set_done( $case, $id, true, $other )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $tasks->delete( $case, $id, $other )->get_error_code() );

		$elsewhere = $this->case_for( $other );
		$this->assertSame( 'dx_not_found', $tasks->delete( $elsewhere, $id, $other )->get_error_code(), 'A task is reached only through its own case.' );
		$this->assertNotNull( $this->app->tasks->find( $id ) );

		$owners = array_keys( $tasks->assignees( $case ) );
		$this->assertContains( $manager, $owners );
		$this->assertNotContains( $other, $owners, 'Only staff who may manage this case.' );
	}

	public function test_the_morning_summary_goes_to_whoever_has_something_and_to_no_one_else(): void {
		$manager = $this->manager();
		$idle    = $this->manager();
		$case    = $this->late_case( $manager );

		( new TaskService( $this->app ) )->add(
			$case,
			array(
				'title'    => 'להגיש השלמות לוועדה',
				'due_date' => $this->clock->today(),
			),
			$manager
		);
		( new TaskService( $this->app ) )->add(
			$case,
			array(
				'title'    => 'בעוד חודש',
				'due_date' => '2030-01-01',
			),
			$manager
		);

		$this->assertGreaterThanOrEqual( 1, ( new MorningSummary( $this->app ) )->run() );

		$to_manager = array_values( array_filter( $this->mails, static fn( array $m ): bool => (string) get_userdata( $manager )->user_email === $m['to'] ) );
		$this->assertCount( 1, $to_manager );

		$mail = $to_manager[0];
		$this->assertStringContainsString( 'להגיש השלמות לוועדה', (string) $mail['message'] );
		$this->assertStringNotContainsString( 'בעוד חודש', (string) $mail['message'], 'Only what is due by today.' );
		$this->assertStringContainsString( 'שלב 1 עבר את היעד', (string) $mail['message'] );
		$this->assertNotContains( (string) get_userdata( $idle )->user_email, array_column( $this->mails, 'to' ), 'Nothing to tell, no email.' );

		update_option( Settings::OPTION, array( 'morning_summary' => false ) + Settings::all() );
		$this->mails = array();
		$this->assertSame( 0, ( new MorningSummary( $this->app ) )->run() );
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * Regression: "my tasks" compared due_date with '' when no day was given;
	 * MySQL 8 in strict mode rejects that, so the dashboard showed no tasks.
	 */
	public function test_my_tasks_query_runs_cleanly_with_and_without_a_day(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		( new TaskService( $this->app ) )->add( $case, array( 'title' => 'בלי תאריך' ), $manager );
		( new TaskService( $this->app ) )->add(
			$case,
			array(
				'title'    => 'עם תאריך',
				'due_date' => $this->clock->today(),
			),
			$manager
		);

		$this->assertSame( array( 'עם תאריך', 'בלי תאריך' ), array_column( $this->app->tasks->open_for( $manager, false ), 'title' ), 'Dated first, undated last.' );
		$this->assertSame( '', $this->app->db->last_error );
		$this->assertSame( array( 'עם תאריך' ), array_column( $this->app->tasks->open_for( $manager, false, $this->clock->today() ), 'title' ) );
		$this->assertSame( '', $this->app->db->last_error );
	}

	/**
	 * Regression: after an administrator moved a case to another manager, the
	 * former manager kept getting its tasks, and the case's title, on the
	 * dashboard and in the morning summary.
	 */
	public function test_a_moved_case_leaves_the_former_managers_tasks(): void {
		$former = $this->manager();
		$next   = $this->manager();
		$case   = $this->case_for( $former );
		( new TaskService( $this->app ) )->add(
			$case,
			array(
				'title'    => 'משימה בתיק שעבר',
				'due_date' => $this->clock->today(),
			),
			$former
		);
		$this->assertCount( 1, ( new Pages( $this->app ) )->dashboard_data( $former )['tasks'] );

		$moved = $this->app->case_service->update_details(
			$case,
			array(
				'title'      => $case->title,
				'manager_id' => (string) $next,
			),
			$this->admin()
		);
		$this->assertInstanceOf( CaseRecord::class, $moved );
		$this->assertSame( $next, $moved->manager_id );

		$this->assertSame( array(), ( new Pages( $this->app ) )->dashboard_data( $former )['tasks'] );
		( new MorningSummary( $this->app ) )->run();
		$this->assertNotContains( (string) get_userdata( $former )->user_email, array_column( $this->mails, 'to' ) );
	}
}
