<?php
/**
 * Meetings in a case.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\MemberRecord;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\Ics;
use Diromax\Portal\Domain\MeetingInput;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Support\Ids;
use Diromax\Portal\Support\TempFile;
use Diromax\Portal\Support\Transaction;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Meetings, site visits and calls in a case (spec 20.1). The project manager
 * picks which clients are invited (decision 28); they get a calendar
 * invitation by email, see the meeting on their case page, and get a
 * reminder the day before and on the morning of the meeting (decision 29).
 * Clients who turned email off still see it in the portal.
 *
 * @phpstan-import-type MeetingRow from \Diromax\Portal\Data\MeetingRepository
 */
final class MeetingService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Schedule a meeting.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Form fields.
	 * @param int[]                 $chosen      Clients invited.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error New meeting ID.
	 */
	public function create( CaseRecord $client_case, array $input, array $chosen, int $actor ): int|WP_Error {
		$fields = $this->check( $client_case, $input, $chosen, $actor );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		$now     = $this->app->clock->mysql();
		$id      = 0;
		$ok      = Transaction::run(
			$this->app->db,
			function () use ( $client_case, $fields, $actor, $now, &$id ): bool {
				$id = $this->app->meetings->insert(
					array(
						'public_id'  => Ids::public_id(),
						'case_id'    => $client_case->id,
						'title'      => $fields['title'],
						'kind'       => $fields['kind'],
						'starts_at'  => $fields['starts_at'],
						'minutes'    => $fields['minutes'],
						'location'   => $fields['location'],
						'note'       => $fields['note'],
						'created_by' => $actor,
					),
					$now
				);

				return $id > 0 && $this->app->meetings->set_participants( $id, $fields['participants'] );
			}
		);
		$meeting = $ok ? $this->app->meetings->find( $id ) : null;

		if ( null === $meeting ) {
			return Errors::storage();
		}

		$sent = $fields['notify'] ? $this->tell( $client_case, $meeting, $fields['participants'], 'invite' ) : 0;
		$this->app->audit->record(
			'meeting.created',
			$client_case->id,
			'meeting',
			$id,
			array(
				'starts_at' => $meeting['starts_at'],
				'invited'   => count( $fields['participants'] ),
				'emailed'   => $sent,
			),
			$now
		);

		return $id;
	}

	/**
	 * Change a meeting. Invited clients get the updated invitation, new ones
	 * an invitation, and those taken off a cancellation.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $meeting_id  Meeting.
	 * @param array<string, string> $input       Form fields.
	 * @param int[]                 $chosen      Clients invited.
	 * @param int                   $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function update( CaseRecord $client_case, int $meeting_id, array $input, array $chosen, int $actor ): bool|WP_Error {
		$meeting = $this->scheduled( $client_case, $meeting_id );

		if ( null === $meeting ) {
			return Errors::not_found();
		}

		$fields = $this->check( $client_case, $input, $chosen, $actor );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		$before = $this->app->meetings->participants( $meeting_id );
		$moved  = $fields['starts_at'] !== $meeting['starts_at'] || $fields['minutes'] !== $meeting['minutes'];
		$now    = $this->app->clock->mysql();
		$ok     = Transaction::run(
			$this->app->db,
			fn(): bool => $this->app->meetings->update( $meeting_id, $fields, $moved, $now ) && $this->app->meetings->set_participants( $meeting_id, $fields['participants'] )
		);
		$after  = $ok ? $this->app->meetings->find( $meeting_id ) : null;

		if ( null === $after ) {
			return Errors::storage();
		}

		$sent = 0;

		if ( $fields['notify'] ) {
			$sent += $this->tell( $client_case, $after, array_values( array_intersect( $fields['participants'], $before ) ), 'update' );
			$sent += $this->tell( $client_case, $after, array_values( array_diff( $fields['participants'], $before ) ), 'invite' );
			$sent += $this->tell( $client_case, array( 'status' => 'cancelled' ) + $after, array_values( array_diff( $before, $fields['participants'] ) ), 'cancel' );
		}

		$this->app->audit->record(
			'meeting.updated',
			$client_case->id,
			'meeting',
			$meeting_id,
			array_filter(
				array(
					'starts_at' => $moved ? array( $meeting['starts_at'], $after['starts_at'] ) : null,
					'invited'   => count( $fields['participants'] ),
					'emailed'   => $sent,
				)
			),
			$now
		);

		return true;
	}

	/**
	 * Cancel a meeting; invited clients get the cancellation for their calendar.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $meeting_id  Meeting.
	 * @param bool       $notify      Email the invited clients.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function cancel( CaseRecord $client_case, int $meeting_id, bool $notify, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$meeting = $this->scheduled( $client_case, $meeting_id );

		if ( null === $meeting || ! $this->app->meetings->cancel( $meeting_id, $this->app->clock->mysql() ) ) {
			return Errors::not_found();
		}

		$after = $this->app->meetings->find( $meeting_id ) ?? $meeting;
		$sent  = $notify ? $this->tell( $client_case, $after, $this->app->meetings->participants( $meeting_id ), 'cancel' ) : 0;
		$this->app->audit->record( 'meeting.cancelled', $client_case->id, 'meeting', $meeting_id, array( 'emailed' => $sent ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * The case's meetings for the staff tab: upcoming first, then past and cancelled.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_staff( CaseRecord $client_case ): array {
		$meetings = $this->app->meetings->for_case( $client_case->id );
		$people   = $this->app->meetings->participants_of( array_column( $meetings, 'id' ) );
		$names    = array();
		$now      = $this->app->clock->now();

		foreach ( $this->app->members->for_case( $client_case->id ) as $member ) {
			$names[ $member->user_id ] = $member->name;
		}

		$rows = array_map(
			function ( array $meeting ) use ( $people, $names, $now ): array {
				$ids = $people[ $meeting['id'] ] ?? array();

				return $this->view( $meeting ) + array(
					'past'         => self::ends( $meeting ) <= $now,
					'participants' => $ids,
					'names'        => array_values( array_map( static fn( int $id ): string => $names[ $id ] ?? __( 'לקוח שהוסר מהתיק', 'diromax-portal' ), $ids ) ),
				);
			},
			$meetings
		);

		// Coming up first, soonest first (the repository's order); then the rest, latest first.
		$coming = array_filter( $rows, static fn( array $row ): bool => ! $row['past'] && 'scheduled' === $row['status'] );
		$other  = array_reverse( array_diff_key( $rows, $coming ) );

		return array_merge( array_values( $coming ), array_values( $other ) );
	}

	/**
	 * Meetings a client is invited to and that have not ended.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $viewer      Client.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_client( CaseRecord $client_case, int $viewer ): array {
		return array_map(
			fn( array $meeting ): array => $this->view( $meeting ) + array( 'ics' => Router::meeting_url( $meeting['public_id'] ) ),
			$this->app->meetings->upcoming_for( $client_case->id, $viewer, $this->app->clock->mysql() )
		);
	}

	/**
	 * May the user see this meeting: the case's staff, or an invited client
	 * who may still see the case.
	 *
	 * @param CaseRecord           $client_case Case.
	 * @param array<string, mixed> $meeting     Meeting.
	 * @phpstan-param MeetingRow $meeting
	 * @param int                  $user        User.
	 * @return bool
	 */
	public function may_view( CaseRecord $client_case, array $meeting, int $user ): bool {
		if ( $meeting['case_id'] !== $client_case->id ) {
			return false;
		}

		if ( $this->app->policy->can_manage_case( $user, $client_case ) ) {
			return true;
		}

		return $this->app->policy->can_view_case( $user, $client_case ) && in_array( $user, $this->app->meetings->participants( $meeting['id'] ), true );
	}

	/**
	 * The calendar file of a meeting, for a user.
	 *
	 * @param CaseRecord           $client_case Case.
	 * @param array<string, mixed> $meeting     Meeting.
	 * @phpstan-param MeetingRow $meeting
	 * @param \WP_User             $user        Who it is for.
	 * @return string
	 */
	public function ics( CaseRecord $client_case, array $meeting, \WP_User $user ): string {
		$manager = get_userdata( $client_case->manager_id );
		$kind    = Labels::meeting_kinds()[ $meeting['kind'] ] ?? '';

		return Ics::event(
			array(
				'uid'             => 'meeting-' . $meeting['public_id'] . '@' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'sequence'        => $meeting['sequence'],
				'start'           => $meeting['starts_at'],
				'end'             => gmdate( 'Y-m-d H:i:s', self::ends( $meeting ) ),
				'stamp'           => $this->app->clock->mysql(),
				'summary'         => $meeting['title'],
				'location'        => $meeting['location'],
				'description'     => trim( $kind . ' · ' . $client_case->title . "\n" . $meeting['note'] ),
				'url'             => Router::case_url( $client_case ),
				'organizer_name'  => $manager ? (string) $manager->display_name : __( 'דירומקס', 'diromax-portal' ),
				'organizer_email' => $manager ? (string) $manager->user_email : '',
				'attendee_name'   => (string) $user->display_name,
				'attendee_email'  => (string) $user->user_email,
				'cancelled'       => 'cancelled' === $meeting['status'],
			)
		);
	}

	/**
	 * Clients who may be invited: every member of the case, with whether
	 * they get email.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array{name:string, email:string, emails:bool}>
	 */
	public function invitable( CaseRecord $client_case ): array {
		$list = array();

		foreach ( $this->app->members->for_case( $client_case->id ) as $member ) {
			$list[ $member->user_id ] = array(
				'name'   => $member->name,
				'email'  => $member->email,
				'emails' => $member->notify,
			);
		}

		return $list;
	}

	/**
	 * The daily reminders (spec 20.1): meetings tomorrow, and meetings today
	 * that have not started yet. Each is sent once; a new time sends again.
	 *
	 * @return int Emails sent.
	 */
	public function send_reminders(): int {
		$zone  = wp_timezone();
		$local = ( new \DateTimeImmutable( '@' . $this->app->clock->now() ) )->setTimezone( $zone );
		$today = $local->setTime( 0, 0 );
		$utc   = static fn( \DateTimeImmutable $time ): string => $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$sent  = 0;

		foreach ( array(
			'remind_tomorrow' => array( $utc( $today->modify( '+1 day' ) ), $utc( $today->modify( '+2 days' ) ) ),
			'remind_today'    => array( $utc( $local ), $utc( $today->modify( '+1 day' ) ) ),
		) as $kind => [ $from, $to ] ) {
			foreach ( $this->app->meetings->starting_between( $from, $to, null ) as $row ) {
				$meeting = $row['meeting'];
				$before  = 'remind_tomorrow' === $kind;

				if ( $before ? $meeting['reminded_before'] : $meeting['reminded_today'] ) {
					continue;
				}

				$client_case = $this->app->cases->find( $meeting['case_id'] );

				if ( null !== $client_case ) {
					$sent += $this->tell( $client_case, $meeting, $this->app->meetings->participants( $meeting['id'] ), $kind );
				}

				$this->app->meetings->mark_reminded( $meeting['id'], $before );
			}
		}

		return $sent;
	}

	/**
	 * When a meeting ends, Unix time.
	 *
	 * @param array{starts_at:string, minutes:int} $meeting Meeting.
	 * @return int
	 */
	public static function ends( array $meeting ): int {
		return (int) strtotime( $meeting['starts_at'] . ' UTC' ) + 60 * $meeting['minutes'];
	}

	/**
	 * Checks shared by create and update.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Form fields.
	 * @param int[]                 $chosen      Clients invited.
	 * @param int                   $actor       Acting user.
	 * @return array{title:string, kind:string, starts_at:string, minutes:int, location:string, note:string, participants:int[], notify:bool}|WP_Error
	 */
	private function check( CaseRecord $client_case, array $input, array $chosen, int $actor ): array|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		if ( CaseStatus::Closed === $client_case->status ) {
			return Errors::invalid( array( 'title' => 'case_closed' ) );
		}

		$members = array_map( static fn( MemberRecord $member ): int => $member->user_id, $this->app->members->for_case( $client_case->id ) );
		$checked = MeetingInput::validate( $input, $chosen, $members, $this->app->clock->now(), wp_timezone() );

		return null === $checked['fields'] ? Errors::invalid( $checked['errors'] ) : $checked['fields'];
	}

	/**
	 * A scheduled meeting of this case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $meeting_id  Meeting.
	 * @return MeetingRow|null
	 */
	private function scheduled( CaseRecord $client_case, int $meeting_id ): ?array {
		$meeting = $this->app->meetings->find( $meeting_id );

		return null !== $meeting && $meeting['case_id'] === $client_case->id && 'scheduled' === $meeting['status'] ? $meeting : null;
	}

	/**
	 * Email a meeting, with its calendar file, to the invited clients who get email.
	 *
	 * @param CaseRecord           $client_case Case.
	 * @param array<string, mixed> $meeting     Meeting.
	 * @phpstan-param MeetingRow $meeting
	 * @param int[]                $people      User IDs.
	 * @param string               $kind        invite, update, cancel, remind_tomorrow or remind_today.
	 * @return int Sent.
	 */
	private function tell( CaseRecord $client_case, array $meeting, array $people, string $kind ): int {
		if ( array() === $people ) {
			return 0;
		}

		$members = array();

		foreach ( $this->app->members->for_case( $client_case->id ) as $member ) {
			$members[ $member->user_id ] = $member;
		}

		$sent = 0;

		foreach ( $people as $user_id ) {
			$member = $members[ $user_id ] ?? null;
			$user   = get_userdata( $user_id );

			if ( null === $member || ! $member->notify || ! $user ) {
				continue;
			}

			$file = TempFile::create( 'dx-ics' );
			file_put_contents( $file, $this->ics( $client_case, $meeting, $user ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary attachment, deleted below.

			try {
				$message = $this->app->renderer->meeting( $client_case, $member->name, $this->card( $client_case, $meeting, $kind ), $kind, Router::case_url( $client_case ) );
				$sent   += $this->app->notifier->send_to_member( $client_case, $user_id, $member->email, 'meeting', $message, '', array( 'meeting.ics' => $file ) ) ? 1 : 0;
			} finally {
				wp_delete_file( $file );
			}
		}

		return $sent;
	}

	/**
	 * The meeting as an email card.
	 *
	 * @param CaseRecord           $client_case Case.
	 * @param array<string, mixed> $meeting     Meeting.
	 * @phpstan-param MeetingRow $meeting
	 * @param string               $kind        What the email is.
	 * @return MailCard
	 */
	private function card( CaseRecord $client_case, array $meeting, string $kind ): MailCard {
		$manager = Contact::manager( $client_case->manager_id );
		$badge   = match ( $kind ) {
			'update'          => __( 'הפגישה עודכנה', 'diromax-portal' ),
			'cancel'          => __( 'הפגישה בוטלה', 'diromax-portal' ),
			'remind_tomorrow' => __( 'תזכורת: פגישה מחר', 'diromax-portal' ),
			'remind_today'    => __( 'תזכורת: פגישה היום', 'diromax-portal' ),
			default           => __( 'הזמנה לפגישה', 'diromax-portal' ),
		};
		$rows = array(
			array( __( 'מתי', 'diromax-portal' ), Labels::meeting_when( $meeting['starts_at'], $meeting['minutes'] ) ),
			array( __( 'סוג', 'diromax-portal' ), Labels::meeting_kinds()[ $meeting['kind'] ] ?? '' ),
			array( __( 'איפה', 'diromax-portal' ), $meeting['location'] ),
			array( __( 'עם', 'diromax-portal' ), $manager['name'] . ( '' !== $manager['phone'] ? ' · ' . $manager['phone'] : '' ) ),
		);

		return new MailCard(
			tone: 'cancel' === $kind ? 'grey' : 'blue',
			badge: $badge,
			title: $meeting['title'],
			rows: array_values( array_filter( $rows, static fn( array $row ): bool => '' !== $row[1] ) ),
			amount: null,
			note: 'cancel' === $kind ? '' : $meeting['note'],
			stage: null,
			link: null,
			subject: $badge . ': ' . $meeting['title'],
		);
	}

	/**
	 * A meeting for a screen.
	 *
	 * @param array<string, mixed> $meeting Meeting.
	 * @phpstan-param MeetingRow $meeting
	 * @return array<string, mixed>
	 */
	private function view( array $meeting ): array {
		$start = ( new \DateTimeImmutable( '@' . (int) strtotime( $meeting['starts_at'] . ' UTC' ) ) )->setTimezone( wp_timezone() );

		return array(
			'id'        => $meeting['id'],
			'public_id' => $meeting['public_id'],
			'title'     => $meeting['title'],
			'kind'      => $meeting['kind'],
			'kind_name' => Labels::meeting_kinds()[ $meeting['kind'] ] ?? '',
			'when'      => Labels::meeting_when( $meeting['starts_at'], $meeting['minutes'] ),
			'starts_at' => $meeting['starts_at'],
			'date'      => $start->format( 'Y-m-d' ),
			'time'      => $start->format( 'H:i' ),
			'minutes'   => $meeting['minutes'],
			'location'  => $meeting['location'],
			'note'      => $meeting['note'],
			'status'    => $meeting['status'],
		);
	}
}
