<?php
/**
 * Digital signatures (spec 16.6).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Plugin;
use Diromax\Portal\Service\SignatureService;
use Diromax\Portal\Support\Clock;
use WP_Error;

final class SignatureTest extends PortalTestCase {

	/** A real 1×1 PNG, as a data URL. */
	private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	/** A valid Israeli ID number. */
	private const ID = '123456782';

	/**
	 * Manager, case with an owner, its agreement.
	 *
	 * @return array{0:int, 1:CaseRecord, 2:int, 3:DocumentRecord}
	 */
	private function signable(): array {
		$manager = $this->manager();
		$owner   = $this->client( 'owner' . wp_rand() . '@example.org' );
		$case    = $this->case_for( $manager, $owner );

		return array( $manager, $case, $owner, $this->add_document( $case, $manager, 'agreement' ) );
	}

	/**
	 * The token in the signing email.
	 *
	 * @return string
	 */
	private function token_from_mail(): string {
		foreach ( array_reverse( $this->mails ) as $mail ) {
			if ( preg_match( '#/sign/([A-Za-z0-9_-]{43})/#', (string) $mail['message'], $match ) || preg_match( '#dx_portal_id=([A-Za-z0-9_-]{43})#', (string) $mail['message'], $match ) ) {
				return $match[1];
			}
		}

		$this->fail( 'No signing link was emailed.' );
	}

	/**
	 * The code in the latest code email.
	 *
	 * @return string
	 */
	private function code_from_mail(): string {
		foreach ( array_reverse( $this->mails ) as $mail ) {
			if ( preg_match( '/קוד החתימה: (\d{6})/u', (string) $mail['message'], $match ) ) {
				return $match[1];
			}
		}

		$this->fail( 'No code was emailed.' );
	}

	public function test_an_owner_signs_the_agreement_in_the_portal(): void {
		[ $manager, $case, $owner, $agreement ] = $this->signable();
		$service                                = new SignatureService( $this->app );

		$this->assertSame( array( $agreement->id ), array_map( static fn( DocumentRecord $d ): int => $d->id, $service->waiting_for( $case, $owner ) ) );

		$this->assertTrue(
			$service->sign_document(
				$case,
				$agreement->id,
				$owner,
				array(
					'name'    => 'משפחת כהן',
					'image'   => self::PNG,
					'confirm' => '1',
				)
			)
		);

		$signed = $service->of_document( $agreement );
		$this->assertCount( 1, $signed );
		$this->assertSame( $owner, $signed[0]['user_id'] );
		$this->assertSame( 'portal', $signed[0]['method'] );
		$this->assertSame( $agreement->sha256, $signed[0]['sha256'] );
		$this->assertStringNotContainsString( 'PNG', $signed[0]['image_enc'], 'The drawing is stored encrypted.' );
		$this->assertSame( substr( self::PNG, 22 ), base64_encode( (string) $service->image( $signed[0] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Comparing test fixtures.
		$this->assertSame( array(), $service->waiting_for( $case, $owner ) );
		$this->assertTrue( $service->sign_document( $case, $agreement->id, $owner, array() ), 'Signing twice is harmless.' );
		$this->assertCount( 1, $service->of_document( $agreement ) );
		$this->assertSame( (string) get_userdata( $manager )->user_email, end( $this->mails )['to'], 'The manager is told.' );
	}

	public function test_portal_signing_is_for_owners_and_partners_of_the_case_only(): void {
		[ $manager, $case, , $agreement ] = $this->signable();
		$viewer                           = $this->client();
		$this->app->members->upsert( $case->id, $viewer, MemberRole::Viewer, true, $this->clock->mysql() );
		$service = new SignatureService( $this->app );
		$input   = array(
			'name'    => 'מישהו',
			'no_draw' => '1',
			'confirm' => '1',
		);

		$this->assertSame( 'dx_not_found', $service->sign_document( $case, $agreement->id, $viewer, $input )->get_error_code() );
		$this->assertSame( 'dx_not_found', $service->sign_document( $case, $agreement->id, $manager, $input )->get_error_code(), 'Staff do not sign for the client.' );
		$this->assertSame( 'dx_not_found', $service->sign_document( $case, $agreement->id, $this->client(), $input )->get_error_code() );

		$quote = $this->add_document( $case, $manager, 'quote' );
		$owner = $this->app->members->for_case( $case->id )[0]->user_id;
		$this->assertSame( 'dx_not_found', $service->sign_document( $case, $quote->id, $owner, $input )->get_error_code(), 'Only documents for signature.' );
		$this->assertSame( array(), $this->app->signatures->for_case( $case->id ) );
	}

	public function test_input_is_checked(): void {
		[ , $case, $owner, $agreement ] = $this->signable();
		$result                         = ( new SignatureService( $this->app ) )->sign_document(
			$case,
			$agreement->id,
			$owner,
			array(
				'name'  => 'א',
				'image' => 'data:image/png;base64,bm90IGEgcG5n',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			array(
				'name'    => 'required',
				'image'   => 'invalid',
				'confirm' => 'required',
			),
			$result->get_error_data()['fields']
		);
	}

	public function test_a_replaced_document_is_not_signed(): void {
		[ , $case, $owner, $agreement ] = $this->signable();
		$service                        = new SignatureService( $this->app );
		$service->sign_document(
			$case,
			$agreement->id,
			$owner,
			array(
				'name'    => 'משפחת כהן',
				'no_draw' => '1',
				'confirm' => '1',
			)
		);

		$this->app->db->update( Schema::table( 'documents' ), array( 'sha256' => str_repeat( 'f', 64 ) ), array( 'id' => $agreement->id ) );
		$changed = $this->app->documents->find( $agreement->id );
		$this->assertInstanceOf( DocumentRecord::class, $changed );

		$this->assertSame( array(), $service->of_document( $changed ), 'The signature was on other content.' );
		$this->assertCount( 1, $service->waiting_for( $case, $owner ), 'So it waits for a signature again.' );
	}

	public function test_someone_outside_the_system_signs_through_a_link_with_a_code_and_an_id_number(): void {
		[ $manager, $case, , $agreement ] = $this->signable();
		$consent                          = $this->add_document( $case, $manager, 'other', false );
		$service                          = new SignatureService( $this->app );

		$request = $service->request(
			$case,
			$consent->id,
			array(
				'signer_name'  => 'יוסי השכן',
				'signer_email' => 'yossi@example.org',
			),
			$manager
		);
		$this->assertIsInt( $request );
		$this->assertSame( 'yossi@example.org', end( $this->mails )['to'] );

		$token = $this->token_from_mail();
		$link  = $service->by_token( $token );
		$this->assertNotNull( $link );
		$this->assertSame( $consent->id, $link['document']->id, 'The link opens this document only.' );
		$this->assertNotSame( $agreement->id, $link['document']->id );

		$this->assertSame( array( 'code' => 'expired' ), $service->sign_with_link( $token, array( 'code' => '000000' ) )->get_error_data()['fields'], 'First a code.' );
		$this->assertTrue( $service->send_code( $token ) );
		$code = $this->code_from_mail();

		$details = array(
			'code'      => $code,
			'name'      => 'יוסי כהן',
			'id_number' => '123456789',
			'image'     => self::PNG,
			'confirm'   => '1',
		);
		$this->assertSame( array( 'id_number' => 'invalid' ), $service->sign_with_link( $token, $details )->get_error_data()['fields'], 'The check digit is checked.' );

		$details['id_number'] = self::ID;
		$this->assertTrue( $service->sign_with_link( $token, $details ) );

		$signed = $service->of_document( $consent );
		$this->assertCount( 1, $signed );
		$this->assertSame( 'link', $signed[0]['method'] );
		$this->assertNull( $signed[0]['user_id'] );
		$this->assertNotNull( $signed[0]['verified_at'] );
		$this->assertSame( self::ID, $service->id_number( $signed[0] ) );
		$this->assertStringNotContainsString( self::ID, $signed[0]['id_number_enc'], 'Stored encrypted.' );
		$this->assertNull( $service->by_token( $token ), 'A used link is refused.' );
		$this->assertContains( 'yossi@example.org', array_column( $this->mails, 'to' ), 'The signer gets a confirmation.' );
		$to_signer = array_values( array_filter( $this->mails, static fn( array $m ): bool => 'yossi@example.org' === $m['to'] ) );
		$this->assertStringContainsString( $consent->sha256, (string) $to_signer[ count( $to_signer ) - 1 ]['message'], 'With the fingerprint of what was signed.' );
	}

	public function test_five_wrong_codes_need_a_new_one_and_codes_are_limited(): void {
		[ $manager, $case, , $agreement ] = $this->signable();
		$service                          = new SignatureService( $this->app );
		$service->request(
			$case,
			$agreement->id,
			array(
				'signer_name'  => 'דנה',
				'signer_email' => 'dana@example.org',
			),
			$manager
		);
		$token = $this->token_from_mail();
		$service->send_code( $token );
		$code  = $this->code_from_mail();
		$wrong = str_pad( (string) ( ( (int) $code + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT );
		$input = array(
			'name'      => 'דנה לוי',
			'id_number' => self::ID,
			'no_draw'   => '1',
			'confirm'   => '1',
		);

		for ( $i = 1; $i < SignatureService::CODE_ATTEMPTS; $i++ ) {
			$this->assertSame( array( 'code' => 'wrong' ), $service->sign_with_link( $token, array( 'code' => $wrong ) + $input )->get_error_data()['fields'] );
		}

		$this->assertSame( array( 'code' => 'expired' ), $service->sign_with_link( $token, array( 'code' => $wrong ) + $input )->get_error_data()['fields'] );
		$this->assertSame( array( 'code' => 'expired' ), $service->sign_with_link( $token, array( 'code' => $code ) + $input )->get_error_data()['fields'], 'Even the right code is gone now.' );

		$this->assertTrue( $service->send_code( $token ) );
		$this->assertTrue( $service->send_code( $token ) );
		$this->assertSame( 'dx_rate_limited', $service->send_code( $token )->get_error_code(), 'Three codes per quarter hour.' );

		$this->assertTrue( $service->sign_with_link( $token, array( 'code' => $this->code_from_mail() ) + $input ) );
	}

	public function test_a_link_expires_and_can_be_cancelled_by_its_manager_only(): void {
		[ $manager, $case, , $agreement ] = $this->signable();
		$service                          = new SignatureService( $this->app );
		$first                            = $service->request(
			$case,
			$agreement->id,
			array(
				'signer_name'  => 'אבי',
				'signer_email' => 'avi@example.org',
			),
			$manager
		);
		$this->assertIsInt( $first );
		$token = $this->token_from_mail();

		$this->assertSame( 'dx_forbidden', $service->cancel( $case, $first, $this->manager() )->get_error_code() );
		$this->assertSame(
			'dx_forbidden',
			$service->request(
				$case,
				$agreement->id,
				array(
					'signer_name'  => 'x',
					'signer_email' => 'x@example.org',
				),
				$this->manager()
			)->get_error_code()
		);
		$this->assertSame(
			array(
				'signer_name'  => 'invalid',
				'signer_email' => 'invalid',
			),
			$service->request(
				$case,
				$agreement->id,
				array(
					'signer_name'  => 'x',
					'signer_email' => 'not-an-email',
				),
				$manager
			)->get_error_data()['fields']
		);

		$later = new SignatureService( Plugin::reset( new Clock( self::NOW + ( SignatureService::LINK_DAYS + 1 ) * DAY_IN_SECONDS ) ) );
		$this->assertNull( $later->by_token( $token ), 'After 14 days the link is refused.' );

		$this->app = Plugin::reset( $this->clock );
		$service   = new SignatureService( $this->app );
		$this->assertNotNull( $service->by_token( $token ) );
		$this->assertTrue( $service->cancel( $case, $first, $manager ) );
		$this->assertNull( $service->by_token( $token ), 'A cancelled link is refused.' );
		$this->assertNull( $service->by_token( 'not-a-token' ) );
		$this->assertNull( $service->by_token( str_repeat( 'A', 43 ) ) );
	}

	public function test_sealed_values_open_only_in_their_own_context(): void {
		$sealed = $this->app->store->seal( self::ID, 'id|signature|case:1|document:2' );

		$this->assertSame( self::ID, $this->app->store->unseal( $sealed, 'id|signature|case:1|document:2' ) );
		$this->assertNull( $this->app->store->unseal( $sealed, 'id|signature|case:1|document:3' ), 'Moved to another record, it does not open.' );
		$this->assertNull( $this->app->store->unseal( 'garbage', 'x' ) );
	}

	public function test_a_signer_finds_their_signatures_in_the_privacy_export(): void {
		[ , $case, $owner, $agreement ] = $this->signable();
		( new SignatureService( $this->app ) )->sign_document(
			$case,
			$agreement->id,
			$owner,
			array(
				'name'    => 'משפחת כהן',
				'no_draw' => '1',
				'confirm' => '1',
			)
		);

		$export = ( new \Diromax\Portal\Service\Retention( $this->app ) )->export( (string) get_userdata( $owner )->user_email );
		$groups = array_column( $export['data'], 'group_id' );

		$this->assertContains( 'dx-portal-signature', $groups );
		$this->assertStringNotContainsString( 'image_enc', (string) wp_json_encode( $export ), 'Not the sealed drawing or ID number.' );
	}
}
