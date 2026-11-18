<?php
/**
 * Notification batching, delivery, retries and the email content.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Settings;

final class NotifierTest extends PortalTestCase {

	public function test_five_quick_expenses_become_one_email_after_the_quiet_window(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->app->ledger_service->add( $case, self::expense( array( 'description' => 'חשבונית ' . $i ) ), $manager );
			$this->clock->advance( 30 );
		}

		$this->app->notifier->run();
		$this->assertCount( 0, $this->mails, 'Nothing leaves inside the quiet window.' );

		$this->clock->advance( 10 * MINUTE_IN_SECONDS );
		$this->app->notifier->run();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '5 עדכונים חדשים', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'חשבונית 5', $this->mails[0]['message'] );
		$this->assertSame( array(), $this->app->events->pending_for_case( $case->id ) );

		$this->app->notifier->run();
		$this->assertCount( 1, $this->mails, 'A sent batch never goes out twice.' );
	}

	public function test_a_steady_stream_still_goes_out_after_the_maximum_wait(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );

		for ( $i = 0; $i < 7; $i++ ) {
			$this->app->ledger_service->add( $case, self::expense(), $manager );
			$this->clock->advance( 5 * MINUTE_IN_SECONDS );
			$this->app->notifier->run();
		}

		$this->assertCount( 1, $this->mails );
	}

	public function test_only_members_who_want_email_receive_it(): void {
		$manager = $this->manager();
		$owner   = $this->client( 'owner@example.org' );
		$quiet   = $this->client( 'quiet@example.org' );
		$case    = $this->case_for( $manager, $owner );
		$this->app->members->upsert( $case->id, $quiet, MemberRole::Viewer, false, $this->clock->mysql() );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->app->notifier->process_case( $case->id, true );

		$this->assertSame( array( 'owner@example.org' ), array_column( $this->mails, 'to' ) );
	}

	public function test_email_carries_the_manager_as_reply_to_and_html_content_type(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->app->notifier->process_case( $case->id, true );

		$headers = implode( "\n", (array) $this->mails[0]['headers'] );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $headers );
		$this->assertStringContainsString( 'Reply-To: דניאל לוי <' . get_userdata( $manager )->user_email . '>', $headers );
		$this->assertStringContainsString( 'הוצאה חדשה בתיק: 12,300 ₪', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'dir="rtl"', $this->mails[0]['message'] );
	}

	public function test_entry_created_and_voided_in_one_batch_is_never_mentioned(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );
		$id      = $this->app->ledger_service->add( $case, self::expense(), $manager );

		$this->app->ledger_service->void( $case, $id, 'טעות', true, $manager );
		$this->app->notifier->process_case( $case->id, true );

		$this->assertCount( 0, $this->mails );
		$this->assertSame( array(), $this->app->events->pending_for_case( $case->id ) );
	}

	public function test_amounts_are_left_out_when_hidden_in_settings(): void {
		Settings::save( array( 'hide_amounts' => '1' ) );
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->app->notifier->process_case( $case->id, true );

		$this->assertStringNotContainsString( '12,300', $this->mails[0]['subject'] . $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'המצב הכספי', $this->mails[0]['message'] );
	}

	public function test_user_text_is_escaped_in_the_email(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );

		$this->app->ledger_service->add( $case, self::expense( array( 'description' => '<img src=x onerror=alert(1)>' ) ), $manager );
		$this->app->notifier->process_case( $case->id, true );

		$this->assertStringNotContainsString( '<img src=x', $this->mails[0]['message'] );
		$this->assertStringContainsString( '&lt;img src=x', $this->mails[0]['message'] );
	}

	public function test_failed_send_is_retried_with_backoff_and_then_marked_failed(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );
		$this->app->ledger_service->add( $case, self::expense(), $manager );

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->app->notifier->process_case( $case->id, true );
		$pending = $this->app->events->pending_for_case( $case->id );
		$this->assertSame( 1, $pending[0]['attempts'] );

		$this->clock->advance( 60 );
		$this->app->notifier->run();
		$this->assertSame( 1, $this->app->events->pending_for_case( $case->id )[0]['attempts'], 'Waits for the retry delay.' );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->clock->advance( 3 * HOUR_IN_SECONDS );
			$this->app->notifier->run();
		}

		$this->assertSame( array(), $this->app->events->pending_for_case( $case->id ) );
		$this->assertSame( 1, $this->app->events->failed_count() );
		$this->assertSame( 'failed', $this->app->mail_log->for_case( $case->id )[0]['status'] );

		remove_filter( 'pre_wp_mail', '__return_false' );
	}

	public function test_retry_skips_members_who_already_received_the_batch(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client( 'first@example.org' ) );
		$second  = $this->client( 'second@example.org' );
		$this->app->members->upsert( $case->id, $second, MemberRole::Partner, true, $this->clock->mysql() );
		$this->app->ledger_service->add( $case, self::expense(), $manager );

		$fail_second = static fn( $short, array $atts ) => 'second@example.org' === $atts['to'] ? false : null;
		add_filter( 'pre_wp_mail', $fail_second, 5, 2 );
		$this->app->notifier->process_case( $case->id, true );
		remove_filter( 'pre_wp_mail', $fail_second, 5 );

		$this->assertSame( array( 'first@example.org' ), array_column( $this->mails, 'to' ) );

		$this->clock->advance( 10 * MINUTE_IN_SECONDS );
		$this->app->notifier->run();

		$this->assertSame( array( 'first@example.org', 'second@example.org' ), array_column( $this->mails, 'to' ) );
	}

	public function test_manager_can_send_now_cancel_and_preview(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->assertSame( 1, $this->app->notifier->pending( $case )['count'] );
		$this->assertSame( self::NOW + 10 * MINUTE_IN_SECONDS, $this->app->notifier->pending( $case )['send_at'] );
		$this->assertStringContainsString( 'אגרת בקשה להיתר', $this->app->notifier->preview( $case, $manager ) );
		$this->assertSame( 'dx_forbidden', $this->app->notifier->preview( $case, $client )->get_error_code() );

		$this->assertSame( 1, $this->app->notifier->send_now( $case, $manager ) );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->assertSame( 1, $this->app->notifier->cancel( $case, $manager ) );
		$this->assertSame( '', $this->app->notifier->preview( $case, $manager ) );
		$this->assertSame( 0, $this->app->notifier->pending( $case )['count'] );

		$this->assertSame( 'dx_forbidden', $this->app->notifier->send_now( $case, $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->notifier->cancel( $case, $client )->get_error_code() );
	}

	public function test_progress_update_with_stage_and_document_cards(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );

		$update = ( new \Diromax\Portal\Service\UpdateService( $this->app ) )->publish(
			$case,
			array(
				'title'   => 'הבקשה להיתר הוגשה',
				'body'    => "הוועדה תדון בתוך 45 יום.\nאין צורך בפעולה.",
				'stage'   => '5',
				'visible' => '1',
				'notify'  => '1',
			),
			array(),
			$manager
		);

		$this->assertIsInt( $update );
		$this->assertSame( 5, $this->app->cases->find( $case->id )->stage );

		$this->app->notifier->process_case( $case->id, true );
		$this->assertStringContainsString( 'התקדמנו לשלב 05', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'הוועדה תדון בתוך 45 יום.<br />', $this->mails[0]['message'] );
	}

	public function test_plain_text_alternative_keeps_links_and_drops_markup(): void {
		$html = '<html><head><style>p{}</style></head><body><div class="dx-preheader">pre</div><h1>כותרת</h1><p>שורה<br>שנייה</p><a href="https://example.org/portal/">לאזור האישי</a></body></html>';
		$text = \Diromax\Portal\Service\MailRenderer::to_text( $html );

		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( 'pre', $text );
		$this->assertStringContainsString( "כותרת\nשורה\nשנייה", $text );
		$this->assertStringContainsString( 'לאזור האישי: https://example.org/portal/', $text );
	}
}
