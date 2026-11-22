<?php
/**
 * The photo journal of a case.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Domain\StageProgress;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Security\DocumentStore;
use Diromax\Portal\Support\Ids;
use Diromax\Portal\Support\TempFile;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Photos from the site, by stage and date, with a caption (spec 18.2).
 *
 * The project manager uploads up to 20 at once, from a phone or a computer.
 * Each photo is re-encoded (which drops EXIF and the location in it), made
 * no larger than 2560 pixels, given a 640-pixel thumbnail for the journal,
 * and both are encrypted in the private store like every document. The
 * manager decides which photos the clients see (default: shown); new shown
 * photos reach the clients through the batched update email.
 *
 * @phpstan-import-type PhotoRow from \Diromax\Portal\Data\PhotoRepository
 */
final class PhotoService {

	/** Photos in one upload. */
	public const PER_UPLOAD = 20;

	/** Longest side of a stored photo, pixels. */
	private const FULL_MAX = 2560;

	/** Longest side of a thumbnail, pixels. */
	private const THUMB_MAX = 640;

	/** Image types accepted. */
	private const TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Add photos to a case.
	 *
	 * @param CaseRecord                       $client_case Case.
	 * @param array<int, array<string, mixed>> $files       $_FILES items.
	 * @param array<string, string>            $input       stage, taken_on (Y-m-d, '' for the photo's own date or today), caption, visible, notify.
	 * @param int                              $actor       Acting user.
	 * @return array{stored:int, failed:string[]}|WP_Error
	 */
	public function upload( CaseRecord $client_case, array $files, array $input, int $actor ): array|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$blocked = $this->app->document_service->storage_error();

		if ( null !== $blocked ) {
			return $blocked;
		}

		$fields = self::check( $client_case, $input, $this->app->clock->today() );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		if ( array() === $files ) {
			return Errors::invalid( array( 'photos' => 'missing' ) );
		}

		if ( count( $files ) > self::PER_UPLOAD ) {
			return Errors::invalid( array( 'photos' => 'too_many' ) );
		}

		$batch  = substr( Ids::public_id(), 0, 16 );
		$stored = array();
		$failed = array();

		foreach ( $files as $file ) {
			$id = $this->store_one( $client_case, $file, $fields, $batch, $actor );

			if ( $id instanceof WP_Error ) {
				$failed[] = is_string( $file['name'] ?? null ) ? sanitize_file_name( $file['name'] ) : '';
			} else {
				$stored[] = $id;
			}
		}

		if ( array() === $stored ) {
			return Errors::invalid( array( 'photos' => 'type' ) );
		}

		$this->app->audit->record(
			'photos.added',
			$client_case->id,
			'photo',
			$stored[0],
			array(
				'count'   => count( $stored ),
				'visible' => $fields['visible'],
			),
			$this->app->clock->mysql()
		);

		if ( $fields['visible'] && '1' === ( $input['notify'] ?? '' ) ) {
			$this->app->notifier->enqueue( $client_case->id, 'photos_added', $stored[0], false );
		}

		return array(
			'stored' => count( $stored ),
			'failed' => array_values( array_filter( $failed ) ),
		);
	}

	/**
	 * Show a photo to the clients, or keep it internal.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $photo_id    Photo.
	 * @param bool       $visible     Shown.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function set_visible( CaseRecord $client_case, int $photo_id, bool $visible, int $actor ): bool|WP_Error {
		$photo = $this->owned( $client_case, $photo_id, $actor );

		if ( $photo instanceof WP_Error ) {
			return $photo;
		}

		$this->app->photos->set_visible( $photo['id'], $visible );
		$this->app->audit->record( $visible ? 'photo.shown' : 'photo.hidden', $client_case->id, 'photo', $photo['id'], array(), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Delete a photo and its files.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $photo_id    Photo.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function delete( CaseRecord $client_case, int $photo_id, int $actor ): bool|WP_Error {
		$photo = $this->owned( $client_case, $photo_id, $actor );

		if ( $photo instanceof WP_Error ) {
			return $photo;
		}

		// The upload's email card names its first photo: hand it to one that stays.
		$rest = array_values( array_filter( $this->app->photos->in_batch( $photo['batch_key'], false ), static fn( array $other ): bool => $other['id'] !== $photo['id'] ) );

		if ( array() !== $rest ) {
			$this->app->events->repoint( 'photos_added', $photo['id'], $rest[0]['id'] );
		}

		$this->app->store->delete( $photo['stored_name'] );
		$this->app->store->delete( $photo['thumb_name'] );
		$this->app->photos->delete( $photo['id'] );
		$this->app->audit->record( 'photo.deleted', $client_case->id, 'photo', $photo['id'], array( 'caption' => $photo['caption'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * May the viewer see this photo? Staff of the case, or a client of the
	 * case when the photo is shown.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param array      $photo       Photo.
	 * @param int        $viewer      User.
	 * @phpstan-param PhotoRow $photo
	 * @return bool
	 */
	public function may_view( CaseRecord $client_case, array $photo, int $viewer ): bool {
		if ( $photo['case_id'] !== $client_case->id ) {
			return false;
		}

		return $this->app->policy->can_manage_case( $viewer, $client_case )
			|| ( $photo['visible'] && $this->app->policy->can_view_case( $viewer, $client_case ) );
	}

	/**
	 * Decrypt a photo or its thumbnail to the response, shown in the page.
	 * Thumbnails may stay a day in the viewer's own browser cache; the full
	 * photo is not cached, like a document.
	 *
	 * @param array $photo Photo the caller checked with may_view().
	 * @param bool  $thumb The thumbnail.
	 * @phpstan-param PhotoRow $photo
	 */
	public function stream( array $photo, bool $thumb ): void {
		header_remove( 'Pragma' );
		header_remove( 'Expires' );
		header( 'Content-Type: ' . $photo['mime'] );
		header( 'Content-Disposition: inline; filename="photo-' . $photo['public_id'] . ( $thumb ? '-thumb' : '' ) . '.' . self::extension( $photo['mime'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( $thumb ? 'Cache-Control: private, max-age=86400' : 'Cache-Control: private, no-store, max-age=0' );

		if ( ! $thumb ) {
			header( 'Content-Length: ' . $photo['size'] );
		}

		$ok = $this->app->store->read(
			$thumb ? $photo['thumb_name'] : $photo['stored_name'],
			$photo['key_version'],
			static function ( string $chunk ): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image body.
				flush();
			}
		);

		if ( ! $ok ) {
			Logger::error( 'photo.decrypt_failed', array( 'photo' => $photo['id'] ) );
		}
	}

	/**
	 * The journal for a screen: stages that have photos, in order, each with
	 * its photos by date.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param bool       $visible_only Only those shown to the client.
	 * @return array<int, array{number:string, name:string, photos:array<int, array{id:int, date:string, iso:string, caption:string, alt:string, thumb:string, full:string, width:int, height:int, visible:bool}>}>
	 */
	public function journal( CaseRecord $client_case, bool $visible_only ): array {
		$names   = Stages::names();
		$journal = array();

		foreach ( $this->app->photos->for_case( $client_case->id, $visible_only ) as $photo ) {
			$stage = $photo['stage'];

			if ( ! isset( $journal[ $stage ] ) ) {
				$journal[ $stage ] = array(
					'number' => Stages::number( $stage ),
					'name'   => (string) ( $names[ $stage ] ?? '' ),
					'photos' => array(),
				);
			}

			$date = Labels::date( $photo['taken_on'] );

			$journal[ $stage ]['photos'][] = array(
				'id'      => $photo['id'],
				'date'    => $date,
				'iso'     => $photo['taken_on'],
				'caption' => $photo['caption'],
				/* translators: 1: stage name, 2: date. */
				'alt'     => '' !== $photo['caption'] ? $photo['caption'] : sprintf( __( 'צילום מהשטח, %1$s, %2$s', 'diromax-portal' ), (string) ( $names[ $stage ] ?? '' ), $date ),
				'thumb'   => Router::photo_url( $photo['public_id'], true ),
				'full'    => Router::photo_url( $photo['public_id'], false ),
				'width'   => $photo['thumb_width'],
				'height'  => $photo['thumb_height'],
				'visible' => $photo['visible'],
			);
		}

		return array_values( $journal );
	}

	/**
	 * Validated batch fields.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Input.
	 * @param string                $today       Y-m-d.
	 * @return array{stage:int, taken_on:string, caption:string, visible:bool}|WP_Error
	 */
	private static function check( CaseRecord $client_case, array $input, string $today ): array|WP_Error {
		$errors = array();
		$stage  = trim( (string) ( $input['stage'] ?? '' ) );
		$stage  = '' === $stage ? StageProgress::lead( $client_case->stages ) : (int) $stage;
		$date   = trim( (string) ( $input['taken_on'] ?? '' ) );

		if ( ! isset( Stages::names()[ $stage ] ) ) {
			$errors['stage'] = 'invalid';
		}

		if ( '' !== $date ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

			if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
				$errors['taken_on'] = 'invalid';
			} elseif ( $date > $today ) {
				$errors['taken_on'] = 'future';
			}
		}

		$caption = Text::line( (string) ( $input['caption'] ?? '' ) );

		if ( Text::length( $caption ) > 300 ) {
			$errors['caption'] = 'too_long';
		}

		if ( array() !== $errors ) {
			return Errors::invalid( $errors );
		}

		return array(
			'stage'    => $stage,
			'taken_on' => $date,
			'caption'  => $caption,
			'visible'  => '1' === ( $input['visible'] ?? '' ),
		);
	}

	/**
	 * Check, re-encode, encrypt and record one photo.
	 *
	 * @param CaseRecord                                                      $client_case Case.
	 * @param array<string, mixed>                                            $file        One $_FILES item.
	 * @param array{stage:int, taken_on:string, caption:string, visible:bool} $fields Batch fields.
	 * @param string                                                          $batch       Batch key.
	 * @param int                                                             $actor       Acting user.
	 * @return int|WP_Error Photo ID.
	 */
	private function store_one( CaseRecord $client_case, array $file, array $fields, string $batch, int $actor ): int|WP_Error {
		$name = is_string( $file['name'] ?? null ) ? sanitize_file_name( $file['name'] ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime = self::TYPES[ $ext ] ?? null;

		if ( null === $mime ) {
			return Errors::invalid( array( 'photos' => 'type' ) );
		}

		$problem = $this->app->document_service->precheck( $file );

		if ( null !== $problem ) {
			return $problem;
		}

		$source = (string) $file['tmp_name'];
		$taken  = '' !== $fields['taken_on'] ? $fields['taken_on'] : $this->taken_on( $source );
		$full   = self::resized( $source, $mime, self::FULL_MAX, $ext );
		$thumb  = $full instanceof WP_Error ? $full : self::resized( $full['path'], $mime, self::THUMB_MAX, $ext );

		if ( $thumb instanceof WP_Error || $full instanceof WP_Error ) {
			if ( ! $full instanceof WP_Error ) {
				wp_delete_file( $full['path'] );
			}

			return Errors::invalid( array( 'photos' => 'type' ) );
		}

		$names = array(
			'full'  => $this->app->store->new_name(),
			'thumb' => $this->app->store->new_name(),
		);

		try {
			$meta = $this->app->store->put( $full['path'], $names['full'] );
			$this->app->store->put( $thumb['path'], $names['thumb'] );
		} catch ( \RuntimeException $error ) {
			Logger::error( 'photo.store_failed', array( 'reason' => $error->getMessage() ) );
			$this->app->store->delete( $names['full'] );
			$this->app->store->delete( $names['thumb'] );
			return Errors::storage();
		} finally {
			wp_delete_file( $full['path'] );
			wp_delete_file( $thumb['path'] );
		}

		if ( '' === $this->app->store->known_fingerprint() ) {
			$this->app->store->remember_key();
		}

		$id = $this->app->photos->insert(
			array(
				'public_id'    => Ids::public_id(),
				'case_id'      => $client_case->id,
				'stage'        => $fields['stage'],
				'taken_on'     => $taken,
				'caption'      => $fields['caption'],
				'batch_key'    => $batch,
				'stored_name'  => $names['full'],
				'thumb_name'   => $names['thumb'],
				'mime'         => $mime,
				'size'         => (int) $meta['size'],
				'width'        => $full['width'],
				'height'       => $full['height'],
				'thumb_width'  => $thumb['width'],
				'thumb_height' => $thumb['height'],
				'sha256'       => (string) $meta['sha256'],
				'key_version'  => DocumentStore::KEY_VERSION,
				'visible'      => $fields['visible'],
				'uploaded_by'  => $actor,
			),
			$this->app->clock->mysql()
		);

		if ( 0 === $id ) {
			$this->app->store->delete( $names['full'] );
			$this->app->store->delete( $names['thumb'] );
			return Errors::storage();
		}

		return $id;
	}

	/**
	 * The day the photo was taken, from its own data when it has one (before
	 * re-encoding drops it), otherwise today.
	 *
	 * @param string $path Uploaded file.
	 * @return string Y-m-d.
	 */
	private function taken_on( string $path ): string {
		$today = $this->app->clock->today();

		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$meta = wp_read_image_metadata( $path );
		$time = is_array( $meta ) ? (int) ( $meta['created_timestamp'] ?? 0 ) : 0;
		$day  = $time > 0 ? gmdate( 'Y-m-d', $time ) : '';

		return '' !== $day && $day <= $today && $day >= '2000-01-01' ? $day : $today;
	}

	/**
	 * A re-encoded copy no larger than $max on its longest side.
	 *
	 * @param string $path Source image.
	 * @param string $mime Its type.
	 * @param int    $max  Longest side, pixels.
	 * @param string $ext  File extension.
	 * @return array{path:string, width:int, height:int}|WP_Error
	 */
	private static function resized( string $path, string $mime, int $max, string $ext ): array|WP_Error {
		$editor = wp_get_image_editor( $path );

		if ( $editor instanceof WP_Error ) {
			return $editor;
		}

		$size = $editor->get_size();

		if ( $size['width'] > $max || $size['height'] > $max ) {
			$resized = $editor->resize( $max, $max, false );

			if ( $resized instanceof WP_Error ) {
				return $resized;
			}
		}

		$editor->set_quality( self::THUMB_MAX === $max ? 72 : 82 );

		// The editor writes next to an empty placeholder: remove it, keep only the image.
		$base = TempFile::create( 'dx-portal-photo' );
		wp_delete_file( $base );
		$saved = $editor->save( $base . '.' . $ext, $mime );

		if ( $saved instanceof WP_Error || ! is_array( $saved ) || ! isset( $saved['path'] ) ) {
			return $saved instanceof WP_Error ? $saved : new WP_Error( 'dx_photo_encode' );
		}

		return array(
			'path'   => (string) $saved['path'],
			'width'  => (int) ( $saved['width'] ?? 0 ),
			'height' => (int) ( $saved['height'] ?? 0 ),
		);
	}

	/**
	 * The photo, if it belongs to the case and the actor manages the case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $photo_id    Photo.
	 * @param int        $actor       Acting user.
	 * @return PhotoRow|WP_Error
	 */
	private function owned( CaseRecord $client_case, int $photo_id, int $actor ): array|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$photo = $this->app->photos->find( $photo_id );

		return null === $photo || $photo['case_id'] !== $client_case->id ? Errors::not_found() : $photo;
	}

	/**
	 * File extension for a type.
	 *
	 * @param string $mime Type.
	 * @return string
	 */
	private static function extension( string $mime ): string {
		return match ( $mime ) {
			'image/png'  => 'png',
			'image/webp' => 'webp',
			default      => 'jpg',
		};
	}
}
