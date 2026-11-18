<?php
/**
 * Phase 2 scheduled email: daily slot, payment reminders, email modes,
 * daily digest and monthly summary (spec 15.1–15.4).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\EventRepository;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Service\DailyJobs;
use Diromax\Portal\Service\DigestService;
use Diromax\Portal\Service\ReminderService;
use Diromax\Portal\Settings;
use WP_Error;

final class ScheduledMailTest extends PortalTestCase {

	public function tear_down(): void {
		delete_option( DailyJobs::OPTION );
		delete_option( DigestService::MONTHLY_OPTION );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_the_daily_slot_runs_once_a_day_after_the_hour(): void {
		$jobs = new DailyJobs( $this->app );

		$this->clock->advance( -2 * HOUR_IN_SECONDS );
		$this->assertFalse( $jobs->maybe_run(), 'Not before 09:00.' );

		$this->clock->advance( 2 * HOUR_IN_SECONDS );
		$this->assertTrue( $jobs->maybe_run() );
		$this->assertFalse( $jobs->maybe_run(), 'Not twice on one day.' );

		$this->clock->advance( DAY_IN_SECONDS );
		$this->assertTrue( $jobs->maybe_run() );
	}

	public function test_a_reminder_before_the_due_date_goes_out_once(): void {
		[ $manager, $case ] = $this->case_with_client();
		$this->request_at( $case, $manager, '-2 days', '2026-11-21' );

		$reminders = new ReminderService( $this->app );

		$this->assertSame( 1, $reminders->run() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'תזכורת: דרישת תשלום של 400 ₪ עד 21.11.2026', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'IBAN IL00 0000', $this->mails[0]['message'], 'The payment details travel with the reminder.' );

		$this->assertSame( 0, $reminders->run(), 'Never twice on one day.' );
		$this->clock->advance( DAY_IN_SECONDS );
		$this->assertSame( 0, $reminders->run(), 'One reminder per window.' );
		$this->assertCount( 1, $this->mails );
	}

	/**
	 * Spec 15.11: late by 1, 8, 15 and 22 days gives exactly three reminders.
	 */
	public function test_overdue_reminders_stop_at_the_maximum(): void {
		[ $manager, $case ] = $this->case_with_client();
		$entry_id           = $this->request_at( $case, $manager, '-30 days', '2026-11-10' );
		$this->clock        = new \Diromax\Portal\Support\Clock( (int) strtotime( '2026-11-11 10:00:00 UTC' ) );
		$this->app          = \Diromax\Portal\Plugin::reset( $this->clock );

		for ( $day = 0; $day < 30; $day++ ) {
			( new ReminderService( $this->app ) )->run();
			$this->clock->advance( DAY_IN_SECONDS );
		}

		$late = array_filter( $this->mails, static fn( array $mail ): bool => str_contains( $mail['subject'], 'עברה את המועד' ) );
		$this->assertCount( 3, $late );

		$row = $this->app->ledger->find( $entry_id );
		$this->assertSame( 3, $row->overdue_reminders );
		$this->assertSame( '2026-11-25', $row->last_reminder_on );
	}

	public function test_no_reminder_for_paid_voided_hidden_or_closed(): void {
		[ $manager, $case ] = $this->case_with_client();
		$paid               = $this->request_at( $case, $manager, '-20 days', '2026-11-10' );
		$voided             = $this->request_at( $case, $manager, '-20 days', '2026-11-10' );
		$this->request_at( $case, $manager, '-20 days', '2026-11-10', array( 'visible' => '0' ) );

		$this->assertIsInt(
			$this->app->ledger_service->settle(
				$case,
				$paid,
				array(
					'entry_date' => '2026-11-15',
					'method'     => 'transfer',
				),
				$manager
			)
		);
		$this->assertTrue( $this->app->ledger_service->void( $case, $voided, 'נפתחה בטעות', false, $manager ) );

		$closed = $this->case_for( $manager, $this->client() );
		$this->request_at( $closed, $manager, '-20 days', '2026-11-10' );
		$this->assertInstanceOf( CaseRecord::class, $this->app->case_service->set_status( $closed, 'closed', $manager ) );

		$this->mails = array();
		$this->assertSame( 0, ( new ReminderService( $this->app ) )->run() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_manual_reminder_needs_the_case_manager_and_waits_a_day(): void {
		[ $manager, $case ] = $this->case_with_client();
		$entry_id           = $this->request_at( $case, $manager, '-1 day', '2026-12-30' );
		$reminders          = new ReminderService( $this->app );

		$this->assertInstanceOf( WP_Error::class, $reminders->send_now( $case, $entry_id, $this->manager() ), 'Another manager may not.' );
		$this->assertInstanceOf( WP_Error::class, $reminders->send_now( $case, $entry_id, (int) $this->app->members->for_case( $case->id )[0]->user_id ), 'A client may not.' );
		$this->assertSame( array(), $this->mails );

		$this->assertTrue( $reminders->send_now( $case, $entry_id, $manager ) );
		$this->assertCount( 1, $this->mails );

		$again = $reminders->send_now( $case, $entry_id, $manager );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'dx_reminded_today', $again->get_error_code() );
	}

	public function test_a_manual_reminder_for_another_cases_request_is_not_found(): void {
		[ $manager, $case ] = $this->case_with_client();
		$other              = $this->case_for( $manager, $this->client() );
		$entry_id           = $this->request_at( $other, $manager, '-1 day', '2026-12-30' );

		$result = ( new ReminderService( $this->app ) )->send_now( $case, $entry_id, $manager );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dx_not_found', $result->get_error_code() );
	}

	public function test_a_daily_member_gets_no_instant_email_then_one_digest(): void {
		$manager = $this->manager();
		$instant = $this->client( 'instant@example.org' );
		$daily   = $this->client( 'daily@example.org' );
		$case    = $this->case_for( $manager, $instant );
		$this->app->members->upsert( $case->id, $daily, MemberRole::Owner, true, $this->clock->mysql() );
		$this->assertTrue( $this->app->members->set_mode( $case->id, $daily, NotifyMode::Daily, $this->clock->mysql() ) );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->app->ledger_service->add( $case, self::expense( array( 'description' => 'חשבונית ' . $i ) ), $manager );
		}

		$this->app->notifier->process_case( $case->id, true );
		$this->assertSame( array( 'instant@example.org' ), array_column( $this->mails, 'to' ) );

		$this->mails = array();
		$this->clock->advance( DAY_IN_SECONDS );
		$this->assertSame( 1, ( new DigestService( $this->app ) )->daily() );
		$this->assertSame( array( 'daily@example.org' ), array_column( $this->mails, 'to' ) );
		$this->assertStringContainsString( 'סיכום יומי: 5 עדכונים', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'חשבונית 5', $this->mails[0]['message'] );

		$this->assertSame( 0, ( new DigestService( $this->app ) )->daily(), 'Nothing new since the last digest.' );
	}

	/**
	 * Regression (PR review): more events than one page must all reach the
	 * digest, since the window cursor jumps to "now" after the send.
	 */
	public function test_a_digest_reads_past_the_first_page_of_events(): void {
		[ $manager, $case, $client ] = $this->case_with_client();
		$this->app->members->set_mode( $case->id, $client, NotifyMode::Daily, $this->clock->mysql() );
		$this->clock->advance( 1 );

		for ( $i = 0; $i < EventRepository::DIGEST_PAGE; $i++ ) {
			// Events whose object is gone: they fill the first page and produce no card.
			$this->app->events->add( $case->id, 'entry_created', 999999, false, $this->clock->mysql() );
		}

		$this->app->ledger_service->add( $case, self::expense( array( 'description' => 'אחרי העמוד הראשון' ) ), $manager );
		$this->mails = array();
		$this->clock->advance( DAY_IN_SECONDS );

		$this->assertSame( 1, ( new DigestService( $this->app ) )->daily() );
		$this->assertStringContainsString( 'אחרי העמוד הראשון', $this->mails[0]['message'] );
	}

	public function test_a_daily_member_still_gets_a_payment_request_at_once(): void {
		[ $manager, $case, $client ] = $this->case_with_client();
		$this->app->members->set_mode( $case->id, $client, NotifyMode::Daily, $this->clock->mysql() );

		$this->app->ledger_service->add( $case, self::request( '2026-12-01' ), $manager );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'דרישת תשלום', $this->mails[0]['subject'] );

		$this->clock->advance( DAY_IN_SECONDS );
		$this->assertSame( 0, ( new DigestService( $this->app ) )->daily(), 'The request is not repeated in the digest.' );
	}

	public function test_a_member_with_email_off_receives_nothing(): void {
		[ $manager, $case, $client ] = $this->case_with_client();
		$this->app->members->set_mode( $case->id, $client, NotifyMode::Off, $this->clock->mysql() );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->app->ledger_service->add( $case, self::request( '2026-12-01' ), $manager );
		$this->app->notifier->process_case( $case->id, true );
		( new DigestService( $this->app ) )->daily();

		$this->assertSame( array(), $this->mails );
	}

	public function test_the_monthly_summary_goes_out_once_for_the_month_before(): void {
		[ $manager, $case ] = $this->case_with_client();
		$this->app->ledger_service->add( $case, self::expense( array( 'entry_date' => '2026-10-12' ) ), $manager );
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'entry_date'  => '2026-11-02',
					'description' => 'לא של אוקטובר',
				)
			),
			$manager
		);
		$this->mails = array();

		$digests = new DigestService( $this->app );
		$this->assertSame( 0, $digests->monthly(), 'The first run only records the period.' );
		$this->assertSame( '2026-10', get_option( DigestService::MONTHLY_OPTION ) );

		update_option( DigestService::MONTHLY_OPTION, '2026-09' );
		$this->assertSame( 1, $digests->monthly() );
		$this->assertStringContainsString( 'סיכום October 2026', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'הוצאות ששולמו', $this->mails[0]['message'] );
		$this->assertStringContainsString( '1 · 12,300 ₪', $this->mails[0]['message'] );

		$this->assertSame( 0, $digests->monthly(), 'Never twice for one month.' );
	}

	public function test_the_monthly_summary_leaves_amounts_out_when_hidden(): void {
		[ $manager, $case ] = $this->case_with_client();
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'entry_date' => '2026-10-12',
					'amount'     => '98765',
				)
			),
			$manager
		);
		$this->mails = array();
		Settings::save( array( 'hide_amounts' => '1' ) );
		update_option( DigestService::MONTHLY_OPTION, '2026-09' );

		( new DigestService( $this->app ) )->monthly();

		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( '98,765', $this->mails[0]['message'] );
	}

	public function test_only_the_member_or_the_case_manager_may_change_the_email_mode(): void {
		[ $manager, $case, $client ] = $this->case_with_client();
		$other_client                = $this->client();
		$this->app->members->upsert( $case->id, $other_client, MemberRole::Owner, true, $this->clock->mysql() );

		$this->assertInstanceOf( WP_Error::class, $this->app->case_service->set_member_mode( $case, $client, 'off', $other_client ), 'Another member may not.' );
		$this->assertInstanceOf( WP_Error::class, $this->app->case_service->set_member_mode( $case, $client, 'off', $this->manager() ), 'Another manager may not.' );
		$this->assertInstanceOf( WP_Error::class, $this->app->case_service->set_member_mode( $case, $client, 'weekly', $client ), 'Unknown modes are rejected.' );
		$this->assertSame( NotifyMode::Instant, $this->app->members->for_case( $case->id )[0]->mode() );

		$this->assertTrue( $this->app->case_service->set_member_mode( $case, $client, 'daily', $client ) );
		$this->assertTrue( $this->app->case_service->set_member_mode( $case, $other_client, 'off', $manager ) );

		$modes = array_map( static fn( $member ) => $member->mode(), $this->app->members->for_case( $case->id ) );
		$this->assertSame( array( NotifyMode::Daily, NotifyMode::Off ), $modes );
	}

	public function test_a_former_member_cannot_change_a_mode(): void {
		[ $manager, $case, $client ] = $this->case_with_client();
		$this->app->case_service->remove_member( $case, $client, $manager );

		$this->assertInstanceOf( WP_Error::class, $this->app->case_service->set_member_mode( $case, $client, 'daily', $client ) );
	}

	/**
	 * A case with one client who receives email.
	 *
	 * @return array{0:int, 1:CaseRecord, 2:int} Manager, case, client.
	 */
	private function case_with_client(): array {
		$manager = $this->manager();
		$client  = $this->client();

		return array( $manager, $this->case_for( $manager, $client ), $client );
	}

	/**
	 * Fields of a payment request to the office.
	 *
	 * @param string $due  Due date.
	 * @param string $date Entry date.
	 * @return array<string, string>
	 */
	private static function request( string $due, string $date = '2026-11-19' ): array {
		return array(
			'type'            => 'request',
			'entry_date'      => $date,
			'due_date'        => $due,
			'amount'          => '400',
			'description'     => 'מקדמה',
			'pay_to'          => 'diromax',
			'payment_details' => 'IBAN IL00 0000',
			'visible'         => '1',
			'notify'          => '1',
		);
	}

	/**
	 * Add a payment request as if created at another time, then clear the mail it sent.
	 *
	 * @param CaseRecord           $client_case Case.
	 * @param int                  $manager   Manager.
	 * @param string               $created   Relative time of creation, e.g. '-2 days'.
	 * @param string               $due       Due date.
	 * @param array<string,string> $overrides Field overrides.
	 * @return int Entry ID.
	 */
	private function request_at( CaseRecord $client_case, int $manager, string $created, string $due, array $overrides = array() ): int {
		$now = $this->clock->now();
		$this->clock->advance( (int) strtotime( $created, $now ) - $now );

		$id = $this->app->ledger_service->add( $client_case, array_merge( self::request( $due, gmdate( 'Y-m-d', $this->clock->now() ) ), $overrides ), $manager );

		$this->clock->advance( $now - $this->clock->now() );
		$this->assertIsInt( $id );
		$this->mails = array();

		return $id;
	}
}
