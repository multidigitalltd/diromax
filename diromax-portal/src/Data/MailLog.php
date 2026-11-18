<?php
/**
 * Mail log table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * One row per email per recipient: what was sent to whom and whether it went out.
 * The message body is not stored.
 */
final class MailLog {

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Record a send attempt.
	 *
	 * @param array{case_id:int|null, user_id:int|null, recipient:string, template:string, subject:string, batch_key:string, status:string, error:string} $row Fields.
	 * @param string                                                                                                                                      $now UTC DATETIME.
	 */
	public function add( array $row, string $now ): void {
		$row['subject']    = mb_substr( $row['subject'], 0, 255 );
		$row['error']      = mb_substr( $row['error'], 0, 255 );
		$row['created_at'] = $now;

		$this->db->insert( Schema::table( 'mail_log' ), $row );
	}

	/**
	 * Was this batch already delivered to this user (so a retry skips them)?
	 *
	 * @param string $batch_key Batch key.
	 * @param int    $user_id   User.
	 * @return bool
	 */
	public function delivered( string $batch_key, int $user_id ): bool {
		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT 1 FROM %i WHERE batch_key = %s AND user_id = %d AND status = 'sent' LIMIT 1",
				Schema::table( 'mail_log' ),
				$batch_key,
				$user_id
			)
		);

		return null !== $found;
	}

	/**
	 * Recent emails of a case, newest first.
	 *
	 * @param int $case_id Case.
	 * @param int $limit   Maximum rows, 1–500.
	 * @return array<int, array{recipient:string, template:string, subject:string, status:string, error:string, created_at:string}>
	 */
	public function for_case( int $case_id, int $limit = 100 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT recipient, template, subject, status, error, created_at FROM %i WHERE case_id = %d ORDER BY id DESC LIMIT %d',
				Schema::table( 'mail_log' ),
				$case_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'recipient'  => (string) $row['recipient'],
				'template'   => (string) $row['template'],
				'subject'    => (string) $row['subject'],
				'status'     => (string) $row['status'],
				'error'      => (string) $row['error'],
				'created_at' => (string) $row['created_at'],
			),
			(array) $rows
		);
	}

	/**
	 * Rows sent to a user (privacy export).
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, string|null>>
	 */
	public function for_user( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT recipient, subject, status, created_at FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 1000', Schema::table( 'mail_log' ), $user_id ),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Replace a user's address with a placeholder (privacy erasure).
	 *
	 * @param int $user_id User.
	 * @return int Rows changed.
	 */
	public function anonymize_user( int $user_id ): int {
		return (int) $this->db->update(
			Schema::table( 'mail_log' ),
			array(
				'recipient' => 'deleted@example.invalid',
				'user_id'   => null,
			),
			array( 'user_id' => $user_id )
		);
	}

	/**
	 * Delete rows older than the cut-off (24-month retention).
	 *
	 * @param string $before UTC DATETIME.
	 * @return int Rows deleted.
	 */
	public function purge( string $before ): int {
		return max( 0, Db::write( $this->db, 'DELETE FROM %i WHERE created_at < %s LIMIT 5000', Schema::table( 'mail_log' ), $before ) );
	}
}
