<?php
/**
 * Client notifications.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Domain\NotificationSchedule;
use Diromax\Portal\Plugin;
use Diromax\Portal\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Queues case events and turns them into branded emails (spec section 8).
 *
 * Events wait for the batch window and leave as one email per member; a
 * payment request leaves at once. A failed send is retried three times with
 * backoff, and a member who already received a batch is skipped on retry.
 */
final class Notifier {

	/** Worker lock, so overlapping cron runs never double-send. */
	private const LOCK = 'dx_portal_notify_lock';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Queue an event; send at once when it is immediate.
	 *
	 * @param int    $case_id   Case.
	 * @param string $kind      Event kind.
	 * @param int    $object_id Object.
	 * @param bool   $immediate Skip the batch window.
	 */
	public function enqueue( int $case_id, string $kind, int $object_id, bool $immediate ): void {
		$this->app->events->add( $case_id, $kind, $object_id, $immediate, $this->app->clock->mysql() );

		if ( $immediate ) {
			$this->process_case( $case_id, true );
		}
	}

	/**
	 * Cron worker: send every batch that is due.
	 */
	public function run(): void {
		if ( get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 4 * MINUTE_IN_SECONDS );

		try {
			foreach ( $this->app->events->cases_with_pending() as $case_id ) {
				$this->process_case( $case_id, false );
			}
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Send a case's pending events when due (or now, when forced).
	 *
	 * @param int  $case_id Case.
	 * @param bool $force   Ignore the batch window and retry delay.
	 * @return int Emails sent.
	 */
	public function process_case( int $case_id, bool $force ): int {
		$events      = $this->app->events->pending_for_case( $case_id );
		$client_case = array() === $events ? null : $this->app->cases->find( $case_id );

		if ( null === $client_case || ( ! $force && ! $this->due( $events ) ) ) {
			return 0;
		}

		$ids        = array_column( $events, 'id' );
		$builder    = new MailItems( $this->app );
		$hide       = Settings::all()['hide_amounts'];
		$items      = $builder->build( $client_case, $events, $hide );
		$recipients = $this->app->members->for_case( $client_case->id, true );

		if ( array() === $items || array() === $recipients ) {
			$this->app->events->set_status( $ids, 'sent', $this->app->clock->mysql() );
			return 0;
		}

		// Members on the daily digest get only what needs action now; the rest waits for their digest.
		$urgent = $builder->build( $client_case, array_values( array_filter( $events, static fn( array $event ): bool => $event['immediate'] ) ), $hide );

		return $this->deliver( $client_case, $items, $urgent, $events, $recipients );
	}

	/**
	 * Admin action: send the pending batch now.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Acting user.
	 * @return int|WP_Error Emails sent.
	 */
	public function send_now( CaseRecord $client_case, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$sent = $this->process_case( $client_case->id, true );
		$this->app->audit->record( 'mail.sent_now', $client_case->id, 'case', $client_case->id, array( 'emails' => $sent ), $this->app->clock->mysql() );

		return $sent;
	}

	/**
	 * Admin action: drop the pending batch.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Acting user.
	 * @return int|WP_Error Events cancelled.
	 */
	public function cancel( CaseRecord $client_case, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$ids = array_column( $this->app->events->pending_for_case( $client_case->id ), 'id' );
		$this->app->events->set_status( $ids, 'cancelled' );
		$this->app->audit->record( 'mail.cancelled', $client_case->id, 'case', $client_case->id, array( 'events' => count( $ids ) ), $this->app->clock->mysql() );

		return count( $ids );
	}

	/**
	 * Pending batch of a case: how many events and when they go out.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array{count:int, send_at:int|null}
	 */
	public function pending( CaseRecord $client_case ): array {
		$events = $this->app->events->pending_for_case( $client_case->id );

		if ( array() === $events ) {
			return array(
				'count'   => 0,
				'send_at' => null,
			);
		}

		$created = array_map( array( self::class, 'timestamp' ), array_column( $events, 'created_at' ) );
		$quiet   = max( $created ) + Settings::all()['quiet_minutes'] * MINUTE_IN_SECONDS;
		$latest  = min( $created ) + Settings::all()['max_wait_minutes'] * MINUTE_IN_SECONDS;

		return array(
			'count'   => count( $events ),
			'send_at' => min( $quiet, $latest ),
		);
	}

	/**
	 * The email the pending batch would produce, for the manager's preview.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $actor Acting user.
	 * @return string|WP_Error HTML, or '' when nothing is pending.
	 */
	public function preview( CaseRecord $client_case, int $actor ): string|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$items = ( new MailItems( $this->app ) )->build( $client_case, $this->app->events->pending_for_case( $client_case->id ), Settings::all()['hide_amounts'] );

		if ( array() === $items ) {
			return '';
		}

		return $this->app->renderer->case_update( $client_case, $items, $this->client_summary( $client_case ), __( 'שם הלקוח', 'diromax-portal' ) )->html;
	}

	/**
	 * Email a sign-in link.
	 *
	 * @param int    $user_id User.
	 * @param string $url     Link.
	 * @return bool
	 */
	public function send_login_link( int $user_id, string $url ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$message = $this->app->renderer->login_link( (string) $user->display_name, $url );

		return $this->send_logged( null, $user_id, (string) $user->user_email, 'login', $message, null );
	}

	/**
	 * Email an invitation.
	 *
	 * @param int        $user_id User.
	 * @param CaseRecord $client_case    Case.
	 * @param string     $url     Link.
	 * @return bool
	 */
	public function send_invitation( int $user_id, CaseRecord $client_case, string $url ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$message = $this->app->renderer->invitation( (string) $user->display_name, $client_case, $url );

		return $this->send_logged( $client_case->id, $user_id, (string) $user->user_email, 'invite', $message, $client_case );
	}

	/**
	 * Send a ready message to one member of a case and log it (digests, summaries).
	 *
	 * @param CaseRecord  $client_case Case.
	 * @param int         $user_id     Recipient.
	 * @param string      $email       Address.
	 * @param string      $template    Template code for the log.
	 * @param MailMessage $message     Message.
	 * @param string      $batch_key   Key that makes the send unique per recipient.
	 * @return bool
	 */
	public function send_to_member( CaseRecord $client_case, int $user_id, string $email, string $template, MailMessage $message, string $batch_key ): bool {
		return $this->send_logged( $client_case->id, $user_id, $email, $template, $message, $client_case, $batch_key );
	}

	/**
	 * Tell the case's project manager that a client acted (decision, agreement).
	 *
	 * @param CaseRecord  $client_case Case.
	 * @param MailMessage $message     Message.
	 * @return bool
	 */
	public function send_to_manager( CaseRecord $client_case, MailMessage $message ): bool {
		$manager = get_userdata( $client_case->manager_id );

		if ( ! $manager ) {
			return false;
		}

		$ok = Mailer::send( (string) $manager->user_email, $message, null );

		$this->app->mail_log->add(
			array(
				'case_id'   => $client_case->id,
				'user_id'   => $manager->ID,
				'recipient' => (string) $manager->user_email,
				'template'  => 'manager',
				'subject'   => $message->subject,
				'batch_key' => '',
				'status'    => $ok ? 'sent' : 'failed',
				'error'     => $ok ? '' : 'wp_mail_failed',
			),
			$this->app->clock->mysql()
		);

		return $ok;
	}

	/**
	 * Are the pending events due by the batching rule and not waiting for a retry?
	 *
	 * @param array<int, array{immediate:bool, next_attempt_at:string|null, created_at:string}> $events Pending events.
	 * @return bool
	 */
	private function due( array $events ): bool {
		$now = $this->app->clock->now();

		foreach ( $events as $event ) {
			if ( null !== $event['next_attempt_at'] && self::timestamp( $event['next_attempt_at'] ) > $now ) {
				return false;
			}
		}

		return NotificationSchedule::is_due(
			array_map( array( self::class, 'timestamp' ), array_column( $events, 'created_at' ) ),
			in_array( true, array_column( $events, 'immediate' ), true ),
			$now,
			Settings::all()['quiet_minutes'] * MINUTE_IN_SECONDS,
			Settings::all()['max_wait_minutes'] * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Send a batch to every member; schedule a retry on failure.
	 *
	 * @param CaseRecord                                    $client_case Case.
	 * @param MailCard[]                                    $items       Mail cards.
	 * @param MailCard[]                                    $urgent      Cards for members on the daily digest.
	 * @param array<int, array{id:int, attempts:int}>       $events      Pending events.
	 * @param array<int, \Diromax\Portal\Data\MemberRecord> $recipients  Members who receive email.
	 * @return int Emails sent.
	 */
	private function deliver( CaseRecord $client_case, array $items, array $urgent, array $events, array $recipients ): int {
		$ids     = array_column( $events, 'id' );
		$batch   = sha1( implode( ',', $ids ) );
		$summary = $this->client_summary( $client_case );
		$sent    = 0;
		$failed  = 0;

		foreach ( $recipients as $member ) {
			$cards = $member->digest ? $urgent : $items;

			if ( array() === $cards || $this->app->mail_log->delivered( $batch, $member->user_id ) ) {
				continue;
			}

			$message = $this->app->renderer->case_update( $client_case, $cards, $summary, $member->name );

			if ( $this->send_logged( $client_case->id, $member->user_id, $member->email, 'case_update', $message, $client_case, $batch ) ) {
				++$sent;
			} else {
				++$failed;
			}
		}

		if ( 0 === $failed ) {
			$this->app->events->set_status( $ids, 'sent', $this->app->clock->mysql() );
			return $sent;
		}

		$previous = array_column( $events, 'attempts' );
		$attempts = 1 + ( array() === $previous ? 0 : max( $previous ) );
		$retry_at = NotificationSchedule::next_attempt( $attempts, $this->app->clock->now() );

		if ( null === $retry_at ) {
			$this->app->events->set_status( $ids, 'failed' );
			Logger::error( 'mail.batch_failed', array( 'case' => $client_case->id ) );
		} else {
			$this->app->events->schedule_retry( $ids, $attempts, gmdate( 'Y-m-d H:i:s', $retry_at ) );
		}

		return $sent;
	}

	/**
	 * Send a message to a member of staff, not about one case, and log it.
	 *
	 * @param int         $user_id  Staff member.
	 * @param string      $email    Address.
	 * @param MailMessage $message  Message.
	 * @param string      $template Template code for the log.
	 * @return bool
	 */
	public function send_to_staff( int $user_id, string $email, MailMessage $message, string $template ): bool {
		$ok = Mailer::send( $email, $message, null );

		$this->app->mail_log->add(
			array(
				'case_id'   => null,
				'user_id'   => $user_id,
				'recipient' => $email,
				'template'  => $template,
				'subject'   => $message->subject,
				'batch_key' => '',
				'status'    => $ok ? 'sent' : 'failed',
				'error'     => $ok ? '' : 'wp_mail_failed',
			),
			$this->app->clock->mysql()
		);

		return $ok;
	}

	/**
	 * Send one message and log it.
	 *
	 * @param int|null        $case_id   Case.
	 * @param int             $user_id   Recipient user.
	 * @param string          $email     Recipient address.
	 * @param string          $template  Template code.
	 * @param MailMessage     $message   Rendered message.
	 * @param CaseRecord|null $client_case      Case, for the reply-to manager.
	 * @param string          $batch_key Batch key.
	 * @return bool
	 */
	private function send_logged( ?int $case_id, int $user_id, string $email, string $template, MailMessage $message, ?CaseRecord $client_case, string $batch_key = '' ): bool {
		$manager = null === $client_case ? null : get_userdata( $client_case->manager_id );
		$ok      = Mailer::send( $email, $message, $manager ? $manager : null );

		$this->app->mail_log->add(
			array(
				'case_id'   => $case_id,
				'user_id'   => $user_id,
				'recipient' => $email,
				'template'  => $template,
				'subject'   => $message->subject,
				'batch_key' => $batch_key,
				'status'    => $ok ? 'sent' : 'failed',
				'error'     => $ok ? '' : 'wp_mail_failed',
			),
			$this->app->clock->mysql()
		);

		return $ok;
	}

	/**
	 * The client's view of the money, or null when amounts are hidden from email.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return \Diromax\Portal\Domain\CaseSummary|null
	 */
	private function client_summary( CaseRecord $client_case ): ?\Diromax\Portal\Domain\CaseSummary {
		return Settings::all()['hide_amounts'] ? null : $this->app->case_service->summary( $client_case, true );
	}

	/**
	 * Unix time of a UTC DATETIME.
	 *
	 * @param string $datetime UTC DATETIME.
	 * @return int
	 */
	private static function timestamp( string $datetime ): int {
		return (int) strtotime( $datetime . ' UTC' );
	}
}
