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
	 * @param LedgerEntry[] $entries Entries of one case. Voided entries are ignored.
	 * @param BudgetTree    $budget  Sections and sub-sections with their planned amounts.
	 * @param string        $today   Today, Y-m-d, for overdue requests.
	 * @return CaseSummary
	 */
	public static function calculate( array $entries, BudgetTree $budget, string $today ): CaseSummary {
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
			budget: $budget->total(),
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
			categories: self::roll_up( $categories ),
		);
	}

	/**
	 * Budget lines with zeroed actuals, in the tree's order.
	 *
	 * @param BudgetTree $budget Budget.
	 * @return array<string, array{planned:int, paid:int, committed:int, parent:string}>
	 */
	private static function category_lines( BudgetTree $budget ): array {
		$lines = array();

		foreach ( $budget->lines() as $line ) {
			$lines[ $line['key'] ] = array(
				'planned'   => $line['planned'],
				'paid'      => 0,
				'committed' => 0,
				'parent'    => $line['parent'],
			);
		}

		return $lines;
	}

	/**
	 * Add each sub-section's numbers to its section, so a section shows its
	 * whole total. Sub-sections keep their own.
	 *
	 * @param array<string, array{planned:int, paid:int, committed:int, parent:string}> $lines Lines with own amounts.
	 * @return array<string, array{planned:int, paid:int, committed:int, parent:string}>
	 */
	private static function roll_up( array $lines ): array {
		foreach ( $lines as $line ) {
			$parent = $line['parent'];

			if ( '' === $parent || ! isset( $lines[ $parent ] ) ) {
				continue;
			}

			$lines[ $parent ]['planned']   += $line['planned'];
			$lines[ $parent ]['paid']      += $line['paid'];
			$lines[ $parent ]['committed'] += $line['committed'];
		}

		return $lines;
	}

	/**
	 * Add one expense to the running totals and its budget line.
	 *
	 * @param LedgerEntry                                                               $entry      The expense.
	 * @param array<string, int>                                                        $totals     Running totals, by reference.
	 * @param array<string, array{planned:int, paid:int, committed:int, parent:string}> $categories Budget lines, by reference.
	 */
	private static function add_expense( LedgerEntry $entry, array &$totals, array &$categories ): void {
		$key = '' === $entry->category ? 'other' : $entry->category;

		if ( ! isset( $categories[ $key ] ) ) {
			// A key that is not in the budget (an old entry): a section of its own.
			$categories[ $key ] = array(
				'planned'   => 0,
				'paid'      => 0,
				'committed' => 0,
				'parent'    => '',
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
