<?php
/**
 * Release hygiene and the 1.4.0 additions: versions, cache-busting asset
 * URLs, two-factor through either plugin, AIOS compatibility of the theme,
 * and the case setup checklist.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\Pages;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Service\UpdateService;
use Diromax\Portal\Support\AssetFile;

final class ReleaseTest extends PortalTestCase {

	/**
	 * Files this test wrote under the plugin, removed afterwards.
	 *
	 * @var string[]
	 */
	private array $written = array();

	public function tear_down(): void {
		foreach ( $this->written as $file ) {
			wp_delete_file( $file );
		}

		unset( $GLOBALS['simba_two_factor_authentication'] );
		parent::tear_down();
	}

	public function test_the_version_in_the_header_is_the_version_in_the_code(): void {
		$plugin = get_file_data( DX_PORTAL_FILE, array( 'version' => 'Version' ) );
		$theme  = get_file_data( DX_DIR . '/style.css', array( 'version' => 'Version' ) );

		$this->assertSame( DX_PORTAL_VERSION, $plugin['version'] );
		$this->assertSame( DX_VERSION, $theme['version'] );
	}

	/**
	 * Regression: assets went out as ?ver=1.0.0 forever, so an updated
	 * stylesheet stayed cached in browsers and page caches.
	 */
	public function test_an_asset_url_changes_when_the_file_changes(): void {
		$relative        = 'assets/css/zz-test-' . wp_rand() . '.css';
		$this->written[] = DX_PORTAL_DIR . $relative;

		file_put_contents( DX_PORTAL_DIR . $relative, 'a{color:red}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$first = AssetFile::version( $relative );

		$this->assertMatchesRegularExpression( '/^' . preg_quote( DX_PORTAL_VERSION, '/' ) . '-[a-f0-9]{8}$/', $first );
		$this->assertSame( DX_PORTAL_VERSION . '-' . substr( (string) md5_file( DX_PORTAL_DIR . $relative ), 0, 8 ), $first );
		$this->assertSame( 'assets/css/admin.min.css', AssetFile::path( 'admin', 'css' ) );
		$this->assertStringStartsWith( DX_VERSION . '-', dx_asset_version( 'style.min.css', 'style.css' ), 'The theme does the same, with its own version.' );
		$this->assertNotSame( dx_asset_version( 'style.min.css', 'style.css' ), dx_asset_version( 'assets/js/privacy.min.js', 'assets/js/privacy.js' ) );
	}

	public function test_the_built_in_email_code_is_the_second_step_and_other_plugins_are_flagged(): void {
		$manager = $this->manager();

		$this->assertSame( 'email', Admin::two_factor_status( $manager ), 'Built in: no plugin needed.' );
		$this->assertNotContains( 'warning', array_column( ( new Admin( $this->app ) )->config_notices( $manager ), 0 ) );

		$GLOBALS['simba_two_factor_authentication'] = new class() {
			public function is_activated_for_user( int $user_id ): bool {
				return $user_id > 0;
			}

			public function is_activated_by_user( int $user_id ): bool {
				return $user_id > 0;
			}
		};

		$this->assertSame( 'other', Admin::two_factor_status( $manager ), 'AIOS two-factor on top: suggest turning it off.' );
		$this->assertContains( 'info', array_column( ( new Admin( $this->app ) )->config_notices( $manager ), 0 ) );
		unset( $GLOBALS['simba_two_factor_authentication'] );

		add_filter( 'dx_portal_email_code_enabled', '__return_false' );
		$this->assertSame( 'off', Admin::two_factor_status( $manager ) );
		$this->assertContains( 'error', array_column( ( new Admin( $this->app ) )->config_notices( $manager ), 0 ), 'The emergency switch is loud.' );
		remove_filter( 'dx_portal_email_code_enabled', '__return_false' );
	}

	public function test_the_lead_form_route_stays_open_under_the_aios_rest_lockdown(): void {
		$routes = apply_filters( 'aios_whitelisted_rest_routes', array( 'wc' ) );

		$this->assertContains( 'wc', $routes, 'Routes the owner whitelisted are kept.' );
		$this->assertContains( 'diromax', $routes );
		$from_junk = apply_filters( 'aios_whitelisted_rest_routes', 'not an array' );
		$this->assertContains( 'diromax', $from_junk );
		$this->assertContains( '/diromax', $from_junk );
		$this->assertContains( 'diromax-portal', $from_junk, '1.7.0: the Postmark webhooks too.' );
		$this->assertSame( $from_junk, array_values( array_unique( array_filter( $from_junk, 'is_string' ) ) ), 'Strings only, once each.' );
	}

	/**
	 * Regression: a manual reminder on a case with no client who gets email
	 * was recorded and reported as sent, though it reached no one.
	 */
	public function test_a_reminder_with_no_one_to_receive_it_is_refused(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$request = $this->app->ledger_service->add(
			$case,
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-01',
				'due_date'    => '2026-11-10',
				'amount'      => '1000',
				'description' => 'מקדמה',
				'pay_to'      => 'diromax',
				'visible'     => '1',
			),
			$manager
		);
		$this->assertIsInt( $request );

		$result = ( new \Diromax\Portal\Service\ReminderService( $this->app ) )->send_now( $case, $request, $manager );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dx_no_recipients', $result->get_error_code() );
		$this->assertSame( 0, $this->app->ledger->find( $request )?->reminders_sent, 'Not counted as sent.' );

		$client = $this->client();
		$this->app->members->upsert( $case->id, $client, MemberRole::Owner, true, $this->clock->mysql() );
		$this->assertTrue( ( new \Diromax\Portal\Service\ReminderService( $this->app ) )->send_now( $case, $request, $manager ), 'With a client, it goes.' );
	}

	public function test_the_setup_checklist_follows_the_case(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$pages   = new Pages( $this->app );
		$done    = static fn( array $steps ): array => array_column( $steps, 'done' );

		$this->assertSame( array( false, false, false, false, false ), $done( $pages->setup_steps( $case ) ) );

		$this->assertTrue( $this->app->case_service->save_budget( $case, array( 'fees.planned' => '1000' ), $manager ) );
		$client = $this->client();
		$this->app->members->upsert( $case->id, $client, MemberRole::Owner, true, $this->clock->mysql() );
		$this->add_document( $case, $manager, 'agreement' );
		$this->assertIsInt(
			( new UpdateService( $this->app ) )->publish(
				$case,
				array(
					'title'   => 'התחלנו',
					'body'    => 'x',
					'stage'   => '',
					'visible' => '1',
					'notify'  => '',
				),
				array(),
				$manager
			)
		);

		$this->assertSame( array( true, true, false, true, true ), $done( $pages->setup_steps( $case ) ), 'Only the client signing in is left.' );
		$this->assertStringContainsString( 'tab=members', $pages->setup_steps( $case )[2]['url'] );
	}
}
