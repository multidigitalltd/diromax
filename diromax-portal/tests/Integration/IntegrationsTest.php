<?php
/**
 * Postmark and Turnstile set up from the settings screen, secrets sealed
 * (spec 17.5, exception 6).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Actions;
use Diromax\Portal\Portal\Webhooks;
use Diromax\Portal\Security\Turnstile;
use Diromax\Portal\Service\Postmark;
use Diromax\Portal\Support\Integrations;
use Diromax\Portal\Support\MailConfig;
use WP_Error;
use WP_REST_Request;

final class IntegrationsTest extends PortalTestCase {

	/** A Postmark server token as the tests use it. */
	private const TOKEN = '1a2b3c4d-5e6f-7a8b-9c0d-ef1234567890';

	/**
	 * The option as stored, as text.
	 *
	 * @return string
	 */
	private static function raw(): string {
		return (string) wp_json_encode( get_option( Integrations::OPTION ) );
	}

	/**
	 * A webhook request with Basic credentials.
	 *
	 * @param string $password Password.
	 * @return WP_REST_Request
	 */
	private static function request( string $password ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Webhooks::NAMESPACE . '/postmark/events' );
		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'postmark:' . $password ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.

		return $request;
	}

	public function test_the_token_is_sealed_in_the_database_and_used_to_send(): void {
		$this->assertFalse( Postmark::configured() );

		$changed = Integrations::save(
			array(
				'token'   => self::TOKEN,
				'from'    => 'portal@diromax.co.il',
				'stream'  => 'outbound',
				'inbound' => 'abc123@inbound.postmarkapp.com',
			)
		);

		$this->assertSame( array( 'token', 'from', 'stream', 'inbound' ), $changed );
		$this->assertStringNotContainsString( self::TOKEN, self::raw(), 'Never stored in the clear.' );
		$this->assertStringContainsString( 'portal@diromax.co.il', self::raw(), 'Settings that are not secret are plain.' );
		$this->assertSame( self::TOKEN, MailConfig::get( 'token' ) );
		$this->assertTrue( Postmark::configured() );
		$this->assertFalse( Integrations::locked( 'token' ) );
	}

	public function test_an_empty_secret_keeps_it_clear_removes_it_and_bad_input_saves_nothing(): void {
		Integrations::save( array( 'token' => self::TOKEN ) );

		$this->assertSame( array(), Integrations::save( array( 'token' => '' ) ), 'Empty field: no change.' );
		$this->assertSame( self::TOKEN, Integrations::get( 'token' ) );
		$this->assertSame( array(), Integrations::save( array( 'token' => self::TOKEN ) ), 'The same token again is no change.' );

		$bad = Integrations::save(
			array(
				'from'   => 'not an address',
				'stream' => 'Has Spaces',
				'token'  => 'short',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame(
			array(
				'token'  => 'invalid',
				'from'   => 'invalid',
				'stream' => 'invalid',
			),
			$bad->get_error_data()['fields']
		);
		$this->assertSame( self::TOKEN, Integrations::get( 'token' ), 'Nothing saved on an error.' );

		$this->assertSame( array( 'token' ), Integrations::save( array( 'token_clear' => '1' ) ) );
		$this->assertFalse( Integrations::is_set( 'token' ) );
	}

	public function test_the_sender_name_is_saved_and_a_name_that_could_break_a_header_is_refused(): void {
		$this->assertSame( 'דירומקס', MailConfig::sender_name(), 'Empty: the brand.' );
		$this->assertSame( array( 'from_name' ), Integrations::save( array( 'from_name' => 'דירומקס בע״מ' ) ), 'Hebrew, with gershayim.' );
		$this->assertSame( 'דירומקס בע״מ', MailConfig::sender_name() );
		$this->assertSame( array( 'from_name' ), Integrations::save( array( 'from_name' => str_repeat( 'א', Integrations::MAX_FROM_NAME ) ) ) );

		foreach ( array( 'דירומקס <x@example.org>', 'דירומקס, בע"מ', 'office@diromax', 'a;b', 'a\\b', str_repeat( 'א', Integrations::MAX_FROM_NAME + 1 ) ) as $bad ) {
			$result = Integrations::save( array( 'from_name' => $bad ) );
			$this->assertInstanceOf( WP_Error::class, $result, $bad );
			$this->assertSame( array( 'from_name' => 'invalid' ), $result->get_error_data()['fields'], $bad );
		}

		$this->assertSame( str_repeat( 'א', Integrations::MAX_FROM_NAME ), MailConfig::sender_name(), 'Nothing saved on an error.' );
		$this->assertSame( array( 'from_name' ), Integrations::save( array( 'from_name' => '' ) ) );
		$this->assertSame( 'דירומקס', MailConfig::sender_name(), 'Cleared: the brand again.' );
	}

	public function test_the_webhook_key_is_shown_once_and_kept_only_as_a_hash(): void {
		$hooks = new Webhooks( $this->app );
		$this->assertFalse( Webhooks::configured() );

		$first = Integrations::new_webhook_key();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $first );
		$this->assertStringNotContainsString( $first, self::raw() );
		$this->assertStringContainsString( hash( 'sha256', $first ), self::raw() );
		$this->assertTrue( $hooks->authorized( self::request( $first ) ) );
		$this->assertInstanceOf( WP_Error::class, $hooks->authorized( self::request( 'not-the-key' ) ) );

		$second = Integrations::new_webhook_key();
		$this->assertInstanceOf( WP_Error::class, $hooks->authorized( self::request( $first ) ), 'A new key ends the old one.' );
		$this->assertTrue( $hooks->authorized( self::request( $second ) ) );
	}

	public function test_turnstile_keys_reach_the_portal_and_the_theme(): void {
		$this->assertFalse( Turnstile::enabled() );
		$this->assertFalse( dx_turnstile_enabled() );

		Integrations::save(
			array(
				'turnstile_site'   => 'site-key-for-tests-0000',
				'turnstile_secret' => '0x4AAAAAAA-secret-for-tests',
			)
		);

		$this->assertTrue( Turnstile::enabled() );
		$this->assertSame( 'site-key-for-tests-0000', Turnstile::site_key() );
		$this->assertTrue( dx_turnstile_enabled(), 'The lead form uses the same pair.' );
		$this->assertSame( '0x4AAAAAAA-secret-for-tests', dx_turnstile_secret() );
		$this->assertStringNotContainsString( 'secret-for-tests', self::raw() );
	}

	public function test_an_unreadable_turnstile_secret_keeps_the_forms_closed(): void {
		Integrations::save( array( 'turnstile_site' => 'site-key-for-tests-0000' ) );
		$values                     = get_option( Integrations::OPTION );
		$values['turnstile_secret'] = $this->app->store->seal( 'another-secret-0000', 'integration|token' );
		update_option( Integrations::OPTION, $values, false );

		$calls = 0;
		$count = static function ( $preempt ) use ( &$calls ) {
			++$calls;
			return $preempt;
		};
		add_filter( 'pre_http_request', $count );

		$this->assertTrue( Integrations::unreadable( 'turnstile_secret' ) );
		$this->assertTrue( Turnstile::enabled(), 'Still protected: the form asks for the widget.' );
		$this->assertFalse( Turnstile::verify( 'a-token' ), 'And refuses every answer.' );
		$this->assertTrue( dx_turnstile_enabled(), 'The lead form too.' );
		$this->assertFalse( dx_turnstile_verify( 'a-token', 'dx_lead' ) );
		$this->assertSame( 0, $calls, 'Cloudflare is not asked without a secret.' );

		remove_filter( 'pre_http_request', $count );
	}

	public function test_a_secret_sealed_for_another_field_or_key_does_not_open(): void {
		update_option(
			Integrations::OPTION,
			array( 'token' => $this->app->store->seal( self::TOKEN, 'integration|turnstile_secret' ) ),
			false
		);

		$this->assertSame( '', Integrations::get( 'token' ) );
		$this->assertTrue( Integrations::unreadable( 'token' ), 'The screen asks for it again.' );
	}

	public function test_only_an_administrator_with_a_valid_form_may_save(): void {
		wp_set_current_user( $this->manager() );
		$nonce                     = wp_create_nonce( 'dx_portal_save_integrations' );
		$_POST['integrations']     = array( 'token' => self::TOKEN );
		$_REQUEST['_wpnonce']      = $nonce;
		$_POST['_wpnonce']         = $nonce;
		$_POST['_wp_http_referer'] = '/wp-admin/admin.php?page=dx-portal-settings';

		try {
			( new Actions( $this->app ) )->save_integrations();
			$this->fail( 'A project manager must not change the integrations.' );
		} catch ( \WPDieException $error ) {
			$this->assertStringContainsString( 'אין הרשאה', $error->getMessage() );
		} finally {
			unset( $_POST['integrations'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['_wp_http_referer'] );
		}

		$this->assertFalse( Integrations::is_set( 'token' ) );

		wp_set_current_user( $this->admin() );
		$_REQUEST['_wpnonce'] = 'forged';

		try {
			( new Actions( $this->app ) )->new_webhook_key();
			$this->fail( 'A forged nonce must be refused.' );
		} catch ( \WPDieException $error ) {
			$this->assertFalse( Webhooks::configured() );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}
}
