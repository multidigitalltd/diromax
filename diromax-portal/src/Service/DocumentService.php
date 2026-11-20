<?php
/**
 * Document use cases.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Security\DocumentStore;
use Diromax\Portal\Support\Ids;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Upload, visibility and download of case documents (standard 4.3).
 */
final class DocumentService {

	/** Allowed extensions and their MIME types. No scripts, HTML or SVG. */
	public const TYPES = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);

	/** Content sniffing reports Office files as ZIP on some systems. */
	private const ZIP_CONTAINERS = array( 'docx', 'xlsx' );

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Why documents cannot be stored right now, or null when they can.
	 *
	 * @return WP_Error|null
	 */
	public function storage_error(): ?WP_Error {
		return match ( $this->key_status() ) {
			'missing' => new WP_Error( 'dx_no_key', __( 'העלאת מסמכים חסומה: לא הוגדר מפתח הצפנה (DX_PORTAL_KEY).', 'diromax-portal' ), array( 'status' => 503 ) ),
			'changed' => new WP_Error( 'dx_key_changed', __( 'העלאת מסמכים חסומה: מפתח ההצפנה השתנה מאז שהועלו המסמכים הקיימים. יש להחזיר את המפתח הקודם ל-wp-config.php.', 'diromax-portal' ), array( 'status' => 503 ) ),
			default   => null,
		};
	}

	/**
	 * State of the encryption key: missing, changed (not the key the stored
	 * documents were encrypted with, so they cannot be opened), or ok.
	 *
	 * The key is remembered by its fingerprint. With no documents stored, a
	 * new key is simply adopted. On a site that stored documents before the
	 * fingerprint existed, the key is first tried on the latest stored files,
	 * and adopted only if it opens them.
	 *
	 * @return string
	 */
	public function key_status(): string {
		$current = $this->app->store->fingerprint();

		if ( null === $current ) {
			return 'missing';
		}

		$known = $this->app->store->known_fingerprint();

		if ( '' !== $known && hash_equals( $known, $current ) ) {
			return 'ok';
		}

		if ( ! $this->app->documents->any() ) {
			$this->app->store->remember_key();
			return 'ok';
		}

		return '' === $known ? $this->adopt_if_it_opens() : 'changed';
	}

	/**
	 * First check on a site with documents and no remembered key: adopt the
	 * key if it opens the latest stored file it can find.
	 *
	 * @return string ok or changed.
	 */
	private function adopt_if_it_opens(): string {
		foreach ( $this->app->documents->latest_stored( DocumentStore::KEY_VERSION, 3 ) as $stored_name ) {
			$opens = $this->app->store->can_open( $stored_name, DocumentStore::KEY_VERSION );

			if ( true === $opens ) {
				$this->app->store->remember_key();
				return 'ok';
			}

			if ( false === $opens ) {
				Logger::error( 'document.key_mismatch' );
				return 'changed';
			}
		}

		// None of the files is on disk to check against: nothing to protect.
		return 'ok';
	}

	/**
	 * The checks an upload can pass or fail without touching the file: it
	 * arrived, its size, and that its content matches an allowed type. For
	 * forms that must refuse a bad file before saving anything else.
	 *
	 * @param array<string, mixed> $file One $_FILES item.
	 * @return WP_Error|null
	 */
	public function precheck( array $file ): ?WP_Error {
		$path = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$name = is_string( $file['name'] ?? null ) ? sanitize_file_name( $file['name'] ) : '';
		$size = is_int( $file['size'] ?? null ) ? $file['size'] : 0;

		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $path || ! apply_filters( 'dx_portal_is_uploaded_file', is_uploaded_file( $path ), $path ) ) {
			return Errors::invalid( array( 'file' => 'missing' ) );
		}

		if ( $size <= 0 || $size > DocumentStore::MAX_BYTES ) {
			return Errors::invalid( array( 'file' => 'too_large' ) );
		}

		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime = self::TYPES[ $ext ] ?? null;

		if ( null === $mime || ! $this->content_matches( $path, $name, $ext, $mime ) ) {
			return Errors::invalid( array( 'file' => 'type' ) );
		}

		return null;
	}

	/**
	 * Validate, encrypt and record an uploaded file.
	 *
	 * @param CaseRecord           $client_case      Case.
	 * @param array<string, mixed> $file      One $_FILES item.
	 * @param string               $kind      Document kind.
	 * @param string               $title     Title; the file name when empty.
	 * @param bool                 $visible   Shown to the client.
	 * @param int                  $actor     Acting user.
	 * @param bool                 $notify    Email the client about it.
	 * @param int|null             $update_id Update it belongs to.
	 * @return DocumentRecord|WP_Error
	 */
	public function upload( CaseRecord $client_case, array $file, string $kind, string $title, bool $visible, int $actor, bool $notify, ?int $update_id = null ): DocumentRecord|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$blocked = $this->storage_error();

		if ( null !== $blocked ) {
			return $blocked;
		}

		$checked = $this->check_upload( $file );

		if ( $checked instanceof WP_Error ) {
			return $checked;
		}

		return $this->store_file( $client_case, $checked, $kind, $title, $visible, $actor, $notify, $update_id );
	}

	/**
	 * Show or hide a document from the client.
	 *
	 * @param CaseRecord     $client_case     Case.
	 * @param DocumentRecord $document Document.
	 * @param bool           $visible  Visibility.
	 * @param int            $actor    Acting user.
	 * @return true|WP_Error
	 */
	public function set_visible( CaseRecord $client_case, DocumentRecord $document, bool $visible, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) || $document->case_id !== $client_case->id ) {
			return Errors::forbidden();
		}

		$this->app->documents->set_visible( $document->id, $visible );
		$this->app->audit->record( 'document.visibility', $client_case->id, 'document', $document->id, array( 'visible' => $visible ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Delete a document and its encrypted file.
	 *
	 * @param CaseRecord     $client_case     Case.
	 * @param DocumentRecord $document Document.
	 * @param int            $actor    Acting user.
	 * @return true|WP_Error
	 */
	public function delete( CaseRecord $client_case, DocumentRecord $document, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) || $document->case_id !== $client_case->id ) {
			return Errors::forbidden();
		}

		if ( $this->app->decisions->uses_document( $document->id ) ) {
			// The decision's record of what the client saw depends on this file.
			return new WP_Error( 'dx_document_in_use', __( 'המסמך מצורף להחלטה בתיק, ולכן אי אפשר למחוק אותו. אפשר להסתיר אותו מהלקוח.', 'diromax-portal' ) );
		}

		$this->app->store->delete( $document->stored_name );
		$this->app->documents->delete( $document->id );
		$this->app->audit->record( 'document.deleted', $client_case->id, 'document', $document->id, array( 'title' => $document->title ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Decrypt and send a document to the browser as an attachment.
	 *
	 * @param CaseRecord     $client_case     Case.
	 * @param DocumentRecord $document Document.
	 * @param int            $viewer   Requesting user.
	 * @return true|WP_Error Error before any output; true once the file was sent.
	 */
	public function send( CaseRecord $client_case, DocumentRecord $document, int $viewer ): bool|WP_Error {
		if ( ! $this->app->policy->can_download( $viewer, $client_case, $document ) ) {
			return Errors::not_found();
		}

		$this->app->audit->record( 'document.downloaded', $client_case->id, 'document', $document->id, array(), $this->app->clock->mysql() );

		nocache_headers();
		header( 'Content-Type: ' . $document->mime );
		header( 'Content-Disposition: ' . self::disposition( $document ) );
		header( 'Content-Length: ' . $document->size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );

		$ok = $this->app->store->read(
			$document->stored_name,
			$document->key_version,
			static function ( string $chunk ): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file body.
				flush();
			}
		);

		if ( ! $ok ) {
			Logger::error( 'document.decrypt_failed', array( 'document' => $document->id ) );
		}

		return true;
	}

	/**
	 * Every check an upload must pass (standard 4.3.1–4.3.2, 4.3.6).
	 *
	 * @param array<string, mixed> $file One $_FILES item.
	 * @return array{path:string, name:string, ext:string, mime:string, temporary:bool}|WP_Error
	 */
	private function check_upload( array $file ): array|WP_Error {
		$problem = $this->precheck( $file );

		if ( null !== $problem ) {
			return $problem;
		}

		$path      = (string) $file['tmp_name'];
		$name      = sanitize_file_name( (string) $file['name'] );
		$ext       = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime      = self::TYPES[ $ext ];
		$file_info = array(
			'path'      => $path,
			'name'      => $name,
			'ext'       => $ext,
			'mime'      => $mime,
			'temporary' => false,
		);

		if ( str_starts_with( $mime, 'image/' ) ) {
			$file_info = $this->reencode_image( $file_info );
		}

		if ( $file_info instanceof WP_Error ) {
			return $file_info;
		}

		if ( ! apply_filters( 'dx_portal_scan_upload', true, $file_info['path'] ) ) {
			return Errors::invalid( array( 'file' => 'rejected' ) );
		}

		return $file_info;
	}

	/**
	 * Does the content match the extension? WordPress's check plus the file's magic bytes.
	 *
	 * @param string $path Temp path.
	 * @param string $name Original name.
	 * @param string $ext  Extension.
	 * @param string $mime Expected MIME.
	 * @return bool
	 */
	private function content_matches( string $path, string $name, string $ext, string $mime ): bool {
		$wp = wp_check_filetype_and_ext( $path, $name, array( $ext => $mime ) );

		if ( $mime !== $wp['type'] ) {
			return false;
		}

		$sniffed = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path );

		if ( $sniffed === $mime ) {
			return true;
		}

		return in_array( $ext, self::ZIP_CONTAINERS, true ) && 'application/zip' === $sniffed;
	}

	/**
	 * Re-encode an image to drop EXIF and location data (standard 4.3.6).
	 *
	 * @param array{path:string, name:string, ext:string, mime:string, temporary:bool} $file Checked upload.
	 * @return array{path:string, name:string, ext:string, mime:string, temporary:bool}|WP_Error
	 */
	private function reencode_image( array $file ): array|WP_Error {
		$editor = wp_get_image_editor( $file['path'] );

		if ( $editor instanceof WP_Error ) {
			return Errors::invalid( array( 'file' => 'type' ) );
		}

		$target = wp_tempnam( 'dx-portal-image' ) . '.' . $file['ext'];
		$saved  = $editor->save( $target, $file['mime'] );

		if ( $saved instanceof WP_Error || ! is_array( $saved ) || ! isset( $saved['path'] ) ) {
			return Errors::invalid( array( 'file' => 'type' ) );
		}

		$file['path']      = (string) $saved['path'];
		$file['temporary'] = true;

		return $file;
	}

	/**
	 * Encrypt into the store and record the metadata.
	 *
	 * @param CaseRecord                                                               $client_case      Case.
	 * @param array{path:string, name:string, ext:string, mime:string, temporary:bool} $file      Checked upload.
	 * @param string                                                                   $kind      Kind.
	 * @param string                                                                   $title     Title.
	 * @param bool                                                                     $visible   Visibility.
	 * @param int                                                                      $actor     Acting user.
	 * @param bool                                                                     $notify    Email the client.
	 * @param int|null                                                                 $update_id Update.
	 * @return DocumentRecord|WP_Error
	 */
	private function store_file( CaseRecord $client_case, array $file, string $kind, string $title, bool $visible, int $actor, bool $notify, ?int $update_id ): DocumentRecord|WP_Error {
		$stored = $this->app->store->new_name();

		try {
			$meta = $this->app->store->put( $file['path'], $stored );
		} catch ( \RuntimeException $error ) {
			Logger::error( 'document.store_failed', array( 'reason' => $error->getMessage() ) );
			return Errors::storage();
		} finally {
			if ( $file['temporary'] ) {
				wp_delete_file( $file['path'] );
			}
		}

		if ( '' === $this->app->store->known_fingerprint() ) {
			$this->app->store->remember_key();
		}

		$title = mb_substr( Text::line( $title ), 0, 200 );
		$now   = $this->app->clock->mysql();
		$id    = $this->app->documents->insert(
			array(
				'public_id'     => Ids::public_id(),
				'case_id'       => $client_case->id,
				'update_id'     => $update_id,
				'kind'          => in_array( $kind, DocumentRecord::KINDS, true ) ? $kind : 'other',
				'title'         => '' === $title ? mb_substr( $file['name'], 0, 200 ) : $title,
				'original_name' => mb_substr( $file['name'], 0, 200 ),
				'stored_name'   => $stored,
				'mime'          => $file['mime'],
				'size'          => $meta['size'],
				'sha256'        => $meta['sha256'],
				'key_version'   => DocumentStore::KEY_VERSION,
				'visible'       => $visible,
			),
			$actor,
			$now
		);

		$document = $id ? $this->app->documents->find( $id ) : null;

		if ( null === $document ) {
			$this->app->store->delete( $stored );
			return Errors::storage();
		}

		$this->app->audit->record( 'document.uploaded', $client_case->id, 'document', $id, array( 'kind' => $document->kind ), $now );

		if ( $notify && $visible ) {
			$this->app->notifier->enqueue( $client_case->id, 'document_added', $id, false );
		}

		return $document;
	}

	/**
	 * Content-Disposition with an ASCII fallback and the UTF-8 name (RFC 6266).
	 *
	 * @param DocumentRecord $document Document.
	 * @return string
	 */
	private static function disposition( DocumentRecord $document ): string {
		$ext      = strtolower( pathinfo( $document->original_name, PATHINFO_EXTENSION ) );
		$name     = Text::line( $document->title );
		$name     = str_ends_with( strtolower( $name ), '.' . $ext ) ? $name : $name . '.' . $ext;
		$fallback = 'document-' . $document->public_id . '.' . $ext;

		return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}
}
