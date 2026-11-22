<?php
/**
 * Morning summary for staff.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

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
		$tasks = $this->app->tasks->open_for( $user_id, $today, 50 );
		$late  = $this->late_stages( $this->app->policy->can_manage_all( $user_id ) ? null : $user_id );
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
