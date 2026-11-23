<?php
/**
 * A code by email on every password sign-in (decision 16).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Plugin;
use Diromax\Portal\Security\LoginGate;
use Diromax\Portal\Service\LoginCodeService;
use Diromax\Portal\Support\Clock;
use WP_Error;
use WP_User;

final class LoginCodeTest extends PortalTestCase {

	public function tear_down(): void {
		remove_all_filters( 'dx_portal_email_code_enabled' );
		parent::tear_down();
	}

	/**
	 * The code in the latest code email.
	 *
	 * @return string
	 */
	private function code(): string {
		foreach ( array_reverse( $this->mails ) as $mail ) {
			if ( preg_match( '/קוד כניסה: (\d{6})/u', (string) $mail['subject'], $match ) ) {
				return $match[1];
			}
		}

		$this->fail( 'No code was emailed.' );
	}

	/**
	 * A staff user.
	 *
	 * @return WP_User
	 */
	private function staff(): WP_User {
		$user = get_userdata( $this->manager() );
		$this->assertInstanceOf( WP_User::class, $user );

		return $user;
	}

	public function test_the_right_code_completes_the_sign_in(): void {
		$user  = $this->staff();
		$codes = new LoginCodeService( $this->app );
		$token = $codes->start( $user, true, 'https://example.org/portal/manage/', 'wp-login' );

		$this->assertIsString( $token );
		$this->assertSame( (string) $user->user_email, $this->mails[0]['to'] );
		$this->assertStringContainsString( '•••@', $codes->masked_email( $token ) );

		$result = $codes->verify( $token, $this->code() );
		$this->assertIsArray( $result );
		$this->assertSame( $user->ID, $result['user']->ID );
		$this->assertTrue( $result['remember'] );
		$this->assertSame( 'wp-login', $result['via'] );
		$this->assertInstanceOf( WP_Error::class, $codes->verify( $token, $this->code() ), 'A code signs in once.' );
	}

	public function test_five_wrong_codes_end_the_challenge(): void {
		$codes = new LoginCodeService( $this->app );
		$token = $codes->start( $this->staff(), false, '', 'portal' );
		$this->assertIsString( $token );
		$right = $this->code();
		$wrong = str_pad( (string) ( ( (int) $right + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT );

		for ( $i = 1; $i < LoginCodeService::ATTEMPTS; $i++ ) {
			$this->assertSame( 'dx_code_wrong', $codes->verify( $token, $wrong )->get_error_code() );
		}

		$this->assertSame( 'dx_code_expired', $codes->verify( $token, $wrong )->get_error_code() );
		$this->assertSame( 'dx_code_expired', $codes->verify( $token, $right )->get_error_code(), 'Even the right code: sign in again.' );
	}

	public function test_a_code_lasts_ten_minutes_and_new_codes_are_limited(): void {
		$codes = new LoginCodeService( $this->app );
		$token = $codes->start( $this->staff(), false, '', 'portal' );
		$this->assertIsString( $token );
		$first = $this->code();

		$later = new LoginCodeService( Plugin::reset( new Clock( self::NOW + LoginCodeService::TTL + 1 ) ) );
		$this->assertSame( 'dx_code_expired', $later->verify( $token, $first )->get_error_code() );

		$this->app = Plugin::reset( $this->clock );
		$codes     = new LoginCodeService( $this->app );
		$this->assertTrue( $codes->resend( $token ) );
		$this->assertTrue( $codes->resend( $token ) );
		$this->assertSame( 'dx_rate_limited', $codes->resend( $token )->get_error_code(), 'Three codes per quarter hour.' );
		$this->assertSame( 'dx_code_wrong', $codes->verify( $token, $first )->get_error_code(), 'A new code replaces the old one.' );
		$this->assertIsArray( $codes->verify( $token, $this->code() ) );
	}

	public function test_tokens_are_checked(): void {
		$codes = new LoginCodeService( $this->app );

		$this->assertSame( 'dx_code_expired', $codes->verify( 'nonsense', '123456' )->get_error_code() );
		$this->assertSame( 'dx_code_expired', $codes->verify( str_repeat( 'A', 43 ), '123456' )->get_error_code() );
		$this->assertSame( '', $codes->masked_email( str_repeat( 'A', 43 ) ) );
	}

	public function test_wp_login_stops_after_the_password_and_nothing_else_does(): void {
		$gate = new LoginGate( $this->app );
		$user = $this->staff();

		$this->assertSame( $user, $gate->authenticate( $user, 'x', 'y' ), 'Outside wp-login.php (XML-RPC, application passwords) nothing changes.' );
		$error = new WP_Error( 'incorrect_password' );
		$this->assertSame( $error, $gate->authenticate( $error, 'x', 'y' ), 'A wrong password stays a wrong password.' );
		$this->assertNull( $gate->authenticate( null, '', '' ) );
	}

	public function test_the_emergency_switch_lets_the_password_alone_through(): void {
		$this->assertTrue( LoginCodeService::enabled() );

		add_filter( 'dx_portal_email_code_enabled', '__return_false' );
		$this->assertFalse( LoginCodeService::enabled() );

		$GLOBALS['wp_actions']['login_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As on wp-login.php, without running core's screen setup; restored after each test.
		$user                                = $this->staff();
		$this->assertSame( $user, ( new LoginGate( $this->app ) )->authenticate( $user, 'x', 'y' ), 'Switch on: no code.' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_code_email_says_what_it_is_for(): void {
		$message = $this->app->renderer->login_code( 'דניאל', '012345' );

		$this->assertStringContainsString( '012345', $message->subject );
		$this->assertStringContainsString( '012345', $message->html );
		$this->assertStringContainsString( '10 דקות', $message->text );
	}

	/**
	 * 1.14.4: the footer of every email names the office's line of work as
	 * the office asked, without "תיווך".
	 */
	public function test_the_email_footer_has_the_office_tagline(): void {
		$html = $this->app->renderer->login_code( 'דניאל', '012345' )->html;

		$this->assertStringContainsString( 'דירומקס · יזמות וניהול השבחת נכסים', $html );
		$this->assertStringNotContainsString( 'תיווך', $html );
	}
}
