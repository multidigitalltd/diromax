<?php
/**
 * The photo journal of a case (spec 18.2).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Integration;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Service\MailItems;
use Diromax\Portal\Service\PhotoService;
use WP_Error;

final class PhotoJournalTest extends PortalTestCase {

	/**
	 * Temporary image files made by the tests.
	 *
	 * @var string[]
	 */
	private array $images = array();

	public function tear_down(): void {
		foreach ( $this->images as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}

		parent::tear_down();
	}

	/**
	 * A JPEG as one $_FILES item.
	 *
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param string $name   File name.
	 * @return array<string, mixed>
	 */
	private function jpeg( int $width = 800, int $height = 600, string $name = 'roof.jpg' ): array {
		add_filter( 'dx_portal_is_uploaded_file', '__return_true' );

		$path  = (string) tempnam( sys_get_temp_dir(), 'dxp' );
		$image = imagecreatetruecolor( $width, $height );
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 200, 120, 40 ) );
		imagejpeg( $image, $path, 90 );
		$this->images[] = $path;

		return array(
			'name'     => $name,
			'type'     => 'image/jpeg',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $path ),
		);
	}

	/**
	 * Decrypted bytes of a stored file.
	 *
	 * @param string $name Stored name.
	 * @return string
	 */
	private function stored( string $name ): string {
		$bytes = '';
		$this->app->store->read(
			$name,
			1,
			static function ( string $chunk ) use ( &$bytes ): void {
				$bytes .= $chunk;
			}
		);

		return $bytes;
	}

	/**
	 * Upload as the case's manager.
	 *
	 * @param CaseRecord                       $client_case Case.
	 * @param array<int, array<string, mixed>> $files       Files.
	 * @param array<string, string>            $input       Fields.
	 * @return array{stored:int, failed:string[]}|WP_Error
	 */
	private function upload( CaseRecord $client_case, array $files, array $input = array() ) {
		return ( new PhotoService( $this->app ) )->upload(
			$client_case,
			$files,
			$input + array(
				'stage'    => '3',
				'taken_on' => '',
				'caption'  => 'יציקת התקרה',
				'visible'  => '1',
				'notify'   => '1',
			),
			$client_case->manager_id
		);
	}

	public function test_photos_are_resized_encrypted_given_a_thumbnail_and_announced(): void {
		$case   = $this->case_for( $this->manager(), $this->client() );
		$result = $this->upload( $case, array( $this->jpeg( 3000, 1500 ), $this->jpeg( 400, 300, 'detail.jpg' ) ) );

		$this->assertSame(
			array(
				'stored' => 2,
				'failed' => array(),
			),
			$result
		);

		$photos = $this->app->photos->for_case( $case->id, false );
		$this->assertCount( 2, $photos );

		$big = $photos[0];
		$this->assertSame( 3, $big['stage'] );
		$this->assertSame( $this->clock->today(), $big['taken_on'], 'No date given and none in the photo: today.' );
		$this->assertSame( 'יציקת התקרה', $big['caption'] );
		$this->assertSame( array( 2560, 1280 ), array( $big['width'], $big['height'] ), 'No larger than 2560 pixels.' );
		$this->assertSame( array( 640, 320 ), array( $big['thumb_width'], $big['thumb_height'] ) );
		$this->assertSame( array( 400, 300 ), array( $photos[1]['width'], $photos[1]['height'] ), 'A small photo keeps its size.' );

		$file  = $this->stored( $big['stored_name'] );
		$thumb = $this->stored( $big['thumb_name'] );
		$this->assertSame( array( 2560, 1280 ), array_slice( (array) getimagesizefromstring( $file ), 0, 2 ) );
		$this->assertSame( array( 640, 320 ), array_slice( (array) getimagesizefromstring( $thumb ), 0, 2 ) );
		$this->assertSame( $big['sha256'], hash( 'sha256', $file ) );

		$events = $this->app->events->pending_for_case( $case->id );
		$this->assertSame( array( 'photos_added' ), array_column( $events, 'kind' ), 'One email card for the whole upload.' );

		$cards = ( new MailItems( $this->app ) )->build( $case, $events, false );
		$this->assertCount( 1, $cards );
		$this->assertSame( '2 צילומים מהשטח', $cards[0]->title );
		$this->assertStringContainsString( 'tab=photos', (string) $cards[0]->link['url'] );
	}

	public function test_the_client_sees_only_what_the_manager_shows(): void {
		$client = $this->client();
		$case   = $this->case_for( $this->manager(), $client );
		$this->upload( $case, array( $this->jpeg() ), array( 'visible' => '' ) );
		$this->upload( $case, array( $this->jpeg() ), array( 'stage' => '1' ) );

		$photos  = $this->app->photos->for_case( $case->id, false );
		$hidden  = array_values( array_filter( $photos, static fn( array $p ): bool => ! $p['visible'] ) )[0];
		$shown   = array_values( array_filter( $photos, static fn( array $p ): bool => $p['visible'] ) )[0];
		$service = new PhotoService( $this->app );

		$journal = $service->journal( $case, true );
		$this->assertCount( 1, $journal, 'Only the stage with a shown photo.' );
		$this->assertSame( '01', $journal[0]['number'] );
		$this->assertSame( array( '01', '03' ), array_column( $service->journal( $case, false ), 'number' ), 'Staff see both, in stage order.' );

		$this->assertTrue( $service->may_view( $case, $shown, $client ) );
		$this->assertFalse( $service->may_view( $case, $hidden, $client ), 'Negative: an internal photo is not the client\'s.' );
		$this->assertTrue( $service->may_view( $case, $hidden, $case->manager_id ) );
		$this->assertFalse( $service->may_view( $case, $shown, $this->client() ), 'Negative: a client of another case.' );
		$this->assertFalse( $service->may_view( $case, $shown, $this->manager() ), 'Negative: a manager of another case.' );

		$other = $this->case_for( $this->manager(), $client );
		$this->assertFalse( $service->may_view( $other, $shown, $client ), 'A photo is reached only through its own case.' );

		$this->assertTrue( $service->set_visible( $case, $hidden['id'], true, $case->manager_id ) );
		$this->assertCount( 2, $this->app->photos->for_case( $case->id, true ) );
		$this->assertSame( 'dx_forbidden', $service->set_visible( $case, $hidden['id'], false, $this->manager() )->get_error_code() );
	}

	public function test_input_is_checked(): void {
		$case = $this->case_for( $this->manager() );

		$future = $this->upload( $case, array( $this->jpeg() ), array( 'taken_on' => '2099-01-01' ) );
		$this->assertInstanceOf( WP_Error::class, $future );
		$this->assertSame( 'future', $future->get_error_data()['fields']['taken_on'] );

		$bad = $this->upload( $case, array( $this->jpeg() ), array( 'stage' => '9' ) );
		$this->assertSame( 'invalid', $bad->get_error_data()['fields']['stage'] );

		$many = $this->upload( $case, array_fill( 0, PhotoService::PER_UPLOAD + 1, $this->jpeg( 20, 20 ) ) );
		$this->assertSame( 'too_many', $many->get_error_data()['fields']['photos'] );

		$pdf   = $this->sample_pdf();
		$mixed = $this->upload( $case, array( $this->jpeg(), $pdf ) );
		$this->assertSame( 1, $mixed['stored'] );
		$this->assertSame( array( 'מסמך.pdf' ), $mixed['failed'], 'Only photos: the PDF is turned away, the photo is kept.' );

		$none = $this->upload( $case, array( $this->sample_pdf() ) );
		$this->assertSame( 'type', $none->get_error_data()['fields']['photos'] );

		$dated = $this->upload( $case, array( $this->jpeg() ), array( 'taken_on' => '2026-10-01' ) );
		$this->assertIsArray( $dated );

		$this->assertSame( 'dx_forbidden', ( new PhotoService( $this->app ) )->upload( $case, array( $this->jpeg() ), array(), $this->manager() )->get_error_code(), 'Negative: another manager cannot add.' );
		$this->assertSame( 'dx_forbidden', ( new PhotoService( $this->app ) )->upload( $case, array( $this->jpeg() ), array(), $this->client() )->get_error_code(), 'Negative: a client cannot add.' );
	}

	public function test_deleting_a_photo_or_its_case_removes_the_files(): void {
		$case = $this->case_for( $this->manager() );
		$this->upload( $case, array( $this->jpeg(), $this->jpeg() ) );
		[ $one, $two ] = $this->app->photos->for_case( $case->id, false );
		$service       = new PhotoService( $this->app );

		$this->assertSame( 'dx_forbidden', $service->delete( $case, $one['id'], $this->manager() )->get_error_code() );
		$this->assertTrue( $service->delete( $case, $one['id'], $case->manager_id ) );
		$this->assertNull( $this->app->photos->find( $one['id'] ) );
		$this->assertNotTrue( $this->app->store->can_open( $one['stored_name'], 1 ) );
		$this->assertNotTrue( $this->app->store->can_open( $one['thumb_name'], 1 ) );
		$this->assertSame( 'photo.deleted', $this->app->audit->for_case( $case->id )[0]['action'] );

		$closed = $this->app->case_service->set_status( $case, 'closed', $case->manager_id );
		$this->assertInstanceOf( CaseRecord::class, $closed );
		$this->assertTrue( $this->app->case_service->delete( $closed, $this->admin() ) );
		$this->assertNotTrue( $this->app->store->can_open( $two['stored_name'], 1 ), 'Deleting the case deletes its photos.' );
		$this->assertNotTrue( $this->app->store->can_open( $two['thumb_name'], 1 ) );
	}

	public function test_an_email_card_is_dropped_when_the_photos_were_hidden_before_it_went(): void {
		$case = $this->case_for( $this->manager(), $this->client() );
		$this->upload( $case, array( $this->jpeg() ) );
		$photo = $this->app->photos->for_case( $case->id, false )[0];
		( new PhotoService( $this->app ) )->set_visible( $case, $photo['id'], false, $case->manager_id );

		$this->assertSame( array(), ( new MailItems( $this->app ) )->build( $case, $this->app->events->pending_for_case( $case->id ), false ) );
	}
}
