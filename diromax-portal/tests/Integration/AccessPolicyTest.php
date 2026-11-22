<?php
/**
 * Authorization: every decision of AccessPolicy, including the negative ones.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\DocumentRecord;

final class AccessPolicyTest extends PortalTestCase {

	public function test_member_client_can_view_own_case(): void {
		$client = $this->client();
		$case   = $this->case_for( $this->manager(), $client );

		$this->assertTrue( $this->app->policy->can_view_case( $client, $case ) );
		$this->assertFalse( $this->app->policy->can_manage_case( $client, $case ) );
		$this->assertFalse( $this->app->policy->can_use_admin( $client ) );
		$this->assertTrue( $this->app->policy->is_client_only( $client ) );
	}

	public function test_client_cannot_view_another_clients_case(): void {
		$manager = $this->manager();
		$mine    = $this->client();
		$theirs  = $this->client();
		$case    = $this->case_for( $manager, $theirs );

		$this->case_for( $manager, $mine );

		$this->assertFalse( $this->app->policy->can_view_case( $mine, $case ) );
	}

	public function test_anonymous_user_sees_nothing(): void {
		$case = $this->case_for( $this->manager(), $this->client() );

		$this->assertFalse( $this->app->policy->can_view_case( 0, $case ) );
		$this->assertFalse( $this->app->policy->can_manage_case( 0, $case ) );
		$this->assertFalse( $this->app->policy->can_use_admin( 0 ) );
		$this->assertFalse( $this->app->policy->can_manage_settings( 0 ) );
		$this->assertFalse( $this->app->policy->is_client_only( 0 ) );
	}

	public function test_member_without_portal_capability_is_refused(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$case       = $this->case_for( $this->manager(), $subscriber );

		$this->assertFalse( $this->app->policy->can_view_case( $subscriber, $case ) );
	}

	public function test_manager_manages_only_assigned_cases(): void {
		$manager = $this->manager();
		$other   = $this->manager();
		$case    = $this->case_for( $manager );

		$this->assertTrue( $this->app->policy->can_manage_case( $manager, $case ) );
		$this->assertTrue( $this->app->policy->can_view_case( $manager, $case ) );
		$this->assertFalse( $this->app->policy->can_manage_case( $other, $case ) );
		$this->assertFalse( $this->app->policy->can_view_case( $other, $case ) );
		$this->assertFalse( $this->app->policy->can_manage_all( $manager ) );
		$this->assertFalse( $this->app->policy->can_manage_settings( $manager ) );
	}

	public function test_admin_manages_every_case_and_settings(): void {
		$admin = $this->admin();
		$case  = $this->case_for( $this->manager() );

		$this->assertTrue( $this->app->policy->can_manage_all( $admin ) );
		$this->assertTrue( $this->app->policy->can_manage_case( $admin, $case ) );
		$this->assertTrue( $this->app->policy->can_manage_settings( $admin ) );
		$this->assertFalse( $this->app->policy->is_client_only( $admin ) );
	}

	public function test_any_case_can_be_deleted_but_only_by_an_administrator(): void {
		$admin   = $this->admin();
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$this->assertTrue( $this->app->policy->can_delete_case( $admin, $case ), 'Open cases too (spec 19.1).' );
		$this->assertFalse( $this->app->policy->can_delete_case( $manager, $case ), 'Not even the case manager.' );
		$this->assertFalse( $this->app->policy->can_delete_case( $this->client(), $case ) );
		$this->assertFalse( $this->app->policy->can_delete_case( 0, $case ) );
	}

	public function test_client_access_ends_after_the_access_period(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );
		$closed  = $this->app->case_service->set_status( $case, 'closed', $manager );

		$this->assertTrue( $this->app->policy->can_view_case( $client, $closed ) );

		$this->clock->advance( 366 * DAY_IN_SECONDS );

		$this->assertFalse( $this->app->policy->can_view_case( $client, $closed ) );
		$this->assertTrue( $this->app->policy->can_view_case( $manager, $closed ) );
	}

	public function test_hidden_document_is_for_managers_only(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );
		$hidden  = self::document( $case->id, false );
		$shown   = self::document( $case->id, true );

		$this->assertFalse( $this->app->policy->can_download( $client, $case, $hidden ) );
		$this->assertTrue( $this->app->policy->can_download( $client, $case, $shown ) );
		$this->assertTrue( $this->app->policy->can_download( $manager, $case, $hidden ) );
	}

	public function test_document_of_another_case_is_refused_even_for_its_manager(): void {
		$manager = $this->manager();
		$case_a  = $this->case_for( $manager );
		$case_b  = $this->case_for( $manager );

		$this->assertFalse( $this->app->policy->can_download( $manager, $case_a, self::document( $case_b->id, true ) ) );
	}

	private static function document( int $case_id, bool $visible ): DocumentRecord {
		return new DocumentRecord( 1, str_repeat( 'a', 24 ), $case_id, null, 'other', 't', 't.pdf', str_repeat( 'b', 40 ) . '.bin', 'application/pdf', 1, str_repeat( 'c', 64 ), 1, $visible, '2026-11-18 10:00:00' );
	}
}
