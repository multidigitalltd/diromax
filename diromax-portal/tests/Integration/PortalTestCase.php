<?php
/**
 * Base class for integration tests.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Plugin;
use Diromax\Portal\Security\Capabilities;
use Diromax\Portal\Support\Clock;

/**
 * A fresh object graph with a frozen clock, and helpers to build users and cases.
 */
abstract class PortalTestCase extends \WP_UnitTestCase {

	/** 2026-11-18 10:00:00 UTC. */
	protected const NOW = 1795082400;

	protected Plugin $app;

	protected Clock $clock;

	/**
	 * Captured emails.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->clock = new Clock( self::NOW );
		$this->app   = Plugin::reset( $this->clock );
		$this->mails = array();

		reset_phpmailer_instance();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		parent::tear_down();
	}

	/**
	 * Capture instead of sending; the AltBody hook still runs through a real PHPMailer below.
	 *
	 * @param null|bool            $short Short-circuit value.
	 * @param array<string, mixed> $atts  wp_mail arguments.
	 * @return bool
	 */
	public function capture_mail( $short, array $atts ): bool {
		if ( null !== $short ) {
			return (bool) $short;
		}

		$this->mails[] = $atts;

		return true;
	}

	protected function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_role( 'administrator' )->add_cap( Capabilities::MANAGE_CASES );

		return $id;
	}

	protected function manager(): int {
		return self::factory()->user->create(
			array(
				'role'         => Capabilities::ROLE_MANAGER,
				'display_name' => 'דניאל לוי',
			)
		);
	}

	protected function client( string $email = '' ): int {
		return self::factory()->user->create(
			array(
				'role'         => Capabilities::ROLE_CLIENT,
				'user_email'   => '' === $email ? 'client' . wp_rand() . '@example.org' : $email,
				'display_name' => 'משפחת כהן',
			)
		);
	}

	/**
	 * A case managed by the given manager, with the given client as a member.
	 */
	protected function case_for( int $manager, ?int $client = null ): CaseRecord {
		$case = $this->app->case_service->create(
			array(
				'title' => 'רח׳ הזית 14, קריית אונו',
				'scope' => 'הוספת יחידת דיור על הגג',
			),
			$manager
		);
		$this->assertInstanceOf( CaseRecord::class, $case );

		if ( null !== $client ) {
			$this->app->members->upsert( $case->id, $client, \Diromax\Portal\Domain\MemberRole::Owner, true, $this->clock->mysql() );
		}

		return $case;
	}

	/**
	 * Expense input with overrides.
	 *
	 * @param array<string, string> $overrides Fields.
	 * @return array<string, string>
	 */
	protected static function expense( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'        => 'expense',
				'status'      => 'paid',
				'entry_date'  => '2026-11-12',
				'amount'      => '12300',
				'vat_mode'    => 'included',
				'category'    => 'fees',
				'payee'       => 'הוועדה המקומית',
				'description' => 'אגרת בקשה להיתר',
				'funding'     => 'project',
				'visible'     => '1',
				'notify'      => '1',
			),
			$overrides
		);
	}
}
