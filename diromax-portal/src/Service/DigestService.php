<?php
/**
 * Daily digests and monthly summaries.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\EventRepository;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Data\MemberRecord;
use Diromax\Portal\Domain\CaseSummary;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\MonthlyActivity;
use Diromax\Portal\Plugin;
use Diromax\Portal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The scheduled emails that are not tied to a single change (spec 15.3–15.4).
 *
 * Each send is logged under a key unique to the recipient and the period, and
 * checked before sending, so a second run on the same day never repeats one.
 */
final class DigestService {

	/** Option: the month (Y-m) whose summary already went out. */
	public const MONTHLY_OPTION = 'dx_portal_monthly_for';

	/** Days into a month during which failed summaries are retried. */
	private const MONTHLY_RETRY_DAYS = 3;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Send each daily-digest member what happened since their last digest.
	 *
	 * @return int Digests sent.
	 */
	public function daily(): int {
		$now   = $this->app->clock->mysql();
		$today = $this->app->clock->today();
		$hide  = Settings::all()['hide_amounts'];
		$items = new MailItems( $this->app );
		$cases = array();
		$sent  = 0;

		foreach ( $this->app->members->on_digest() as $member ) {
			$client_case = $cases[ $member->case_id ] ??= $this->app->cases->find( $member->case_id );

			if ( null === $client_case ) {
				continue;
			}

			$events = $this->digest_events( $client_case->id, $this->window_start( $member ), $now );
			$cards  = $items->build( $client_case, $events, $hide );
			$batch  = sha1( 'digest|' . $client_case->id . '|' . $member->user_id . '|' . $today );

			if ( array() === $cards || $this->app->mail_log->delivered( $batch, $member->user_id ) ) {
				$this->app->members->mark_digest_sent( $client_case->id, $member->user_id, $now );
				continue;
			}

			$message = $this->app->renderer->digest( $client_case, $cards, $this->summary( $client_case, $hide ), $member->name );

			if ( $this->app->notifier->send_to_member( $client_case, $member->user_id, $member->email, 'digest', $message, $batch ) ) {
				$this->app->members->mark_digest_sent( $client_case->id, $member->user_id, $now );
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * On the first daily run of a month, summarize the month before.
	 *
	 * The first run after installation only records the current period, so a
	 * new site does not get a summary of a month it did not track.
	 *
	 * @return int Summaries sent.
	 */
	public function monthly(): int {
		$local  = ( new \DateTimeImmutable( '@' . $this->app->clock->now() ) )->setTimezone( wp_timezone() );
		$start  = $local->modify( 'first day of last month' )->setTime( 0, 0 );
		$period = $start->format( 'Y-m' );
		$done   = get_option( self::MONTHLY_OPTION );

		if ( false === $done || ! Settings::all()['monthly_summary'] ) {
			update_option( self::MONTHLY_OPTION, $period, false );
			return 0;
		}

		if ( $done >= $period ) {
			return 0;
		}

		[ $sent, $failed ] = $this->send_monthly( $start, $local->modify( 'first day of this month' )->setTime( 0, 0 ) );

		if ( 0 === $failed || (int) $local->format( 'j' ) >= self::MONTHLY_RETRY_DAYS ) {
			update_option( self::MONTHLY_OPTION, $period, false );
		}

		if ( $failed > 0 ) {
			Logger::warning( 'mail.monthly_failed', array( 'failed' => $failed ) );
		}

		return $sent;
	}

	/**
	 * Send the summary of one month to every member who receives email.
	 *
	 * @param \DateTimeImmutable $from First moment of the month, local.
	 * @param \DateTimeImmutable $to   First moment of the next month, local.
	 * @return array{0:int, 1:int} Sent and failed.
	 */
	private function send_monthly( \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$hide   = Settings::all()['hide_amounts'];
		$month  = wp_date( 'F Y', $from->getTimestamp() );
		$sent   = 0;
		$failed = 0;

		foreach ( $this->app->cases->active() as $client_case ) {
			$members = $this->app->members->for_case( $client_case->id, true );

			if ( array() === $members ) {
				continue;
			}

			$summary = $this->app->case_service->summary( $client_case, true );
			$cards   = $this->monthly_cards( $client_case, $summary, $from, $to, $hide );
			$batch   = sha1( 'monthly|' . $client_case->id . '|' . $from->format( 'Y-m' ) );

			foreach ( $members as $member ) {
				if ( $this->app->mail_log->delivered( $batch, $member->user_id ) ) {
					continue;
				}

				$message = $this->app->renderer->monthly( $client_case, $cards, $hide ? null : $summary, $member->name, (string) $month );

				if ( $this->app->notifier->send_to_member( $client_case, $member->user_id, $member->email, 'monthly', $message, $batch ) ) {
					++$sent;
				} else {
					++$failed;
				}
			}
		}

		return array( $sent, $failed );
	}

	/**
	 * Cards of the monthly summary: the month's activity, and what is open.
	 *
	 * @param CaseRecord         $client_case Case.
	 * @param CaseSummary        $summary     Client summary.
	 * @param \DateTimeImmutable $from        Month start, local.
	 * @param \DateTimeImmutable $to          Next month start, local.
	 * @param bool               $hide        Leave amounts out.
	 * @return MailCard[]
	 */
	private function monthly_cards( CaseRecord $client_case, CaseSummary $summary, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $hide ): array {
		$entries  = array_map( static fn( $row ) => $row->entry, $this->app->ledger->for_case( $client_case->id, true ) );
		$activity = MonthlyActivity::from( $entries, $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
		$utc_from = gmdate( 'Y-m-d H:i:s', $from->getTimestamp() );
		$utc_to   = gmdate( 'Y-m-d H:i:s', $to->getTimestamp() );
		$updates  = count( array_filter( $this->app->updates->for_case( $client_case->id, true ), static fn( array $u ): bool => $u['created_at'] >= $utc_from && $u['created_at'] < $utc_to ) );
		$docs     = count( array_filter( $this->app->documents->for_case( $client_case->id, true ), static fn( $d ): bool => $d->created_at >= $utc_from && $d->created_at < $utc_to ) );
		$count    = static fn( int $n, int $total ): string => $hide || 0 === $total ? (string) $n : $n . ' · ' . Money::format( $total );

		$rows = array_filter(
			array(
				array( __( 'הוצאות ששולמו', 'diromax-portal' ), $activity->expenses > 0 ? $count( $activity->expenses, $activity->expense_total ) : '' ),
				array( __( 'התחייבויות חדשות', 'diromax-portal' ), $activity->commitments > 0 ? $count( $activity->commitments, $activity->commitment_total ) : '' ),
				array( __( 'תשלומים שהתקבלו', 'diromax-portal' ), $activity->payments > 0 ? $count( $activity->payments, $activity->payment_total ) : '' ),
				array( __( 'דרישות תשלום', 'diromax-portal' ), $activity->requests > 0 ? $count( $activity->requests, $activity->request_total ) : '' ),
				array( __( 'עדכוני התקדמות', 'diromax-portal' ), $updates > 0 ? (string) $updates : '' ),
				array( __( 'מסמכים חדשים', 'diromax-portal' ), $docs > 0 ? (string) $docs : '' ),
			),
			static fn( array $row ): bool => '' !== $row[1]
		);

		$cards = array(
			new MailCard(
				tone: 'teal',
				badge: __( 'החודש בתיק', 'diromax-portal' ),
				/* translators: %s: stage name. */
				title: sprintf( __( 'השלב הנוכחי: %s', 'diromax-portal' ), Stages::name( $client_case->stage ) ),
				rows: array_values( $rows ),
				amount: null,
				note: array() === $rows ? __( 'החודש לא היו תנועות או עדכונים חדשים בתיק.', 'diromax-portal' ) : '',
				stage: $client_case->stage,
				link: null,
				subject: '',
			),
		);

		if ( $summary->open_request_count > 0 ) {
			$cards[] = new MailCard(
				tone: 'orange',
				badge: __( 'מה פתוח', 'diromax-portal' ),
				title: 1 === $summary->open_request_count
					? __( 'דרישת תשלום פתוחה אחת', 'diromax-portal' )
					/* translators: %d: number of open payment requests. */
					: sprintf( __( '%d דרישות תשלום פתוחות', 'diromax-portal' ), $summary->open_request_count ),
				rows: $summary->overdue_count > 0 ? array( array( __( 'מהן באיחור', 'diromax-portal' ), (string) $summary->overdue_count ) ) : array(),
				amount: $hide ? null : Money::format( $summary->open_requests ),
				note: '',
				stage: null,
				link: array(
					'label' => __( 'לפרטי התשלום באזור האישי', 'diromax-portal' ),
					'url'   => \Diromax\Portal\Portal\Router::case_url( $client_case, 'finance' ),
				),
				subject: '',
			);
		}

		return $cards;
	}

	/**
	 * Every batched event of a case in a window, page by page, so the cursor
	 * that moves to $until after the send never skips events.
	 *
	 * @param int    $case_id Case.
	 * @param string $after   UTC DATETIME, exclusive.
	 * @param string $until   UTC DATETIME, inclusive.
	 * @return array<int, array{id:int, case_id:int, kind:string, object_id:int, immediate:bool, attempts:int, next_attempt_at:string|null, created_at:string}>
	 */
	private function digest_events( int $case_id, string $after, string $until ): array {
		$events  = array();
		$last_id = 0;

		do {
			$page    = $this->app->events->for_digest( $case_id, $after, $until, $last_id );
			$size    = count( $page );
			$events  = array_merge( $events, $page );
			$last_id = 0 === $size ? $last_id : $page[ $size - 1 ]['id'];
		} while ( EventRepository::DIGEST_PAGE === $size );

		return $events;
	}

	/**
	 * Start of a member's digest window: the last digest, or when they joined.
	 *
	 * @param MemberRecord $member Member.
	 * @return string UTC DATETIME.
	 */
	private function window_start( MemberRecord $member ): string {
		// One second before joining, so a change made in the same second still counts.
		return $member->digest_sent_at ?? gmdate( 'Y-m-d H:i:s', (int) strtotime( $member->joined_at . ' UTC' ) - 1 );
	}

	/**
	 * The client's money block, or null when amounts are hidden.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param bool       $hide        Hide amounts.
	 * @return CaseSummary|null
	 */
	private function summary( CaseRecord $client_case, bool $hide ): ?CaseSummary {
		return $hide ? null : $this->app->case_service->summary( $client_case, true );
	}
}
