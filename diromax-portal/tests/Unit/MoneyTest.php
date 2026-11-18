<?php
/**
 * Tests for Money.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\Money
 */
final class MoneyTest extends TestCase {

	/**
	 * @dataProvider valid_amounts
	 */
	public function test_parses_valid_amounts_into_agorot( string $input, int $expected ): void {
		$this->assertSame( $expected, Money::parse( $input ) );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function valid_amounts(): array {
		return array(
			'whole'                 => array( '12300', 1230000 ),
			'thousands separator'   => array( '12,300', 1230000 ),
			'one decimal'           => array( '12300.5', 1230050 ),
			'two decimals'          => array( '12,300.05', 1230005 ),
			'shekel sign and space' => array( ' ₪ 12 300 ', 1230000 ),
			'apostrophe separator'  => array( "1'000", 100000 ),
			'no-break space'        => array( "1\u{00A0}000", 100000 ),
			'zero'                  => array( '0', 0 ),
			'maximum'               => array( '999,999,999.99', 99999999999 ),
		);
	}

	/**
	 * @dataProvider invalid_amounts
	 */
	public function test_rejects_invalid_amounts( string $input ): void {
		$this->assertNull( Money::parse( $input ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_amounts(): array {
		return array(
			'empty'           => array( '' ),
			'negative'        => array( '-5' ),
			'three decimals'  => array( '1.005' ),
			'letters'         => array( '12a' ),
			'exponent'        => array( '1e5' ),
			'too many digits' => array( '1234567890' ),
			'very long'       => array( str_repeat( '1', 40 ) ),
			'only dot'        => array( '.' ),
		);
	}

	public function test_formats_with_thousands_and_currency(): void {
		$this->assertSame( '12,300 ₪', Money::format( 1230000 ) );
		$this->assertSame( '12,300.50 ₪', Money::format( 1230050 ) );
		$this->assertSame( '0.05 ₪', Money::format( 5 ) );
	}

	public function test_plain_formats_negative_with_minus_sign(): void {
		$this->assertSame( '−50,300', Money::plain( -5030000 ) );
	}

	public function test_input_value_round_trips_through_parse(): void {
		foreach ( array( 0, 5, 1230000, 1230050, 99999999999 ) as $agorot ) {
			$this->assertSame( $agorot, Money::parse( Money::input( $agorot ) ) );
		}
	}

	public function test_share_in_basis_points_rounds_half_up(): void {
		$this->assertSame( 1642, Money::share_bps( 7880000, 48000000 ) );
		$this->assertSame( 2579, Money::share_bps( 12380000, 48000000 ) );
		$this->assertSame( 5000, Money::share_bps( 1, 2 ) );
	}

	public function test_share_is_zero_without_a_positive_total_or_part(): void {
		$this->assertSame( 0, Money::share_bps( 100, 0 ) );
		$this->assertSame( 0, Money::share_bps( 0, 100 ) );
		$this->assertSame( 0, Money::share_bps( -100, 100 ) );
	}

	public function test_percent_label(): void {
		$this->assertSame( '16.4%', Money::percent( 1642 ) );
		$this->assertSame( '25.8%', Money::percent( 2579 ) );
		$this->assertSame( '50%', Money::percent( 5000 ) );
		$this->assertSame( '0%', Money::percent( -10 ) );
	}

	public function test_divide_rounded_rounds_half_up(): void {
		$this->assertSame( 3, Money::divide_rounded( 5, 2 ) );
		$this->assertSame( 2, Money::divide_rounded( 7, 4 ) );
		$this->assertSame( 1, Money::divide_rounded( 5, 4 ) );
	}
}
