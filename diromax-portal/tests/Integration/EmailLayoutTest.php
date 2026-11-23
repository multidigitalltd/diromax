<?php
/**
 * The email layout fits a phone even where the app strips <style>.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

final class EmailLayoutTest extends PortalTestCase {

	/**
	 * The HTML outside Outlook's [if mso] blocks: what every other client reads.
	 *
	 * @param string $html Email.
	 * @return string
	 */
	private static function outside_outlook( string $html ): string {
		return (string) preg_replace( '/<!--\[if mso\]>.*?<!\[endif\]-->/s', '', $html );
	}

	/**
	 * 1.15.1 (the office saw emails wider than the phone): the card is 100%
	 * wide up to 600px, inline, so it needs no media query; long words and
	 * links break instead of widening it. Only Outlook on Windows gets 600px.
	 */
	public function test_the_card_is_fluid_without_a_media_query(): void {
		$html = $this->app->renderer->login_code( 'דניאל', '012345' )->html;

		$this->assertStringContainsString( 'class="dx-container dx-card" width="100%"', $html );
		$this->assertStringContainsString( 'width:100%;max-width:600px;', $html );
		$this->assertStringContainsString( 'overflow-wrap:anywhere;word-break:break-word;', $html );
		$this->assertStringNotContainsString( 'width="600"', self::outside_outlook( $html ), 'A fixed width only for Outlook on Windows.' );
		$this->assertDoesNotMatchRegularExpression( '/(?<!max-)width:600px/', self::outside_outlook( $html ) );
		$this->assertStringContainsString( '<!--[if mso]><table role="presentation" width="600"', $html, 'Outlook on Windows keeps its fixed table.' );
		$this->assertSame( 2, substr_count( self::outside_outlook( $html ), '<style>' ), 'Mobile and dark mode in separate blocks: a client that drops one keeps the other.' );
	}

	public function test_the_money_columns_stack_on_a_phone(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$this->assertTrue( $this->app->case_service->save_budget( $case, array( 'fees.planned' => '100,000' ), $manager ) );
		$html = $this->app->renderer->case_update( $case, array(), $this->app->case_service->summary( $case, true ), 'משפחת כהן' )->html;

		$this->assertGreaterThanOrEqual( 2, substr_count( $html, 'class="dx-col" style="display:inline-block;width:100%;max-width:240px;' ), 'Inline blocks that fall one under the other when the screen is narrow.' );
		$this->assertStringNotContainsString( 'dx-stack', $html, 'No table cells that only a media query can stack.' );
		$this->assertStringContainsString( '<!--[if mso]></td><td width="50%" valign="top"><![endif]-->', $html, 'Outlook on Windows gets two cells.' );
	}
}
