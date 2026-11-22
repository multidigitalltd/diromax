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
 * sub-section; a section without sub-sections is a plain option.
 */
final class BudgetSelect {

	/**
	 * Escaped <option> and <optgroup> elements.
	 *
	 * @param BudgetTree $tree     The case's budget.
	 * @param string     $selected Selected key.
	 * @return string
	 */
	public static function options( BudgetTree $tree, string $selected ): string {
		$html = '';

		foreach ( $tree->sections() as $section ) {
			$children = $tree->children( $section['key'] );

			if ( array() === $children ) {
				$html .= self::option( $section['key'], $section['label'], $selected );
				continue;
			}

			$html .= '<optgroup label="' . esc_attr( $section['label'] ) . '">';
			/* translators: %s: budget section. */
			$html .= self::option( $section['key'], sprintf( __( '%s (כללי)', 'diromax-portal' ), $section['label'] ), $selected );

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
