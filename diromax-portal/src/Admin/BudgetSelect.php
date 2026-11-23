<?php
/**
 * Budget line picker.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Domain\BudgetTree;

defined( 'ABSPATH' ) || exit;

/**
 * The options of a "budget line" select (spec 19.2): a section with
 * sub-sections is a group holding the section itself ("general") and each
 * sub-section; a section without sub-sections is a plain option. The
 * ledger's forms list only the lines with a budget (spec 19.2).
 */
final class BudgetSelect {

	/**
	 * Escaped <option> and <optgroup> elements.
	 *
	 * With $only_set, a ledger entry's picker lists only the lines that have
	 * a planned amount, and the line the entry already has. While no line has
	 * one (a new case), it lists them all, so an expense can still be noted.
	 *
	 * @param BudgetTree $tree     The case's budget.
	 * @param string     $selected Selected key.
	 * @param bool       $only_set Only lines with a planned amount.
	 * @return string
	 */
	public static function options( BudgetTree $tree, string $selected, bool $only_set = false ): string {
		$only_set = $only_set && $tree->total() > 0;
		$shown    = static fn( array $line ): bool => ! $only_set || $line['planned'] > 0 || $line['key'] === $selected;
		$html     = '';

		foreach ( $tree->sections() as $section ) {
			$children = array_values( array_filter( $tree->children( $section['key'] ), $shown ) );

			if ( array() === $children ) {
				// A section with no sub-section to offer is offered on its own, when it has a budget.
				$html .= $shown( $section ) ? self::option( $section['key'], $section['label'], $selected ) : '';
				continue;
			}

			$html .= '<optgroup label="' . esc_attr( $section['label'] ) . '">';

			if ( $shown( $section ) ) {
				/* translators: %s: budget section. */
				$html .= self::option( $section['key'], sprintf( __( '%s (כללי)', 'diromax-portal' ), $section['label'] ), $selected );
			}

			foreach ( $children as $child ) {
				$html .= self::option( $child['key'], $child['label'], $selected );
			}

			$html .= '</optgroup>';
		}

		return $html;
	}

	/**
	 * One option.
	 *
	 * @param string $key      Value.
	 * @param string $label    Text.
	 * @param string $selected Selected key.
	 * @return string
	 */
	private static function option( string $key, string $label, string $selected ): string {
		return '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
}
