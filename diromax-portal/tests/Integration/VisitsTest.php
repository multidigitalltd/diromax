<?php
/**
 * Site visits and findings: reports, what the client sees, photos before
 * and after, fixing, overdue follow-up, email on request (spec 23).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\MorningSummary;
use Diromax\Portal\Service\VisitService;
use WP_Error;

final class VisitsTest extends PortalTestCase {

	/**
	 * Temporary image files made by the tests.
	 *
	 * @var string[]
	 */
	private array $images = array();

	public function tear_down(): void {
		foreach ( $this->images as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}

		parent::tear_down();
	}

	/**
	 * A JPEG as one $_FILES item.
	 *
	 * @return array<string, mixed>
	 */
	private function jpeg(): array {
		add_filter( 'dx_portal_is_uploaded_file', '__return_true' );

		$path  = (string) tempnam( sys_get_temp_dir(), 'dxv' );
		$image = imagecreatetruecolor( 640, 480 );
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 90, 90, 90 ) );
		imagejpeg( $image, $path, 90 );
		$this->images[] = $path;

		return array(
			'name'     => 'crack.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $path ),
		);
	}

	/**
	 * A visit form.
	 *
	 * @param array<string, string> $overrides Fields.
	 * @return array<string, string>
	 */
	private function visit_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'visit_on'  => $this->clock->today(),
				'attendees' => 'דניאל לוי, קבלן השלד',
				'summary'   => 'יציקת תקרה בקומה השנייה. נמצאו ליקויים.',
				'visible'   => '1',
				'notify'    => '',
			),
			$overrides
		);
	}

	/**
	 * A finding form.
	 *
	 * @param array<string, string> $overrides Fields.
	 * @return array<string, string>
	 */
	private static function finding_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'    => 'סדק בקיר המזרחי',
				'location' => 'חדר שינה',
				'severity' => 'high',
				'supplier' => 'קבלן השלד',
				'due_on'   => '2026-11-25',
				'visible'  => '1',
			),
			$overrides
		);
	}

	/**
	 * A case with a client and one visit.
	 *
	 * @param array<string, string> $visit Visit overrides.
	 * @return array{0:CaseRecord, 1:int, 2:int, 3:int} Case, manager, client, visit.
	 */
	private function visited( array $visit = array() ): array {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );
		$id      = ( new VisitService( $this->app ) )->add_visit( $case, $this->visit_input( $visit ), $manager );
		$this->assertIsInt( $id );

		return array( $case, $manager, $client, $id );
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

	public function test_a_visit_with_a_photographed_finding_and_its_fix(): void {
		[ $case, $manager, , $visit ] = $this->visited();
		$service                      = new VisitService( $this->app );

		$id = $service->add_finding( $case, $visit, self::finding_input(), $this->jpeg(), $manager );
		$this->assertIsInt( $id );
		$finding = $this->app->visits->find_finding( $id );
		$this->assertNotNull( $finding );
		$this->assertNotNull( $finding['photo_id'] );
		$this->assertTrue( $this->app->photos->find( (int) $finding['photo_id'] )['visible'] ?? false, 'The photo joins the journal, shown like the finding.' );

		$staff = $service->for_staff( $case );
		$this->assertSame(
			array(
				'open'    => 1,
				'overdue' => 0,
				'fixed'   => 0,
			),
			$staff['totals']
		);
		$this->assertNotNull( $staff['visits'][0]['findings'][0]['photo'] );

		$this->assertTrue( $service->fix( $case, $id, 'הקיר תוקן ונצבע', $this->jpeg(), $manager ) );
		$fixed = $this->app->visits->find_finding( $id );
		$this->assertSame( array( 'fixed', $this->clock->today(), 'הקיר תוקן ונצבע' ), array( $fixed['status'] ?? '', $fixed['fixed_on'] ?? '', $fixed['fix_note'] ?? '' ) );
		$this->assertNotNull( $fixed['after_photo_id'] ?? null );
		$this->assertSame( 'dx_not_found', $this->code( $service->fix( $case, $id, '', null, $manager ) ), 'Fixed once.' );

		$this->assertTrue( $service->reopen( $case, $id, $manager ) );
		$this->assertSame( 'open', $this->app->visits->find_finding( $id )['status'] ?? '' );
		$this->assertSame( 'finding.reopened', $this->app->audit->for_case( $case->id, 1 )[0]['action'] );
	}

	public function test_the_client_sees_only_what_the_manager_shows(): void {
		[ $case, $manager, $client, $visit ] = $this->visited();
		$service                             = new VisitService( $this->app );
		$shown                               = $service->add_finding( $case, $visit, self::finding_input(), $this->jpeg(), $manager );
		$inside                              = $service->add_finding(
			$case,
			$visit,
			self::finding_input(
				array(
					'title'   => 'הערה לקבלן',
					'visible' => '',
				)
			),
			$this->jpeg(),
			$manager
		);
		$this->assertIsInt( $shown );
		$this->assertIsInt( $inside );
		$hidden_visit = $service->add_visit(
			$case,
			$this->visit_input(
				array(
					'summary' => 'ביקור פנימי',
					'visible' => '',
				)
			),
			$manager
		);
		$this->assertIsInt( $hidden_visit );
		$this->assertIsInt( $service->add_finding( $case, $hidden_visit, self::finding_input( array( 'title' => 'בביקור פנימי' ) ), null, $manager ) );

		$client_view = $service->for_client( $case );
		$this->assertNotNull( $client_view );
		$this->assertCount( 1, $client_view['visits'] );
		$this->assertSame( array( 'סדק בקיר המזרחי' ), array_column( $client_view['visits'][0]['findings'], 'title' ) );
		$this->assertFalse( $this->app->photos->find( (int) $this->app->visits->find_finding( $inside )['photo_id'] )['visible'] ?? true, 'An internal finding keeps its photo internal.' );

		// Hiding the visit hides its findings' photos too.
		$this->assertTrue( $service->update_visit( $case, $visit, $this->visit_input( array( 'visible' => '' ) ), $manager ) );
		$this->assertNull( $service->for_client( $case ) );
		$this->assertFalse( $this->app->photos->find( (int) $this->app->visits->find_finding( $shown )['photo_id'] )['visible'] ?? true );
		$this->assertNull( ( new CaseView( $this->app ) )->build( $case, $client, 'visits' )['visits'], 'No tab when nothing is shown.' );
	}

	public function test_overdue_findings_reach_the_tab_the_dashboard_and_the_morning_summary(): void {
		[ $case, $manager, , $visit ] = $this->visited();
		$service                      = new VisitService( $this->app );
		$late                         = $service->add_finding( $case, $visit, self::finding_input( array( 'due_on' => '2026-11-10' ) ), null, $manager );
		$this->assertIsInt( $late );
		$this->assertIsInt(
			$service->add_finding(
				$case,
				$visit,
				self::finding_input(
					array(
						'title'  => 'בזמן',
						'due_on' => '2026-12-31',
					)
				),
				null,
				$manager
			)
		);

		$this->assertSame( 1, $service->for_staff( $case )['totals']['overdue'] );
		$this->assertSame( array( $late ), array_map( static fn( array $row ): int => $row['finding']['id'], $this->app->visits->overdue( $manager, $this->clock->today() ) ) );
		$this->assertSame( array(), $this->app->visits->overdue( $this->manager(), $this->clock->today() ), 'Another manager: not theirs.' );

		wp_set_current_user( $manager );
		$pages = new Pages( $this->app );
		$this->assertCount( 1, $pages->dashboard_data( $manager )['findings'] );
		$this->assertSame( array( 'visits', 2, 'ביקורים וממצאים' ), array( $pages->case_data( $case, $manager, 'visits' )['tab'], $pages->case_data( $case, $manager, 'visits' )['counts']['visits'], $pages->case_data( $case, $manager, 'visits' )['tabs']['visits'] ) );

		( new MorningSummary( $this->app ) )->run();
		$mine = array_values( array_filter( $this->mails, static fn( array $mail ): bool => get_userdata( $manager )->user_email === $mail['to'] ) );
		$this->assertCount( 1, $mine );
		$this->assertStringContainsString( 'ממצא 1 עבר את מועד התיקון', (string) $mine[0]['message'] );
	}

	public function test_the_client_hears_of_a_shown_visit_only_when_the_manager_asks(): void {
		[ $case, $manager ] = $this->visited();
		$this->assertSame( 0, $this->app->notifier->pending( $case )['count'] );

		$service = new VisitService( $this->app );
		$hidden  = $service->add_visit(
			$case,
			$this->visit_input(
				array(
					'visible' => '',
					'notify'  => '1',
				)
			),
			$manager
		);
		$this->assertIsInt( $hidden );
		$this->assertSame( 0, $this->app->notifier->pending( $case )['count'], 'Not shown: nothing to tell.' );

		$visit = $service->add_visit(
			$case,
			$this->visit_input(
				array(
					'summary' => 'בדיקת איטום הגג',
					'notify'  => '1',
				)
			),
			$manager
		);
		$this->assertIsInt( $visit );
		$this->assertIsInt( $service->add_finding( $case, $visit, self::finding_input( array( 'title' => 'רטיבות בפינת הגג' ) ), null, $manager ) );
		$this->assertSame( 1, $this->app->notifier->pending( $case )['count'] );

		$this->assertSame( 1, $this->app->notifier->send_now( $case, $manager ) );
		$body = (string) end( $this->mails )['message'];
		$this->assertStringContainsString( 'דוח ביקור באתר', $body );
		$this->assertStringContainsString( 'בדיקת איטום הגג', $body );
		$this->assertStringContainsString( 'רטיבות בפינת הגג', $body );
	}

	public function test_bad_input_is_refused_by_field(): void {
		[ $case, $manager, , $visit ] = $this->visited();
		$service                      = new VisitService( $this->app );

		$this->assertSame(
			array(
				'visit_on' => 'future',
				'summary'  => 'required',
			),
			$service->add_visit(
				$case,
				$this->visit_input(
					array(
						'visit_on' => '2027-01-01',
						'summary'  => '',
					)
				),
				$manager
			)->get_error_data()['fields'] ?? array()
		);
		$this->assertSame(
			array(
				'title'    => 'required',
				'severity' => 'invalid',
			),
			$service->add_finding(
				$case,
				$visit,
				self::finding_input(
					array(
						'title'    => '',
						'severity' => 'x',
					)
				),
				null,
				$manager
			)->get_error_data()['fields'] ?? array()
		);
		$this->assertSame( 'dx_not_found', $this->code( $service->add_finding( $case, $visit + 999, self::finding_input(), null, $manager ) ) );
	}

	public function test_other_managers_clients_closed_cases_and_other_cases_are_refused(): void {
		[ $case, $manager, $client, $visit ] = $this->visited();
		$service                             = new VisitService( $this->app );
		$finding                             = $service->add_finding( $case, $visit, self::finding_input(), null, $manager );
		$this->assertIsInt( $finding );
		[ $elsewhere, $its_manager ] = $this->visited();

		foreach ( array( $this->manager(), $client ) as $actor ) {
			$this->assertSame( 'dx_forbidden', $this->code( $service->add_visit( $case, $this->visit_input(), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->update_visit( $case, $visit, $this->visit_input(), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->delete_visit( $case, $visit, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->add_finding( $case, $visit, self::finding_input(), null, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->update_finding( $case, $finding, self::finding_input(), $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->fix( $case, $finding, '', null, $actor ) ) );
			$this->assertSame( 'dx_forbidden', $this->code( $service->delete_finding( $case, $finding, $actor ) ) );
		}

		$this->assertSame( 'dx_not_found', $this->code( $service->fix( $elsewhere, $finding, '', null, $its_manager ) ), 'A finding of another case.' );
		$this->assertSame( 'dx_not_found', $this->code( $service->delete_visit( $elsewhere, $visit, $its_manager ) ) );
		$this->assertSame( 'dx_not_found', $this->code( $service->add_finding( $elsewhere, $visit, self::finding_input(), null, $its_manager ) ) );
		$this->assertNotNull( $this->app->visits->find_finding( $finding ) );

		$this->assertInstanceOf( CaseRecord::class, $this->app->case_service->set_status( $case, 'closed', $manager ) );
		$closed = $this->app->cases->find( $case->id );
		$this->assertNotNull( $closed );
		$this->assertSame( 'dx_closed', $this->code( $service->fix( $closed, $finding, '', null, $manager ) ) );
		$this->assertSame( 'dx_closed', $this->code( $service->add_visit( $closed, $this->visit_input(), $manager ) ) );
	}

	public function test_editing_a_shown_visit_can_send_the_report(): void {
		// Regression (PR #19 review): the email box was ignored unless the visit had just been made visible.
		[ $case, $manager, , $visit ] = $this->visited();
		$service                      = new VisitService( $this->app );
		$this->assertIsInt( $service->add_finding( $case, $visit, self::finding_input(), null, $manager ) );
		$this->assertSame( 0, $this->app->notifier->pending( $case )['count'] );

		$this->assertTrue( $service->update_visit( $case, $visit, $this->visit_input( array( 'notify' => '1' ) ), $manager ) );
		$this->assertSame( 1, $this->app->notifier->pending( $case )['count'] );
	}

	public function test_hiding_a_visit_hides_every_finding_photo_beyond_the_screen_list(): void {
		// Regression (PR #19 review): photo visibility followed the capped list of 500 findings.
		[ $case, $manager, , $visit ] = $this->visited();
		$photo                        = ( new \Diromax\Portal\Service\PhotoService( $this->app ) )->attach( $case, $this->jpeg(), 'הממצא האחרון', true, $manager );
		$this->assertIsInt( $photo );

		for ( $i = 0; $i < 500; $i++ ) {
			$this->app->visits->insert_finding(
				$case->id,
				$visit,
				array(
					'title'    => 'ממצא ' . $i,
					'severity' => 'low',
					'visible'  => true,
				),
				$this->clock->mysql()
			);
		}
		$this->app->visits->insert_finding(
			$case->id,
			$visit,
			array(
				'title'    => 'הממצא האחרון',
				'severity' => 'low',
				'visible'  => true,
				'photo_id' => $photo,
			),
			$this->clock->mysql()
		);

		$this->assertTrue( ( new VisitService( $this->app ) )->update_visit( $case, $visit, $this->visit_input( array( 'visible' => '' ) ), $manager ) );
		$this->assertFalse( $this->app->photos->find( $photo )['visible'] ?? true );
	}

	public function test_deleting_a_visit_or_its_case_removes_the_findings(): void {
		global $wpdb;
		[ $case, $manager, , $visit ] = $this->visited();
		$service                      = new VisitService( $this->app );
		$finding                      = $service->add_finding( $case, $visit, self::finding_input(), null, $manager );
		$this->assertIsInt( $finding );

		$this->assertTrue( $service->delete_visit( $case, $visit, $manager ) );
		$this->assertNull( $this->app->visits->find_finding( $finding ) );

		$again = $service->add_visit( $case, $this->visit_input(), $manager );
		$this->assertIsInt( $again );
		$this->assertIsInt( $service->add_finding( $case, $again, self::finding_input(), null, $manager ) );
		$this->assertIsInt( $this->app->case_service->delete( $case, $this->admin() ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'findings' ), $case->id ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'visits' ), $case->id ) ) );
	}
}
