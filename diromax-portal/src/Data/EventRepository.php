<?php
/**
 * Notification events table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Things that happened in a case and should reach its members by email.
 *
 * Status: pending → sent | cancelled | failed. Recipients are resolved at send
 * time, so a member added in between also gets the batch.
 */
final class EventRepository {

	/** Event kinds. */
	public const KINDS = array( 'entry_created', 'entry_updated', 'entry_voided', 'request_created', 'request_settled', 'update_published', 'document_added', 'request_reminder', 'decision_created' );

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Queue an event.
	 *
	 * @param int    $case_id   Case.
	 * @param string $kind      One of KINDS.
	 * @param int    $object_id Ledger entry, update or document ID.
	 * @param bool   $immediate Send without waiting for the batch window.
	 * @param string $now       UTC DATETIME.
	 * @return int New ID.
	 */
	public function add( int $case_id, string $kind, int $object_id, bool $immediate, string $now ): int {
		$this->db->insert(
			Schema::table( 'events' ),
			array(
				'case_id'    => $case_id,
				'kind'       => $kind,
				'object_id'  => $object_id,
				'immediate'  => $immediate ? 1 : 0,
				'status'     => 'pending',
				'created_at' => $now,
			)
		);

		return (int) $this->db->insert_id;
	}

	/**
	 * Pending events of one case, oldest first.
	 *
	 * @param int $case_id Case.
	 * @return array<int, array{id:int, case_id:int, kind:string, object_id:int, immediate:bool, attempts:int, next_attempt_at:string|null, created_at:string}>
	 */
	public function pending_for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, case_id, kind, object_id, immediate, attempts, next_attempt_at, created_at FROM %i WHERE status = 'pending' AND case_id = %d ORDER BY id LIMIT 200",
				Schema::table( 'events' ),
				$case_id
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * A case's batched events in a time window, for a daily digest.
	 *
	 * Immediate events are left out: digest members received them at once.
	 * Cancelled ones are left out: the manager withdrew them.
	 *
	 * @param int    $case_id Case.
	 * @param string $after   UTC DATETIME, exclusive.
	 * @param string $until   UTC DATETIME, inclusive.
	 * @return array<int, array{id:int, case_id:int, kind:string, object_id:int, immediate:bool, attempts:int, next_attempt_at:string|null, created_at:string}>
	 */
	public function for_digest( int $case_id, string $after, string $until ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, case_id, kind, object_id, immediate, attempts, next_attempt_at, created_at FROM %i
				  WHERE case_id = %d AND immediate = 0 AND status <> 'cancelled' AND created_at > %s AND created_at <= %s
				  ORDER BY id LIMIT 200",
				Schema::table( 'events' ),
				$case_id,
				$after,
				$until
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Cases with pending events.
	 *
	 * @return int[]
	 */
	public function cases_with_pending(): array {
		$ids = $this->db->get_col(
			$this->db->prepare( "SELECT DISTINCT case_id FROM %i WHERE status = 'pending' LIMIT 500", Schema::table( 'events' ) )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Set the status of several events.
	 *
	 * @param int[]       $ids    Event IDs.
	 * @param string      $status sent, cancelled or failed.
	 * @param string|null $now    UTC DATETIME for sent_at.
	 */
	public function set_status( array $ids, string $status, ?string $now = null ): void {
		foreach ( $ids as $id ) {
			$this->db->update(
				Schema::table( 'events' ),
				array(
					'status'  => $status,
					'sent_at' => 'sent' === $status ? $now : null,
				),
				array(
					'id'     => (int) $id,
					'status' => 'pending',
				)
			);
		}
	}

	/**
	 * Record a failed attempt and when to retry.
	 *
	 * @param int[]  $ids      Event IDs.
	 * @param int    $attempts Attempts made so far.
	 * @param string $retry_at UTC DATETIME of the next attempt.
	 */
	public function schedule_retry( array $ids, int $attempts, string $retry_at ): void {
		foreach ( $ids as $id ) {
			$this->db->update(
				Schema::table( 'events' ),
				array(
					'attempts'        => $attempts,
					'next_attempt_at' => $retry_at,
				),
				array( 'id' => (int) $id )
			);
		}
	}

	/**
	 * Number of events that failed for good and nobody dismissed.
	 *
	 * @return int
	 */
	public function failed_count(): int {
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed'", Schema::table( 'events' ) )
		);
	}

	/**
	 * Delete finished events older than the cut-off (retention).
	 *
	 * @param string $before UTC DATETIME.
	 * @return int Rows deleted.
	 */
	public function purge( string $before ): int {
		return max( 0, Db::write( $this->db, "DELETE FROM %i WHERE status <> 'pending' AND created_at < %s LIMIT 5000", Schema::table( 'events' ), $before ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return array{id:int, case_id:int, kind:string, object_id:int, immediate:bool, attempts:int, next_attempt_at:string|null, created_at:string}
	 */
	private static function map( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'case_id'         => (int) $row['case_id'],
			'kind'            => (string) $row['kind'],
			'object_id'       => (int) $row['object_id'],
			'immediate'       => '1' === (string) $row['immediate'],
			'attempts'        => (int) $row['attempts'],
			'next_attempt_at' => $row['next_attempt_at'] ?? null,
			'created_at'      => (string) $row['created_at'],
		);
	}
}
