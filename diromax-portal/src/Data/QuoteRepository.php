<?php
/**
 * Quote comparison tables.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Quote comparisons and their quotes (spec 24). A comparison is a "draft"
 * while the manager builds it, "open" once sent to the client, "chosen" when
 * the client picked a quote, or "withdrawn".
 *
 * @phpstan-type ComparisonRow array{id:int, case_id:int, title:string, category:string, note:string, due_on:string|null, status:string, chosen_offer_id:int|null, chosen_by:int|null, chosen_at:string|null, chosen_note:string, entry_id:int|null}
 * @phpstan-type OfferRow array{id:int, comparison_id:int, case_id:int, supplier:string, amount:int, duration:string, note:string, recommended:bool, document_id:int|null}
 */
final class QuoteRepository {

	/** Comparison columns read back. */
	private const COMPARISON_COLUMNS = 'id, case_id, title, category, note, due_on, status, chosen_offer_id, chosen_by, chosen_at, chosen_note, entry_id';

	/** Quote columns read back. */
	private const OFFER_COLUMNS = 'id, comparison_id, case_id, supplier, amount, duration, note, recommended, document_id';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Start a comparison, as a draft.
	 *
	 * @param int                                                                   $case_id Case.
	 * @param array{title:string, category:string, note:string, due_on:string|null} $fields  Fields.
	 * @param int                                                                   $author  Staff member.
	 * @param string                                                                $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert_comparison( int $case_id, array $fields, int $author, string $now ): int {
		$row = $fields + array(
			'case_id'    => $case_id,
			'status'     => 'draft',
			'created_by' => $author,
			'created_at' => $now,
			'updated_at' => $now,
		);

		return false === $this->db->insert( Schema::table( 'comparisons' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Change a draft's details.
	 *
	 * @param int                                                                   $id     Comparison.
	 * @param array{title:string, category:string, note:string, due_on:string|null} $fields Fields.
	 * @param string                                                                $now    UTC DATETIME.
	 * @return bool False when it is no longer a draft.
	 */
	public function update_draft( int $id, array $fields, string $now ): bool {
		return 1 === (int) $this->db->update(
			Schema::table( 'comparisons' ),
			$fields + array( 'updated_at' => $now ),
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		) || 'draft' === ( $this->find_comparison( $id )['status'] ?? '' );
	}

	/**
	 * Move a comparison between states, atomically.
	 *
	 * @param int      $id   Comparison.
	 * @param string[] $from States it may be in.
	 * @param string   $to   New state.
	 * @param string   $now  UTC DATETIME.
	 * @return bool False when it was in none of them.
	 */
	public function move( int $id, array $from, string $to, string $now ): bool {
		$sql = $this->db->prepare(
			'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status IN (' . implode( ',', array_fill( 0, count( $from ), '%s' ) ) . ')',
			Schema::table( 'comparisons' ),
			$to,
			$now,
			$id,
			...$from
		);

		return null !== $sql && 1 === $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Record the client's choice, only while the comparison is open.
	 *
	 * @param int    $id       Comparison.
	 * @param int    $offer_id Quote.
	 * @param int    $user_id  Client.
	 * @param string $note     Client's note.
	 * @param string $now      UTC DATETIME.
	 * @return bool False when it was no longer open.
	 */
	public function choose( int $id, int $offer_id, int $user_id, string $note, string $now ): bool {
		$sql = $this->db->prepare(
			"UPDATE %i SET status = 'chosen', chosen_offer_id = %d, chosen_by = %d, chosen_at = %s, chosen_note = %s, updated_at = %s WHERE id = %d AND status = 'open'",
			Schema::table( 'comparisons' ),
			$offer_id,
			$user_id,
			$now,
			$note,
			$now,
			$id
		);

		return null !== $sql && 1 === $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Lock a chosen comparison not yet recorded in the ledger, for the rest
	 * of the transaction.
	 *
	 * @param int $id Comparison.
	 * @return bool False when it is not chosen, or already recorded.
	 */
	public function lock_for_entry( int $id ): bool {
		return null !== $this->db->get_var( $this->db->prepare( "SELECT id FROM %i WHERE id = %d AND status = 'chosen' AND entry_id IS NULL FOR UPDATE", Schema::table( 'comparisons' ), $id ) );
	}

	/**
	 * Link the ledger commitment made from the choice.
	 *
	 * @param int    $id       Comparison.
	 * @param int    $entry_id Ledger entry.
	 * @param string $now      UTC DATETIME.
	 */
	public function set_entry( int $id, int $entry_id, string $now ): void {
		$this->db->update(
			Schema::table( 'comparisons' ),
			array(
				'entry_id'   => $entry_id,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * One comparison.
	 *
	 * @param int $id Comparison.
	 * @return ComparisonRow|null
	 */
	public function find_comparison( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COMPARISON_COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'comparisons' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::comparison_row( $row ) : null;
	}

	/**
	 * A case's comparisons in the given states, newest first.
	 *
	 * @param int      $case_id Case.
	 * @param string[] $states  States.
	 * @return ComparisonRow[]
	 */
	public function comparisons_for( int $case_id, array $states ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COMPARISON_COLUMNS . ' FROM %i WHERE case_id = %d AND status IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ') ORDER BY id DESC LIMIT 100',
				Schema::table( 'comparisons' ),
				$case_id,
				...$states
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'comparison_row' ), (array) $rows );
	}

	/**
	 * Comparisons waiting on someone: open (the client) or chosen and not
	 * recorded (the manager).
	 *
	 * @param int $case_id Case.
	 * @return int
	 */
	public function count_waiting( int $case_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE case_id = %d AND ( status = 'open' OR ( status = 'chosen' AND entry_id IS NULL ) )", Schema::table( 'comparisons' ), $case_id ) );
	}

	/**
	 * Add a quote.
	 *
	 * @param int                                                                                $comparison_id Comparison.
	 * @param int                                                                                $case_id       Case.
	 * @param array{supplier:string, amount:int, duration:string, note:string, recommended:bool} $fields        Fields.
	 * @param int|null                                                                           $document_id   Attached quote, if any.
	 * @param string                                                                             $now           UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert_offer( int $comparison_id, int $case_id, array $fields, ?int $document_id, string $now ): int {
		$row = array(
			'comparison_id' => $comparison_id,
			'case_id'       => $case_id,
			'supplier'      => $fields['supplier'],
			'amount'        => $fields['amount'],
			'duration'      => $fields['duration'],
			'note'          => $fields['note'],
			'recommended'   => $fields['recommended'] ? 1 : 0,
			'document_id'   => $document_id,
			'created_at'    => $now,
		);

		return false === $this->db->insert( Schema::table( 'offers' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Delete a quote.
	 *
	 * @param int $id Quote.
	 */
	public function delete_offer( int $id ): void {
		$this->db->delete( Schema::table( 'offers' ), array( 'id' => $id ) );
	}

	/**
	 * One quote.
	 *
	 * @param int $id Quote.
	 * @return OfferRow|null
	 */
	public function find_offer( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::OFFER_COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'offers' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::offer_row( $row ) : null;
	}

	/**
	 * Quotes of some comparisons, in one query, by comparison.
	 *
	 * @param int[] $comparison_ids Comparisons.
	 * @return array<int, OfferRow[]> Comparison ID => quotes, cheapest first.
	 */
	public function offers_of( array $comparison_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $comparison_ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::OFFER_COLUMNS . ' FROM %i WHERE comparison_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY amount, id',
				Schema::table( 'offers' ),
				...$ids
			),
			ARRAY_A
		);
		$by   = array();

		foreach ( (array) $rows as $row ) {
			$offer                           = self::offer_row( $row );
			$by[ $offer['comparison_id'] ][] = $offer;
		}

		return $by;
	}

	/**
	 * Typed comparison.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return ComparisonRow
	 */
	private static function comparison_row( array $row ): array {
		$id = static fn( ?string $value ): ?int => null === $value || '' === $value ? null : (int) $value;

		return array(
			'id'              => (int) $row['id'],
			'case_id'         => (int) $row['case_id'],
			'title'           => (string) $row['title'],
			'category'        => (string) $row['category'],
			'note'            => (string) $row['note'],
			'due_on'          => null === $row['due_on'] || '' === $row['due_on'] ? null : (string) $row['due_on'],
			'status'          => (string) $row['status'],
			'chosen_offer_id' => $id( $row['chosen_offer_id'] ),
			'chosen_by'       => $id( $row['chosen_by'] ),
			'chosen_at'       => null === $row['chosen_at'] ? null : (string) $row['chosen_at'],
			'chosen_note'     => (string) $row['chosen_note'],
			'entry_id'        => $id( $row['entry_id'] ),
		);
	}

	/**
	 * Typed quote.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return OfferRow
	 */
	private static function offer_row( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'comparison_id' => (int) $row['comparison_id'],
			'case_id'       => (int) $row['case_id'],
			'supplier'      => (string) $row['supplier'],
			'amount'        => (int) $row['amount'],
			'duration'      => (string) $row['duration'],
			'note'          => (string) $row['note'],
			'recommended'   => '1' === (string) $row['recommended'],
			'document_id'   => null === $row['document_id'] ? null : (int) $row['document_id'],
		);
	}
}
