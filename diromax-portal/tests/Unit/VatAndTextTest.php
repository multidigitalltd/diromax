<?php
/**
 * Tests for Vat and Text.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\Text;
use Diromax\Portal\Domain\Vat;
use Diromax\Portal\Domain\VatMode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\Vat
 * @covers \Diromax\Portal\Domain\Text
 */
final class VatAndTextTest extends TestCase {

	public function test_included_vat_keeps_the_entered_total(): void {
		$split = Vat::split( 1230000, VatMode::Included, 1800 );

		$this->assertSame( 1230000, $split['total'] );
		$this->assertSame( 1042373, $split['net'] );
		$this->assertSame( 187627, $split['vat'] );
		$this->assertSame( $split['total'], $split['net'] + $split['vat'] );
	}

	public function test_excluded_vat_is_added_on_top(): void {
		$this->assertSame(
			array(
				'net'   => 1000000,
				'vat'   => 180000,
				'total' => 1180000,
			),
			Vat::split( 1000000, VatMode::Excluded, 1800 )
		);
	}

	public function test_exempt_has_no_vat(): void {
		$this->assertSame(
			array(
				'net'   => 450000,
				'vat'   => 0,
				'total' => 450000,
			),
			Vat::split( 450000, VatMode::Exempt, 1800 )
		);
	}

	public function test_net_and_vat_always_add_up_to_total(): void {
		foreach ( array( 1, 99, 101, 117, 999999 ) as $amount ) {
			foreach ( array( VatMode::Included, VatMode::Excluded ) as $mode ) {
				$split = Vat::split( $amount, $mode, 1800 );
				$this->assertSame( $split['total'], $split['net'] + $split['vat'] );
			}
		}
	}

	public function test_line_strips_controls_and_bidi_overrides_but_keeps_marks(): void {
		$this->assertSame( 'a b c', Text::line( "a\tb\x00c" ) );
		$this->assertSame( 'שם ספק', Text::line( "שם\u{202E}ספק" ) );
		$this->assertSame( "שלום\u{200F}", Text::line( "  שלום\u{200F}  " ) );
	}

	public function test_line_normalizes_to_nfc(): void {
		$this->assertSame( "\u{00E9}", Text::line( "e\u{0301}" ) );
	}

	public function test_block_keeps_single_line_breaks_and_collapses_runs(): void {
		$this->assertSame( "שורה 1\nשורה 2\n\nשורה 3", Text::block( "שורה 1\r\nשורה 2\n\n\n\nשורה 3 " ) );
	}

	public function test_invalid_utf8_becomes_empty(): void {
		$this->assertSame( '', Text::line( "\xC3\x28" ) );
		$this->assertSame( 4, Text::length( 'שלום' ) );
	}
}
