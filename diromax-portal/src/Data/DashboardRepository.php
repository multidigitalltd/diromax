<?php
/**
 * Queries behind the manager dashboard.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * One query per dashboard card, across all cases at once (spec 15.8).
 *
 * Every query takes the manager ID and keeps only that manager's cases; 0
 * means every case (site administrator). The filter is part of each fixed
 * statement ("%d = 0 OR c.manager_id = %d"), so no SQL is assembled at run
 * time.
 */
final class DashboardRepository {

	/** Rows per card at most. */
	private const LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Open, visible payment requests in active cases due inside a date range.
	 *
	 * @param int    $manager Manager, 0 for all.
	 * @param string $from    First due date, inclusive (Y-m-d).
	 * @param string $to      Last due date, inclusive (Y-m-d).
	 * @return array<int, array<string, string|null>>
	 */
	public function requests_due( int $manager, string $from, string $to ): array {
		return $this->rows(
			$this->db->prepare(
				"SELECT c.id AS case_id, c.number, c.title AS case_title, l.id, l.description, l.total, l.due_date, l.reminders_sent, l.last_reminder_on
				   FROM %i l INNER JOIN %i c ON c.id = l.case_id
				  WHERE l.type = 'request' AND l.status = 'open' AND l.voided_at IS NULL AND l.visible = 1
				    AND l.due_date BETWEEN %s AND %s AND c.status = 'active' AND ( %d = 0 OR c.manager_id = %d )
				  ORDER BY l.due_date, l.id LIMIT %d",
				Schema::table( 'ledger' ),
				Schema::table( 'cases' ),
				$from,
				$to,
				$manager,
				$manager,
				self::LIMIT
			)
		);
	}

	/**
	 * Every open payment request the clients see, in active cases, for the
	 * payments forecast (spec 20.2).
	 *
	 * @param int $batch Rows read per query.
	 * @return array<int, array{id:int, case_id:int, number:string, case_title:string, description:string, total:int, due_date:string|null, to_diromax:bool, payee:string}>
	 */
	public function open_requests( int $batch = 1000 ): array {
		$batch  = max( 1, $batch );
		$rows   = array();
		$offset = 0;

		// Read in pages so no request is ever left out of the totals (PR #16 review).
		do {
			$page    = $this->rows(
				$this->db->prepare(
					"SELECT l.id, c.id AS case_id, c.number, c.title AS case_title, l.description, l.total, l.due_date, l.pay_to, l.payee
					   FROM %i l INNER JOIN %i c ON c.id = l.case_id
					  WHERE l.type = 'request' AND l.status = 'open' AND l.voided_at IS NULL AND l.visible = 1 AND c.status = 'active'
					  ORDER BY l.due_date IS NULL, l.due_date, l.id LIMIT %d OFFSET %d",
					Schema::table( 'ledger' ),
					Schema::table( 'cases' ),
					$batch,
					$offset
				)
			);
			$rows    = array_merge( $rows, $page );
			$offset += $batch;
			$full    = count( $page ) === $batch;
		} while ( $full );

		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'case_id'     => (int) $row['case_id'],
				'number'      => (string) $row['number'],
				'case_title'  => (string) $row['case_title'],
				'description' => (string) $row['description'],
				'total'       => (int) $row['total'],
				'due_date'    => null === $row['due_date'] || '' === $row['due_date'] ? null : (string) $row['due_date'],
				'to_diromax'  => 'diromax' === $row['pay_to'],
				'payee'       => (string) $row['payee'],
			),
			$rows
		);
	}

		/**
		 * Count and sum of the same requests as requests_due(), without the
		 * display limit, for the dashboard totals.
		 *
		 * @param int    $manager Manager, 0 for all.
		 * @param string $from    First due date, inclusive (Y-m-d).
		 * @param string $to      Last due date, inclusive (Y-m-d).
		 * @return array{count:int, total:int}
		 */
	public function requests_due_total( int $manager, string $from, string $to ): array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT COUNT(*) AS n, COALESCE(SUM(l.total), 0) AS total
				   FROM %i l INNER JOIN %i c ON c.id = l.case_id
				  WHERE l.type = 'request' AND l.status = 'open' AND l.voided_at IS NULL AND l.visible = 1
				    AND l.due_date BETWEEN %s AND %s AND c.status = 'active' AND ( %d = 0 OR c.manager_id = %d )",
				Schema::table( 'ledger' ),
				Schema::table( 'cases' ),
				$from,
				$to,
				$manager,
				$manager
			),
			ARRAY_A
		);

		return array(
			'count' => (int) ( $row['n'] ?? 0 ),
			'total' => (int) ( $row['total'] ?? 0 ),
		);
	}

	/**
	 * Decisions still waiting for the client.
	 *
	 * @param int $manager Manager, 0 for all.
	 * @return array<int, array<string, string|null>>
	 */
	public function pending_decisions( int $manager ): array {
		return $this->rows(
			$this->db->prepare(
				"SELECT c.id AS case_id, c.number, c.title AS case_title, d.id, d.title, d.created_at, d.due_date
				   FROM %i d INNER JOIN %i c ON c.id = d.case_id
				  WHERE d.status = 'pending' AND ( %d = 0 OR c.manager_id = %d )
				  ORDER BY d.created_at LIMIT %d",
				Schema::table( 'decisions' ),
				Schema::table( 'cases' ),
				$manager,
				$manager,
				self::LIMIT
			)
		);
	}

	/**
	 * What clients did since a moment: answered decisions and confirmed agreements.
	 *
	 * @param int    $manager Manager, 0 for all.
	 * @param string $since   UTC DATETIME.
	 * @return array<int, array<string, string|null>> Newest first; kind is 'decision' or 'agreement'.
	 */
	public function client_actions( int $manager, string $since ): array {
		return $this->rows(
			$this->db->prepare(
				"SELECT * FROM (
				   SELECT 'decision' AS kind, c.id AS case_id, c.number, c.title AS case_title, d.title, d.status, d.answered_at AS acted_at, u.display_name
				     FROM %i d INNER JOIN %i c ON c.id = d.case_id LEFT JOIN %i u ON u.ID = d.answered_by
				    WHERE d.answered_at >= %s AND ( %d = 0 OR c.manager_id = %d )
				   UNION ALL
				   SELECT 'agreement' AS kind, c.id AS case_id, c.number, c.title AS case_title, doc.title, '' AS status, a.created_at AS acted_at, u.display_name
				     FROM %i a INNER JOIN %i c ON c.id = a.case_id INNER JOIN %i doc ON doc.id = a.document_id LEFT JOIN %i u ON u.ID = a.user_id
				    WHERE a.created_at >= %s AND ( %d = 0 OR c.manager_id = %d )
				 ) actions ORDER BY acted_at DESC LIMIT %d",
				Schema::table( 'decisions' ),
				Schema::table( 'cases' ),
				$this->db->users,
				$since,
				$manager,
				$manager,
				Schema::table( 'acknowledgements' ),
				Schema::table( 'cases' ),
				Schema::table( 'documents' ),
				$this->db->users,
				$since,
				$manager,
				$manager,
				self::LIMIT
			)
		);
	}

	/**
	 * Active cases opened before the cut-off with no visible update since it.
	 *
	 * @param int    $manager Manager, 0 for all.
	 * @param string $cutoff  UTC DATETIME.
	 * @return array<int, array<string, string|null>> last_update is null when there never was one.
	 */
	public function stale_cases( int $manager, string $cutoff ): array {
		return $this->rows(
			$this->db->prepare(
				"SELECT c.id AS case_id, c.number, c.title AS case_title, MAX(u.created_at) AS last_update
				   FROM %i c LEFT JOIN %i u ON u.case_id = c.id AND u.visible = 1
				  WHERE c.status = 'active' AND c.created_at < %s AND ( %d = 0 OR c.manager_id = %d )
				  GROUP BY c.id, c.number, c.title
				 HAVING last_update IS NULL OR last_update < %s
				  ORDER BY last_update LIMIT %d",
				Schema::table( 'cases' ),
				Schema::table( 'updates' ),
				$cutoff,
				$manager,
				$manager,
				$cutoff,
				self::LIMIT
			)
		);
	}

	/**
	 * Members invited before the cut-off who never opened the portal.
	 *
	 * @param int    $manager Manager, 0 for all.
	 * @param string $cutoff  UTC DATETIME.
	 * @return array<int, array<string, string|null>>
	 */
	public function never_signed_in( int $manager, string $cutoff ): array {
		return $this->rows(
			$this->db->prepare(
				"SELECT c.id AS case_id, c.number, c.title AS case_title, u.display_name, m.invited_at
				   FROM %i m INNER JOIN %i c ON c.id = m.case_id INNER JOIN %i u ON u.ID = m.user_id
				  WHERE m.invited_at IS NOT NULL AND m.invited_at < %s AND m.last_seen_at IS NULL
				    AND c.status = 'active' AND ( %d = 0 OR c.manager_id = %d )
				  ORDER BY m.invited_at LIMIT %d",
				Schema::table( 'case_members' ),
				Schema::table( 'cases' ),
				$this->db->users,
				$cutoff,
				$manager,
				$manager,
				self::LIMIT
			)
		);
	}

	/**
	 * Notifications that failed for good, per case.
	 *
	 * @param int $manager Manager, 0 for all.
	 * @return array<int, array<string, string|null>>
	 */
	public function failed_mail( int $manager ): array {
		return $this->rows(
			$this->db->prepare(
				"SELECT c.id AS case_id, c.number, c.title AS case_title, COUNT(*) AS failed
				   FROM %i e INNER JOIN %i c ON c.id = e.case_id
				  WHERE e.status = 'failed' AND ( %d = 0 OR c.manager_id = %d )
				  GROUP BY c.id, c.number, c.title LIMIT %d",
				Schema::table( 'events' ),
				Schema::table( 'cases' ),
				$manager,
				$manager,
				self::LIMIT
			)
		);
	}

	/**
	 * Emails the provider reported back (bounced or marked as spam) since a
	 * date, newest first: the address is probably wrong.
	 *
	 * @param int    $manager Manager, 0 for all.
	 * @param string $since   UTC DATETIME.
	 * @return array<int, array<string, string|null>>
	 */
	public function bounced_mail( int $manager, string $since ): array {
		return $this->rows(
			$this->db->prepare(
				"SELECT c.id AS case_id, c.number, c.title AS case_title, m.recipient, m.status, m.created_at
				   FROM %i m INNER JOIN %i c ON c.id = m.case_id
				  WHERE m.status IN ('bounced','complaint') AND m.created_at >= %s AND ( %d = 0 OR c.manager_id = %d )
				  ORDER BY m.id DESC LIMIT %d",
				Schema::table( 'mail_log' ),
				Schema::table( 'cases' ),
				$since,
				$manager,
				$manager,
				self::LIMIT
			)
		);
	}

	/**
	 * Run a prepared query.
	 *
	 * @param string|null $sql Prepared SQL.
	 * @return array<int, array<string, string|null>>
	 */
	private function rows( ?string $sql ): array {
		if ( null === $sql ) {
			return array();
		}

		return (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every caller passes the result of prepare().
	}
}
