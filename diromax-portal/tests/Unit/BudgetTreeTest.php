<?php
/**
 * Tests for the budget tree and its form edits (spec 19.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\BudgetTree;
use Diromax\Portal\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\BudgetTree
 */
final class BudgetTreeTest extends TestCase {

	/**
	 * Two sections, the first with two sub-sections.
	 *
	 * @return BudgetTree
	 */
	private static function tree(): BudgetTree {
		return new BudgetTree(
			array(
				array(
					'key'     => 'structure',
					'parent'  => 'consultants',
					'label'   => 'קונסטרוקציה',
					'planned' => 500000,
				),
				array(
					'key'     => 'consultants',
					'parent'  => '',
					'label'   => 'יועצים',
					'planned' => 100000,
				),
				array(
					'key'     => 'survey',
					'parent'  => 'consultants',
					'label'   => 'מדידות',
					'planned' => 200000,
				),
				array(
					'key'     => 'fees',
					'parent'  => '',
					'label'   => 'אגרות',
					'planned' => 0,
				),
				array(
					'key'     => 'orphan',
					'parent'  => 'gone',
					'label'   => 'No section',
					'planned' => 7,
				),
				array(
					'key'     => 'fees',
					'parent'  => '',
					'label'   => 'Repeated key',
					'planned' => 9,
				),
			)
		);
	}

	/**
	 * Key maker for tests: n1, n2, ...
	 *
	 * @return callable(): string
	 */
	private static function keys(): callable {
		$n = 0;

		return static function () use ( &$n ): string {
			return 'n' . ( ++$n );
		};
	}

	public function test_lines_come_in_tree_order_without_orphans_or_repeats(): void {
		$tree = self::tree();

		$this->assertSame( array( 'consultants', 'structure', 'survey', 'fees' ), $tree->keys() );
		$this->assertSame( 800000, $tree->total() );
		$this->assertSame( 800000, $tree->section_total( 'consultants' ) );
		$this->assertSame( 'יועצים › מדידות', $tree->label( 'survey' ) );
		$this->assertSame( 'אגרות', $tree->label( 'fees' ) );
		$this->assertSame( '', $tree->label( 'orphan' ) );
		$this->assertSame( 'consultants', $tree->section_of( 'structure' ) );
		$this->assertSame( 'fees', $tree->section_of( 'fees' ) );
		$this->assertSame( '', $tree->section_of( 'nope' ) );
		$this->assertCount( 2, $tree->children( 'consultants' ) );
	}

	public function test_rename_set_amounts_and_add_lines(): void {
		$result = self::tree()->edit(
			array(
				'consultants.label'     => '  יועצים ומדידות ',
				'survey.planned'        => '2,500.50',
				'fees.planned'          => '',
				'add.fees.label'        => 'היטל השבחה',
				'add.fees.planned'      => '40000',
				'add.*.label'           => 'שיווק',
				'add.*.planned'         => '',
				'add.consultants.label' => '',
			),
			array(),
			self::keys()
		);

		$this->assertSame( array(), $result['errors'] );
		$tree = $result['tree'];
		$this->assertSame( array( 'consultants', 'structure', 'survey', 'fees', 'n1', 'n2' ), $tree->keys() );
		$this->assertSame( 'יועצים ומדידות', $tree->find( 'consultants' )['label'] ?? '' );
		$this->assertSame( 250050, $tree->find( 'survey' )['planned'] ?? 0 );
		$this->assertSame(
			array(
				'key'     => 'n1',
				'parent'  => 'fees',
				'label'   => 'היטל השבחה',
				'planned' => 4000000,
			),
			$tree->find( 'n1' )
		);
		$this->assertSame(
			array(
				'key'     => 'n2',
				'parent'  => '',
				'label'   => 'שיווק',
				'planned' => 0,
			),
			$tree->find( 'n2' )
		);
	}

	/**
	 * 1.15.0: an amount typed before VAT gets VAT added once, on this save;
	 * the amount kept is the total, like the ledger's.
	 */
	public function test_an_amount_typed_before_vat_is_kept_with_vat(): void {
		$result = self::tree()->edit(
			array(
				'survey.planned'      => '10,000',
				'survey.vat'          => 'excluded',
				'structure.planned'   => '5,000',
				'structure.vat'       => 'included',
				'consultants.planned' => '',
				'consultants.vat'     => 'excluded',
				'add.fees.label'      => 'היטל השבחה',
				'add.fees.planned'    => '1,000.50',
				'add.fees.vat'        => 'excluded',
				'add.*.label'         => 'שיווק',
				'add.*.planned'       => '200',
				'add.*.vat'           => 'excluded',
			),
			array(),
			self::keys(),
			1800
		);

		$this->assertSame( array(), $result['errors'] );
		$tree = $result['tree'];
		$this->assertSame( 1180000, $tree->find( 'survey' )['planned'] ?? 0, '10,000 + 18% VAT.' );
		$this->assertSame( 500000, $tree->find( 'structure' )['planned'] ?? 0, 'Already including VAT: as typed.' );
		$this->assertSame( 0, $tree->find( 'consultants' )['planned'] ?? -1, 'Empty stays empty.' );
		$this->assertSame( 118059, $tree->find( 'n1' )['planned'] ?? 0, 'A new sub-section: 1,000.50 + 180.09.' );
		$this->assertSame( 23600, $tree->find( 'n2' )['planned'] ?? 0, 'A new section.' );
		$this->assertSame(
			500000,
			$tree->edit(
				array(
					'structure.planned' => '5,000',
					'structure.vat'     => 'nonsense',
				),
				array(),
				self::keys(),
				1800
			)['tree']->find( 'structure' )['planned'] ?? 0,
			'Anything but "excluded" is taken as including VAT.'
		);
	}

	public function test_vat_cannot_take_an_amount_past_the_largest(): void {
		$largest = Money::MAX;
		$result  = self::tree()->edit(
			array(
				'survey.planned' => Money::input( $largest ),
				'survey.vat'     => 'excluded',
			),
			array(),
			self::keys(),
			1800
		);

		$this->assertSame( array( 'survey.planned' => 'invalid' ), $result['errors'] );
		$this->assertSame( array(), self::tree()->edit( array( 'survey.planned' => Money::input( $largest ) ), array(), self::keys(), 1800 )['errors'], 'Including VAT, the largest amount itself is fine.' );
	}

	public function test_removing_a_sub_section_moves_its_entries_to_the_section(): void {
		$result = self::tree()->edit(
			array(
				'structure.remove' => '1',
				'survey.remove'    => '1',
			),
			array( 'structure' => 3 ),
			self::keys()
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'consultants', 'fees' ), $result['tree']->keys() );
		$this->assertSame( array( 'structure' => 'consultants' ), $result['moved'], 'Only lines with entries are moved.' );
	}

	public function test_a_section_with_entries_anywhere_in_it_stays(): void {
		$result = self::tree()->edit( array( 'consultants.remove' => '1' ), array( 'survey' => 1 ), self::keys() );

		$this->assertSame( array( 'consultants.remove' => 'in_use' ), $result['errors'] );
		$this->assertSame( self::tree()->keys(), $result['tree']->keys(), 'Nothing changes on an error.' );

		$free = self::tree()->edit( array( 'consultants.remove' => '1' ), array( 'fees' => 2 ), self::keys() );
		$this->assertSame( array( 'fees' ), $free['tree']->keys(), 'Its sub-sections go with it.' );
		$this->assertSame( array(), $free['moved'] );
	}

	public function test_bad_input_is_reported_on_its_field(): void {
		$result = self::tree()->edit(
			array(
				'consultants.label' => '',
				'survey.planned'    => '-5',
				'structure.label'   => 'מדידות',
				'fees.planned'      => 'abc',
				'add.*.planned'     => '100',
				'add.fees.label'    => str_repeat( 'א', 81 ),
			),
			array(),
			self::keys()
		);

		$this->assertSame(
			array(
				'consultants.label' => 'required',
				'survey.planned'    => 'invalid',
				'fees.planned'      => 'invalid',
				'add.fees.label'    => 'too_long',
				'add.*.label'       => 'required',
				'survey.label'      => 'duplicate',
			),
			$result['errors']
		);
	}

	public function test_a_new_line_with_a_sibling_label_is_a_duplicate(): void {
		$result = self::tree()->edit( array( 'add.consultants.label' => 'מדידות' ), array(), self::keys() );

		$this->assertSame( array( 'add.consultants.label' => 'duplicate' ), $result['errors'] );
	}

	public function test_limits_on_sections_and_sub_sections(): void {
		$lines = array();

		for ( $i = 0; $i < BudgetTree::MAX_SECTIONS; $i++ ) {
			$lines[] = array(
				'key'     => 's' . $i,
				'parent'  => '',
				'label'   => 'Section ' . $i,
				'planned' => 0,
			);
		}

		for ( $i = 0; $i < BudgetTree::MAX_CHILDREN; $i++ ) {
			$lines[] = array(
				'key'     => 'c' . $i,
				'parent'  => 's0',
				'label'   => 'Child ' . $i,
				'planned' => 0,
			);
		}

		$result = ( new BudgetTree( $lines ) )->edit(
			array(
				'add.*.label'  => 'One too many',
				'add.s0.label' => 'One too many',
			),
			array(),
			self::keys()
		);

		$this->assertSame(
			array(
				'add.*.label'  => 'too_many',
				'add.s0.label' => 'too_many',
			),
			$result['errors']
		);
	}

	public function test_move_swaps_with_a_sibling_and_stops_at_the_ends(): void {
		$this->assertSame( array( 'fees', 'consultants', 'structure', 'survey' ), self::tree()->edit( array( 'move' => 'fees:up' ), array(), self::keys() )['tree']->keys() );
		$this->assertSame( array( 'consultants', 'survey', 'structure', 'fees' ), self::tree()->edit( array( 'move' => 'structure:down' ), array(), self::keys() )['tree']->keys() );
		$this->assertSame( self::tree()->keys(), self::tree()->edit( array( 'move' => 'consultants:up' ), array(), self::keys() )['tree']->keys() );
		$this->assertSame( self::tree()->keys(), self::tree()->edit( array( 'move' => 'nope:down' ), array(), self::keys() )['tree']->keys() );
	}

	public function test_flat_builds_sections_from_amounts(): void {
		$tree = BudgetTree::flat(
			array(
				'a' => 5,
				'b' => 7,
			),
			array( 'a' => 'A' )
		);

		$this->assertSame( array( 'a', 'b' ), $tree->keys() );
		$this->assertSame( 'A', $tree->label( 'a' ) );
		$this->assertSame( 'b', $tree->label( 'b' ) );
		$this->assertSame( 12, $tree->total() );
		$this->assertFalse( $tree->is_empty() );
		$this->assertTrue( ( new BudgetTree( array() ) )->is_empty() );
	}
}
