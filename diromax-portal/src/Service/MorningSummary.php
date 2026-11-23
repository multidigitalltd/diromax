<?php
/**
 * Morning summary for staff.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\StageProgress;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Once a day, each member of staff gets what needs them (spec 16.3): their
 * tasks due by today, and the stages past their due date in the cases they
 * manage (every case, for an administrator). Nothing to tell, no email.
 */
final class MorningSummary {

	/** Most cases looked at per person. */
	private const CASE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Send the summaries.
	 *
	 * @return int Emails sent.
	 */
	public function run(): int {
		if ( ! Settings::all()['morning_summary'] ) {
			return 0;
		}

		$sent = 0;

		foreach ( array_keys( $this->app->case_service->managers() ) as $user_id ) {
			$sent += $this->send_to( $user_id ) ? 1 : 0;
		}

		return $sent;
	}

	/**
	 * Stages past their due date in the active cases in scope, longest late first.
	 *
	 * @param int|null $scope Manager, or null for every case.
	 * @return array<int, array{case_id:int, case_title:string, number:string, stage:string, due:string, days:int}>
	 */
	public function late_stages( ?int $scope ): array {
		$today = $this->app->clock->today();
		$rows  = array();

		foreach ( $this->app->cases->active_for( $scope, self::CASE_LIMIT ) as $client_case ) {
			foreach ( StageProgress::late( $client_case->stages, $today ) as $number ) {
				$due    = (string) $client_case->stages[ $number ]['due'];
				$rows[] = array(
					'case_id'    => $client_case->id,
					'case_title' => $client_case->title,
					'number'     => $client_case->number,
					'stage'      => Stages::label( $number ),
					'due'        => $due,
					'days'       => intdiv( (int) strtotime( $today . ' UTC' ) - (int) strtotime( $due . ' UTC' ), DAY_IN_SECONDS ),
				);
			}
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['days'] <=> $a['days'] );

		return $rows;
	}

	/**
	 * Scheduled meetings from now until the end of tomorrow, in the cases in scope.
	 *
	 * @param int|null $scope Manager, or null for every case.
	 * @return array<int, array{meeting:array{id:int, public_id:string, case_id:int, title:string, kind:string, starts_at:string, minutes:int, location:string, note:string, status:string, sequence:int, reminded_before:bool, reminded_today:bool, created_by:int, created_at:string, updated_at:string}, case_title:string, number:string, manager_id:int}>
	 */
	public function meetings( ?int $scope ): array {
		$now = $this->app->clock->now();
		$end = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 0, 0 )->modify( '+2 days' );

		return $this->app->meetings->starting_between( gmdate( 'Y-m-d H:i:s', $now ), $end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $scope );
	}

		/**
		 * One person's summary, when there is something in it.
		 *
		 * @param int $user_id Staff member.
		 * @return bool Sent.
		 */
	private function send_to( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user || '' === (string) $user->user_email ) {
			return false;
		}

		$today = $this->app->clock->today();
		$all   = $this->app->policy->can_manage_all( $user_id );
		$tasks = $this->app->tasks->open_for( $user_id, $all, $today, 50 );
		$late  = $this->late_stages( $all ? null : $user_id );
		$cards = array();

		if ( array() !== $tasks ) {
			$cards[] = new MailCard(
				tone: 'orange',
				badge: __( 'המשימות שלך', 'diromax-portal' ),
				/* translators: %d: number of tasks. */
				title: sprintf( _n( 'משימה %d להיום או באיחור', '%d משימות להיום או באיחור', count( $tasks ), 'diromax-portal' ), count( $tasks ) ),
				rows: array_map(
					static fn( array $task ): array => array(
						$task['title'],
						$task['case_title'] . ' · ' . ( $task['due_date'] === $today ? __( 'היום', 'diromax-portal' ) : Labels::date( $task['due_date'] ) ),
					),
					$tasks
				),
				amount: null,
				note: '',
				stage: null,
				link: null,
				subject: '',
			);
		}

		$meetings = $this->meetings( $all ? null : $user_id );

		if ( array() !== $meetings ) {
			$cards[] = new MailCard(
				tone: 'blue',
				badge: __( 'פגישות', 'diromax-portal' ),
				/* translators: %d: number of meetings. */
				title: sprintf( _n( 'פגישה %d היום או מחר', '%d פגישות היום ומחר', count( $meetings ), 'diromax-portal' ), count( $meetings ) ),
				rows: array_map(
					static fn( array $row ): array => array(
						$row['meeting']['title'],
						$row['case_title'] . ' · ' . Labels::meeting_when( $row['meeting']['starts_at'], $row['meeting']['minutes'] ),
					),
					array_slice( $meetings, 0, 30 )
				),
				amount: null,
				note: '',
				stage: null,
				link: null,
				subject: '',
			);
		}

		$drafts = $this->app->plan->drafts( $all ? null : $user_id );

		if ( array() !== $drafts ) {
			$cards[] = new MailCard(
				tone: 'orange',
				badge: __( 'תוכנית תשלומים', 'diromax-portal' ),
				/* translators: %d: number of drafts. */
				title: sprintf( _n( 'דרישת תשלום %d מחכה לאישור שלך', '%d דרישות תשלום מחכות לאישור שלך', count( $drafts ), 'diromax-portal' ), count( $drafts ) ),
				rows: array_map(
					static fn( array $row ): array => array(
						$row['payment']['label'],
						$row['case_title'] . ' · ' . Money::format( $row['payment']['draft_amount'] ),
					),
					array_slice( $drafts, 0, 30 )
				),
				amount: null,
				note: '',
				stage: null,
				link: null,
				subject: '',
			);
		}

		if ( array() !== $late ) {
			$cards[] = new MailCard(
				tone: 'ink',
				badge: __( 'שלבים שעברו את היעד', 'diromax-portal' ),
				/* translators: %d: number of stages. */
				title: sprintf( _n( 'שלב %d עבר את היעד', '%d שלבים עברו את היעד', count( $late ), 'diromax-portal' ), count( $late ) ),
				rows: array_map(
					static fn( array $row ): array => array(
						$row['case_title'],
						/* translators: 1: stage, 2: due date. */
						sprintf( __( '%1$s · היעד היה %2$s', 'diromax-portal' ), $row['stage'], Labels::date( $row['due'] ) ),
					),
					array_slice( $late, 0, 30 )
				),
				amount: null,
				note: '',
				stage: null,
				link: null,
				subject: '',
			);
		}

		if ( array() === $cards ) {
			return false;
		}

		$message = $this->app->renderer->morning( (string) $user->display_name, $cards, Router::manage_url() );

		return $this->app->notifier->send_to_staff( $user_id, (string) $user->user_email, $message, 'morning' );
	}
}
