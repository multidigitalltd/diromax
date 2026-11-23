<?php
/**
 * Meetings in a case: invitations, changes, cancellations and reminders
 * (spec 20.1).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\MeetingService;
use Diromax\Portal\Service\MorningSummary;

final class MeetingsTest extends PortalTestCase {

	/**
	 * Calendar files attached to the emails sent, by recipient.
	 *
	 * @var array<int, array{to:string, subject:string, ics:string}>
	 */
	private array $invites = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Asia/Jerusalem' );
		// Read the attachment before the sender deletes it; then let the base class capture the mail.
		add_filter( 'pre_wp_mail', array( $this, 'read_invite' ), 5, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'read_invite' ), 5 );
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Keep the calendar file of an outgoing email.
	 *
	 * @param null|bool            $short Short-circuit value.
	 * @param array<string, mixed> $atts  wp_mail arguments.
	 * @return null|bool
	 */
	public function read_invite( $short, array $atts ) {
		$file = $atts['attachments']['meeting.ics'] ?? null;

		if ( is_string( $file ) && is_file( $file ) ) {
			$this->invites[] = array(
				'to'      => (string) $atts['to'],
				'subject' => (string) $atts['subject'],
				'ics'     => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary file.
			);
		}

		return $short;
	}

	/**
	 * A case with three clients: two who get email, one who turned it off.
	 *
	 * @return array{0:CaseRecord, 1:int, 2:int, 3:int, 4:int} Case, manager, owner, partner, quiet viewer.
	 */
	private function family(): array {
		$manager = $this->manager();
		$owner   = $this->client( 'owner@example.org' );
		$partner = $this->client( 'partner@example.org' );
		$quiet   = $this->client( 'quiet@example.org' );
		$case    = $this->case_for( $manager, $owner );
		$this->app->members->upsert( $case->id, $partner, MemberRole::Partner, true, $this->clock->mysql() );
		$this->app->members->upsert( $case->id, $quiet, MemberRole::Viewer, true, $this->clock->mysql() );
		$this->app->members->set_mode( $case->id, $quiet, NotifyMode::Off, $this->clock->mysql() );

		return array( $case, $manager, $owner, $partner, $quiet );
	}

	/**
	 * A meeting form.
	 *
	 * @param array<string, string> $overrides Fields.
	 * @return array<string, string>
	 */
	private static function form( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'    => 'סיור בדירה עם האדריכלית',
				'kind'     => 'site',
				'date'     => '2026-11-24',
				'time'     => '09:30',
				'minutes'  => '90',
				'location' => 'רח׳ הזית 14, קריית אונו',
				'note'     => 'להביא את התוכניות',
				'notify'   => '1',
			),
			$overrides
		);
	}

	public function test_only_the_chosen_clients_are_invited_and_see_the_meeting(): void {
		[ $case, $manager, $owner, $partner, $quiet ] = $this->family();
		$service                                      = new MeetingService( $this->app );

		$id = $service->create( $case, self::form(), array( $owner, $quiet ), $manager );

		$this->assertIsInt( $id );
		$this->assertSame( array( 'owner@example.org' ), array_column( $this->invites, 'to' ), 'The client who turned email off gets none.' );
		$ics = str_replace( "\r\n ", '', $this->invites[0]['ics'] );
		$this->assertStringContainsString( "METHOD:REQUEST\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20261124T073000Z\r\n", $ics, 'Typed in Israel time, stored in UTC.' );
		$this->assertStringContainsString( "DTEND:20261124T090000Z\r\n", $ics );
		$this->assertStringContainsString( 'mailto:owner@example.org', $ics );
		$this->assertStringContainsString( 'הזמנה לפגישה', $this->invites[0]['subject'] );

		$view = new CaseView( $this->app );
		$this->assertSame( array( 'סיור בדירה עם האדריכלית' ), array_column( $view->build( $case, $owner, 'finance' )['meetings'], 'title' ) );
		$this->assertCount( 1, $view->build( $case, $quiet, 'finance' )['meetings'], 'Seen in the portal even without email.' );
		$this->assertSame( array(), $view->build( $case, $partner, 'finance' )['meetings'], 'Not invited: not shown.' );

		$meeting = $this->app->meetings->find( $id );
		$this->assertNotNull( $meeting );
		$this->assertTrue( $service->may_view( $case, $meeting, $owner ) );
		$this->assertTrue( $service->may_view( $case, $meeting, $manager ) );
		$this->assertFalse( $service->may_view( $case, $meeting, $partner ) );
		$this->assertFalse( $service->may_view( $case, $meeting, $this->manager() ) );
		$this->assertSame( 'meeting.created', $this->app->audit->for_case( $case->id, 1 )[0]['action'] );
	}

	public function test_a_change_updates_adds_and_cancels_per_person(): void {
		[ $case, $manager, $owner, $partner ] = $this->family();
		$third                                = $this->client( 'third@example.org' );
		$this->app->members->upsert( $case->id, $third, MemberRole::Partner, true, $this->clock->mysql() );
		$service = new MeetingService( $this->app );
		$id      = $service->create( $case, self::form(), array( $owner, $partner ), $manager );
		$this->assertIsInt( $id );
		$this->app->meetings->mark_reminded( $id, true );
		$this->invites = array();

		$this->assertTrue( $service->update( $case, $id, self::form( array( 'time' => '11:00' ) ), array( $partner, $third ), $manager ) );

		$sent = array_column( $this->invites, 'ics', 'to' );
		$this->assertStringContainsString( 'METHOD:CANCEL', $sent['owner@example.org'], 'Taken off: cancelled in their calendar.' );
		$this->assertStringContainsString( 'SEQUENCE:1', $sent['partner@example.org'] );
		$this->assertStringContainsString( 'DTSTART:20261124T090000Z', $sent['partner@example.org'] );
		$this->assertStringContainsString( 'METHOD:REQUEST', $sent['third@example.org'] );
		$this->assertSame( array( $partner, $third ), $this->app->meetings->participants( $id ) );
		$this->assertFalse( $this->app->meetings->find( $id )['reminded_before'] ?? true, 'A new time sends the reminders again.' );
	}

	public function test_cancelling_tells_the_invited_and_takes_it_off_their_page(): void {
		[ $case, $manager, $owner ] = $this->family();
		$service                    = new MeetingService( $this->app );
		$id                         = $service->create( $case, self::form(), array( $owner ), $manager );
		$this->assertIsInt( $id );
		$this->invites = array();

		$this->assertTrue( $service->cancel( $case, $id, true, $manager ) );

		$this->assertStringContainsString( 'STATUS:CANCELLED', $this->invites[0]['ics'] ?? '' );
		$this->assertSame( array(), ( new CaseView( $this->app ) )->build( $case, $owner, 'finance' )['meetings'] );
		$this->assertSame( 'cancelled', $service->for_staff( $case )[0]['status'] );
		$this->assertInstanceOf( \WP_Error::class, $service->cancel( $case, $id, true, $manager ), 'Only once.' );
	}

	public function test_reminders_go_the_day_before_and_that_morning_once_each(): void {
		[ $case, $manager, $owner ] = $this->family();
		$service                    = new MeetingService( $this->app );
		$this->assertIsInt(
			$service->create(
				$case,
				self::form(
					array(
						'date' => '2026-11-20',
						'time' => '16:00',
					)
				),
				array( $owner ),
				$manager
			)
		);
		$this->assertIsInt(
			$service->create(
				$case,
				self::form(
					array(
						'date'  => '2026-11-19',
						'time'  => '15:00',
						'title' => 'שיחה היום',
					)
				),
				array( $owner ),
				$manager
			)
		);
		$this->invites = array();

		$this->assertSame( 2, $service->send_reminders() );
		$subjects = implode( "\n", array_column( $this->invites, 'subject' ) );
		$this->assertStringContainsString( 'תזכורת: פגישה מחר: סיור בדירה עם האדריכלית', $subjects );
		$this->assertStringContainsString( 'תזכורת: פגישה היום: שיחה היום', $subjects );
		$this->assertSame( 0, $service->send_reminders(), 'Each reminder is sent once.' );

		$this->clock->advance( DAY_IN_SECONDS );
		$this->assertSame( 1, $service->send_reminders(), 'The morning of the meeting.' );
	}

	public function test_only_the_case_manager_may_schedule_and_only_clients_of_the_case(): void {
		[ $case, $manager, $owner ] = $this->family();
		$service                    = new MeetingService( $this->app );
		$stranger                   = $this->client( 'stranger@example.org' );

		foreach ( array( $owner, $this->manager(), 0 ) as $intruder ) {
			$result = $service->create( $case, self::form(), array(), $intruder );
			$this->assertSame( 'dx_forbidden', $result instanceof \WP_Error ? $result->get_error_code() : 'created' );
		}

		$outside = $service->create( $case, self::form(), array( $stranger ), $manager );
		$this->assertSame( array( 'participants' => 'invalid' ), $outside instanceof \WP_Error ? $outside->get_error_data()['fields'] : array() );
		$past = $service->create( $case, self::form( array( 'date' => '2026-11-18' ) ), array(), $manager );
		$this->assertSame( array( 'date' => 'past' ), $past instanceof \WP_Error ? $past->get_error_data()['fields'] : array() );

		$id = $service->create( $case, self::form(), array(), $manager );
		$this->assertIsInt( $id );
		$this->assertSame( 'dx_forbidden', ( $service->cancel( $case, $id, false, $owner ) )->get_error_code() );
		$other = $this->case_for( $manager );
		$this->assertSame( 'dx_not_found', ( $service->update( $other, $id, self::form(), array(), $manager ) )->get_error_code(), 'A meeting is changed only through its own case.' );
	}

	public function test_staff_see_the_coming_meetings_and_the_case_deletion_takes_them(): void {
		global $wpdb;

		[ $case, $manager, $owner ] = $this->family();
		$service                    = new MeetingService( $this->app );
		$this->assertIsInt( $service->create( $case, self::form( array( 'date' => '2026-11-20' ) ), array( $owner ), $manager ) );

		$this->assertCount( 1, ( new MorningSummary( $this->app ) )->meetings( $manager ) );
		$this->assertSame( array(), ( new MorningSummary( $this->app ) )->meetings( $this->manager() ), 'Another manager: not theirs.' );
		$this->assertCount( 1, ( new Pages( $this->app ) )->dashboard_data( $manager )['meetings'] );

		$this->assertIsInt( $this->app->case_service->delete( $case, $this->admin() ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'meeting_people' ) ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'meetings' ), $case->id ) ) );
	}
}
