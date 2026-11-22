<?php
/**
 * Tests for IsraeliId and SignatureInput.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\IsraeliId;
use Diromax\Portal\Domain\SignatureInput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\IsraeliId
 * @covers \Diromax\Portal\Domain\SignatureInput
 */
final class SignatureDomainTest extends TestCase {

	private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function test_israeli_id_check_digit(): void {
		$this->assertSame( '123456782', IsraeliId::normalize( '123456782' ) );
		$this->assertSame( '123456782', IsraeliId::normalize( ' 12345678-2 ' ), 'Spaces and dashes are ignored.' );
		$this->assertSame( '000000018', IsraeliId::normalize( '00018' ), 'Short IDs get their leading zeros.' );

		foreach ( array( '123456789', '000000000', '1234', '1234567890', 'abcdefghi', '' ) as $bad ) {
			$this->assertNull( IsraeliId::normalize( $bad ), $bad );
		}

		$this->assertSame( '••••••782', IsraeliId::masked( '123456782' ) );
	}

	public function test_signature_input(): void {
		$ok = SignatureInput::check(
			array(
				'name'    => '  משפחת   כהן ',
				'image'   => self::PNG,
				'confirm' => '1',
			),
			false
		);
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( 'משפחת כהן', $ok['name'] );
		$this->assertStringStartsWith( "\x89PNG", $ok['image'] );

		$typed = SignatureInput::check(
			array(
				'name'    => 'משפחת כהן',
				'no_draw' => '1',
				'confirm' => '1',
			),
			false
		);
		$this->assertSame( array(), $typed['errors'], 'The typed name signs when the signer cannot draw.' );
		$this->assertSame( '', $typed['image'] );

		$link = SignatureInput::check(
			array(
				'name'      => 'יוסי',
				'no_draw'   => '1',
				'confirm'   => '1',
				'id_number' => '12345678-2',
			),
			true
		);
		$this->assertSame( '123456782', $link['id_number'] );

		$bad = SignatureInput::check( array( 'id_number' => '1' ), true );
		$this->assertSame(
			array(
				'name'      => 'required',
				'image'     => 'required',
				'id_number' => 'invalid',
				'confirm'   => 'required',
			),
			$bad['errors']
		);
	}

	public function test_only_a_small_real_png_is_a_signature_image(): void {
		$this->assertSame( '', SignatureInput::png( '' ) );
		$this->assertNotNull( SignatureInput::png( self::PNG ) );

		foreach ( array(
			'data:image/jpeg;base64,' . substr( self::PNG, 22 ),
			'data:image/png;base64,bm90IGEgcG5n',
			'data:image/png;base64,***',
			'data:image/png;base64,' . str_repeat( 'A', 300000 ),
			'javascript:alert(1)',
		) as $bad ) {
			$this->assertNull( SignatureInput::png( $bad ), substr( $bad, 0, 40 ) );
		}
	}
}
