<?php
/**
 * A case's timeline: planned dates, publication, moved dates with the date
 * first shown and a note, milestones, email on request, and a plan from
 * stage lengths (spec 22).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\ScheduleService;
use Diromax\Portal\Settings;
use WP_Error;

final class ScheduleTest extends PortalTestCase {

	/**
	 * Options of a save.
	 *
	 * @param bool   $visible Show to the client.
	 * @param bool   $notify  Email the client.
	 * @param string $note    Note.
	 * @return array{note:string, visible:bool, notify:bool}
	 */
	private static function options( bool $visible, bool $notify = false, string $note = '' ): array {
		return array(
			'note'    => $note,
			'visible' => $visible,
			'notify'  => $notify,
		);
	}

	/**
	 * A case with a client, and a timeline for stages 1 and 2.
	 *
	 * @return array{0:CaseRecord, 1:int, 2:int} Case, manager, client.
	 */
	private function planned(): array {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		$this->assertTrue(
			( new ScheduleService( $this->app ) )->save(
				$case,
				array(
					1 => '2026-12-01',
					2 => '2027-01-01',
				),
				array(
					1 => '2026-12-31',
					2 => '2027-03-31',
				),
				self::options( false ),
				$manager
			)
		);

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
	 * The error code of a refusal, or '' when it was allowed.
	 *
	 * @param mixed $result Service result.
	 * @return string
	 */
	private function code( mixed $result ): string {
		return $result instanceof WP_Error ? (string) $result->get_error_code() : '';
	}

	public function test_planned_dates_are_the_stage_due_dates_and_stay_internal_until_published(): void {
		[ $case, $manager ] = $this->planned();
		$service            = new ScheduleService( $this->app );

		$this->assertSame( array( '2026-12-31', '2027-03-31' ), array( $case->stages[1]['due'], $case->stages[2]['due'] ), 'The planned end is the due date.' );
		$this->assertNull( $service->for_client( $case ), 'Not published yet.' );
		$staff = $service->for_staff( $case );
		$this->assertSame( array( '2026-12-01', '2026-12-31', false ), array( $staff['rows'][0]['start'], $staff['rows'][0]['end'], $staff['visible'] ) );
		$this->assertNotNull( $staff['layout'] );

		$this->assertTrue(
			$service->save(
				$case,
				array( 1 => '2026-12-01' ),
				array(
					1 => '2026-12-31',
					2 => '2027-03-31',
				),
				self::options( true ),
				$manager
			)
		);
		$case   = $this->fresh( $case );
		$client = $service->for_client( $case );
		$this->assertTrue( $case->schedule_visible );
		$this->assertNotNull( $client );
		$this->assertSame( array( false, '' ), array( $client['rows'][1]['moved'], $client['rows'][1]['note'] ) );
		$this->assertSame( 'schedule.updated', $this->app->audit->for_case( $case->id, 1 )[0]['action'] );
	}

	public function test_a_moved_date_shows_the_first_one_and_the_note_and_moving_back_clears_them(): void {
		[ $case, $manager ] = $this->planned();
		$service            = new ScheduleService( $this->app );
		$ends               = array(
			1 => '2026-12-31',
			2 => '2027-03-31',
		);
		$this->assertTrue( $service->save( $case, array(), $ends, self::options( true ), $manager ) );

		$ends[2] = '2027-05-15';
		$this->assertTrue( $service->save( $this->fresh( $case ), array(), $ends, self::options( true, false, 'הוועדה דחתה את הדיון' ), $manager ) );
		$case = $this->fresh( $case );
		$row  = $service->for_client( $case )['rows'][1] ?? array();
		$this->assertSame( array( true, '31.03.2027', 'הוועדה דחתה את הדיון' ), array( $row['moved'] ?? null, $row['first_label'] ?? '', $row['note'] ?? '' ) );
		$this->assertFalse( $service->for_client( $case )['rows'][0]['moved'] ?? true, 'Stage 1 did not move, and keeps no note.' );
		$this->assertCount( 1, $service->moved( $case ) );

		$ends[2] = '2027-03-31';
		$this->assertTrue( $service->save( $case, array(), $ends, self::options( true, false, 'חזרנו ללוח' ), $manager ) );
		$row = $service->for_client( $this->fresh( $case ) )['rows'][1] ?? array();
		$this->assertSame( array( false, '' ), array( $row['moved'] ?? null, $row['note'] ?? 'x' ) );
	}

	public function test_the_client_is_emailed_only_when_the_manager_asks_and_the_timeline_is_shown(): void {
		[ $case, $manager ] = $this->planned();
		$service            = new ScheduleService( $this->app );
		$ends               = array(
			1 => '2026-12-31',
			2 => '2027-03-31',
		);

		$this->assertTrue( $service->save( $case, array(), $ends, self::options( false, true ), $manager ) );
		$this->assertSame( 0, $this->app->notifier->pending( $case )['count'], 'Not shown: nothing to tell.' );
		$this->assertTrue( $service->save( $this->fresh( $case ), array(), $ends, self::options( true ), $manager ) );
		$this->assertSame( 0, $this->app->notifier->pending( $case )['count'], 'Not asked.' );

		$ends[1] = '2027-01-20';
		$this->assertTrue( $service->save( $this->fresh( $case ), array(), $ends, self::options( true, true, 'עיכוב בקבלת המדידה' ), $manager ) );
		$this->assertSame( 1, $this->app->notifier->pending( $case )['count'] );

		$this->assertSame( 1, $this->app->notifier->send_now( $this->fresh( $case ), $manager ) );
		$body = (string) end( $this->mails )['message'];
		$this->assertStringContainsString( 'לוח הזמנים של הפרויקט עודכן', $body );
		$this->assertStringContainsString( 'במקור 31.12.2026', $body );
		$this->assertStringContainsString( 'עיכוב בקבלת המדידה', $body );
	}

	public function test_bad_dates_are_refused_by_field(): void {
		[ $case, $manager ] = $this->planned();
		$result             = ( new ScheduleService( $this->app ) )->save(
			$case,
			array(
				1 => '2027-02-01',
				3 => '2027-02-30',
			),
			array( 1 => '2027-01-01' ),
			self::options( true ),
			$manager
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			array(
				'end-1'   => 'before_start',
				'start-3' => 'date',
			),
			$result->get_error_data()['fields']
		);
		$this->assertSame( '2026-12-31', $this->fresh( $case )->stages[1]['due'], 'Nothing saved.' );
	}

	public function test_a_plan_from_the_office_stage_lengths(): void {
		[ $case, $manager ] = $this->planned();
		$service            = new ScheduleService( $this->app );

		$this->assertSame( array( 'start_on' => 'no_weeks' ), $service->compute( $case, '2027-01-03', $manager )->get_error_data()['fields'] ?? array() );

		Settings::save(
			array(
				'schedule_weeks' => array(
					1 => '2',
					2 => '4',
					3 => '0',
				),
			)
		);
		$this->assertTrue( $service->compute( $case, '2027-01-03', $manager ) );
		$staff = $service->for_staff( $this->fresh( $case ) );
		$this->assertSame(
			array( '2027-01-03', '2027-01-16', '2027-01-17', '2027-02-13' ),
			array( $staff['rows'][0]['start'], $staff['rows'][0]['end'], $staff['rows'][1]['start'], $staff['rows'][1]['end'] )
		);
		$this->assertTrue( $staff['weeks'] );
		$this->assertSame( 'date', $this->code( $service->compute( $case, '2027-13-01', $manager ) ) === 'dx_invalid' ? 'date' : '', 'A bad first day is refused.' );
	}

	public function test_milestones_keep_their_first_date_once_shown_and_can_be_marked_reached(): void {
		[ $case, $manager ] = $this->planned();
		$service            = new ScheduleService( $this->app );

		$this->assertSame(
			array( 'date' => 'date' ),
			$service->add_milestone(
				$case,
				array(
					'label' => 'היתר',
					'stage' => '2',
					'date'  => '2027-02-30',
				),
				$manager
			)->get_error_data()['fields'] ?? array()
		);
		$id = $service->add_milestone(
			$case,
			array(
				'label' => 'קבלת היתר',
				'stage' => '2',
				'date'  => '2027-02-15',
			),
			$manager
		);
		$this->assertIsInt( $id );
		$row = $this->app->schedule->find( $id );
		$this->assertNotNull( $row );
		$this->assertNull( $row['first_ends_on'], 'Not shown yet: no first date.' );

		$this->assertTrue(
			$service->save(
				$case,
				array(),
				array(
					1 => '2026-12-31',
					2 => '2027-03-31',
				),
				self::options( true ),
				$manager
			)
		);
		$case = $this->fresh( $case );
		$this->assertSame( '2027-02-15', $this->app->schedule->find( $id )['first_ends_on'] ?? '' );

		$this->assertTrue(
			$service->update_milestone(
				$case,
				$id,
				array(
					'label' => 'קבלת היתר',
					'stage' => '2',
					'date'  => '2027-03-01',
					'note'  => 'השלמת מסמכים לוועדה',
				),
				$manager
			)
		);
		$milestone = $service->for_client( $case )['rows'][1]['milestones'][0] ?? array();
		$this->assertSame( array( true, '15.02.2027', 'השלמת מסמכים לוועדה' ), array( $milestone['moved'] ?? null, $milestone['first_label'] ?? '', $milestone['note'] ?? '' ) );

		$this->assertTrue( $service->set_done( $case, $id, true, $manager ) );
		$this->assertSame( $this->clock->today(), $this->app->schedule->find( $id )['done_on'] ?? '' );
		$this->assertTrue( $service->delete_milestone( $case, $id, $manager ) );
		$this->assertNull( $this->app->schedule->find( $id ) );
	}

	public function test_other_managers_clients_closed_cases_and_other_cases_are_refused(): void {
		[ $case, $manager, $client ] = $this->planned();
		$service                     = new ScheduleService( $this->app );
		$id                          = $service->add_milestone(
			$case,
			array(
				'label' => 'יציקה',
				'stage' => '1',
				'date'  => '2026-12-20',
			),
			$manager
		);
		$this->assertIsInt( $id );
		[ $elsewhere, $its_manager ] = $this->planned();

		foreach ( array( $this->manager(), $client ) as $actor ) {
			$this->assertSame( 'dx_forbidden', $this->code( $service->save( $case, array(), array(), self::options( true ), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->compute( $case, '2027-01-01', $actor ) ) );
			$this->assertSame(
				'dx_forbidden',
				$this->code(
					$service->add_milestone(
						$case,
						array(
							'label' => 'x',
							'stage' => '1',
							'date'  => '2027-01-01',
						),
						$actor
					)
				)
			);
			$this->assertSame(
				'dx_forbidden',
				$this->code(
					$service->update_milestone(
						$case,
						$id,
						array(
							'label' => 'x',
							'stage' => '1',
							'date'  => '2027-01-01',
						),
						$actor
					)
				)
			);
			$this->assertSame( 'dx_forbidden', $this->code( $service->set_done( $case, $id, true, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->delete_milestone( $case, $id, $actor ) ) );
		}

		$this->assertSame( 'dx_not_found', $this->code( $service->delete_milestone( $elsewhere, $id, $its_manager ) ), 'A milestone of another case.' );
		$this->assertSame( 'dx_not_found', $this->code( $service->set_done( $elsewhere, $id, true, $its_manager ) ) );
		$this->assertNotNull( $this->app->schedule->find( $id ) );

		$this->assertInstanceOf( CaseRecord::class, $this->app->case_service->set_status( $case, 'closed', $manager ) );
		$closed = $this->fresh( $case );
		$this->assertSame( 'dx_closed', $this->code( $service->save( $closed, array(), array( 1 => '2027-06-01' ), self::options( true, true ), $manager ) ) );
		$this->assertSame(
			'dx_closed',
			$this->code(
				$service->add_milestone(
					$closed,
					array(
						'label' => 'x',
						'stage' => '1',
						'date'  => '2027-01-01',
					),
					$manager
				)
			)
		);
		$this->assertSame( '2026-12-31', $this->fresh( $case )->stages[1]['due'] );
	}

	public function test_the_client_tab_and_the_staff_tab(): void {
		[ $case, $manager, $client ] = $this->planned();
		$view                        = new CaseView( $this->app );
		$this->assertNull( $view->build( $case, $client, 'schedule' )['schedule'], 'No tab before it is published.' );

		$this->assertTrue(
			( new ScheduleService( $this->app ) )->save(
				$case,
				array(),
				array(
					1 => '2026-12-31',
					2 => '2027-03-31',
				),
				self::options( true ),
				$manager
			)
		);
		$case = $this->app->case_service->move_to_stage( $this->fresh( $case ), 3, false, $manager );
		$this->assertInstanceOf( CaseRecord::class, $case );
		$schedule = $view->build( $case, $client, 'schedule' )['schedule'];
		$this->assertIsArray( $schedule );
		$this->assertStringContainsString( 'בפועל', (string) $schedule['rows'][0]['actual'] );

		wp_set_current_user( $manager );
		$staff = ( new Pages( $this->app ) )->case_data( $case, $manager, 'schedule' );
		$this->assertSame( array( 'schedule', 'לוח זמנים' ), array( $staff['tab'], $staff['tabs']['schedule'] ) );
		$this->assertTrue( $staff['visible'] );
	}

	public function test_deleting_a_case_removes_its_timeline(): void {
		global $wpdb;
		[ $case, $manager ] = $this->planned();
		$this->assertIsInt(
			( new ScheduleService( $this->app ) )->add_milestone(
				$case,
				array(
					'label' => 'יציקה',
					'stage' => '1',
					'date'  => '2026-12-20',
				),
				$manager
			)
		);

		$this->assertIsInt( $this->app->case_service->delete( $case, $this->admin() ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'schedule' ), $case->id ) ) );
	}
}
