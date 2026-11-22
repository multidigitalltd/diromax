<?php
/**
 * Budget table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\BudgetTree;
use Diromax\Portal\Support\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * The budget tree of each case (spec 19.2): one row per section and per
 * sub-section. "category" is the line's key, unique in the case; "parent"
 * is its section's key, '' for a section. Ledger entries point at a key.
 */
final class BudgetRepository {

	/**
	 * Trees read in this request, by case.
	 *
	 * @var array<int, BudgetTree>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * A case's budget.
	 *
	 * @param int $case_id Case.
	 * @return BudgetTree Empty when the case has no lines.
	 */
	public function tree( int $case_id ): BudgetTree {
		if ( isset( $this->cache[ $case_id ] ) ) {
			return $this->cache[ $case_id ];
		}

		$rows  = $this->db->get_results(
			$this->db->prepare( 'SELECT category, parent, label, planned FROM %i WHERE case_id = %d ORDER BY sort, id LIMIT 2000', Schema::table( 'budget' ), $case_id ),
			ARRAY_A
		);
		$lines = array();

		foreach ( (array) $rows as $row ) {
			$lines[] = array(
				'key'     => (string) $row['category'],
				'parent'  => (string) $row['parent'],
				'label'   => '' !== (string) $row['label'] ? (string) $row['label'] : (string) $row['category'],
				'planned' => (int) $row['planned'],
			);
		}

		$this->cache[ $case_id ] = new BudgetTree( $lines );

		return $this->cache[ $case_id ];
	}

	/**
	 * Planned amount per line, not rolled up.
	 *
	 * @param int $case_id Case.
	 * @return array<string, int> Key => agorot.
	 */
	public function planned( int $case_id ): array {
		return $this->tree( $case_id )->planned();
	}

	/**
	 * Replace a case's budget in one transaction.
	 *
	 * @param int        $case_id Case.
	 * @param BudgetTree $tree    New budget.
	 * @return bool
	 */
	public function save( int $case_id, BudgetTree $tree ): bool {
		$table = Schema::table( 'budget' );
		unset( $this->cache[ $case_id ] );

		return Transaction::run(
			$this->db,
			function () use ( $table, $case_id, $tree ): bool {
				if ( false === $this->db->delete( $table, array( 'case_id' => $case_id ) ) ) {
					return false;
				}

				foreach ( $tree->lines() as $sort => $line ) {
					$row = array(
						'case_id'  => $case_id,
						'category' => $line['key'],
						'parent'   => $line['parent'],
						'label'    => $line['label'],
						'planned'  => $line['planned'],
						'sort'     => $sort,
					);

					// Explicit formats: WordPress maps a column named "parent" to %d by default.
					if ( false === $this->db->insert( $table, $row, array( '%d', '%s', '%s', '%s', '%d', '%d' ) ) ) {
						return false;
					}
				}

				return true;
			}
		);
	}

	/**
	 * Give a case the default budget, when it has no lines yet.
	 *
	 * @param int        $case_id  Case.
	 * @param BudgetTree $template Default budget.
	 * @return bool False when the case already had lines or saving failed.
	 */
	public function seed( int $case_id, BudgetTree $template ): bool {
		return $this->tree( $case_id )->is_empty() && ! $template->is_empty() && $this->save( $case_id, $template );
	}

	/**
	 * Upgrade to schema 7: budgets held one row per section with an amount,
	 * and the section names lived in the settings. Every case gets every
	 * section it could use before, named, in the settings' order, plus any
	 * key its entries still point at. Safe to run again.
	 *
	 * @param array<string, string> $sections Section key => label, in order.
	 */
	public function adopt_sections( array $sections ): void {
		$budget = Schema::table( 'budget' );
		$cases  = Schema::table( 'cases' );
		$ledger = Schema::table( 'ledger' );
		$sort   = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-time, set-based upgrade; every value is bound.
		foreach ( $sections as $key => $label ) {
			$this->run(
				$this->db->prepare(
					'INSERT IGNORE INTO %i (case_id, category, parent, label, planned, sort) SELECT id, %s, %s, %s, 0, %d FROM %i',
					$budget,
					$key,
					'',
					$label,
					$sort,
					$cases
				)
			);
			$this->run( $this->db->prepare( "UPDATE %i SET label = %s, sort = %d WHERE category = %s AND parent = '' AND label = ''", $budget, $label, $sort, $key ) );
			++$sort;
		}

		$this->run(
			$this->db->prepare(
				"INSERT IGNORE INTO %i (case_id, category, parent, label, planned, sort) SELECT DISTINCT case_id, category, '', category, 0, %d FROM %i WHERE category <> ''",
				$budget,
				$sort,
				$ledger
			)
		);
		$this->run( $this->db->prepare( "UPDATE %i SET label = category WHERE label = ''", $budget ) );
		// phpcs:enable

		$this->cache = array();
	}

	/**
	 * Run a prepared statement; prepare() gives null for a malformed one.
	 *
	 * @param string|null $sql Prepared SQL.
	 */
	private function run( ?string $sql ): void {
		if ( null !== $sql ) {
			$this->db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller; one-time upgrade.
		}
	}
}
