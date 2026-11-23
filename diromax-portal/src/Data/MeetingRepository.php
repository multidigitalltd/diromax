<?php
/**
 * Meetings table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Meetings of a case and who is invited (spec 20.1). Times are UTC.
 * "sequence" goes up on every change, so a calendar replaces the event it
 * holds; the reminder flags go back to 0 when the time changes.
 *
 * @phpstan-type MeetingRow array{id:int, public_id:string, case_id:int, title:string, kind:string, starts_at:string, minutes:int, location:string, note:string, status:string, sequence:int, reminded_before:bool, reminded_today:bool, created_by:int, created_at:string, updated_at:string}
 */
final class MeetingRepository {

	/** Columns read back. */
	private const COLUMNS = 'm.id, m.public_id, m.case_id, m.title, m.kind, m.starts_at, m.minutes, m.location, m.note, m.status, m.sequence, m.reminded_before, m.reminded_today, m.created_by, m.created_at, m.updated_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Add a meeting.
	 *
	 * @param array{public_id:string, case_id:int, title:string, kind:string, starts_at:string, minutes:int, location:string, note:string, created_by:int} $meeting Fields.
	 * @param string                                                                                                                                       $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( array $meeting, string $now ): int {
		$row = $meeting + array(
			'status'     => 'scheduled',
			'created_at' => $now,
			'updated_at' => $now,
		);

		return false === $this->db->insert( Schema::table( 'meetings' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Change a meeting; the sequence goes up, and a new time resets the reminders.
	 *
	 * @param int                                                                                           $id      Meeting.
	 * @param array{title:string, kind:string, starts_at:string, minutes:int, location:string, note:string} $fields  Fields.
	 * @param bool                                                                                          $moved   The time changed.
	 * @param string                                                                                        $now     UTC DATETIME.
	 * @return bool
	 */
	public function update( int $id, array $fields, bool $moved, string $now ): bool {
		$table = Schema::table( 'meetings' );
		$reset = $moved ? ', reminded_before = 0, reminded_today = 0' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $reset is a fixed fragment; every value is bound.
		return $this->run( $this->db->prepare( "UPDATE %i SET title = %s, kind = %s, starts_at = %s, minutes = %d, location = %s, note = %s, sequence = sequence + 1, updated_at = %s{$reset} WHERE id = %d", $table, $fields['title'], $fields['kind'], $fields['starts_at'], $fields['minutes'], $fields['location'], $fields['note'], $now, $id ) );
	}

	/**
	 * Cancel a meeting.
	 *
	 * @param int    $id  Meeting.
	 * @param string $now UTC DATETIME.
	 * @return bool
	 */
	public function cancel( int $id, string $now ): bool {
		return $this->run( $this->db->prepare( "UPDATE %i SET status = 'cancelled', sequence = sequence + 1, updated_at = %s WHERE id = %d", Schema::table( 'meetings' ), $now, $id ) );
	}

	/**
	 * Record a reminder as sent.
	 *
	 * @param int  $id     Meeting.
	 * @param bool $before The day-before reminder; false for the same-morning one.
	 */
	public function mark_reminded( int $id, bool $before ): void {
		$this->db->update( Schema::table( 'meetings' ), array( $before ? 'reminded_before' : 'reminded_today' => 1 ), array( 'id' => $id ) );
	}

	/**
	 * One meeting.
	 *
	 * @param int $id Meeting.
	 * @return MeetingRow|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i m WHERE m.id = %d', Schema::table( 'meetings' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * One meeting by its public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return MeetingRow|null
	 */
	public function find_by_public_id( string $public_id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i m WHERE m.public_id = %s', Schema::table( 'meetings' ), $public_id ), ARRAY_A );

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Meetings of a case, soonest first.
	 *
	 * @param int $case_id Case.
	 * @return MeetingRow[]
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i m WHERE m.case_id = %d ORDER BY m.starts_at LIMIT 500', Schema::table( 'meetings' ), $case_id ),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Scheduled meetings of a case that a client is invited to and that have
	 * not ended, soonest first.
	 *
	 * @param int    $case_id Case.
	 * @param int    $user_id Client.
	 * @param string $now     UTC DATETIME.
	 * @return MeetingRow[]
	 */
	public function upcoming_for( int $case_id, int $user_id, string $now ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i m INNER JOIN %i p ON p.meeting_id = m.id
				  WHERE m.case_id = %d AND p.user_id = %d AND m.status = 'scheduled' AND DATE_ADD(m.starts_at, INTERVAL m.minutes MINUTE) > %s
				  ORDER BY m.starts_at LIMIT 20",
				Schema::table( 'meetings' ),
				Schema::table( 'meeting_people' ),
				$case_id,
				$user_id,
				$now
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Scheduled meetings starting in a UTC window, with their case, for
	 * reminders and staff summaries.
	 *
	 * @param string   $from    UTC DATETIME, inclusive.
	 * @param string   $to      UTC DATETIME, exclusive.
	 * @param int|null $manager Only this manager's cases; null for all.
	 * @return array<int, array{meeting:MeetingRow, case_title:string, number:string, manager_id:int}>
	 */
	public function starting_between( string $from, string $to, ?int $manager ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . ", c.title AS case_title, c.number, c.manager_id FROM %i m INNER JOIN %i c ON c.id = m.case_id
				  WHERE m.status = 'scheduled' AND m.starts_at >= %s AND m.starts_at < %s AND c.status <> 'closed' AND (%d = 0 OR c.manager_id = %d)
				  ORDER BY m.starts_at LIMIT 500",
				Schema::table( 'meetings' ),
				Schema::table( 'cases' ),
				$from,
				$to,
				(int) $manager,
				(int) $manager
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'meeting'    => self::map( $row ),
				'case_title' => (string) $row['case_title'],
				'number'     => (string) $row['number'],
				'manager_id' => (int) $row['manager_id'],
			),
			(array) $rows
		);
	}

	/**
	 * Who is invited.
	 *
	 * @param int $meeting_id Meeting.
	 * @return int[] User IDs.
	 */
	public function participants( int $meeting_id ): array {
		return array_map(
			'intval',
			(array) $this->db->get_col( $this->db->prepare( 'SELECT user_id FROM %i WHERE meeting_id = %d ORDER BY user_id', Schema::table( 'meeting_people' ), $meeting_id ) )
		);
	}

	/**
	 * Invited people of several meetings at once.
	 *
	 * @param int[] $meeting_ids Meetings.
	 * @return array<int, int[]> Meeting ID => user IDs.
	 */
	public function participants_of( array $meeting_ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $meeting_ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT meeting_id, user_id FROM %i WHERE meeting_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				Schema::table( 'meeting_people' ),
				...$ids
			),
			ARRAY_A
		);
		$map  = array_fill_keys( $ids, array() );

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['meeting_id'] ][] = (int) $row['user_id'];
		}

		return $map;
	}

	/**
	 * Replace who is invited.
	 *
	 * @param int   $meeting_id Meeting.
	 * @param int[] $user_ids   People.
	 * @return bool
	 */
	public function set_participants( int $meeting_id, array $user_ids ): bool {
		$table = Schema::table( 'meeting_people' );

		if ( false === $this->db->delete( $table, array( 'meeting_id' => $meeting_id ) ) ) {
			return false;
		}

		foreach ( array_unique( $user_ids ) as $user_id ) {
			if ( false === $this->db->insert(
				$table,
				array(
					'meeting_id' => $meeting_id,
					'user_id'    => $user_id,
				)
			) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Take a person off every meeting (privacy erasure).
	 *
	 * @param int $user_id User.
	 * @return int Invitations removed.
	 */
	public function remove_user( int $user_id ): int {
		return (int) $this->db->delete( Schema::table( 'meeting_people' ), array( 'user_id' => $user_id ) );
	}

		/**
		 * Scheduled meetings of a case that have not ended.
		 *
		 * @param int    $case_id Case.
		 * @param string $now     UTC DATETIME.
		 * @return int
		 */
	public function count_upcoming( int $case_id, string $now ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM %i WHERE case_id = %d AND status = 'scheduled' AND DATE_ADD(starts_at, INTERVAL minutes MINUTE) > %s", Schema::table( 'meetings' ), $case_id, $now )
		);
	}

	/**
	 * Run a prepared statement.
	 *
	 * @param string|null $sql Prepared SQL; null when prepare() refused it.
	 * @return bool
	 */
	private function run( ?string $sql ): bool {
		return null !== $sql && false !== $this->db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller.
	}

		/**
		 * Typed row.
		 *
		 * @param array<string, string|null> $row Row.
		 * @return MeetingRow
		 */
	private static function map( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'public_id'       => (string) $row['public_id'],
			'case_id'         => (int) $row['case_id'],
			'title'           => (string) $row['title'],
			'kind'            => (string) $row['kind'],
			'starts_at'       => (string) $row['starts_at'],
			'minutes'         => (int) $row['minutes'],
			'location'        => (string) $row['location'],
			'note'            => (string) $row['note'],
			'status'          => (string) $row['status'],
			'sequence'        => (int) $row['sequence'],
			'reminded_before' => '1' === (string) $row['reminded_before'],
			'reminded_today'  => '1' === (string) $row['reminded_today'],
			'created_by'      => (int) $row['created_by'],
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}
}
