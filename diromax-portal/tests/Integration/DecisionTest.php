<?php
/**
 * Decisions for client approval and agreement confirmations (spec 15.5–15.6).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\DecisionStatus;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\AgreementService;
use Diromax\Portal\Service\DecisionService;
use WP_Error;

final class DecisionTest extends PortalTestCase {

	/** A portal signature by someone who cannot draw: the typed name signs. */
	private const SIGNATURE = array(
		'name'    => 'משפחת כהן',
		'no_draw' => '1',
	);

	private DecisionService $decisions;

	public function set_up(): void {
		parent::set_up();
		$this->decisions = new DecisionService( $this->app );
	}

	public function test_a_manager_opens_a_decision_and_the_client_gets_an_email(): void {
		[ $manager, $case ] = $this->case_with_owner();

		$id = $this->decisions->create( $case, self::fields( array( 'notify' => '1' ) ), $manager );

		$this->assertIsInt( $id );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'מבקשים את אישורך: הצעת המחיר של הקבלן', $this->mails[0]['subject'] );
		$this->assertStringContainsString( '84,000 ₪', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'tab=decisions', $this->mails[0]['message'] );
	}

	public function test_only_the_case_manager_opens_decisions_and_input_is_validated(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();

		$this->assertInstanceOf( WP_Error::class, $this->decisions->create( $case, self::fields(), $this->manager() ) );
		$this->assertInstanceOf( WP_Error::class, $this->decisions->create( $case, self::fields(), $owner ) );

		$bad = $this->decisions->create(
			$case,
			self::fields(
				array(
					'title'    => ' ',
					'amount'   => 'abc',
					'due_date' => '2026-02-31',
				)
			),
			$manager
		);
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( array( 'title', 'amount', 'due_date' ), array_keys( $bad->get_error_data()['fields'] ) );
	}

	public function test_a_document_from_another_case_or_hidden_cannot_be_attached(): void {
		[ $manager, $case ] = $this->case_with_owner();
		$other              = $this->case_for( $manager );
		$foreign            = $this->add_document( $other, $manager, 'quote' );
		$hidden             = $this->add_document( $case, $manager, 'quote', false );

		foreach ( array( $foreign, $hidden ) as $document ) {
			$result = $this->decisions->create( $case, self::fields( array( 'document_id' => (string) $document->id ) ), $manager );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertArrayHasKey( 'document_id', $result->get_error_data()['fields'] );
		}
	}

	public function test_the_owner_approves_and_the_manager_is_told(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$quote                      = $this->add_document( $case, $manager, 'quote' );
		$id                         = $this->decisions->create( $case, self::fields( array( 'document_id' => (string) $quote->id ) ), $manager );

		$this->assertTrue( $this->decisions->answer( $case, $id, $owner, 'approve', 'מאשרים, תודה', true, self::SIGNATURE ) );

		$decision = $this->app->decisions->find( $id );
		$this->assertSame( DecisionStatus::Approved, $decision->status );
		$this->assertSame( $owner, $decision->answered_by );
		$this->assertSame( 'מאשרים, תודה', $decision->answer_note );
		$this->assertSame( $this->decisions->fingerprint( $decision ), $decision->fingerprint );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $decision->fingerprint );

		$this->assertSame( array( get_userdata( $manager )->user_email ), array_column( $this->mails, 'to' ) );
		$this->assertStringContainsString( 'הלקוח אישר', $this->mails[0]['subject'] );
	}

	public function test_approving_needs_the_confirmation_box_declining_does_not(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$first                      = $this->decisions->create( $case, self::fields(), $manager );
		$second                     = $this->decisions->create( $case, self::fields(), $manager );

		$result = $this->decisions->answer( $case, $first, $owner, 'approve', '', false );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'confirm', $result->get_error_data()['fields'] );
		$this->assertTrue( $this->app->decisions->find( $first )->is_pending() );

		$this->assertTrue( $this->decisions->answer( $case, $second, $owner, 'decline', 'יקר מדי', false ) );
		$this->assertSame( DecisionStatus::Declined, $this->app->decisions->find( $second )->status );
	}

	public function test_the_first_answer_wins(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$partner                    = $this->client();
		$this->app->members->upsert( $case->id, $partner, MemberRole::Partner, true, $this->clock->mysql() );
		$id = $this->decisions->create( $case, self::fields(), $manager );

		$this->assertTrue( $this->decisions->answer( $case, $id, $owner, 'approve', '', true, self::SIGNATURE ) );
		$again = $this->decisions->answer( $case, $id, $partner, 'decline', '', false );

		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'dx_not_pending', $again->get_error_code() );
		$this->assertSame( DecisionStatus::Approved, $this->app->decisions->find( $id )->status );
	}

	public function test_a_viewer_staff_and_strangers_cannot_answer(): void {
		[ $manager, $case ] = $this->case_with_owner();
		$viewer             = $this->client();
		$this->app->members->upsert( $case->id, $viewer, MemberRole::Viewer, true, $this->clock->mysql() );
		$id = $this->decisions->create( $case, self::fields(), $manager );

		$this->assertSame( 'dx_forbidden', $this->decisions->answer( $case, $id, $viewer, 'approve', '', true, self::SIGNATURE )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->decisions->answer( $case, $id, $manager, 'approve', '', true, self::SIGNATURE )->get_error_code(), 'Staff previewing the portal do not answer for the client.' );
		$this->assertSame( 'dx_not_found', $this->decisions->answer( $case, $id, $this->client(), 'approve', '', true, self::SIGNATURE )->get_error_code() );
		$this->assertTrue( $this->app->decisions->find( $id )->is_pending() );
	}

	/**
	 * Spec 15.11: a client of case A answering a decision of case B gets 404 and changes nothing.
	 */
	public function test_a_client_cannot_answer_another_cases_decision(): void {
		[ $manager, $case_a, $owner_a ] = $this->case_with_owner();
		[ $manager_b, $case_b ]         = $this->case_with_owner();
		$id                             = $this->decisions->create( $case_b, self::fields(), $manager_b );

		$through_own_case = $this->decisions->answer( $case_a, $id, $owner_a, 'approve', '', true, self::SIGNATURE );
		$directly         = $this->decisions->answer( $case_b, $id, $owner_a, 'approve', '', true, self::SIGNATURE );

		$this->assertSame( 'dx_not_found', $through_own_case->get_error_code() );
		$this->assertSame( 'dx_not_found', $directly->get_error_code() );
		$this->assertTrue( $this->app->decisions->find( $id )->is_pending() );
	}

	public function test_a_withdrawn_decision_cannot_be_answered_and_only_its_manager_withdraws(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$id                         = $this->decisions->create( $case, self::fields(), $manager );

		$this->assertInstanceOf( WP_Error::class, $this->decisions->withdraw( $case, $id, 'השתנו התנאים', $this->manager() ) );
		$this->assertInstanceOf( WP_Error::class, $this->decisions->withdraw( $case, $id, '', $manager ) );
		$this->assertTrue( $this->decisions->withdraw( $case, $id, 'השתנו התנאים', $manager ) );

		$this->assertSame( 'dx_not_pending', $this->decisions->answer( $case, $id, $owner, 'approve', '', true, self::SIGNATURE )->get_error_code() );
		$this->assertSame( 'dx_not_pending', $this->decisions->withdraw( $case, $id, 'שוב', $manager )->get_error_code() );
	}

	public function test_a_document_attached_to_a_decision_cannot_be_deleted(): void {
		[ $manager, $case ] = $this->case_with_owner();
		$quote              = $this->add_document( $case, $manager, 'quote' );
		$this->decisions->create( $case, self::fields( array( 'document_id' => (string) $quote->id ) ), $manager );

		$result = $this->app->document_service->delete( $case, $quote, $manager );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dx_document_in_use', $result->get_error_code() );
		$this->assertNotNull( $this->app->documents->find( $quote->id ) );
	}

	public function test_the_portal_shows_pending_decisions_only_answerable_by_owners(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$viewer                     = $this->client();
		$this->app->members->upsert( $case->id, $viewer, MemberRole::Viewer, true, $this->clock->mysql() );
		$this->decisions->create( $case, self::fields(), $manager );

		$as_owner  = ( new CaseView( $this->app ) )->build( $case, $owner, 'decisions' );
		$as_viewer = ( new CaseView( $this->app ) )->build( $case, $viewer, 'decisions' );

		$this->assertSame( 'decisions', $as_owner['tab'] );
		$this->assertCount( 1, $as_owner['decisions'] );
		$this->assertTrue( $as_owner['decisions'][0]['pending'] );
		$this->assertTrue( $as_owner['can_answer'] );
		$this->assertFalse( $as_viewer['can_answer'] );
	}

	public function test_an_agreement_is_confirmed_once_and_the_manager_is_told_once(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$agreement                  = $this->add_document( $case, $manager );
		$service                    = new AgreementService( $this->app );

		$this->assertCount( 1, $service->waiting_for( $case, $owner ) );
		$this->assertTrue( $service->acknowledge( $case, $agreement->id, $owner ) );
		$this->assertTrue( $service->acknowledge( $case, $agreement->id, $owner ), 'Repeating is harmless.' );

		$this->assertSame( array(), $service->waiting_for( $case, $owner ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'אישור קריאת הסכם', $this->mails[0]['subject'] );

		$acks = $this->app->acknowledgements->for_case( $case->id )[ $agreement->id ];
		$this->assertSame( $agreement->sha256, $acks[0]['sha256'] );

		$view = ( new CaseView( $this->app ) )->build( $case, $owner, 'documents' );
		$this->assertNotSame( '', $view['agreement']['confirmed'] );
	}

	public function test_only_a_visible_agreement_of_the_case_and_only_members_can_confirm(): void {
		[ $manager, $case, $owner ]     = $this->case_with_owner();
		$service                        = new AgreementService( $this->app );
		$invoice                        = $this->add_document( $case, $manager, 'quote' );
		$hidden                         = $this->add_document( $case, $manager, 'agreement', false );
		$visible                        = $this->add_document( $case, $manager );
		[ $other_manager, $other_case ] = $this->case_with_owner();
		$foreign                        = $this->add_document( $other_case, $other_manager );

		$this->assertInstanceOf( WP_Error::class, $service->acknowledge( $case, $invoice->id, $owner ) );
		$this->assertInstanceOf( WP_Error::class, $service->acknowledge( $case, $hidden->id, $owner ) );
		$this->assertInstanceOf( WP_Error::class, $service->acknowledge( $case, $foreign->id, $owner ) );
		$this->assertInstanceOf( WP_Error::class, $service->acknowledge( $case, $visible->id, $manager ), 'Staff do not confirm for the client.' );
		$this->assertInstanceOf( WP_Error::class, $service->acknowledge( $case, $visible->id, $this->client() ) );
		$this->assertSame( array(), $this->app->acknowledgements->for_case( $case->id ) );
	}

	public function test_answers_and_confirmations_are_in_the_privacy_export(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$id                         = $this->decisions->create( $case, self::fields(), $manager );
		$this->decisions->answer( $case, $id, $owner, 'decline', 'לא כרגע', false );
		( new AgreementService( $this->app ) )->acknowledge( $case, $this->add_document( $case, $manager )->id, $owner );

		$export = ( new \Diromax\Portal\Service\Retention( $this->app ) )->export( (string) get_userdata( $owner )->user_email );
		$groups = array_column( $export['data'], 'group_id' );

		$this->assertContains( 'dx-portal-decision', $groups );
		$this->assertContains( 'dx-portal-agreement', $groups );
	}

	/**
	 * A case with an owner.
	 *
	 * @return array{0:int, 1:CaseRecord, 2:int} Manager, case, owner.
	 */
	private function case_with_owner(): array {
		$manager = $this->manager();
		$owner   = $this->client();

		return array( $manager, $this->case_for( $manager, $owner ), $owner );
	}

	/**
	 * Decision form fields.
	 *
	 * @param array<string, string> $overrides Overrides.
	 * @return array<string, string>
	 */
	private static function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'       => 'הצעת המחיר של הקבלן',
				'body'        => 'שלד ובניה של יחידת הגג, לפי התוכנית המאושרת.',
				'amount'      => '84000',
				'document_id' => '',
				'due_date'    => '2026-11-30',
				'notify'      => '',
			),
			$overrides
		);
	}

	public function test_approving_is_signing(): void {
		[ $manager, $case, $owner ] = $this->case_with_owner();
		$id                         = $this->decisions->create( $case, self::fields(), $manager );

		$unsigned = $this->decisions->answer( $case, $id, $owner, 'approve', '', true );
		$this->assertInstanceOf( WP_Error::class, $unsigned );
		$this->assertSame( array( 'name', 'image' ), array_keys( $unsigned->get_error_data()['fields'] ), 'A name, and a drawing or the "cannot draw" box.' );
		$this->assertTrue( $this->app->decisions->find( $id )->is_pending(), 'Nothing is answered without the signature.' );

		$this->assertTrue( $this->decisions->answer( $case, $id, $owner, 'approve', '', true, self::SIGNATURE ) );

		$signed = $this->app->signatures->for_subject( $case->id, 'decision', $id );
		$this->assertCount( 1, $signed );
		$this->assertSame( $owner, $signed[0]['user_id'] );
		$this->assertSame( 'משפחת כהן', $signed[0]['signer_name'] );
		$this->assertSame( $this->app->decisions->find( $id )->fingerprint, $signed[0]['sha256'], 'Bound to what was shown.' );
		$this->assertSame( 'portal', $signed[0]['method'] );
	}
}
