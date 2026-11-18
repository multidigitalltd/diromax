<?php
/**
 * Passwordless sign-in and invitations.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

final class LoginServiceTest extends PortalTestCase {

	/**
	 * Request a link and return the token from the email that was sent.
	 */
	private function link_for( string $email ): ?string {
		$before = count( $this->mails );
		$this->app->login->request_link( $email );
		$this->app->login->flush_deferred();

		if ( count( $this->mails ) === $before ) {
			return null;
		}

		$this->assertSame( 1, preg_match( '/(?:[?&]|&#038;)t=([A-Za-z0-9_-]{43})/', (string) end( $this->mails )['message'], $match ) );

		return $match[1];
	}

	public function test_member_gets_a_link_and_it_works_once(): void {
		$client = $this->client( 'cohen@example.org' );
		$this->case_for( $this->manager(), $client );

		$token = $this->link_for( 'cohen@example.org' );

		$this->assertNotNull( $token );
		$this->assertTrue( $this->app->login->is_valid( $token ) );
		$this->assertSame( $client, $this->app->login->consume( $token, false ) );
		$this->assertSame( $client, get_current_user_id() );

		$this->assertFalse( $this->app->login->is_valid( $token ) );
		$this->assertSame( 'dx_link_invalid', $this->app->login->consume( $token, false )->get_error_code() );
	}

	public function test_link_expires_after_fifteen_minutes(): void {
		$client = $this->client( 'late@example.org' );
		$this->case_for( $this->manager(), $client );
		$token = $this->link_for( 'late@example.org' );

		$this->clock->advance( 15 * MINUTE_IN_SECONDS + 1 );

		$this->assertFalse( $this->app->login->is_valid( $token ) );
		$this->assertSame( 'dx_link_invalid', $this->app->login->consume( $token, false )->get_error_code() );
	}

	public function test_unknown_address_staff_and_caseless_clients_get_nothing(): void {
		$staff = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'boss@example.org',
			)
		);
		$this->client( 'nocase@example.org' );

		$this->assertNull( $this->link_for( 'nobody@example.org' ) );
		$this->assertNull( $this->link_for( 'boss@example.org' ) );
		$this->assertNull( $this->link_for( 'nocase@example.org' ) );
		$this->assertNull( $this->link_for( 'not an email' ) );
		$this->assertGreaterThan( 0, $staff );
	}

	public function test_fourth_request_in_the_window_is_dropped(): void {
		$client = $this->client( 'many@example.org' );
		$this->case_for( $this->manager(), $client );

		$this->assertNotNull( $this->link_for( 'many@example.org' ) );
		$this->assertNotNull( $this->link_for( 'many@example.org' ) );
		$this->assertNotNull( $this->link_for( 'many@example.org' ) );
		$this->assertNull( $this->link_for( 'many@example.org' ) );
	}

	public function test_malformed_and_made_up_tokens_are_refused(): void {
		$this->assertFalse( $this->app->login->is_valid( 'short' ) );
		$this->assertFalse( $this->app->login->is_valid( str_repeat( 'A', 43 ) ) );
		$this->assertSame( 'dx_link_invalid', $this->app->login->consume( "' OR 1=1 --", false )->get_error_code() );
	}

	public function test_link_stops_working_when_the_client_is_removed_from_every_case(): void {
		$client = $this->client( 'gone@example.org' );
		$case   = $this->case_for( $this->manager(), $client );
		$token  = $this->link_for( 'gone@example.org' );

		$this->app->members->remove( $case->id, $client );

		$this->assertSame( 'dx_link_invalid', $this->app->login->consume( $token, false )->get_error_code() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_only_the_hash_is_stored(): void {
		global $wpdb;

		$client = $this->client( 'hash@example.org' );
		$this->case_for( $this->manager(), $client );
		$token = $this->link_for( 'hash@example.org' );

		$stored = $wpdb->get_col( $wpdb->prepare( 'SELECT token_hash FROM %i WHERE user_id = %d', \Diromax\Portal\Data\Schema::table( 'login_tokens' ), $client ) );

		$this->assertNotContains( $token, $stored );
		$this->assertSame( 64, strlen( (string) $stored[0] ) );
	}

	public function test_invitation_is_sent_valid_for_three_days_and_recorded(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$user_id = $this->app->case_service->add_member(
			$case,
			array(
				'email'  => 'new@example.org',
				'name'   => 'משפחת לוי',
				'role'   => 'owner',
				'notify' => '1',
				'invite' => '1',
			),
			$manager
		);

		$this->assertIsInt( $user_id );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'הוזמנת לאזור האישי', $this->mails[0]['subject'] );
		$this->assertNotNull( $this->app->members->for_case( $case->id )[0]->invited_at );

		preg_match( '/(?:[?&]|&#038;)t=([A-Za-z0-9_-]{43})/', (string) $this->mails[0]['message'], $match );
		$this->clock->advance( 71 * HOUR_IN_SECONDS );
		$this->assertTrue( $this->app->login->is_valid( $match[1] ) );
		$this->clock->advance( 2 * HOUR_IN_SECONDS );
		$this->assertFalse( $this->app->login->is_valid( $match[1] ) );
	}
}
