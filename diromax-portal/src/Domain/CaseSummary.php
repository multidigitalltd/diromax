<?php
/**
 * Financial summary of a case.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The numbers the client and the manager see at the top of a case.
 *
 * All amounts in agorot, all shares in basis points. Produced only by
 * SummaryCalculator; serialisable to a plain array for the summary cache.
 */
final readonly class CaseSummary {

	/**
	 * Constructor.
	 *
	 * @param int                                                                       $budget              Approved budget.
	 * @param int                                                                       $cost_to_date        Paid expenses, every funding source.
	 * @param int                                                                       $committed           Committed, unpaid expenses.
	 * @param int                                                                       $payments            Payments received into the project account.
	 * @param int                                                                       $refunds             Refunds paid back to the client.
	 * @param int                                                                       $project_balance     Payments − refunds − expenses paid from the project account.
	 * @param bool                                                                      $has_project_account Any movement through the project account at all.
	 * @param int                                                                       $deferred            Paid expenses to be settled from the proceeds.
	 * @param int                                                                       $partner_funded      Paid expenses carried by a partner.
	 * @param int                                                                       $open_requests       Total of open payment requests.
	 * @param int                                                                       $open_request_count  Number of open payment requests.
	 * @param int                                                                       $overdue_count       Open requests past their due date.
	 * @param int|null                                                                  $next_request_id     Open request with the nearest due date.
	 * @param array<string, array{planned:int, paid:int, committed:int, parent:string}> $categories Budget against actuals per line, in the tree's order; a section's numbers include its sub-sections.
	 */
	public function __construct(
		public int $budget,
		public int $cost_to_date,
		public int $committed,
		public int $payments,
		public int $refunds,
		public int $project_balance,
		public bool $has_project_account,
		public int $deferred,
		public int $partner_funded,
		public int $open_requests,
		public int $open_request_count,
		public int $overdue_count,
		public ?int $next_request_id,
		public array $categories,
	) {}

	/**
	 * Cost to date plus open commitments.
	 *
	 * @return int
	 */
	public function forecast(): int {
		return $this->cost_to_date + $this->committed;
	}

	/**
	 * Share of the budget already paid, in basis points.
	 *
	 * @return int
	 */
	public function utilization_bps(): int {
		return Money::share_bps( $this->cost_to_date, $this->budget );
	}

	/**
	 * Share of the budget paid or committed, in basis points.
	 *
	 * @return int
	 */
	public function forecast_bps(): int {
		return Money::share_bps( $this->forecast(), $this->budget );
	}

	/**
	 * Plain array for the summary cache column.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}
}
