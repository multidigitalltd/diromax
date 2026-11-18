<?php
/**
 * Budget table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Planned amount per budget category of a case.
 */
final class BudgetRepository {

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Planned amount per category, in the case's order.
	 *
	 * @param int $case_id Case.
	 * @return array<string, int> Category key => agorot.
	 */
	public function planned( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT category, planned FROM %i WHERE case_id = %d ORDER BY sort, id', Schema::table( 'budget' ), $case_id ),
			ARRAY_A
		);

		$lines = array();

		foreach ( (array) $rows as $row ) {
			$lines[ (string) $row['category'] ] = (int) $row['planned'];
		}

		return $lines;
	}

	/**
	 * Replace every line of a case in one transaction.
	 *
	 * @param int                $case_id Case.
	 * @param array<string, int> $lines   Category key => agorot; zero lines are dropped.
	 * @return bool
	 */
	public function replace( int $case_id, array $lines ): bool {
		$table = Schema::table( 'budget' );

		return Transaction::run(
			$this->db,
			function () use ( $table, $case_id, $lines ): bool {
				if ( false === $this->db->delete( $table, array( 'case_id' => $case_id ) ) ) {
					return false;
				}

				$sort = 0;

				foreach ( $lines as $category => $planned ) {
					if ( $planned <= 0 ) {
						continue;
					}

					$row = array(
						'case_id'  => $case_id,
						'category' => $category,
						'planned'  => $planned,
						'sort'     => $sort++,
					);

					if ( false === $this->db->insert( $table, $row ) ) {
						return false;
					}
				}

				return true;
			}
		);
	}
}
