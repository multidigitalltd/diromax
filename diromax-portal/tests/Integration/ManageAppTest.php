<?php
/**
 * The management app on the site, and the guard on the encryption key.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Security\DocumentStore;
use Diromax\Portal\Service\UpdateService;
use WP_Error;

final class ManageAppTest extends PortalTestCase {

	public function tear_down(): void {
		Admin::use_front( false );
		delete_option( DocumentStore::FINGERPRINT_OPTION );
		update_option( 'permalink_structure', '/%postname%/' );
		parent::tear_down();
	}

	public function test_app_urls_with_and_without_pretty_permalinks(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( home_url( '/portal/manage/' ), Router::manage_url() );
		$this->assertSame( home_url( '/portal/manage/cases/' ), Router::manage_url( 'cases' ) );
		$this->assertSame( home_url( '/portal/manage/case/12/?tab=finance' ), Router::manage_url( 'case', 12, 'finance' ) );
		$this->assertSame( home_url( '/portal/manage/' ), Router::manage_url( 'evil' ) );

		update_option( 'permalink_structure', '' );
		$this->assertStringContainsString( 'dx_portal=manage', Router::manage_url( 'case', 12 ) );
		$this->assertStringContainsString( 'dx_manage_case=12', Router::manage_url( 'case', 12 ) );
		$this->assertTrue( Router::is_manage_url( Router::manage_url( 'cases' ) ) );
	}

	/**
	 * After a form, the user goes back to the interface the form came from.
	 */
	public function test_a_form_referer_tells_which_interface_to_return_to(): void {
		$this->assertTrue( Router::is_manage_url( '/portal/manage/case/3/?tab=finance&add=1' ), 'The referer field holds a path.' );
		$this->assertTrue( Router::is_manage_url( home_url( '/portal/manage/' ) ) );
		$this->assertFalse( Router::is_manage_url( '/wp-admin/admin.php?page=dx-cases&case=3' ) );
		$this->assertFalse( Router::is_manage_url( '/portal/case/abc/' ) );
		$this->assertFalse( Router::is_manage_url( 'https://evil.example/portal/manage/' ), 'Another site is never the app.' );
		$this->assertFalse( Router::is_manage_url( '' ) );
	}

	public function test_links_follow_the_interface_in_use(): void {
		$this->assertStringContainsString( '/wp-admin/admin.php?page=dx-cases&case=7&tab=updates', Admin::case_url( 7, 'updates' ) );
		$this->assertStringContainsString( 'page=dx-case-new', Admin::page_url( Admin::PAGE_NEW ) );

		Admin::use_front( true );
		$this->assertSame( Router::manage_url( 'case', 7, 'updates' ), Admin::case_url( 7, 'updates' ) );
		$this->assertSame( Router::manage_url( 'new' ), Admin::page_url( Admin::PAGE_NEW ) );
		$this->assertSame( Router::manage_url(), Admin::page_url( Admin::PAGE_DASHBOARD ) );
	}

	public function test_project_managers_land_in_the_app_after_signing_in(): void {
		$manager = get_userdata( $this->manager() );
		$admin   = get_userdata( $this->admin() );
		$client  = get_userdata( $this->client() );
		$default = admin_url();

		$this->assertSame( Router::manage_url(), apply_filters( 'login_redirect', $default, '', $manager ) );
		$this->assertSame( Router::manage_url(), apply_filters( 'login_redirect', $default, $default, $manager ), 'The sign-in form sends admin_url() when nothing was asked for.' );
		$this->assertSame( home_url( '/portal/manage/case/4/' ), apply_filters( 'login_redirect', home_url( '/portal/manage/case/4/' ), home_url( '/portal/manage/case/4/' ), $manager ), 'A page that was asked for wins.' );
		$this->assertSame( $default, apply_filters( 'login_redirect', $default, '', $admin ), 'Administrators keep wp-admin.' );
		$this->assertSame( Router::portal_url(), apply_filters( 'login_redirect', $default, '', $client ) );
	}

	public function test_the_key_is_remembered_on_the_first_upload(): void {
		$manager = $this->manager();
		$this->assertSame( '', $this->app->store->known_fingerprint() );

		$this->add_document( $this->case_for( $manager ), $manager );

		$this->assertSame( $this->app->store->fingerprint(), $this->app->store->known_fingerprint() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', (string) $this->app->store->fingerprint() );
		$this->assertSame( 'ok', $this->app->document_service->key_status() );
	}

	/**
	 * A replaced key would leave the stored documents unreadable, and new
	 * uploads under the new key would mix two keys: uploads stop, and the
	 * staff screens say so.
	 */
	public function test_a_changed_key_blocks_uploads_while_documents_exist(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$this->add_document( $case, $manager );
		update_option( DocumentStore::FINGERPRINT_OPTION, '000000000000' );

		$this->assertSame( 'changed', $this->app->document_service->key_status() );

		add_filter( 'dx_portal_is_uploaded_file', '__return_true' );
		$path = (string) tempnam( sys_get_temp_dir(), 'dxt' );
		file_put_contents( $path, "%PDF-1.4\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$result = $this->app->document_service->upload(
			$case,
			array(
				'name'     => 'x.pdf',
				'type'     => 'application/pdf',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => (int) filesize( $path ),
			),
			'other',
			'x',
			true,
			$manager,
			false
		);
		wp_delete_file( $path );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dx_key_changed', $result->get_error_code() );
		$this->assertContains( 'error', array_column( ( new Admin( $this->app ) )->config_notices( $manager ), 0 ) );
	}

	public function test_with_no_documents_a_new_key_is_simply_adopted(): void {
		update_option( DocumentStore::FINGERPRINT_OPTION, '000000000000' );

		$this->assertSame( 'ok', $this->app->document_service->key_status() );
		$this->assertSame( $this->app->store->fingerprint(), $this->app->store->known_fingerprint() );
		$manager = $this->manager();
		$this->assertInstanceOf( DocumentRecord::class, $this->add_document( $this->case_for( $manager ), $manager ) );
	}

	/**
	 * Regression (PR #7 review): a site that stored documents before the
	 * fingerprint existed must not adopt a key that cannot open them.
	 */
	public function test_an_upgraded_site_adopts_the_key_only_if_it_opens_the_stored_files(): void {
		$manager  = $this->manager();
		$case     = $this->case_for( $manager );
		$document = $this->add_document( $case, $manager );

		delete_option( DocumentStore::FINGERPRINT_OPTION );
		$this->assertSame( 'ok', $this->app->document_service->key_status(), 'The key opens the stored file.' );
		$this->assertSame( $this->app->store->fingerprint(), $this->app->store->known_fingerprint() );

		// Same as a different key: the stored file no longer authenticates.
		delete_option( DocumentStore::FINGERPRINT_OPTION );
		$path = $this->app->store->directory() . '/' . $document->stored_name;
		file_put_contents( $path, random_bytes( (int) filesize( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->assertSame( 'changed', $this->app->document_service->key_status() );
		$this->assertSame( '', $this->app->store->known_fingerprint(), 'A key that cannot open the files is not adopted.' );
		$this->assertSame( 'dx_key_changed', $this->app->document_service->storage_error()?->get_error_code() );
	}

	/**
	 * Regression (PR #7 review): an update was published while its
	 * attachments were refused one by one and silently dropped.
	 */
	public function test_an_update_is_not_published_when_its_attachments_cannot_be_stored(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$this->add_document( $case, $manager );
		update_option( DocumentStore::FINGERPRINT_OPTION, '000000000000' );
		$before = count( $this->app->updates->for_case( $case->id, false ) );

		$result = ( new UpdateService( $this->app ) )->publish( $case, self::update(), array( $this->pdf_upload( 'plan.pdf', "%PDF-1.4\n%%EOF\n" ) ), $manager );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'files' => 'storage' ), \Diromax\Portal\Service\Errors::fields( $result ) );
		$this->assertCount( $before, $this->app->updates->for_case( $case->id, false ), 'Nothing was published.' );
	}

	public function test_an_update_with_a_file_of_the_wrong_type_is_not_published(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$result = ( new UpdateService( $this->app ) )->publish( $case, self::update(), array( $this->pdf_upload( 'tool.exe', 'MZ' . str_repeat( 'x', 64 ) ) ), $manager );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'files' => 'type' ), \Diromax\Portal\Service\Errors::fields( $result ) );
		$this->assertSame( array(), $this->app->updates->for_case( $case->id, false ) );

		$ok = ( new UpdateService( $this->app ) )->publish( $case, self::update(), array( $this->pdf_upload( 'plan.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n" ) ), $manager );
		$this->assertIsInt( $ok, 'A good file still goes through.' );
		$this->assertCount( 1, $this->app->documents->for_case( $case->id, false ) );
	}

	/**
	 * Update input.
	 *
	 * @return array<string, string>
	 */
	private static function update(): array {
		return array(
			'title'   => 'הבקשה הוגשה',
			'body'    => 'פרטים',
			'stage'   => '',
			'visible' => '1',
			'notify'  => '',
		);
	}

	/**
	 * A fake uploaded file.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 * @return array<string, mixed>
	 */
	private function pdf_upload( string $name, string $content ): array {
		add_filter( 'dx_portal_is_uploaded_file', '__return_true' );
		$path = (string) tempnam( sys_get_temp_dir(), 'dxt' );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		);
	}

	public function test_clients_get_no_staff_notices(): void {
		update_option( DocumentStore::FINGERPRINT_OPTION, '000000000000' );

		$this->assertSame( array(), ( new Admin( $this->app ) )->config_notices( $this->client() ) );
	}
}
