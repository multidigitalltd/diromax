<?php
/**
 * Ledger table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\EntryDraft;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Ledger rows. Rows are never deleted: they are voided (spec section 3).
 */
final class LedgerRepository {

	/** Columns returned by every read. */
	private const COLUMNS = 'id, case_id, type, status, entry_date, due_date, category, payee, description, net, vat, total, vat_rate, funding, pay_to, method, reference, payment_details, internal_note, visible, request_id, document_id, created_by, created_at, updated_at, voided_at, void_reason, reminders_sent, overdue_reminders, last_reminder_on';

	/** COLUMNS with the l. alias, for joins. */
	private const LEDGER_COLUMNS = 'l.id, l.case_id, l.type, l.status, l.entry_date, l.due_date, l.category, l.payee, l.description, l.net, l.vat, l.total, l.vat_rate, l.funding, l.pay_to, l.method, l.reference, l.payment_details, l.internal_note, l.visible, l.request_id, l.document_id, l.created_by, l.created_at, l.updated_at, l.voided_at, l.void_reason, l.reminders_sent, l.overdue_reminders, l.last_reminder_on';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Insert an entry.
	 *
	 * @param int        $case_id     Case.
	 * @param EntryDraft $draft       Validated entry.
	 * @param int        $user_id     Author.
	 * @param string     $now         UTC DATETIME.
	 * @param int|null   $request_id  Request this entry settles.
	 * @param int|null   $document_id Attached document.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $case_id, EntryDraft $draft, int $user_id, string $now, ?int $request_id = null, ?int $document_id = null ): int {
		$data = array_merge(
			self::draft_columns( $draft ),
			array(
				'case_id'     => $case_id,
				'type'        => $draft->type->value,
				'request_id'  => $request_id,
				'document_id' => $document_id,
				'created_by'  => $user_id,
				'created_at'  => $now,
				'updated_by'  => $user_id,
				'updated_at'  => $now,
			)
		);

		return false === $this->db->insert( Schema::table( 'ledger' ), $data ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Update an entry's editable fields. The type never changes.
	 *
	 * @param int        $id      Entry.
	 * @param EntryDraft $draft   Validated entry.
	 * @param int        $user_id Editor.
	 * @param string     $now     UTC DATETIME.
	 * @return bool
	 */
	public function update( int $id, EntryDraft $draft, int $user_id, string $now ): bool {
		$data               = self::draft_columns( $draft );
		$data['updated_by'] = $user_id;
		$data['updated_at'] = $now;

		return false !== $this->db->update( Schema::table( 'ledger' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Attach a document.
	 *
	 * @param int $id          Entry.
	 * @param int $document_id Document.
	 */
	public function attach_document( int $id, int $document_id ): void {
		$this->db->update( Schema::table( 'ledger' ), array( 'document_id' => $document_id ), array( 'id' => $id ) );
	}

	/**
	 * Void an entry. Only a live entry can be voided.
	 *
	 * @param int    $id      Entry.
	 * @param int    $user_id Who voided it.
	 * @param string $reason  Reason, shown to the client.
	 * @param string $now     UTC DATETIME.
	 * @return bool Whether a live entry was voided.
	 */
	public function void( int $id, int $user_id, string $reason, string $now ): bool {
		// The IS NULL condition makes a second void a no-op.
		return 1 === Db::write(
			$this->db,
			'UPDATE %i SET voided_at = %s, voided_by = %d, void_reason = %s, updated_at = %s, updated_by = %d WHERE id = %d AND voided_at IS NULL',
			Schema::table( 'ledger' ),
			$now,
			$user_id,
			$reason,
			$now,
			$user_id,
			$id
		);
	}

	/**
	 * Mark an open request as paid. Atomic: a second call changes nothing.
	 *
	 * @param int    $id      Request.
	 * @param int    $user_id Who settled it.
	 * @param string $now     UTC DATETIME.
	 * @return bool Whether the request was open and is now paid.
	 */
	public function settle_request( int $id, int $user_id, string $now ): bool {
		// The status condition guards against settling twice.
		return 1 === Db::write(
			$this->db,
			"UPDATE %i SET status = %s, updated_at = %s, updated_by = %d WHERE id = %d AND type = 'request' AND status = %s AND voided_at IS NULL",
			Schema::table( 'ledger' ),
			EntryStatus::Paid->value,
			$now,
			$user_id,
			$id,
			EntryStatus::Open->value
		);
	}

	/**
	 * Reopen a paid request whose settling entry was voided.
	 *
	 * @param int    $id      Request.
	 * @param int    $user_id Who reopened it.
	 * @param string $now     UTC DATETIME.
	 * @return bool
	 */
	public function reopen_request( int $id, int $user_id, string $now ): bool {
		return 1 === Db::write(
			$this->db,
			"UPDATE %i SET status = %s, updated_at = %s, updated_by = %d WHERE id = %d AND type = 'request' AND status = %s",
			Schema::table( 'ledger' ),
			EntryStatus::Open->value,
			$now,
			$user_id,
			$id,
			EntryStatus::Paid->value
		);
	}

	/**
	 * Entry by ID.
	 *
	 * @param int $id Entry.
	 * @return LedgerRow|null
	 */
	public function find( int $id ): ?LedgerRow {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'ledger' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? LedgerRow::from_row( $row ) : null;
	}

	/**
	 * Every entry of a case, newest first.
	 *
	 * @param int  $case_id      Case.
	 * @param bool $visible_only Only entries shown to the client.
	 * @return LedgerRow[]
	 */
	public function for_case( int $case_id, bool $visible_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE case_id = %d AND visible >= %d ORDER BY entry_date DESC, id DESC LIMIT 2000',
				Schema::table( 'ledger' ),
				$case_id,
				$visible_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( LedgerRow::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Open, client-visible payment requests with a due date, in active cases:
	 * the candidates for an automatic reminder.
	 *
	 * @return LedgerRow[]
	 */
	public function reminder_candidates(): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::LEDGER_COLUMNS . " FROM %i l INNER JOIN %i c ON c.id = l.case_id
				  WHERE l.type = 'request' AND l.status = %s AND l.voided_at IS NULL AND l.visible = 1
				    AND l.due_date IS NOT NULL AND c.status = 'active'
				  ORDER BY l.due_date LIMIT 1000",
				Schema::table( 'ledger' ),
				Schema::table( 'cases' ),
				EntryStatus::Open->value
			),
			ARRAY_A
		);

		return array_map( array( LedgerRow::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Record a reminder, at most one per request per day.
	 *
	 * The WHERE clause makes the check and the write one atomic step, so two
	 * overlapping workers cannot both send.
	 *
	 * @param int    $id      Request.
	 * @param string $today   Local Y-m-d.
	 * @param bool   $overdue Count it towards the overdue maximum.
	 * @return bool False when the request already had a reminder today or is no longer open.
	 */
	public function mark_reminded( int $id, string $today, bool $overdue ): bool {
		return 1 === Db::write(
			$this->db,
			"UPDATE %i SET reminders_sent = reminders_sent + 1, overdue_reminders = overdue_reminders + %d, last_reminder_on = %s
			  WHERE id = %d AND type = 'request' AND status = %s AND voided_at IS NULL AND ( last_reminder_on IS NULL OR last_reminder_on <> %s )",
			Schema::table( 'ledger' ),
			$overdue ? 1 : 0,
			$today,
			$id,
			EntryStatus::Open->value,
			$today
		);
	}

	/**
	 * Payee names used before, for autocomplete.
	 *
	 * @return string[]
	 */
	public function payees(): array {
		$rows = $this->db->get_col(
			$this->db->prepare( "SELECT DISTINCT payee FROM %i WHERE payee <> '' AND voided_at IS NULL ORDER BY payee LIMIT 300", Schema::table( 'ledger' ) )
		);

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Entries per budget key in a case, voided ones included: they still
	 * show in the ledger with their line.
	 *
	 * @param int $case_id Case.
	 * @return array<string, int>
	 */
	public function count_by_category( int $case_id ): array {
		$rows   = $this->db->get_results(
			$this->db->prepare( "SELECT category, COUNT(*) AS n FROM %i WHERE case_id = %d AND category <> '' GROUP BY category", Schema::table( 'ledger' ), $case_id ),
			ARRAY_A
		);
		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['category'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Entries of a case with no budget key (payments, refunds, most requests).
	 *
	 * @param int $case_id Case.
	 * @return int
	 */
	public function count_without_category( int $case_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM %i WHERE case_id = %d AND category = ''", Schema::table( 'ledger' ), $case_id )
		);
	}

	/**
	 * Move a case's entries from one budget key to another (a sub-section
	 * that was removed goes to its section).
	 *
	 * @param int    $case_id Case.
	 * @param string $from    Old key.
	 * @param string $to      New key.
	 * @return bool False when the update failed.
	 */
	public function recategorize( int $case_id, string $from, string $to ): bool {
		return false !== $this->db->update(
			Schema::table( 'ledger' ),
			array( 'category' => $to ),
			array(
				'case_id'  => $case_id,
				'category' => $from,
			)
		);
	}

	/**
	 * Columns shared by insert and update.
	 *
	 * @param EntryDraft $draft Draft.
	 * @return array<string, string|int|null>
	 */
	private static function draft_columns( EntryDraft $draft ): array {
		return array(
			'status'          => $draft->status->value,
			'entry_date'      => $draft->entry_date,
			'due_date'        => $draft->due_date,
			'category'        => $draft->category,
			'payee'           => $draft->payee,
			'description'     => $draft->description,
			'net'             => $draft->net,
			'vat'             => $draft->vat,
			'total'           => $draft->total,
			'vat_rate'        => $draft->vat_rate,
			'funding'         => $draft->funding->value ?? '',
			'pay_to'          => $draft->pay_to->value ?? '',
			'method'          => $draft->method,
			'reference'       => $draft->reference,
			'payment_details' => $draft->payment_details,
			'internal_note'   => $draft->internal_note,
			'visible'         => $draft->visible ? 1 : 0,
		);
	}
}
