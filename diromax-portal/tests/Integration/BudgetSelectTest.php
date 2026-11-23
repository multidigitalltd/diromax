<?php
/**
 * The budget line picker of the ledger's forms lists only lines with a
 * budget (spec 19.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\BudgetSelect;
use Diromax\Portal\Domain\BudgetTree;

final class BudgetSelectTest extends PortalTestCase {

	/**
	 * A budget: a section with an amount, an empty one, a section whose
	 * budget is in one of its sub-sections, and a section that is all empty.
	 *
	 * @param int $scale 0 for an empty budget.
	 * @return BudgetTree
	 */
	private static function tree( int $scale = 1 ): BudgetTree {
		$line = static fn( string $key, string $section, string $label, int $planned ): array => array(
			'key'     => $key,
			'parent'  => $section,
			'label'   => $label,
			'planned' => $planned * $scale,
		);

		return new BudgetTree(
			array(
				$line( 'design', '', 'תכנון', 100000 ),
				$line( 'permits', '', 'היתרים', 0 ),
				$line( 'build', '', 'ביצוע', 0 ),
				$line( 'build-frame', 'build', 'שלד', 500000 ),
				$line( 'build-finish', 'build', 'גמר', 0 ),
				$line( 'legal', '', 'משפטי', 0 ),
				$line( 'legal-lawyer', 'legal', 'עורך דין', 0 ),
			)
		);
	}

	/**
	 * The option values in some HTML.
	 *
	 * @param string $html Options.
	 * @return string[]
	 */
	private static function values( string $html ): array {
		preg_match_all( '/<option value="([^"]*)"/', $html, $match );

		return $match[1];
	}

	public function test_the_ledger_lists_only_lines_with_a_budget(): void {
		$html = BudgetSelect::options( self::tree(), '', true );

		$this->assertSame( array( 'design', 'build-frame' ), self::values( $html ), 'Empty lines, and an empty section with only empty sub-sections, are left out.' );
		$this->assertStringContainsString( '<optgroup label="ביצוע">', $html, 'A sub-section keeps its section as the group.' );
		$this->assertStringNotContainsString( 'משפטי', $html );
	}

	public function test_the_line_an_entry_already_has_stays_listed(): void {
		$this->assertSame( array( 'design', 'permits', 'build-frame' ), self::values( BudgetSelect::options( self::tree(), 'permits', true ) ) );
		$this->assertSame( array( 'design', 'build-frame', 'build-finish' ), self::values( BudgetSelect::options( self::tree(), 'build-finish', true ) ) );
	}

	public function test_an_empty_budget_or_another_form_lists_every_line(): void {
		$all = array( 'design', 'permits', 'build', 'build-frame', 'build-finish', 'legal', 'legal-lawyer' );

		$this->assertSame( $all, self::values( BudgetSelect::options( self::tree( 0 ), '', true ) ), 'A new case can still note an expense.' );
		$this->assertSame( $all, self::values( BudgetSelect::options( self::tree(), '', false ) ), 'The quotes form keeps the whole list.' );
	}
}
