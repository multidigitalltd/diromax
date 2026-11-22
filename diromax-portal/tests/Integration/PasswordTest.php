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
		add_filter( 'dx_portal_password_breached', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_filter( 'dx_portal_password_breached', '__return_false' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_a_client_who_just_signed_in_sets_a_password_and_uses_it(): void {
		$client = $this->member( 'ronit@example.org' );
		$token  = $this->session( $client );

		$this->assertTrue( $this->passwords->change( $client, $token, self::GOOD, '' ) );
		$this->assertTrue( PasswordService::has_password( $client ) );
		$this->assertSame( array( 'ronit@example.org' ), array_column( $this->mails, 'to' ) );
		$this->assertStringContainsString( 'הסיסמה לאזור האישי עודכנה', $this->mails[0]['subject'] );

		wp_set_current_user( 0 );
		$checked = $this->passwords->check( 'Ronit@Example.org ', self::GOOD );
		$this->assertInstanceOf( \WP_User::class, $checked );
		$this->assertSame( 0, get_current_user_id(), 'The password alone signs no one in: the email code comes first.' );
		$this->assertSame( $client, $this->passwords->complete( $checked, false ) );
		$this->assertSame( $client, get_current_user_id() );
	}

	public function test_without_a_recent_sign_in_the_current_password_is_required(): void {
		$client = $this->member();
		$token  = $this->session( $client );
		$this->passwords->change( $client, $token, self::GOOD, '' );
		$this->clock->advance( HOUR_IN_SECONDS );

		$this->assertSame( 'dx_reauth', $this->passwords->change( $client, $token, 'סיסמה חדשה לגמרי', '' )->get_error_code() );
		$this->assertSame( 'dx_reauth', $this->passwords->change( $client, $token, 'סיסמה חדשה לגמרי', 'לא נכונה בכלל' )->get_error_code() );
		$this->assertTrue( $this->passwords->change( $client, $token, 'סיסמה חדשה לגמרי', self::GOOD ) );
	}

	public function test_weak_passwords_are_refused(): void {
		$client = $this->member( 'dana@example.org' );
		$token  = $this->session( $client );
		$weak   = array(
			'too_short' => 'ארבעה עשר תווי',
			'too_long'  => str_repeat( 'אב', 101 ),
			'is_email'  => 'dana הכי טובה בעולם כולו',
			'pattern'   => 'abababababababab',
		);

		foreach ( $weak as $reason => $password ) {
			$result = $this->passwords->change( $client, $token, $password, '' );
			$this->assertInstanceOf( WP_Error::class, $result, $reason );
			$this->assertSame( $reason, $result->get_error_data()['fields']['password'], $reason );
		}

		$this->assertSame( 15, PasswordService::MIN_LENGTH, 'Standard 4.5.2: 15 characters when the password is the only factor.' );
		$this->assertFalse( PasswordService::has_password( $client ) );
	}

	public function test_a_leaked_password_is_refused(): void {
		remove_filter( 'dx_portal_password_breached', '__return_false' );
		$client = $this->member();
		$token  = $this->session( $client );
		$suffix = substr( strtoupper( sha1( self::GOOD ) ), 5 );
		$asked  = '';

		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( $suffix, &$asked ) {
				$asked = $url;

				return array(
					'headers'  => array(),
					'body'     => "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n" . $suffix . ":42\r\n",
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->passwords->change( $client, $token, self::GOOD, '' );

		$this->assertSame( 'breached', $result->get_error_data()['fields']['password'] );
		$this->assertSame( 'https://api.pwnedpasswords.com/range/' . substr( strtoupper( sha1( self::GOOD ) ), 0, 5 ), $asked, 'Only the first five hex digits leave the site.' );
	}

	public function test_an_unreachable_leak_check_does_not_block_the_change(): void {
		remove_filter( 'dx_portal_password_breached', '__return_false' );
		$client = $this->member();
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'offline' ) );

		$this->assertTrue( $this->passwords->change( $client, $this->session( $client ), self::GOOD, '' ) );
	}

	/**
	 * Regression (PR review): a fresh sign-in on one device must not make an
	 * older session "recent" and let it change the password.
	 */
	public function test_only_a_fresh_session_skips_the_current_password(): void {
		$client = $this->member();
		$token  = $this->session( $client );
		$this->passwords->change( $client, $token, self::GOOD, '' );

		$this->clock->advance( -HOUR_IN_SECONDS );
		$old = $this->session( $client );
		$this->clock->advance( HOUR_IN_SECONDS );
		$fresh = $this->session( $client );

		$this->assertSame( 'dx_reauth', $this->passwords->change( $client, $old, 'משפט חדש וארוך מספיק', '' )->get_error_code() );
		$this->assertSame( 'dx_reauth', $this->passwords->change( $client, '', 'משפט חדש וארוך מספיק', '' )->get_error_code() );
		$this->assertTrue( $this->passwords->change( $client, $fresh, 'משפט חדש וארוך מספיק', '' ) );
	}

	public function test_changing_the_password_ends_every_other_session(): void {
		$client   = $this->member();
		$sessions = \WP_Session_Tokens::get_instance( $client );
		$sessions->create( max( time(), $this->clock->now() ) + DAY_IN_SECONDS );
		$sessions->create( max( time(), $this->clock->now() ) + DAY_IN_SECONDS );
		$token = $this->session( $client );

		$this->assertTrue( $this->passwords->change( $client, $token, self::GOOD, '' ) );

		$this->assertLessThanOrEqual( 1, count( $sessions->get_all() ), 'Only the session of this request may remain.' );
	}

	public function test_every_failure_looks_the_same(): void {
		$client = $this->member( 'known@example.org' );
		$token  = $this->session( $client );
		$this->passwords->change( $client, $token, self::GOOD, '' );

		$wrong   = $this->passwords->check( 'known@example.org', 'לא הסיסמה הזאת' );
		$unknown = $this->passwords->check( 'nobody@example.org', self::GOOD );
		$empty   = $this->passwords->check( 'known@example.org', '' );

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
		$token  = $this->session( $client );
		$this->passwords->change( $client, $token, self::GOOD, '' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->passwords->check( 'rate@example.org', 'טעות ' . $i . ' טעות' );
		}

		$blocked = $this->passwords->check( 'rate@example.org', self::GOOD );
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'dx_login_failed', $blocked->get_error_code() );

		$this->clock->advance( 16 * MINUTE_IN_SECONDS );
		$this->assertSame( $client, $this->passwords->check( 'rate@example.org', self::GOOD )->ID );
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

		$this->assertSame( 'dx_login_failed', $this->passwords->check( 'staff@example.org', self::GOOD )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->passwords->change( $manager, $this->session( $manager ), 'סיסמה חדשה לגמרי', self::GOOD )->get_error_code() );
	}

	public function test_a_client_without_a_case_cannot_sign_in(): void {
		$client = $this->member( 'former@example.org' );
		$token  = $this->session( $client );
		$this->passwords->change( $client, $token, self::GOOD, '' );
		$this->app->members->remove_user( $client );

		$this->assertSame( 'dx_login_failed', $this->passwords->check( 'former@example.org', self::GOOD )->get_error_code() );
	}

	public function test_a_link_sign_in_counts_as_recent(): void {
		$client = $this->member( 'link@example.org' );
		$this->app->login->request_link( 'link@example.org' );
		$this->app->login->flush_deferred();
		$this->assertSame( 1, preg_match( '/(?:[?&]|&#038;)t=([A-Za-z0-9_-]{43})/', (string) $this->mails[0]['message'], $match ) );

		$this->assertSame( $client, $this->app->login->consume( $match[1], false ) );

		$sessions = array_values( \WP_Session_Tokens::get_instance( $client )->get_all() );
		$this->assertSame( $this->clock->now(), end( $sessions )[ PasswordService::SESSION_KEY ] );
	}

	/**
	 * A new session for the user, as a sign-in creates, at the test clock's time.
	 *
	 * @param int $user_id User.
	 * @return string Token.
	 */
	private function session( int $user_id ): string {
		// WordPress checks the expiry against the real time, not the frozen clock:
		// an expiry tied to the frozen date made these tests fail once that date passed.
		return \WP_Session_Tokens::get_instance( $user_id )->create( max( time(), $this->clock->now() ) + DAY_IN_SECONDS );
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
