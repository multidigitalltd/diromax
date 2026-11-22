<?php
/**
 * Documents from the client, document requests and the case report (spec 16.4–16.5).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\DocRequestService;
use Diromax\Portal\Service\DocumentService;
use WP_Error;

final class ClientDocumentsTest extends PortalTestCase {

	/**
	 * A case with an owner who gets email.
	 *
	 * @return array{0:int, 1:CaseRecord, 2:int} Manager, case, owner.
	 */
	private function case_with_owner(): array {
		$manager = $this->manager();
		$owner   = $this->client( 'owner' . wp_rand() . '@example.org' );

		return array( $manager, $this->case_for( $manager, $owner ), $owner );
	}

	public function test_an_owner_uploads_a_document_and_the_manager_is_told(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();

		$document = $this->app->document_service->upload_from_client( $case, $this->sample_pdf(), 'תעודת זהות', $owner, null );

		$this->assertInstanceOf( DocumentRecord::class, $document );
		$this->assertSame( 'client', $document->kind );
		$this->assertSame( $owner, $document->uploaded_by );
		$this->assertTrue( $document->visible );
		$this->assertSame( 'תעודת זהות', $document->title );
		$this->assertSame( (string) get_userdata( $manager )->user_email, $this->mails[0]['to'] ?? '' );
		$this->assertStringContainsString( 'מסמך חדש מהלקוח', (string) $this->mails[0]['subject'] );
		$this->assertContains( 'document.client_upload', array_column( $this->app->audit->for_case( $case->id ), 'action' ) );
	}

	public function test_who_may_upload(): void {
		[ $manager, $case ] = $this->case_with_owner();
		$viewer             = $this->client();
		$this->app->members->upsert( $case->id, $viewer, MemberRole::Viewer, true, $this->clock->mysql() );
		$stranger = $this->client();
		$service  = $this->app->document_service;

		$this->assertSame( 'dx_forbidden', $service->upload_from_client( $case, $this->sample_pdf(), 'x', $viewer, null )->get_error_code(), 'A viewer sees, but does not upload.' );
		$this->assertSame( 'dx_not_found', $service->upload_from_client( $case, $this->sample_pdf(), 'x', $stranger, null )->get_error_code(), 'Someone else\'s case answers like a missing one.' );
		$this->assertSame( 'dx_not_found', $service->upload_from_client( $case, $this->sample_pdf(), 'x', $manager, null )->get_error_code(), 'Staff upload in the admin screens.' );

		$owner  = $this->client();
		$closed = $this->case_for( $manager, $owner );
		$this->app->case_service->set_status( $closed, 'closed', $manager );
		$closed = $this->app->cases->find( $closed->id );
		$this->assertInstanceOf( CaseRecord::class, $closed );
		$this->assertSame( 'dx_forbidden', $service->upload_from_client( $closed, $this->sample_pdf(), 'x', $owner, null )->get_error_code(), 'Not into a closed case.' );
	}

	public function test_a_wrong_file_or_a_missing_title_is_refused(): void {
		[ , $case, $owner ] = $this->case_with_owner();

		$bad = $this->sample_pdf();
		file_put_contents( (string) $bad['tmp_name'], '<?php echo 1;' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$bad['name'] = 'evil.php';

		$this->assertSame( array( 'file' => 'type' ), $this->app->document_service->upload_from_client( $case, $bad, 'x', $owner, null )->get_error_data()['fields'] );
		$this->assertSame( array( 'title' => 'required' ), $this->app->document_service->upload_from_client( $case, $this->sample_pdf(), '  ', $owner, null )->get_error_data()['fields'] );
		$this->assertSame( array(), $this->app->documents->for_case( $case->id, false ) );
	}

	public function test_uploads_are_limited_per_hour(): void {
		[ , $case, $owner ] = $this->case_with_owner();

		for ( $i = 0; $i < DocumentService::CLIENT_UPLOADS_PER_HOUR; $i++ ) {
			$this->assertInstanceOf( DocumentRecord::class, $this->app->document_service->upload_from_client( $case, $this->sample_pdf(), 'מסמך ' . $i, $owner, null ) );
		}

		$this->assertSame( 'dx_rate_limited', $this->app->document_service->upload_from_client( $case, $this->sample_pdf(), 'עוד אחד', $owner, null )->get_error_code() );
	}

	public function test_a_requested_document_is_emailed_shown_and_closed_by_the_upload(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$requests                   = new DocRequestService( $this->app );

		$id = $requests->create(
			$case,
			array(
				'title' => 'נסח טאבו עדכני',
				'note'  => 'מהחודש האחרון',
			),
			$manager
		);
		$this->assertIsInt( $id );
		$this->assertStringContainsString( 'מבקשים ממך מסמך: נסח טאבו עדכני', (string) ( $this->mails[0]['subject'] ?? '' ), 'Emailed at once.' );

		$view = ( new CaseView( $this->app ) )->build( $case, $owner, 'documents' );
		$this->assertSame( array( 'נסח טאבו עדכני' ), array_column( $view['requests'], 'title' ) );

		$document = $this->app->document_service->upload_from_client( $case, $this->sample_pdf(), '', $owner, $id );
		$this->assertInstanceOf( DocumentRecord::class, $document );
		$this->assertSame( 'נסח טאבו עדכני', $document->title, 'An empty title takes the request\'s.' );
		$this->assertSame( $id, $document->request_id );

		$request = $this->app->doc_requests->find( $id );
		$this->assertSame( 'done', $request['status'] ?? '' );
		$this->assertSame( $document->id, $request['document_id'] ?? null );

		$again = $this->app->document_service->upload_from_client( $case, $this->sample_pdf(), 'שוב', $owner, $id );
		$this->assertSame( array( 'request' => 'closed' ), $again->get_error_data()['fields'] );
		$this->assertSame( array(), ( new CaseView( $this->app ) )->build( $case, $owner, 'documents' )['requests'] );
	}

	public function test_requests_belong_to_their_case_and_manager(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$other                      = $this->manager();
		$requests                   = new DocRequestService( $this->app );
		$id                         = $requests->create( $case, array( 'title' => 'אישור זכויות' ), $manager );
		$this->assertIsInt( $id );

		$this->assertSame( 'dx_forbidden', $requests->create( $case, array( 'title' => 'x' ), $other )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $requests->cancel( $case, $id, $other )->get_error_code() );
		$this->assertSame( array( 'title' => 'required' ), $requests->create( $case, array( 'title' => '' ), $manager )->get_error_data()['fields'] );

		[ , $elsewhere, $stranger ] = $this->case_with_owner();
		$this->assertSame( array( 'request' => 'closed' ), $this->app->document_service->upload_from_client( $elsewhere, $this->sample_pdf(), 'x', $stranger, $id )->get_error_data()['fields'], 'A request of another case cannot be answered.' );

		$this->assertTrue( $requests->cancel( $case, $id, $manager ) );
		$this->assertSame( 'cancelled', $this->app->doc_requests->find( $id )['status'] ?? '' );
		$this->assertInstanceOf( WP_Error::class, $requests->cancel( $case, $id, $manager ), 'Only once.' );
		$this->assertInstanceOf( WP_Error::class, $this->app->document_service->upload_from_client( $case, $this->sample_pdf(), 'x', $owner, $id ) );
	}

	public function test_the_report_shows_only_what_the_client_sees(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$this->add_document( $case, $manager, 'quote', false );
		$this->app->ledger_service->add( $case, self::expense( array( 'description' => 'הוצאה גלויה' ) ), $manager );
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'description' => 'הוצאה פנימית',
					'visible'     => '',
				)
			),
			$manager
		);

		$report = ( new CaseView( $this->app ) )->report( $case, $owner );

		$this->assertSame( array( 'הוצאה גלויה' ), array_column( $report['entries'], 'description' ) );
		$this->assertSame( 7, count( $report['stages'] ) );
		$this->assertSame( array(), array_filter( array_column( $report['stages'], 'due' ) ), 'No internal due dates.' );
		$this->assertArrayHasKey( 'signed', $report );
	}
}
