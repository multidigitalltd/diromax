<?php
/**
 * Deleting a case, open or closed, with the client accounts that were only
 * there (spec 19.1).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\Schema;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Service\LeadLink;

final class CaseDeletionTest extends PortalTestCase {

	public function test_an_open_case_is_deleted_with_the_accounts_that_were_only_its(): void {
		global $wpdb;

		$admin   = $this->admin();
		$manager = $this->manager();
		$only    = $this->client( 'only@example.org' );
		$shared  = $this->client( 'shared@example.org' );
		$case    = $this->case_for( $manager, $only );
		$other   = $this->case_for( $manager, $shared );
		$this->app->members->upsert( $case->id, $shared, MemberRole::Partner, true, $this->clock->mysql() );
		$this->app->members->upsert( $case->id, $manager, MemberRole::Viewer, true, $this->clock->mysql() );
		$this->assertIsInt( $this->app->ledger_service->add( $case, self::expense(), $manager ) );
		$document = $this->add_document( $case, $manager );

		$preview = $this->app->case_service->deletion_preview( $case, $admin );
		$this->assertSame( 1, $preview['entries'] );
		$this->assertSame( 1, $preview['documents'] );
		$this->assertSame( array( 'only@example.org' ), array_column( $preview['accounts'], 'email' ), 'A client with another case, and staff, stay.' );

		$this->assertSame( 1, $this->app->case_service->delete( $case, $admin ) );

		$this->assertNull( $this->app->cases->find( $case->id ) );
		$this->assertFalse( get_userdata( $only ), 'The account that was only in this case is gone.' );
		$this->assertNotFalse( get_userdata( $shared ) );
		$this->assertTrue( $this->app->members->is_member( $other->id, $shared ) );
		$this->assertNotFalse( get_userdata( $manager ), 'Staff are never deleted.' );
		$this->assertFileDoesNotExist( sys_get_temp_dir() . '/dx-portal-test-store/' . $document->stored_name );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'budget' ), $case->id ) ) );

		$audit = $wpdb->get_row( $wpdb->prepare( "SELECT case_id, object_id, details FROM %i WHERE action = 'case.deleted' ORDER BY id DESC LIMIT 1", Schema::table( 'audit' ) ), ARRAY_A );
		$this->assertNull( $audit['case_id'] );
		$this->assertSame( (string) $case->id, $audit['object_id'] );
		$this->assertSame(
			array(
				'number'   => $case->number,
				'status'   => 'active',
				'accounts' => 1,
			),
			json_decode( (string) $audit['details'], true ),
			'Who and when, the number: no names or addresses.'
		);
	}

	public function test_nobody_but_an_administrator_can_delete(): void {
		$manager = $this->manager();
		$client  = $this->client();
		$case    = $this->case_for( $manager, $client );

		foreach ( array( $manager, $client, $this->manager(), 0 ) as $user ) {
			$result = $this->app->case_service->delete( $case, $user );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'dx_forbidden', $result->get_error_code() );
		}

		$this->assertNotNull( $this->app->cases->find( $case->id ) );
		$this->assertNotFalse( get_userdata( $client ) );
	}

	public function test_an_administrator_who_is_also_a_member_is_not_deleted(): void {
		$admin = $this->admin();
		$case  = $this->case_for( $this->manager() );
		$this->app->members->upsert( $case->id, $admin, MemberRole::Owner, true, $this->clock->mysql() );

		$this->assertSame( array(), $this->app->case_service->deletion_preview( $case, $admin )['accounts'] );
		$this->assertSame( 0, $this->app->case_service->delete( $case, $admin ) );
		$this->assertNotFalse( get_userdata( $admin ) );
	}

	public function test_the_lead_can_open_a_new_case_after_its_case_is_deleted(): void {
		$admin   = $this->admin();
		$manager = $this->manager();
		$lead    = self::factory()->post->create(
			array(
				'post_type'   => 'dx_lead',
				'post_status' => 'private',
			)
		);
		$case    = $this->case_for( $manager );
		( new LeadLink( $this->app ) )->link( $lead, $case );
		$this->app->cases->update( $case->id, array( 'lead_id' => $lead ), $this->clock->mysql() );
		$case = $this->app->cases->find( $case->id );
		$this->assertNotNull( $case );

		$this->assertSame( 0, $this->app->case_service->delete( $case, $admin ) );
		$this->assertSame( '', (string) get_post_meta( $lead, LeadLink::META, true ) );
	}
}
