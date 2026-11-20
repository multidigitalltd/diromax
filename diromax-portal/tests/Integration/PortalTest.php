<?php
/**
 * What the client sees, the portal URLs and the wp-admin guard.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Portal\CaseView;
use Diromax\Portal\Portal\Router;

final class PortalTest extends PortalTestCase {

	public function test_case_view_never_contains_hidden_entries_or_internal_notes(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		$this->app->ledger_service->add( $case, self::expense( array( 'internal_note' => 'הערה סודית למשרד' ) ), $manager );
		$this->app->ledger_service->add(
			$case,
			self::expense(
				array(
					'description' => 'פריט מוסתר',
					'visible'     => '',
				)
			),
			$manager
		);

		$view = ( new CaseView( $this->app ) )->build( $case, $client, 'finance' );
		$dump = (string) wp_json_encode( $view );

		$this->assertCount( 1, $view['entries'] );
		$this->assertStringNotContainsString( 'הערה סודית', $dump );
		$this->assertStringNotContainsString( 'פריט מוסתר', $dump );
		$this->assertFalse( $view['is_manager'] );
	}

	public function test_project_account_tile_only_when_the_account_is_used(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		$this->app->ledger_service->add( $case, self::expense( array( 'funding' => 'client' ) ), $manager );
		$labels = array_column( ( new CaseView( $this->app ) )->build( $case, $client, 'finance' )['kpis'], 'label' );
		$this->assertNotContains( 'יתרה בחשבון הפרויקט', $labels );

		$this->app->ledger_service->add( $case, self::expense( array( 'funding' => 'project' ) ), $manager );
		$view = ( new CaseView( $this->app ) )->build( $case, $client, 'finance' );
		$this->assertContains( 'יתרה בחשבון הפרויקט', array_column( $view['kpis'], 'label' ) );
		$this->assertNotNull( $view['balance'] );
	}

	public function test_open_request_banner_shows_the_nearest_and_overdue_state(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		$this->app->ledger_service->add(
			$case,
			array(
				'type'            => 'request',
				'entry_date'      => '2026-11-01',
				'due_date'        => '2026-11-10',
				'amount'          => '1000',
				'description'     => 'אגרה',
				'pay_to'          => 'supplier',
				'payee'           => 'עירייה',
				'payment_details' => 'שובר מספר 123',
				'visible'         => '1',
			),
			$manager
		);

		$request = ( new CaseView( $this->app ) )->build( $case, $client, 'finance' )['request'];

		$this->assertSame( '1,000 ₪', $request['amount'] );
		$this->assertSame( 'עירייה', $request['pay_to'] );
		$this->assertSame( 'שובר מספר 123', $request['details'] );
		$this->assertTrue( $request['overdue'] );
	}

	public function test_unknown_tab_falls_back_to_finance(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$this->assertSame( 'finance', ( new CaseView( $this->app ) )->build( $case, $manager, '<script>' )['tab'] );
		$this->assertTrue( ( new CaseView( $this->app ) )->build( $case, $manager, 'updates' )['is_manager'] );
	}

	public function test_urls_use_public_ids_with_and_without_pretty_permalinks(): void {
		$case = $this->case_for( $this->manager() );

		update_option( 'permalink_structure', '' );
		$this->assertStringContainsString( 'dx_portal_id=' . $case->public_id, Router::case_url( $case ) );
		$this->assertStringNotContainsString( 'dx_portal_id=' . $case->id . '&', Router::case_url( $case ) );

		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( home_url( '/portal/case/' . $case->public_id . '/' ), Router::case_url( $case ) );
		$this->assertSame( home_url( '/portal/case/' . $case->public_id . '/?tab=documents' ), Router::case_url( $case, 'documents' ) );
		$this->assertSame( home_url( '/portal/case/' . $case->public_id . '/' ), Router::case_url( $case, 'evil' ) );
		$this->assertStringStartsWith( home_url( '/portal/login/?t=' ), Router::login_url( 'abc' ) );
	}

	public function test_clients_get_no_admin_bar_short_sessions_and_portal_redirect(): void {
		$client  = $this->client();
		$manager = $this->manager();

		wp_set_current_user( $client );
		$this->assertFalse( apply_filters( 'show_admin_bar', true ) );
		$this->assertSame( 12 * HOUR_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $client, false ) );
		$this->assertSame( 14 * DAY_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $client, true ) );
		$this->assertSame( Router::portal_url(), apply_filters( 'login_redirect', admin_url(), '', get_userdata( $client ) ) );

		wp_set_current_user( $manager );
		$this->assertTrue( apply_filters( 'show_admin_bar', true ) );
		$this->assertSame( 2 * DAY_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $manager, false ) );
		$this->assertSame( Router::manage_url(), apply_filters( 'login_redirect', admin_url(), '', get_userdata( $manager ) ), 'Project managers land in the management app.' );
	}

	/**
	 * Regression: a client opening /wp-admin/ got WordPress's 403 page, because
	 * the redirect ran on admin_init, after the core capability check.
	 */
	public function test_client_opening_wp_admin_is_sent_to_the_portal_before_core_checks(): void {
		$client  = $this->client();
		$manager = $this->manager();

		$this->assertTrue( has_action( 'auth_redirect' ) );
		$this->assertSame( Router::portal_url(), \Diromax\Portal\Security\ClientGuard::admin_redirect( $this->app, $client ) );
		$this->assertNull( \Diromax\Portal\Security\ClientGuard::admin_redirect( $this->app, $manager ) );
	}

	public function test_nav_link_is_added_to_the_primary_menu_only(): void {
		$links = apply_filters( 'dx_nav_links', array(), 'primary' );
		$this->assertSame( 'אזור אישי', end( $links )['label'] );

		$this->assertSame( array(), apply_filters( 'dx_nav_links', array(), 'footer' ) );
	}

	/**
	 * Regression: inside the portal the theme also underlined "מאמרים" as current.
	 */
	public function test_inside_the_portal_only_the_portal_link_is_current(): void {
		set_query_var( Router::VIEW, 'case' );
		$links = apply_filters(
			'dx_nav_links',
			array(
				array(
					'url'     => '/articles/',
					'label'   => 'מאמרים',
					'current' => true,
				),
			),
			'primary'
		);
		set_query_var( Router::VIEW, '' );

		$this->assertSame( array( false, true ), array_column( $links, 'current' ) );
	}
}
