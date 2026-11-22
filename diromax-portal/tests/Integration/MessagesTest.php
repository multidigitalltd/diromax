<?php
/**
 * The conversation of a case: clients write in the portal, the project
 * manager answers from the case (spec 18.1).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\InboundService;
use Diromax\Portal\Service\MessageService;
use WP_Error;

final class MessagesTest extends PortalTestCase {

	/**
	 * Settings the tests switch on.
	 *
	 * @var array<string, string>
	 */
	private array $config = array();

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
	 * A case with an owner, a partner who gets no email, and a viewer.
	 *
	 * @return array{0:CaseRecord, 1:int, 2:int, 3:int}
	 */
	private function family(): array {
		$owner   = $this->client( 'owner' . wp_rand() . '@example.org' );
		$partner = $this->client( 'partner' . wp_rand() . '@example.org' );
		$viewer  = $this->client( 'viewer' . wp_rand() . '@example.org' );
		$case    = $this->case_for( $this->manager(), $owner );
		$now     = $this->clock->mysql();
		$this->app->members->upsert( $case->id, $partner, MemberRole::Partner, false, $now );
		$this->app->members->upsert( $case->id, $viewer, MemberRole::Viewer, true, $now );

		return array( $case, $owner, $partner, $viewer );
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

	/**
	 * Email of a user.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function email( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	public function test_a_client_writes_and_the_manager_gets_the_whole_message(): void {
		[ $case, $owner ] = $this->family();
		$service          = new MessageService( $this->app );

		$id = $service->post( $case, $owner, "שלום,\nמתי מגיע הקבלן?" );
		$this->assertIsInt( $id );

		$thread = $service->thread( $case, $owner, false );
		$this->assertCount( 1, $thread );
		$this->assertTrue( $thread[0]['mine'] );
		$this->assertFalse( $thread[0]['staff'] );
		$this->assertSame( "שלום,\nמתי מגיע הקבלן?", $thread[0]['body'] );
		$this->assertSame( '', $thread[0]['address'], 'The client screen shows names, not addresses.' );

		$mail = $this->mails_to( self::email( $case->manager_id ) );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'הודעה ממשפחת כהן', (string) $mail[0]['subject'] );
		$this->assertStringContainsString( 'מתי מגיע הקבלן?', (string) $mail[0]['message'] );
		$this->assertStringContainsString( 'כתב לך מהאזור האישי', (string) $mail[0]['message'] );
		$this->assertContains( 'Reply-To: משפחת כהן <' . self::email( $owner ) . '>', $mail[0]['headers'] );

		$this->assertSame( 'message.posted', $this->app->audit->for_case( $case->id )[0]['action'] );
		$this->assertSame( 'forwarded', $this->app->messages->thread( $case->id )[0]['stage'], 'The manager was told.' );
	}

	public function test_who_may_write(): void {
		[ $case, , $partner, $viewer ] = $this->family();
		$service                       = new MessageService( $this->app );

		$this->assertIsInt( $service->post( $case, $partner, 'גם שותף כותב' ) );
		$this->assertSame( 'dx_forbidden', $service->post( $case, $viewer, 'צופה' )->get_error_code(), 'Negative: a viewer reads but does not write.' );
		$this->assertSame( 'dx_not_found', $service->post( $case, $this->client(), 'זר' )->get_error_code(), 'Negative: a client of no case of theirs.' );
		$this->assertSame( 'dx_not_found', $service->post( $case, $case->manager_id, 'מנהל כלקוח' )->get_error_code(), 'Staff answer from the case, not as a client.' );

		$view = ( new CaseView( $this->app ) )->build( $case, $viewer, 'messages' );
		$this->assertFalse( $view['can_write'] );
		$this->assertCount( 1, $view['messages'], 'The viewer reads the thread.' );

		$closed = $this->app->case_service->set_status( $case, 'closed', $case->manager_id );
		$this->assertInstanceOf( CaseRecord::class, $closed );
		$this->assertSame( 'dx_forbidden', $service->post( $closed, $partner, 'אחרי הסגירה' )->get_error_code() );
	}

	public function test_text_is_checked_and_writing_is_rate_limited(): void {
		[ $case, $owner ] = $this->family();
		$service          = new MessageService( $this->app );

		$empty = $service->post( $case, $owner, "  \n " );
		$this->assertInstanceOf( WP_Error::class, $empty );
		$this->assertSame( 'required', $empty->get_error_data()['fields']['body'] );

		$long = $service->post( $case, $owner, str_repeat( 'א', MessageService::MAX_LENGTH + 1 ) );
		$this->assertSame( 'too_long', $long->get_error_data()['fields']['body'] );

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertIsInt( $service->post( $case, $owner, 'הודעה ' . $i ) );
		}

		$this->assertSame( 'dx_rate_limited', $service->post( $case, $owner, 'עוד אחת' )->get_error_code(), '20 messages an hour.' );
	}

	public function test_the_manager_replies_and_each_client_who_gets_email_gets_it_all(): void {
		[ $case, $owner, $partner, $viewer ] = $this->family();
		$this->app->members->set_mode( $case->id, $viewer, NotifyMode::Off, $this->clock->mysql() );
		$this->config = array(
			'token'   => 'server-token-for-tests',
			'inbound' => 'replies@inbound.postmarkapp.com',
		);
		$service      = new MessageService( $this->app );
		$service->post( $case, $owner, 'שאלה' );
		$this->mails = array();

		$id = $service->reply( $case, $case->manager_id, "הקבלן מגיע ביום ראשון.\nבברכה" );
		$this->assertIsInt( $id );

		$to_owner = $this->mails_to( self::email( $owner ) );
		$this->assertCount( 1, $to_owner );
		$this->assertStringContainsString( 'תשובה ממנהל הפרויקט', (string) $to_owner[0]['subject'] );
		$this->assertStringContainsString( 'הקבלן מגיע ביום ראשון.', (string) $to_owner[0]['message'] );
		$this->assertContains( 'Reply-To: דניאל לוי <' . InboundService::reply_address( $case ) . '>', $to_owner[0]['headers'], 'Answering the email comes back into the case.' );
		$this->assertSame( array(), $this->mails_to( self::email( $partner ) ), 'A partner who gets no case email gets none.' );
		$this->assertSame( array(), $this->mails_to( self::email( $viewer ) ), 'Nor someone who turned case email off.' );

		$thread = $service->thread( $case, $owner, false );
		$this->assertTrue( $thread[1]['staff'] );
		$this->assertSame( 'דניאל לוי', $thread[1]['author'] );
		$audit = $this->app->audit->for_case( $case->id )[0];
		$this->assertSame( 'message.replied', $audit['action'] );
		$this->assertSame( array( 'emailed' => 1 ), $audit['details'] );

		$this->assertSame( 'dx_forbidden', $service->reply( $case, $this->manager(), 'לא שלי' )->get_error_code(), 'Negative: another manager.' );
		$this->assertSame( 'dx_forbidden', $service->reply( $case, $owner, 'לקוח' )->get_error_code(), 'Negative: a client.' );
	}

	public function test_the_dashboard_shows_conversations_waiting_for_an_answer(): void {
		[ $case, $owner ] = $this->family();
		$service          = new MessageService( $this->app );
		$service->post( $case, $owner, 'שאלה' );

		$waiting = $this->app->messages->awaiting_reply( $case->manager_id );
		$this->assertSame( array( $case->id ), array_column( $waiting, 'case_id' ) );
		$this->assertSame( array(), $this->app->messages->awaiting_reply( $this->manager() ), 'Another manager does not see it.' );

		$service->reply( $case, $case->manager_id, 'תשובה' );
		$this->assertSame( array(), $this->app->messages->awaiting_reply( $case->manager_id ), 'Answered.' );
	}

	public function test_a_message_is_shown_as_text_only(): void {
		[ $case, $owner ] = $this->family();
		( new MessageService( $this->app ) )->post( $case, $owner, '<img src=x onerror=alert(1)> שלום' );

		$view         = ( new CaseView( $this->app ) )->build( $case, $owner, 'messages' );
		$view['self'] = 'https://example.org/portal/case/x/';
		ob_start();
		include DX_PORTAL_DIR . 'templates/portal/parts/messages.php';
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt; שלום', $html );
		$this->assertStringContainsString( 'name="dx_step" value="message"', $html, 'The owner gets the form.' );
	}
}
