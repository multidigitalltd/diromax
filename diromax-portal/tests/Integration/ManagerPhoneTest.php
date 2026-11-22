<?php
/**
 * The project manager's phone on the client's case page (spec 19.3).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Service\Contact;

final class ManagerPhoneTest extends PortalTestCase {

	/**
	 * The case page as the client gets it.
	 *
	 * @param \Diromax\Portal\Data\CaseRecord $client_case Case.
	 * @param int                             $client      Client.
	 * @return string
	 */
	private function page( \Diromax\Portal\Data\CaseRecord $client_case, int $client ): string {
		$view         = ( new CaseView( $this->app ) )->build( $client_case, $client, 'finance' );
		$view['self'] = 'https://example.org/portal/case/x/';
		ob_start();
		include DX_PORTAL_DIR . 'templates/portal/case.php';

		return (string) ob_get_clean();
	}

	public function test_the_managers_own_number_is_shown_and_dialled(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );
		update_user_meta( $manager, Contact::PHONE_META, '050-1234567' );

		$html = $this->page( $case, $client );

		$this->assertMatchesRegularExpression( '#<p class="dxp-pm__tel">\s*<span class="dxp-pm__tel-k">טלפון:</span>\s*<a href="tel:0501234567" dir="ltr">050-1234567</a>#u', $html );
	}

	public function test_the_office_number_is_labelled_as_such_and_no_number_shows_nothing(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		set_theme_mod( 'dx_phone', '03-5550000' );
		$html = $this->page( $case, $client );
		$this->assertStringContainsString( 'טלפון המשרד:', $html );
		$this->assertStringContainsString( '>03-5550000</a>', $html );

		remove_theme_mod( 'dx_phone' );
		$this->assertStringNotContainsString( 'dxp-pm__tel"', $this->page( $case, $client ) );
	}
}
