<?php
/**
 * Upload validation, encryption at rest and download authorization.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\DocumentRecord;

final class DocumentServiceTest extends PortalTestCase {

	/**
	 * Temp files to remove.
	 *
	 * @var string[]
	 */
	private array $temp = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'dx_portal_is_uploaded_file', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'dx_portal_is_uploaded_file', '__return_true' );

		foreach ( $this->temp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}

		parent::tear_down();
	}

	/**
	 * A fake $_FILES item with the given content.
	 *
	 * @return array<string, mixed>
	 */
	private function upload( string $name, string $content ): array {
		$path         = (string) tempnam( sys_get_temp_dir(), 'dxt' );
		$this->temp[] = $path;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		);
	}

	private static function pdf( int $padding = 0 ): string {
		return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n" . str_repeat( 'x', $padding ) . "\ntrailer << /Root 1 0 R >>\n%%EOF\n";
	}

	private function stored_path( DocumentRecord $document ): string {
		return sys_get_temp_dir() . '/dx-portal-test-store/' . $document->stored_name;
	}

	private function decrypt( DocumentRecord $document ): ?string {
		$plain = '';
		$ok    = $this->app->store->read(
			$document->stored_name,
			$document->key_version,
			static function ( string $chunk ) use ( &$plain ): void {
				$plain .= $chunk;
			}
		);

		return $ok ? $plain : null;
	}

	public function test_pdf_is_encrypted_on_disk_and_decrypts_byte_for_byte(): void {
		$manager  = $this->manager();
		$case     = $this->case_for( $manager );
		$content  = self::pdf( 200000 );
		$document = $this->app->document_service->upload( $case, $this->upload( 'הסכם.pdf', $content ), 'agreement', 'הסכם ניהול', true, $manager, false );

		$this->assertInstanceOf( DocumentRecord::class, $document );
		$this->assertSame( 'application/pdf', $document->mime );
		$this->assertSame( strlen( $content ), $document->size );
		$this->assertSame( hash( 'sha256', $content ), $document->sha256 );

		$disk = (string) file_get_contents( $this->stored_path( $document ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the raw stored bytes.
		$this->assertStringNotContainsString( '%PDF', $disk );
		$this->assertStringNotContainsString( 'Catalog', $disk );

		$this->assertSame( $content, $this->decrypt( $document ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}\.bin$/', $document->stored_name );
	}

	public function test_tampered_or_truncated_file_fails_authentication(): void {
		$manager  = $this->manager();
		$case     = $this->case_for( $manager );
		$document = $this->app->document_service->upload( $case, $this->upload( 'a.pdf', self::pdf( 100 ) ), 'other', '', true, $manager, false );
		$path     = $this->stored_path( $document );
		$bytes    = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.

		$flipped     = $bytes;
		$flipped[40] = chr( ord( $flipped[40] ) ^ 1 );
		file_put_contents( $path, $flipped ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test tampering.
		$this->assertNull( $this->decrypt( $document ) );

		file_put_contents( $path, substr( $bytes, 0, -5 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test truncation.
		$this->assertNull( $this->decrypt( $document ) );
	}

	/**
	 * @dataProvider rejected_uploads
	 */
	public function test_dangerous_or_mismatched_files_are_rejected( string $name, string $content, string $code ): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$result  = $this->app->document_service->upload( $case, $this->upload( $name, $content ), 'other', '', true, $manager, false );

		$this->assertWPError( $result );
		$this->assertSame( array( 'file' => $code ), $result->get_error_data()['fields'] );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function rejected_uploads(): array {
		return array(
			'php script'         => array( 'shell.php', '<?php echo 1;', 'type' ),
			'svg'                => array( 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'type' ),
			'html'               => array( 'page.html', '<html><script>1</script></html>', 'type' ),
			'exe renamed to pdf' => array( 'invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00binary", 'type' ),
			'double extension'   => array( 'invoice.pdf.php', self::pdf(), 'type' ),
			'empty file'         => array( 'empty.pdf', '', 'too_large' ),
		);
	}

	public function test_file_that_was_not_uploaded_through_http_is_refused(): void {
		remove_filter( 'dx_portal_is_uploaded_file', '__return_true' );
		$manager = $this->manager();
		$case    = $this->case_for( $manager );

		$result = $this->app->document_service->upload( $case, $this->upload( 'a.pdf', self::pdf() ), 'other', '', true, $manager, false );

		$this->assertSame( array( 'file' => 'missing' ), $result->get_error_data()['fields'] );
	}

	public function test_scanner_hook_can_reject_a_file(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		add_filter( 'dx_portal_scan_upload', '__return_false' );

		$result = $this->app->document_service->upload( $case, $this->upload( 'a.pdf', self::pdf() ), 'other', '', true, $manager, false );

		remove_filter( 'dx_portal_scan_upload', '__return_false' );
		$this->assertSame( array( 'file' => 'rejected' ), $result->get_error_data()['fields'] );
	}

	public function test_client_cannot_upload_and_only_manager_downloads_hidden_documents(): void {
		$manager  = $this->manager();
		$client   = $this->client();
		$case     = $this->case_for( $manager, $client );
		$document = $this->app->document_service->upload( $case, $this->upload( 'a.pdf', self::pdf() ), 'invoice', '', false, $manager, false );

		$this->assertSame( 'dx_forbidden', $this->app->document_service->upload( $case, $this->upload( 'b.pdf', self::pdf() ), 'other', '', true, $client, false )->get_error_code() );
		$this->assertSame( 'dx_not_found', $this->app->document_service->send( $case, $document, $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->document_service->set_visible( $case, $document, true, $client )->get_error_code() );
		$this->assertSame( 'dx_forbidden', $this->app->document_service->delete( $case, $document, $client )->get_error_code() );

		$this->assertTrue( $this->app->document_service->set_visible( $case, $document, true, $manager ) );
		$this->assertTrue( $this->app->documents->find( $document->id )->visible );
	}

	public function test_delete_removes_the_encrypted_file_and_the_row(): void {
		$manager  = $this->manager();
		$case     = $this->case_for( $manager );
		$document = $this->app->document_service->upload( $case, $this->upload( 'a.pdf', self::pdf() ), 'other', '', true, $manager, false );
		$path     = $this->stored_path( $document );

		$this->assertFileExists( $path );
		$this->assertTrue( $this->app->document_service->delete( $case, $document, $manager ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertNull( $this->app->documents->find( $document->id ) );
	}

	public function test_store_refuses_names_it_did_not_generate(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->app->store->delete( '../../wp-config.php' );
	}

	public function test_store_directory_is_locked_down(): void {
		$dir = $this->app->store->directory();

		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.
		$this->assertFileExists( $dir . '/index.php' );
	}

	public function test_attached_invoice_with_an_entry_is_linked_and_hidden_with_it(): void {
		$manager = $this->manager();
		$case    = $this->case_for( $manager );
		$id      = $this->app->ledger_service->add( $case, self::expense( array( 'visible' => '' ) ), $manager, $this->upload( 'invoice.pdf', self::pdf() ) );

		$row = $this->app->ledger->find( $id );
		$this->assertNotNull( $row->entry->document_id );

		$document = $this->app->documents->find( (int) $row->entry->document_id );
		$this->assertSame( 'invoice', $document->kind );
		$this->assertFalse( $document->visible );
	}
}
