<?php
/**
 * Cases, members, stages, budget, deletion; schema and retention.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Schema;
use Diromax\Portal\Security\Capabilities;
use Diromax\Portal\Service\Retention;

final class CaseServiceTest extends PortalTestCase {

	public function test_manager_opens_a_case_assigned_to_themself(): void {
		$manager = $this->manager();
		$other   = $this->manager();
		$case    = $this->app->case_service->create(
			array(
				'title'      => 'רחוב הדוגמה 1',
				'manager_id' => (string) $other,
			),
			$manager
		);

		$this->assertInstanceOf( CaseRecord::class, $case );
		$this->assertSame( $manager, $case->manager_id );
		$this->assertMatchesRegularExpression( '/^2026-\d{3,}$/', $case->number );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{24}$/', $case->public_id );
		$this->assertSame( 1, $case->stage );
	}

	public function test_admin_assigns_a_manager_but_not_a_client(): void {
		$admin   = $this->admin();
		$manager = $this->manager();
		$client  = $this->client();

		$case = $this->app->case_service->create(
			array(
				'title'      => 'א',
				'manager_id' => (string) $manager,
			),
			$admin
		);
		$this->assertSame( $manager, $case->manager_id );

		$bad = $this->app->case_service->create(
			array(
				'title'      => 'ב',
				'manager_id' => (string) $client,
			),
			$admin
		);
		$this->assertSame( array( 'manager_id' => 'invalid' ), $bad->get_error_data()['fields'] );
	}

	public function test_client_cannot_open_or_change_cases(): void {
		$client = $this->client();
		$case   = $this->case_for( $this->manager(), $client );

		$this->assertSame( 'dx_forbidden', $this->app->case_service->create( array( 'title' => 'x' ), $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->case_service->update_details( $case, array( 'title' => 'x' ), $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->case_service->set_status( $case, 'closed', $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->case_service->save_budget( $case, array(), $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->case_service->add_member( $case, array(), $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->case_service->remove_member( $case, $client, $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->case_service->delete( $case, $client )->get_error_code() );
	}

	public function test_details_and_stages_are_saved_and_audited(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$updated = $this->app->case_service->update_details(
			$case,
			array(
				'title'        => 'רח׳ הזית 14, קריית אונו',
				'track'        => 'מימון מתוך הפרויקט',
				'fee_terms'    => '10% מהתקציב',
				'signed_on'    => '2026-07-03',
				'stage_status' => '1:done,2:done,3:active,4:skipped,5:active',
			),
			$manager
		);

		$this->assertInstanceOf( CaseRecord::class, $updated );
		$this->assertSame( 5, $updated->stage, 'The furthest stage in progress leads.' );
		$this->assertSame( 'done', $updated->stages[2]['status'] );
		$this->assertSame( 'active', $updated->stages[3]['status'], 'Two stages in progress at once.' );
		$this->assertSame( 'skipped', $updated->stages[4]['status'] );
		$this->assertSame( 'active', $updated->stages[5]['status'] );
		$this->assertSame( 'pending', $updated->stages[6]['status'], 'Stages left out keep their status.' );
		$this->assertSame( $this->clock->today(), $updated->stages[5]['started'] );
		$this->assertSame( '2026-07-03', $updated->signed_on );

		$log = $this->app->audit->for_case( $case->id );
		$this->assertSame( 'case.updated', $log[0]['action'] );
		$this->assertSame( array( 1, 5 ), $log[0]['details']['stage'] );
	}

	public function test_a_progress_update_moves_on_or_starts_a_stage_alongside(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$moved   = $this->app->case_service->move_to_stage( $case, 3, false, $manager );

		$this->assertInstanceOf( CaseRecord::class, $moved );
		$this->assertSame( array( 'done', 'done', 'active', 'pending' ), array_slice( array_column( $moved->stages, 'status' ), 0, 4 ) );

		$parallel = $this->app->case_service->move_to_stage( $moved, 4, true, $manager );

		$this->assertInstanceOf( CaseRecord::class, $parallel );
		$this->assertSame( array( 'done', 'done', 'active', 'active' ), array_slice( array_column( $parallel->stages, 'status' ), 0, 4 ), 'Stage 3 stays in progress.' );
		$this->assertSame( 4, $parallel->stage );

		$count = count( $this->app->audit->for_case( $case->id ) );
		$this->assertEquals( $parallel, $this->app->case_service->move_to_stage( $parallel, 4, true, $manager ), 'Nothing changes, nothing is saved.' );

		$this->assertCount( $count, $this->app->audit->for_case( $case->id ) );

		$stale = $this->app->case_service->move_to_stage( $moved, 5, true, $manager );
		$this->assertInstanceOf( CaseRecord::class, $stale );
		$this->assertSame( array( 'active', 'active', 'active' ), array_slice( array_column( $stale->stages, 'status' ), 2, 3 ), 'Regression: an older record of the case did not undo stage 4.' );

		$this->assertSame( 'dx_forbidden', $this->app->case_service->move_to_stage( $parallel, 6, true, $this->client() )->get_error_code() );
	}

	public function test_invalid_details_are_reported(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$result  = $this->app->case_service->update_details(
			$case,
			array(
				'title'        => '',
				'signed_on'    => '31/12/2026',
				'stage_status' => '9:active',
			),
			$manager
		);

		$this->assertSame(
			array(
				'title'     => 'required',
				'signed_on' => 'invalid',
				'stages'    => 'invalid',
			),
			$result->get_error_data()['fields']
		);
		$this->assertSame( 'invalid', $this->app->case_service->set_status( $case, 'archived', $manager )->get_error_data()['fields']['status'] );
	}

	public function test_member_account_is_created_once_and_staff_emails_are_refused(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$input   = array(
			'email'  => 'heir@example.org',
			'name'   => 'יורש',
			'role'   => 'partner',
			'notify' => '1',
		);

		$first  = $this->app->case_service->add_member( $case, $input, $manager );
		$second = $this->app->case_service->add_member( $case, $input, $manager );

		$this->assertSame( $first, $second );
		$this->assertTrue( user_can( $first, Capabilities::VIEW_PORTAL ) );
		$this->assertFalse( user_can( $first, 'edit_posts' ) );
		$this->assertCount( 1, $this->app->members->for_case( $case->id ) );

		$staff = get_userdata( $manager )->user_email;
		$error = $this->app->case_service->add_member( $case, array_merge( $input, array( 'email' => $staff ) ), $manager );
		$this->assertSame( array( 'email' => 'staff_account' ), $error->get_error_data()['fields'] );

		$this->assertSame(
			'dx_invalid',
			$this->app->case_service->add_member(
				$case,
				array(
					'email' => 'bad',
					'name'  => '',
					'role'  => 'king',
				),
				$manager
			)->get_error_code()
		);

		$this->assertTrue( $this->app->case_service->remove_member( $case, $first, $manager ) );
		$this->assertFalse( $this->app->members->is_member( $case->id, $first ) );
	}

	public function test_budget_rejects_bad_amounts_and_drops_empty_lines(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$this->assertSame( array( 'fees' => 'invalid' ), $this->app->case_service->save_budget( $case, array( 'fees' => 'abc' ), $manager )->get_error_data()['fields'] );
		$this->assertTrue(
			$this->app->case_service->save_budget(
				$case,
				array(
					'fees'    => '42,000',
					'reserve' => '',
					'unknown' => '999',
				),
				$manager
			)
		);
		$this->assertSame( array( 'fees' => 4200000 ), $this->app->budget->planned( $case->id ) );
	}

	public function test_deleting_a_closed_case_removes_its_rows_and_files(): void {
		global $wpdb;

		add_filter( 'dx_portal_is_uploaded_file', '__return_true' );
		$admin   = $this->admin();
		$manager = $this->manager();
		$case    = $this->case_for( $manager, $this->client() );
		$path    = (string) tempnam( sys_get_temp_dir(), 'dxt' );
		file_put_contents( $path, "%PDF-1.4\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture.
		$document = $this->app->document_service->upload(
			$case,
			array(
				'name'     => 'a.pdf',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => 15,
			),
			'other',
			'',
			true,
			$manager,
			false
		);
		remove_filter( 'dx_portal_is_uploaded_file', '__return_true' );
		$this->app->ledger_service->add( $case, self::expense(), $manager );

		$closed = $this->app->case_service->set_status( $case, 'closed', $manager );
		$this->assertTrue( $this->app->case_service->delete( $closed, $admin ) );

		$this->assertNull( $this->app->cases->find( $case->id ) );
		$this->assertFileDoesNotExist( sys_get_temp_dir() . '/dx-portal-test-store/' . $document->stored_name );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'ledger' ), $case->id ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d', Schema::table( 'case_members' ), $case->id ) ) );
	}

	public function test_schema_has_foreign_keys(): void {
		global $wpdb;

		$count = static fn( string $type ): int => (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = %s AND TABLE_NAME LIKE %s',
				$type,
				$wpdb->esc_like( $wpdb->prefix . 'dx_' ) . '%'
			)
		);

		$this->assertSame( 17, $count( 'FOREIGN KEY' ) );
		$this->assertSame( 4, $count( 'CHECK' ) );
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}

	public function test_retention_purges_old_tokens_and_logs(): void {
		global $wpdb;

		$client = $this->client();
		$this->app->tokens->add( $client, 'login', str_repeat( 'a', 64 ), '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
		$this->app->tokens->add( $client, 'login', str_repeat( 'b', 64 ), '2026-12-01 00:00:00', '2026-11-18 00:00:00' );
		$this->app->mail_log->add(
			array(
				'case_id'   => null,
				'user_id'   => $client,
				'recipient' => 'x@example.org',
				'template'  => 'login',
				'subject'   => 's',
				'batch_key' => '',
				'status'    => 'sent',
				'error'     => '',
			),
			'2024-01-01 00:00:00'
		);

		$result = ( new Retention( $this->app ) )->cleanup();

		$this->assertSame( 1, $result['tokens'] );
		$this->assertSame( 1, $result['mail'] );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', Schema::table( 'login_tokens' ), $client ) ) );
	}

	public function test_privacy_export_and_erase(): void {
		$manager   = $this->manager();
		$client    = $this->client( 'privacy@example.org' );
		$case      = $this->case_for( $manager, $client );
		$retention = new Retention( $this->app );

		$this->app->ledger_service->add( $case, self::expense(), $manager );
		$this->app->notifier->process_case( $case->id, true );

		$export = $retention->export( 'privacy@example.org' );
		$this->assertSame( array( 'dx-portal-membership', 'dx-portal-mail' ), array_values( array_unique( array_column( $export['data'], 'group_id' ) ) ) );

		$erase = $retention->erase( 'privacy@example.org' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertFalse( $this->app->members->is_member( $case->id, $client ) );
		$this->assertSame( 'deleted@example.invalid', $this->app->mail_log->for_case( $case->id )[0]['recipient'] );
		$this->assertCount( 1, $this->app->ledger->for_case( $case->id, false ), 'Financial rows stay with the case.' );

		$this->assertSame( array(), $retention->export( 'nobody@example.org' )['data'] );
		$this->assertFalse( $retention->erase( 'nobody@example.org' )['items_removed'] );
	}
}
