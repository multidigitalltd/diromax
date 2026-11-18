<?php
/**
 * Case summary calculator.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Turns ledger entries and budget lines into a CaseSummary.
 *
 * Pure: no WordPress, no database, no clock. The same function produces the
 * manager's numbers (all entries) and the client's (visible entries only), so
 * the two can never disagree about the rules — only about the input.
 */
final class SummaryCalculator {

	/**
	 * Calculate a summary.
	 *
	 * @param LedgerEntry[]      $entries Entries of one case. Voided entries are ignored.
	 * @param array<string, int> $budget  Planned amount per category key, in agorot.
	 * @param string             $today   Today, Y-m-d, for overdue requests.
	 * @return CaseSummary
	 */
	public static function calculate( array $entries, array $budget, string $today ): CaseSummary {
		$totals      = array(
			'cost'      => 0,
			'committed' => 0,
			'payments'  => 0,
			'refunds'   => 0,
			'project'   => 0,
			'deferred'  => 0,
			'partner'   => 0,
		);
		$has_project = false;
		$categories  = self::category_lines( $budget );

		foreach ( $entries as $entry ) {
			if ( $entry->voided ) {
				continue;
			}

			switch ( $entry->type ) {
				case EntryType::Expense:
					$has_project = $has_project || Funding::Project === $entry->funding;
					self::add_expense( $entry, $totals, $categories );
					break;
				case EntryType::Payment:
					$has_project         = true;
					$totals['payments'] += $entry->total;
					break;
				case EntryType::Refund:
					$has_project        = true;
					$totals['refunds'] += $entry->total;
					break;
				case EntryType::Request:
					break;
			}
		}

		$requests = self::open_requests( $entries, $today );

		return new CaseSummary(
			budget: array_sum( $budget ),
			cost_to_date: $totals['cost'],
			committed: $totals['committed'],
			payments: $totals['payments'],
			refunds: $totals['refunds'],
			project_balance: $totals['payments'] - $totals['refunds'] - $totals['project'],
			has_project_account: $has_project,
			deferred: $totals['deferred'],
			partner_funded: $totals['partner'],
			open_requests: $requests['total'],
			open_request_count: $requests['count'],
			overdue_count: $requests['overdue'],
			next_request_id: $requests['next'],
			categories: $categories,
		);
	}

	/**
	 * Budget lines with zeroed actuals.
	 *
	 * @param array<string, int> $budget Planned amount per category.
	 * @return array<string, array{planned:int, paid:int, committed:int}>
	 */
	private static function category_lines( array $budget ): array {
		$lines = array();

		foreach ( $budget as $category => $planned ) {
			$lines[ $category ] = array(
				'planned'   => $planned,
				'paid'      => 0,
				'committed' => 0,
			);
		}

		return $lines;
	}

	/**
	 * Add one expense to the running totals and its category line.
	 *
	 * @param LedgerEntry                                                $entry      The expense.
	 * @param array<string, int>                                         $totals     Running totals, by reference.
	 * @param array<string, array{planned:int, paid:int, committed:int}> $categories Category lines, by reference.
	 */
	private static function add_expense( LedgerEntry $entry, array &$totals, array &$categories ): void {
		$key = '' === $entry->category ? 'other' : $entry->category;

		if ( ! isset( $categories[ $key ] ) ) {
			$categories[ $key ] = array(
				'planned'   => 0,
				'paid'      => 0,
				'committed' => 0,
			);
		}

		if ( EntryStatus::Committed === $entry->status ) {
			$totals['committed']             += $entry->total;
			$categories[ $key ]['committed'] += $entry->total;
			return;
		}

		$totals['cost']             += $entry->total;
		$categories[ $key ]['paid'] += $entry->total;

		match ( $entry->funding ) {
			Funding::Project  => $totals['project']  += $entry->total,
			Funding::Proceeds => $totals['deferred'] += $entry->total,
			Funding::Partner  => $totals['partner']  += $entry->total,
			Funding::Client, null => null,
		};
	}

	/**
	 * Open payment requests: total, count, overdue and the nearest due.
	 *
	 * @param LedgerEntry[] $entries Entries.
	 * @param string        $today   Today, Y-m-d.
	 * @return array{total:int, count:int, overdue:int, next:int|null}
	 */
	private static function open_requests( array $entries, string $today ): array {
		$result   = array(
			'total'   => 0,
			'count'   => 0,
			'overdue' => 0,
			'next'    => null,
		);
		$next_due = null;

		foreach ( $entries as $entry ) {
			if ( ! $entry->is_open_request() ) {
				continue;
			}

			$result['total'] += $entry->total;
			++$result['count'];

			if ( $entry->is_overdue( $today ) ) {
				++$result['overdue'];
			}

			$due = $entry->due_date ?? '9999-12-31';

			if ( null === $next_due || $due < $next_due ) {
				$next_due       = $due;
				$result['next'] = $entry->id;
			}
		}

		return $result;
	}
}
