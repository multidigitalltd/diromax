<?php
/**
 * Client replies by email: kept in the case and forwarded (spec 17.3).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Portal\Webhooks;
use Diromax\Portal\Service\InboundService;
use Diromax\Portal\Service\MailMessage;
use Diromax\Portal\Service\Retention;
use WP_REST_Request;

final class InboundTest extends PortalTestCase {

	/** Sender used by most tests. */
	private const CLIENT = 'cohen@gmail.com';

	/**
	 * Settings the tests switch on.
	 *
	 * @var array<string, string>
	 */
	private array $config = array(
		'token'       => 'server-token-for-tests',
		'inbound'     => 'replies@inbound.postmarkapp.com',
		'webhook_key' => 'test-webhook-key-0123456789abcdefghij',
	);

	/**
	 * Running number for message IDs.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'dx_portal_mail_config', array( $this, 'config' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'dx_portal_mail_config', array( $this, 'config' ), 10 );
		parent::tear_down();
	}

	/**
	 * Test settings.
	 *
	 * @param string $value Value from wp-config.
	 * @param string $name  Setting.
	 * @return string
	 */
	public function config( $value, $name ): string {
		return $this->config[ $name ] ?? (string) $value;
	}

	/**
	 * A case with a client who owns it.
	 *
	 * @param MemberRole $role Client's role.
	 * @return array{0:CaseRecord, 1:int}
	 */
	private function case_with_client( MemberRole $role = MemberRole::Owner ): array {
		$client = $this->client( self::CLIENT );
		$case   = $this->case_for( $this->manager() );
		$this->app->members->upsert( $case->id, $client, $role, true, $this->clock->mysql() );

		return array( $case, $client );
	}

	/**
	 * A reply as Postmark posts it.
	 *
	 * @param CaseRecord|null      $client_case Case it answers.
	 * @param array<string, mixed> $overrides   Fields to change.
	 * @return array<string, mixed>
	 */
	private function reply( ?CaseRecord $client_case, array $overrides = array() ): array {
		$hash = null === $client_case ? '' : InboundService::mailbox_hash( $client_case->public_id );
		++$this->sequence;

		return array_merge(
			array(
				'MessageID'         => 'msg-' . $this->sequence . '-' . wp_rand(),
				'FromName'          => 'משה כהן',
				'From'              => self::CLIENT,
				'FromFull'          => array(
					'Email'       => self::CLIENT,
					'Name'        => 'משה כהן',
					'MailboxHash' => '',
				),
				'ToFull'            => array(
					array(
						'Email'       => 'replies+' . $hash . '@inbound.postmarkapp.com',
						'Name'        => '',
						'MailboxHash' => $hash,
					),
				),
				'MailboxHash'       => $hash,
				'Subject'           => 'Re: עדכון בתיק',
				'TextBody'          => "מתי מגיע הקבלן?\n\n> ההודעה המקורית",
				'StrippedTextReply' => 'מתי מגיע הקבלן?',
				'Headers'           => array(
					array(
						'Name'  => 'X-Spam-Status',
						'Value' => 'No',
					),
					array(
						'Name'  => 'X-Spam-Tests',
						'Value' => 'DKIM_SIGNED,DKIM_VALID,DKIM_VALID_AU,SPF_PASS',
					),
				),
				'Attachments'       => array(),
			),
			$overrides
		);
	}

	/**
	 * A PDF attachment as Postmark sends it.
	 *
	 * @param string $name File name.
	 * @return array<string, mixed>
	 */
	private static function pdf( string $name = 'תוכנית.pdf' ): array {
		$bytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n" . wp_rand() . "\ntrailer << /Root 1 0 R >>\n%%EOF\n";

		return array(
			'Name'          => $name,
			'Content'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As Postmark sends it.
			'ContentType'   => 'application/pdf',
			'ContentLength' => strlen( $bytes ),
		);
	}

	/**
	 * Mails sent to an address.
	 *
	 * @param string $to Address.
	 * @return array<int, array<string, mixed>>
	 */
	private function mails_to( string $to ): array {
		return array_values( array_filter( $this->mails, static fn( array $mail ): bool => $to === $mail['to'] ) );
	}

	public function test_case_emails_reply_into_the_case(): void {
		[ $case, $client ] = $this->case_with_client();
		$address           = InboundService::reply_address( $case );

		$this->assertMatchesRegularExpression( '/^replies\+[0-9a-f]{40}@inbound\.postmarkapp\.com$/', $address );
		$this->assertStringContainsString( $case->public_id, $address );

		$this->app->notifier->send_to_member( $case, $client, self::CLIENT, 'digest', new MailMessage( 'סיכום', '<p>x</p>', 'x' ), '' );
		$this->assertContains( 'Reply-To: דניאל לוי <' . $address . '>', $this->mails[0]['headers'] );

		$this->config['inbound'] = '';
		$this->assertSame( '', InboundService::reply_address( $case ), 'Off: replies go to the manager as before.' );
	}

	public function test_a_verified_reply_from_a_client_is_kept_and_forwarded_with_its_files(): void {
		[ $case, $client ] = $this->case_with_client();
		$manager           = get_userdata( $case->manager_id );
		$mail              = $this->reply( $case, array( 'Attachments' => array( self::pdf(), self::pdf( 'run.exe' ) ) ) );

		$this->assertSame( 'stored', ( new InboundService( $this->app ) )->receive( $mail ) );

		$messages = $this->app->messages->for_case( $case->id );
		$this->assertCount( 1, $messages );
		$this->assertSame( $client, $messages[0]['user_id'] );
		$this->assertSame( 'מתי מגיע הקבלן?', $messages[0]['body'], 'Only the new reply, without the quoted email.' );
		$this->assertSame( 'run.exe', $messages[0]['files'][1]['name'] );
		$this->assertSame( 'type', $messages[0]['files'][1]['reason'] );

		$document = $this->app->documents->find( (int) $messages[0]['files'][0]['document_id'] );
		$this->assertNotNull( $document );
		$this->assertSame( 'client', $document->kind );
		$this->assertTrue( $document->visible );
		$this->assertSame( $case->id, $document->case_id );

		$forward = $this->mails_to( (string) $manager->user_email );
		$this->assertCount( 1, $forward );
		$this->assertStringContainsString( 'הודעה ממשה כהן', (string) $forward[0]['subject'] );
		$this->assertStringContainsString( '&gt; ההודעה המקורית', (string) $forward[0]['message'], 'The manager gets all of it.' );
		$this->assertContains( 'Reply-To: משה כהן <' . self::CLIENT . '>', $forward[0]['headers'] );
		$this->assertSame( array( 'תוכנית.pdf' ), array_keys( $forward[0]['attachments'] ), 'Only files of an accepted type travel.' );

		$this->assertSame( 'message.received', $this->app->audit->for_case( $case->id )[0]['action'] );
		$this->assertSame( 'duplicate', ( new InboundService( $this->app ) )->receive( $mail ), 'Postmark may deliver twice.' );
		$this->assertCount( 1, $this->mails_to( (string) $manager->user_email ) );
	}

	public function test_the_html_of_a_reply_never_reaches_the_forward(): void {
		[ $case ] = $this->case_with_client();
		$manager  = get_userdata( $case->manager_id );

		( new InboundService( $this->app ) )->receive(
			$this->reply(
				$case,
				array(
					'TextBody'          => '',
					'StrippedTextReply' => '',
					'HtmlBody'          => '<p>שלום</p><script>alert(1)</script><a href="https://evil.example">כאן</a>',
				)
			)
		);

		$html = (string) $this->mails_to( (string) $manager->user_email )[0]['message'];
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringContainsString( 'שלום', $html );
	}

	public function test_an_unverified_sender_is_forwarded_with_a_warning_and_not_kept(): void {
		[ $case ] = $this->case_with_client();
		$manager  = get_userdata( $case->manager_id );
		$spoofed  = array(
			array(
				'Name'  => 'X-Spam-Tests',
				'Value' => 'SPF_PASS',
			),
			array(
				'Name'  => 'Received-SPF',
				'Value' => 'Pass (sender SPF authorized) identity=mailfrom; client-ip=192.0.2.1; envelope-from=bounce@attacker.example; receiver=x',
			),
		);

		$this->assertSame( 'forwarded', ( new InboundService( $this->app ) )->receive( $this->reply( $case, array( 'Headers' => $spoofed ) ) ) );
		$this->assertSame( array(), $this->app->messages->for_case( $case->id ) );

		$forward = $this->mails_to( (string) $manager->user_email );
		$this->assertCount( 1, $forward );
		$this->assertStringStartsWith( 'לבדיקה:', (string) $forward[0]['subject'] );
		$this->assertStringContainsString( 'לא הצלחנו לוודא', (string) $forward[0]['message'] );
	}

	public function test_spf_of_the_same_domain_is_enough(): void {
		$headers = array(
			'received-spf' => array( 'Pass (sender SPF authorized) identity=mailfrom; envelope-from=cohen@gmail.com; receiver=x' ),
		);

		$this->assertTrue( InboundService::authentic( 'cohen@gmail.com', $headers ) );
		$this->assertFalse( InboundService::authentic( 'cohen@yahoo.com', $headers ), 'Another domain passed, not this one.' );
		$this->assertFalse( InboundService::authentic( 'cohen@gmail.com', array( 'received-spf' => array( 'SoftFail (domain owner discourages use of this host) envelope-from=cohen@gmail.com' ) ) ) );
		$this->assertFalse( InboundService::authentic( 'cohen@gmail.com', array() ) );
	}

	public function test_a_stranger_or_a_closed_case_is_forwarded_only(): void {
		[ $case ] = $this->case_with_client();
		$manager  = get_userdata( $case->manager_id );
		$stranger = array(
			'Email'       => 'someone@else.example',
			'Name'        => 'מישהו',
			'MailboxHash' => '',
		);

		$this->assertSame( 'forwarded', ( new InboundService( $this->app ) )->receive( $this->reply( $case, array( 'FromFull' => $stranger ) ) ) );
		$this->assertStringContainsString( 'אינה של אחד הלקוחות', (string) $this->mails_to( (string) $manager->user_email )[0]['message'] );

		$closed = $this->app->case_service->set_status( $case, 'closed', $case->manager_id );
		$this->assertInstanceOf( CaseRecord::class, $closed );
		$this->assertSame( 'forwarded', ( new InboundService( $this->app ) )->receive( $this->reply( $closed ) ) );
		$this->assertSame( array(), $this->app->messages->for_case( $case->id ) );
	}

	public function test_a_forged_case_address_reaches_no_case(): void {
		[ $case ] = $this->case_with_client();
		$forged   = $case->public_id . str_repeat( '0', 16 );
		$mail     = $this->reply( null, array( 'MailboxHash' => $forged ) );

		$this->assertSame( 'forwarded', ( new InboundService( $this->app ) )->receive( $mail ) );
		$this->assertSame( array(), $this->app->messages->for_case( $case->id ) );
		$this->assertCount( 1, $this->mails_to( (string) get_option( 'admin_email' ) ), 'Without a case, the site administrator gets it.' );
		$this->assertSame( array(), $this->mails_to( (string) get_userdata( $case->manager_id )->user_email ) );
	}

	public function test_spam_is_dropped(): void {
		[ $case ] = $this->case_with_client();
		$spam     = array(
			array(
				'Name'  => 'X-Spam-Status',
				'Value' => 'Yes, score=7.1',
			),
		);

		$this->assertSame( 'ignored', ( new InboundService( $this->app ) )->receive( $this->reply( $case, array( 'Headers' => $spam ) ) ) );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 'ignored', ( new InboundService( $this->app ) )->receive( array( 'Subject' => 'no id' ) ) );
	}

	public function test_a_viewer_writes_into_the_case_but_adds_no_documents(): void {
		[ $case ] = $this->case_with_client( MemberRole::Viewer );

		$this->assertSame( 'stored', ( new InboundService( $this->app ) )->receive( $this->reply( $case, array( 'Attachments' => array( self::pdf() ) ) ) ) );

		$message = $this->app->messages->for_case( $case->id )[0];
		$this->assertNull( $message['files'][0]['document_id'] );
		$this->assertSame( 'not_allowed', $message['files'][0]['reason'] );
		$this->assertSame( array(), $this->app->documents->for_case( $case->id, false ) );
	}

	public function test_the_inbound_webhook_answers_postmark(): void {
		[ $case ] = $this->case_with_client();
		$hooks    = new Webhooks( $this->app );
		$request  = new WP_REST_Request( 'POST', '/' . Webhooks::NAMESPACE . '/postmark/inbound' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $this->reply( $case ) ) );

		$response = $hooks->inbound( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'stored', $response->get_data()['result'] );

		$broken = new WP_REST_Request( 'POST', '/' . Webhooks::NAMESPACE . '/postmark/inbound' );
		$broken->set_header( 'content-type', 'application/json' );
		$broken->set_body( 'not json' );
		$this->assertSame( 403, $hooks->inbound( $broken )->get_status(), 'Postmark stops retrying what can never be read.' );
	}

	/**
	 * Regression: the message was marked as seen before it was handled, so
	 * when handling crashed (wp_tempnam() is missing outside wp-admin),
	 * Postmark's retry was dropped as a duplicate and the reply was lost.
	 */
	public function test_a_reply_that_failed_is_handled_again_on_retry(): void {
		[ $case ] = $this->case_with_client();
		$mail     = $this->reply( $case, array( 'Attachments' => array( self::pdf() ) ) );
		$crash    = static function (): bool {
			throw new \RuntimeException( 'scanner down' );
		};
		$hooks    = new Webhooks( $this->app );
		$request  = new WP_REST_Request( 'POST', '/' . Webhooks::NAMESPACE . '/postmark/inbound' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $mail ) );

		add_filter( 'dx_portal_scan_upload', $crash );
		$this->assertSame( 500, $hooks->inbound( $request )->get_status(), 'A crash asks Postmark to try again.' );
		remove_filter( 'dx_portal_scan_upload', $crash );

		$this->assertSame( array(), $this->app->messages->for_case( $case->id ) );
		$this->assertSame( 'stored', $hooks->inbound( $request )->get_data()['result'] );
		$this->assertCount( 1, $this->app->messages->for_case( $case->id ) );
		$this->assertNotNull( $this->app->messages->for_case( $case->id )[0]['files'][0]['document_id'] );
	}

	/**
	 * Regression (review of 1.7.0): a forward that Postmark could not send
	 * was ignored; the message counted as handled, so a reply that is only
	 * forwarded was lost, and a kept one never reached the manager.
	 */
	public function test_a_forward_that_could_not_be_sent_is_tried_again(): void {
		[ $case ] = $this->case_with_client();
		$manager  = (string) get_userdata( $case->manager_id )->user_email;
		$down     = static fn( $short, array $atts ) => $manager === $atts['to'] ? false : $short;
		$service  = new InboundService( $this->app );
		$kept     = $this->reply( $case );
		$stranger = $this->reply(
			$case,
			array(
				'FromFull' => array(
					'Email'       => 'someone@else.example',
					'Name'        => 'מישהו',
					'MailboxHash' => '',
				),
			)
		);

		add_filter( 'pre_wp_mail', $down, 5, 2 );
		$this->assertInstanceOf( \WP_Error::class, $service->receive( $kept ), 'Postmark is asked to try again.' );
		$this->assertInstanceOf( \WP_Error::class, $service->receive( $stranger ) );
		remove_filter( 'pre_wp_mail', $down, 5 );

		$this->assertCount( 1, $this->app->messages->for_case( $case->id ), 'The kept reply is saved already.' );
		$this->assertSame( 'stored', $service->receive( $kept ) );
		$this->assertSame( 'forwarded', $service->receive( $stranger ) );
		$this->assertCount( 1, $this->app->messages->for_case( $case->id ), 'Saved once.' );
		$this->assertCount( 2, $this->mails_to( $manager ), 'Each reached the manager once.' );
		$this->assertSame( 'duplicate', $service->receive( $kept ) );
		$this->assertSame( 'duplicate', $service->receive( $stranger ) );
	}

	/**
	 * Regression (review of 1.7.0): when a later step failed after some
	 * attachments were stored, the retry stored them again as new documents.
	 */
	public function test_a_retry_after_a_partial_save_stores_each_file_once(): void {
		[ $case ] = $this->case_with_client();
		$mail     = $this->reply( $case, array( 'Attachments' => array( self::pdf( 'א.pdf' ), self::pdf( 'ב.pdf' ), self::pdf( 'ג.pdf' ) ) ) );
		$calls    = 0;
		$crash    = static function ( bool $ok ) use ( &$calls ): bool {
			if ( 2 === ++$calls ) {
				throw new \RuntimeException( 'scanner down' );
			}

			return $ok;
		};

		add_filter( 'dx_portal_scan_upload', $crash );

		try {
			( new InboundService( $this->app ) )->receive( $mail );
			$this->fail( 'The second file should have failed.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'scanner down', $error->getMessage() );
		} finally {
			remove_filter( 'dx_portal_scan_upload', $crash );
		}

		$this->assertCount( 1, $this->app->documents->for_case( $case->id, false ), 'The first file was stored before the failure.' );
		$this->assertSame( 'stored', ( new InboundService( $this->app ) )->receive( $mail ) );

		$documents = $this->app->documents->for_case( $case->id, false );
		$this->assertCount( 3, $documents, 'Three files, three documents: none twice.' );
		$this->assertSame( array( 'א.pdf', 'ב.pdf', 'ג.pdf' ), array_column( $this->app->messages->for_case( $case->id )[0]['files'], 'name' ) );
		$this->assertNotContains( null, array_column( $this->app->messages->for_case( $case->id )[0]['files'], 'document_id' ) );
	}

	public function test_replies_are_in_the_privacy_export_and_erased_with_the_person(): void {
		[ $case ] = $this->case_with_client();
		( new InboundService( $this->app ) )->receive( $this->reply( $case ) );
		$retention = new Retention( $this->app );

		$groups = array_column( $retention->export( self::CLIENT )['data'], 'group_id' );
		$this->assertContains( 'dx-portal-message', $groups );

		$retention->erase( self::CLIENT );
		$this->assertSame( array(), $this->app->messages->for_case( $case->id ) );
	}

	public function test_the_messages_tab_lists_the_replies(): void {
		[ $case ] = $this->case_with_client();
		( new InboundService( $this->app ) )->receive( $this->reply( $case ) );
		wp_set_current_user( $case->manager_id );

		$view = ( new \Diromax\Portal\Admin\Pages( $this->app ) )->case_data( $case, $case->manager_id, 'messages' );
		$this->assertSame( 1, $view['counts']['messages'] );

		ob_start();
		include DX_PORTAL_DIR . 'templates/admin/tabs/messages.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'מתי מגיע הקבלן?', $html );
		$this->assertStringContainsString( self::CLIENT, $html );
	}
}
