<?php
/**
 * Payment plan table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The payments of each case's plan (spec 21). A payment is "planned" until
 * it falls due, then a "draft" with its amount and due date worked out,
 * then "sent" once the manager approved it into a payment request
 * (request_id); or "cancelled".
 *
 * @phpstan-type PlanRow array{id:int, case_id:int, sort:int, label:string, trigger_on:string, stage:int, trigger_date:string|null, kind:string, amount:int, percent:int, pay_to:string, payee:string, days:int, status:string, draft_amount:int, draft_due:string|null, request_id:int|null, triggered_at:string|null}
 */
final class PlanRepository {

	/** Columns read back. */
	private const COLUMNS = 'p.id, p.case_id, p.sort, p.label, p.trigger_on, p.stage, p.trigger_date, p.kind, p.amount, p.percent, p.pay_to, p.payee, p.days, p.status, p.draft_amount, p.draft_due, p.request_id, p.triggered_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * A case's payments, in plan order.
	 *
	 * @param int $case_id Case.
	 * @return PlanRow[]
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i p WHERE p.case_id = %d ORDER BY p.sort, p.id LIMIT 200', Schema::table( 'plan' ), $case_id ),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * One payment.
	 *
	 * @param int $id Payment.
	 * @return PlanRow|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i p WHERE p.id = %d', Schema::table( 'plan' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Add a payment at the end of the plan.
	 *
	 * @param int                                                                                                                                                      $case_id Case.
	 * @param array{label:string, trigger_on:string, stage:int, trigger_date:string|null, kind:string, amount:int, percent:int, pay_to:string, payee:string, days:int} $fields  Fields.
	 * @param string                                                                                                                                                   $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $case_id, array $fields, string $now ): int {
		$sort = (int) $this->db->get_var( $this->db->prepare( 'SELECT COALESCE(MAX(sort), -1) + 1 FROM %i WHERE case_id = %d', Schema::table( 'plan' ), $case_id ) );
		$row  = $fields + array(
			'case_id'    => $case_id,
			'sort'       => $sort,
			'status'     => 'planned',
			'created_at' => $now,
			'updated_at' => $now,
		);

		return false === $this->db->insert( Schema::table( 'plan' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Change a payment's terms.
	 *
	 * @param int                                                                                                                                                      $id     Payment.
	 * @param array{label:string, trigger_on:string, stage:int, trigger_date:string|null, kind:string, amount:int, percent:int, pay_to:string, payee:string, days:int} $fields Fields.
	 * @param string                                                                                                                                                   $now    UTC DATETIME.
	 * @return bool
	 */
	public function update( int $id, array $fields, string $now ): bool {
		// Only while planned: a draft made meanwhile keeps the terms it was worked out from.
		return false !== $this->db->update(
			Schema::table( 'plan' ),
			$fields + array( 'updated_at' => $now ),
			array(
				'id'     => $id,
				'status' => 'planned',
			)
		);
	}

	/**
	 * Turn a planned payment into a draft request.
	 *
	 * @param int    $id     Payment.
	 * @param int    $amount Agorot.
	 * @param string $due    Y-m-d.
	 * @param string $now    UTC DATETIME.
	 * @return bool False when it was no longer planned (already drafted elsewhere).
	 */
	public function to_draft( int $id, int $amount, string $due, string $now ): bool {
		$sql = $this->db->prepare(
			"UPDATE %i SET status = 'draft', draft_amount = %d, draft_due = %s, triggered_at = %s, updated_at = %s WHERE id = %d AND status = 'planned'",
			Schema::table( 'plan' ),
			$amount,
			$due,
			$now,
			$now,
			$id
		);

		// One row changed: this worker made the draft; none, another got there first.
		return null !== $sql && 1 === $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Lock a draft for the rest of the transaction (approval).
	 *
	 * @param int $id Payment.
	 * @return bool False when it is no longer a draft.
	 */
	public function lock_draft( int $id ): bool {
		return null !== $this->db->get_var( $this->db->prepare( "SELECT id FROM %i WHERE id = %d AND status = 'draft' FOR UPDATE", Schema::table( 'plan' ), $id ) );
	}

	/**
	 * Record the request a draft became.
	 *
	 * @param int    $id         Payment.
	 * @param int    $request_id Ledger request.
	 * @param string $now        UTC DATETIME.
	 */
	public function to_sent( int $id, int $request_id, string $now ): void {
		$this->db->update(
			Schema::table( 'plan' ),
			array(
				'status'     => 'sent',
				'request_id' => $request_id,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Move a payment from one of the given states to another, atomically:
	 * an approval or a draft made meanwhile is never overwritten.
	 *
	 * @param int      $id   Payment.
	 * @param string[] $from States it may be in.
	 * @param string   $to   New state.
	 * @param string   $now  UTC DATETIME.
	 * @return bool False when it was in none of them.
	 */
	public function move( int $id, array $from, string $to, string $now ): bool {
		$sql = $this->db->prepare(
			'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status IN (' . implode( ',', array_fill( 0, count( $from ), '%s' ) ) . ')',
			Schema::table( 'plan' ),
			$to,
			$now,
			$id,
			...$from
		);

		return null !== $sql && 1 === $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Delete a payment that is in one of the given states.
	 *
	 * @param int      $id     Payment.
	 * @param string[] $states States it may be in.
	 * @return bool False when it was in none of them.
	 */
	public function delete( int $id, array $states ): bool {
		$sql = $this->db->prepare(
			'DELETE FROM %i WHERE id = %d AND status IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')',
			Schema::table( 'plan' ),
			$id,
			...$states
		);

		return null !== $sql && 1 === $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Planned payments that fall due when a stage of a case is done.
	 *
	 * @param int   $case_id Case.
	 * @param int[] $stages  Stages just done.
	 * @return PlanRow[]
	 */
	public function on_stages( int $case_id, array $stages ): array {
		$stages = array_values( array_unique( array_map( 'intval', $stages ) ) );

		if ( array() === $stages ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i p WHERE p.case_id = %d AND p.status = 'planned' AND p.trigger_on = 'stage' AND p.stage IN (" . implode( ',', array_fill( 0, count( $stages ), '%d' ) ) . ') ORDER BY p.sort, p.id',
				Schema::table( 'plan' ),
				$case_id,
				...$stages
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Planned payments whose date has come, in active cases.
	 *
	 * @param string $today Y-m-d.
	 * @return PlanRow[]
	 */
	public function dated_until( string $today ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i p INNER JOIN %i c ON c.id = p.case_id
				  WHERE p.status = 'planned' AND p.trigger_on = 'date' AND p.trigger_date <= %s AND c.status = 'active'
				  ORDER BY p.trigger_date, p.id LIMIT 500",
				Schema::table( 'plan' ),
				Schema::table( 'cases' ),
				$today
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Drafts waiting for a manager, with their case.
	 *
	 * @param int|null $manager Only this manager's cases; null for all.
	 * @return array<int, array{payment:PlanRow, case_title:string, number:string}>
	 */
	public function drafts( ?int $manager ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . ", c.title AS case_title, c.number FROM %i p INNER JOIN %i c ON c.id = p.case_id
				  WHERE p.status = 'draft' AND c.status <> 'closed' AND (%d = 0 OR c.manager_id = %d)
				  ORDER BY p.triggered_at, p.id LIMIT 200",
				Schema::table( 'plan' ),
				Schema::table( 'cases' ),
				(int) $manager,
				(int) $manager
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'payment'    => self::map( $row ),
				'case_title' => (string) $row['case_title'],
				'number'     => (string) $row['number'],
			),
			(array) $rows
		);
	}

	/**
	 * Drafts in one case.
	 *
	 * @param int $case_id Case.
	 * @return int
	 */
	public function count_drafts( int $case_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE case_id = %d AND status = 'draft'", Schema::table( 'plan' ), $case_id ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return PlanRow
	 */
	private static function map( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'case_id'      => (int) $row['case_id'],
			'sort'         => (int) $row['sort'],
			'label'        => (string) $row['label'],
			'trigger_on'   => (string) $row['trigger_on'],
			'stage'        => (int) $row['stage'],
			'trigger_date' => null === $row['trigger_date'] || '' === $row['trigger_date'] ? null : (string) $row['trigger_date'],
			'kind'         => (string) $row['kind'],
			'amount'       => (int) $row['amount'],
			'percent'      => (int) $row['percent'],
			'pay_to'       => (string) $row['pay_to'],
			'payee'        => (string) $row['payee'],
			'days'         => (int) $row['days'],
			'status'       => (string) $row['status'],
			'draft_amount' => (int) $row['draft_amount'],
			'draft_due'    => null === $row['draft_due'] || '' === $row['draft_due'] ? null : (string) $row['draft_due'],
			'request_id'   => null === $row['request_id'] ? null : (int) $row['request_id'],
			'triggered_at' => null === $row['triggered_at'] ? null : (string) $row['triggered_at'],
		);
	}
}
