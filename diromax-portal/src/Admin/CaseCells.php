<?php
/**
 * Cells of the cases list.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\StageStatus;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\Stages;

defined( 'ABSPATH' ) || exit;

/**
 * Escaped HTML for the cells of a case row, shared by the wp-admin list
 * table and the management app's list, from the case's cached summary.
 */
final class CaseCells {

	/**
	 * Where the case stands, with a seven-step strip.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	public static function stage( CaseRecord $item ): string {
		return sprintf(
			'<span class="dxa-cell-stack"><span>%1$s</span>%2$s</span>',
			esc_html( Stages::summary( $item->stages ) ),
			self::strip( $item )
		);
	}

	/**
	 * Seven segments, one per stage, coloured by status. Decorative: the
	 * text next to it says the same.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	public static function strip( CaseRecord $item ): string {
		$classes  = array(
			StageStatus::Done->value    => 'is-done',
			StageStatus::Active->value  => 'is-now',
			StageStatus::Skipped->value => 'is-skip',
		);
		$segments = '';

		for ( $step = 1; $step <= Stages::COUNT; $step++ ) {
			$segments .= '<i class="' . esc_attr( $classes[ $item->stages[ $step ]['status'] ?? '' ] ?? '' ) . '"></i>';
		}

		return '<span class="dxa-mini" aria-hidden="true">' . $segments . '</span>';
	}

	/**
	 * Budget use, with a bar.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	public static function budget( CaseRecord $item ): string {
		$budget = (int) ( $item->summary['budget'] ?? 0 );
		$cost   = (int) ( $item->summary['cost_to_date'] ?? 0 );

		if ( $budget <= 0 ) {
			return '<span class="dxa-muted">' . esc_html__( 'אין תקציב', 'diromax-portal' ) . '</span>';
		}

		$share = Money::share_bps( $cost, $budget );

		return sprintf(
			'<span class="dxa-cell-stack"><span><strong>%2$s</strong> <span class="dxa-muted">· %3$s</span></span><span class="dxa-bar" aria-hidden="true"><i style="width:%1$s%%"></i></span></span>',
			esc_attr( (string) min( 100, intdiv( $share, 100 ) ) ),
			esc_html( Money::percent( $share ) ),
			esc_html( Money::format( $cost ) )
		);
	}

	/**
	 * Open requests, with the overdue count.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	public static function requests( CaseRecord $item ): string {
		$open    = (int) ( $item->summary['open_requests'] ?? 0 );
		$overdue = (int) ( $item->summary['overdue_count'] ?? 0 );

		if ( 0 === $open ) {
			return '—';
		}

		$late = $overdue > 0
			/* translators: %d: overdue requests. */
			? ' <span class="dxa-badge dxa-badge--late">' . esc_html( sprintf( _n( '%d באיחור', '%d באיחור', $overdue, 'diromax-portal' ), $overdue ) ) . '</span>'
			: '';

		return esc_html( Money::format( $open ) ) . $late;
	}

	/**
	 * Status chip.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	public static function status( CaseRecord $item ): string {
		return sprintf(
			'<span class="dxa-chip dxa-chip--%1$s">%2$s</span>',
			esc_attr( $item->status->value ),
			esc_html( Labels::case_statuses()[ $item->status->value ] ?? '' )
		);
	}
}
