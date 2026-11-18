<?php
/**
 * Password sign-in and password changes (spec 15.10).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Service\PasswordService;
use WP_Error;

final class PasswordTest extends PortalTestCase {

	private const GOOD = 'סוכריות על מקל 42';

	private PasswordService $passwords;

	public function set_up(): void {
		parent::set_up();
		$this->passwords = new PasswordService( $this->app );
		add_filter( 'send_auth_cookies', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'send_auth_cookies', '__return_false' );
		parent::tear_down();
	}

	public function test_a_client_who_just_signed_in_sets_a_password_and_uses_it(): void {
		$client = $this->member( 'ronit@example.org' );
		$this->passwords->mark_signed_in( $client );

		$this->assertTrue( $this->passwords->change( $client, self::GOOD, '' ) );
		$this->assertTrue( PasswordService::has_password( $client ) );
		$this->assertSame( array( 'ronit@example.org' ), array_column( $this->mails, 'to' ) );
		$this->assertStringContainsString( 'הסיסמה לאזור האישי עודכנה', $this->mails[0]['subject'] );

		wp_set_current_user( 0 );
		$this->assertSame( $client, $this->passwords->sign_in( 'Ronit@Example.org ', self::GOOD, false ) );
		$this->assertSame( $client, get_current_user_id() );
	}

	public function test_without_a_recent_sign_in_the_current_password_is_required(): void {
		$client = $this->member();
		$this->passwords->mark_signed_in( $client );
		$this->passwords->change( $client, self::GOOD, '' );
		$this->clock->advance( HOUR_IN_SECONDS );

		$this->assertSame( 'dx_reauth', $this->passwords->change( $client, 'סיסמה חדשה לגמרי', '' )->get_error_code() );
		$this->assertSame( 'dx_reauth', $this->passwords->change( $client, 'סיסמה חדשה לגמרי', 'לא נכונה בכלל' )->get_error_code() );
		$this->assertTrue( $this->passwords->change( $client, 'סיסמה חדשה לגמרי', self::GOOD ) );
	}

	public function test_weak_passwords_are_refused(): void {
		$client = $this->member( 'dana@example.org' );
		$this->passwords->mark_signed_in( $client );

		foreach ( array( 'קצר', 'dana@example.org', str_repeat( 'א', 201 ) ) as $weak ) {
			$result = $this->passwords->change( $client, $weak, '' );
			$this->assertInstanceOf( WP_Error::class, $result, $weak );
			$this->assertSame( 'dx_invalid', $result->get_error_code() );
		}

		$this->assertFalse( PasswordService::has_password( $client ) );
	}

	public function test_changing_the_password_ends_every_other_session(): void {
		$client   = $this->member();
		$sessions = \WP_Session_Tokens::get_instance( $client );
		$sessions->create( $this->clock->now() + DAY_IN_SECONDS );
		$sessions->create( $this->clock->now() + DAY_IN_SECONDS );
		$this->passwords->mark_signed_in( $client );

		$this->assertTrue( $this->passwords->change( $client, self::GOOD, '' ) );

		$this->assertLessThanOrEqual( 1, count( $sessions->get_all() ), 'Only the session of this request may remain.' );
	}

	public function test_every_failure_looks_the_same(): void {
		$client = $this->member( 'known@example.org' );
		$this->passwords->mark_signed_in( $client );
		$this->passwords->change( $client, self::GOOD, '' );

		$wrong   = $this->passwords->sign_in( 'known@example.org', 'לא הסיסמה הזאת', false );
		$unknown = $this->passwords->sign_in( 'nobody@example.org', self::GOOD, false );
		$empty   = $this->passwords->sign_in( 'known@example.org', '', false );

		foreach ( array( $wrong, $unknown, $empty ) as $result ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'dx_login_failed', $result->get_error_code() );
			$this->assertSame( $wrong->get_error_message(), $result->get_error_message() );
		}
	}

	/**
	 * Spec 15.11: six wrong passwords, and the sixth is refused even when right.
	 */
	public function test_the_sixth_attempt_is_blocked_with_the_same_message(): void {
		$client = $this->member( 'rate@example.org' );
		$this->passwords->mark_signed_in( $client );
		$this->passwords->change( $client, self::GOOD, '' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->passwords->sign_in( 'rate@example.org', 'טעות ' . $i . ' טעות', false );
		}

		$blocked = $this->passwords->sign_in( 'rate@example.org', self::GOOD, false );
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'dx_login_failed', $blocked->get_error_code() );

		$this->clock->advance( 16 * MINUTE_IN_SECONDS );
		$this->assertSame( $client, $this->passwords->sign_in( 'rate@example.org', self::GOOD, false ) );
	}

	/**
	 * Spec 15.11: staff never sign in through the portal form (it would bypass 2FA).
	 */
	public function test_staff_cannot_sign_in_through_the_portal(): void {
		$manager = self::factory()->user->create(
			array(
				'role'       => \Diromax\Portal\Security\Capabilities::ROLE_MANAGER,
				'user_email' => 'staff@example.org',
				'user_pass'  => self::GOOD,
			)
		);

		$this->assertSame( 'dx_login_failed', $this->passwords->sign_in( 'staff@example.org', self::GOOD, false )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->passwords->change( $manager, 'סיסמה חדשה לגמרי', self::GOOD )->get_error_code() );
	}

	public function test_a_client_without_a_case_cannot_sign_in(): void {
		$client = $this->member( 'former@example.org' );
		$this->passwords->mark_signed_in( $client );
		$this->passwords->change( $client, self::GOOD, '' );
		$this->app->members->remove_user( $client );

		$this->assertSame( 'dx_login_failed', $this->passwords->sign_in( 'former@example.org', self::GOOD, false )->get_error_code() );
	}

	public function test_a_link_sign_in_counts_as_recent(): void {
		$client = $this->member( 'link@example.org' );
		$this->app->login->request_link( 'link@example.org' );
		$this->app->login->flush_deferred();
		$this->assertSame( 1, preg_match( '/(?:[?&]|&#038;)t=([A-Za-z0-9_-]{43})/', (string) $this->mails[0]['message'], $match ) );

		$this->assertSame( $client, $this->app->login->consume( $match[1], false ) );
		$this->assertTrue( $this->passwords->recently_signed_in( $client ) );
	}

	/**
	 * A client who is a member of one case.
	 *
	 * @param string $email Address.
	 * @return int
	 */
	private function member( string $email = '' ): int {
		$client = $this->client( $email );
		$case   = $this->case_for( $this->manager() );
		$this->app->members->upsert( $case->id, $client, MemberRole::Owner, true, $this->clock->mysql() );

		return $client;
	}
}
