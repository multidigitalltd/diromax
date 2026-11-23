<?php
/**
 * Tests for the rules of quote comparisons (spec 24).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\QuoteComparison;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\QuoteComparison
 */
final class QuoteComparisonTest extends TestCase {

	public function test_a_comparison_needs_a_title_and_a_line_of_the_case(): void {
		$ok = QuoteComparison::comparison(
			array(
				'title'    => ' קבלן שלד ',
				'category' => 'construction',
				'note'     => 'כולל פיגומים',
				'due_on'   => '2027-01-15',
			),
			array( 'construction', 'consultants' )
		);

		$this->assertSame(
			array(
				'title'    => 'קבלן שלד',
				'category' => 'construction',
				'note'     => 'כולל פיגומים',
				'due_on'   => '2027-01-15',
			),
			$ok['fields']
		);
		$this->assertSame(
			array(
				'title'    => 'required',
				'category' => 'invalid',
				'due_on'   => 'date',
			),
			QuoteComparison::comparison(
				array(
					'title'    => '',
					'category' => 'other-case-line',
					'due_on'   => '2027-02-30',
				),
				array( 'construction' )
			)['errors']
		);
	}

	public function test_a_quote_needs_a_supplier_and_a_positive_amount(): void {
		$ok = QuoteComparison::offer(
			array(
				'supplier'    => 'א.ב. בנייה',
				'amount'      => '185,000',
				'duration'    => '14 שבועות',
				'recommended' => '1',
			)
		);

		$this->assertSame( array( 'א.ב. בנייה', 18500000, '14 שבועות', true ), array( $ok['fields']['supplier'] ?? '', $ok['fields']['amount'] ?? 0, $ok['fields']['duration'] ?? '', $ok['fields']['recommended'] ?? false ) );
		$this->assertSame(
			array(
				'supplier' => 'required',
				'amount'   => 'invalid',
			),
			QuoteComparison::offer(
				array(
					'supplier' => ' ',
					'amount'   => '0',
				)
			)['errors']
		);
		$this->assertSame(
			array( 'amount' => 'invalid' ),
			QuoteComparison::offer(
				array(
					'supplier' => 'x',
					'amount'   => 'abc',
				)
			)['errors']
		);
	}

	public function test_the_range_of_the_quotes(): void {
		$this->assertSame(
			array(
				'lowest'  => 7,
				'highest' => 5,
				'gap'     => 3000000,
			),
			QuoteComparison::range(
				array(
					array(
						'id'     => 5,
						'amount' => 21000000,
					),
					array(
						'id'     => 7,
						'amount' => 18000000,
					),
					array(
						'id'     => 9,
						'amount' => 19500000,
					),
				)
			)
		);
		$this->assertSame(
			array(
				'lowest'  => null,
				'highest' => null,
				'gap'     => 0,
			),
			QuoteComparison::range( array() )
		);
	}
}
