<?php
/**
 * Case members table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Who belongs to which case.
 */
final class MemberRepository {

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Add a member, or update role and notification flag of an existing one.
	 *
	 * @param int        $case_id Case.
	 * @param int        $user_id User.
	 * @param MemberRole $role    Role.
	 * @param bool       $notify  Receives emails.
	 * @param string     $now     UTC DATETIME.
	 */
	public function upsert( int $case_id, int $user_id, MemberRole $role, bool $notify, string $now ): void {
		Db::write(
			$this->db,
			'INSERT INTO %i (case_id, user_id, role, notify, created_at) VALUES (%d, %d, %s, %d, %s) ON DUPLICATE KEY UPDATE role = VALUES(role), notify = VALUES(notify)',
			Schema::table( 'case_members' ),
			$case_id,
			$user_id,
			$role->value,
			$notify ? 1 : 0,
			$now
		);
	}

	/**
	 * Remove a member.
	 *
	 * @param int $case_id Case.
	 * @param int $user_id User.
	 */
	public function remove( int $case_id, int $user_id ): void {
		$this->db->delete(
			Schema::table( 'case_members' ),
			array(
				'case_id' => $case_id,
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Is the user a member of the case?
	 *
	 * @param int $case_id Case.
	 * @param int $user_id User.
	 * @return bool
	 */
	public function is_member( int $case_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$found = $this->db->get_var(
			$this->db->prepare( 'SELECT 1 FROM %i WHERE case_id = %d AND user_id = %d', Schema::table( 'case_members' ), $case_id, $user_id )
		);

		return null !== $found;
	}

	/**
	 * Members of a case with their account details.
	 *
	 * @param int  $case_id     Case.
	 * @param bool $notify_only Only members who receive emails.
	 * @return MemberRecord[]
	 */
	public function for_case( int $case_id, bool $notify_only = false ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT m.case_id, m.user_id, m.role, m.notify, m.invited_at, m.last_seen_at, u.display_name, u.user_email
				   FROM %i m INNER JOIN %i u ON u.ID = m.user_id
				  WHERE m.case_id = %d AND m.notify >= %d
				  ORDER BY m.created_at',
				Schema::table( 'case_members' ),
				$this->db->users,
				$case_id,
				$notify_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( MemberRecord::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Number of cases the user belongs to.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public function case_count( int $user_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', Schema::table( 'case_members' ), $user_id )
		);
	}

	/**
	 * Record that an invitation was sent.
	 *
	 * @param int    $case_id Case.
	 * @param int    $user_id User.
	 * @param string $now     UTC DATETIME.
	 */
	public function mark_invited( int $case_id, int $user_id, string $now ): void {
		$this->set( $case_id, $user_id, 'invited_at', $now );
	}

	/**
	 * Record that the member viewed the case.
	 *
	 * @param int    $case_id Case.
	 * @param int    $user_id User.
	 * @param string $now     UTC DATETIME.
	 */
	public function mark_seen( int $case_id, int $user_id, string $now ): void {
		$this->set( $case_id, $user_id, 'last_seen_at', $now );
	}

	/**
	 * Every membership of a user (privacy export).
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, string|null>>
	 */
	public function memberships( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT c.number, c.title, m.role, m.notify, m.invited_at, m.last_seen_at FROM %i m INNER JOIN %i c ON c.id = m.case_id WHERE m.user_id = %d',
				Schema::table( 'case_members' ),
				Schema::table( 'cases' ),
				$user_id
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Remove a user from every case (privacy erasure).
	 *
	 * @param int $user_id User.
	 * @return int Rows removed.
	 */
	public function remove_user( int $user_id ): int {
		return (int) $this->db->delete( Schema::table( 'case_members' ), array( 'user_id' => $user_id ) );
	}

	/**
	 * Set one timestamp column.
	 *
	 * @param int    $case_id Case.
	 * @param int    $user_id User.
	 * @param string $column  invited_at or last_seen_at.
	 * @param string $value   UTC DATETIME.
	 */
	private function set( int $case_id, int $user_id, string $column, string $value ): void {
		$this->db->update(
			Schema::table( 'case_members' ),
			array( $column => $value ),
			array(
				'case_id' => $case_id,
				'user_id' => $user_id,
			)
		);
	}
}
