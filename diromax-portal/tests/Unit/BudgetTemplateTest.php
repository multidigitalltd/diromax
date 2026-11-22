<?php
/**
 * Tests for the default budget textarea (spec 19.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\BudgetTemplate;
use Diromax\Portal\Domain\BudgetTree;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\BudgetTemplate
 */
final class BudgetTemplateTest extends TestCase {

	/**
	 * The default as saved before the edit.
	 *
	 * @return BudgetTree
	 */
	private static function saved(): BudgetTree {
		return new BudgetTree(
			array(
				array(
					'key'     => 'consultants',
					'parent'  => '',
					'label'   => 'יועצים ומדידות',
					'planned' => 0,
				),
				array(
					'key'     => 'consultants-survey',
					'parent'  => 'consultants',
					'label'   => 'מדידות',
					'planned' => 0,
				),
				array(
					'key'     => 'fees',
					'parent'  => '',
					'label'   => 'אגרות',
					'planned' => 0,
				),
			)
		);
	}

	public function test_lines_with_a_dash_are_sub_sections_and_keys_are_kept(): void {
		$text = "יועצים ומדידות\n- מדידות\n– קונסטרוקציה\n\n  אגרות  \n* היטל השבחה\nlegal=משפטי\n";
		$tree = BudgetTemplate::parse( $text, self::saved() );

		$this->assertSame( 'consultants', $tree->lines()[0]['key'] );
		$this->assertSame( 'consultants-survey', $tree->lines()[1]['key'], 'Same label, same section: same key.' );
		$this->assertSame( 'consultants', $tree->lines()[2]['parent'] );
		$this->assertMatchesRegularExpression( '/^b[0-9a-f]{8}$/', $tree->lines()[2]['key'] );
		$this->assertSame( 'fees', $tree->lines()[3]['key'], 'Spaces around a label do not matter.' );
		$this->assertSame( 'היטל השבחה', $tree->lines()[4]['label'] );
		$this->assertSame( 'fees', $tree->lines()[4]['parent'] );
		$this->assertSame( array( 'legal', '', 'משפטי' ), array( $tree->lines()[5]['key'], $tree->lines()[5]['parent'], $tree->lines()[5]['label'] ) );
		$this->assertSame( 0, $tree->total() );
	}

	public function test_a_sub_section_before_any_section_and_repeated_keys_are_handled(): void {
		$tree = BudgetTemplate::parse( "- orphan\nfees=אגרות\nfees=עוד אגרות\nother=אחר", new BudgetTree( array() ) );

		$this->assertSame( array( 'fees', 'fees-2' ), array_slice( $tree->keys(), 0, 2 ) );
		$this->assertNotSame( 'other', $tree->keys()[2], 'The reserved key is never used.' );
		$this->assertCount( 3, $tree->lines() );
	}

	public function test_text_writes_what_parse_reads(): void {
		$text = BudgetTemplate::text( self::saved() );

		$this->assertSame( "יועצים ומדידות\n- מדידות\nאגרות", $text );
		$this->assertSame( self::saved()->lines(), BudgetTemplate::parse( $text, self::saved() )->lines() );
	}
}
