<?php
/**
 * Progress updates.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Publishing a progress update, optionally moving the case to a stage and
 * attaching photos or documents.
 */
final class UpdateService {

	/** Maximum attachments per update. */
	private const MAX_FILES = 10;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Publish an update.
	 *
	 * @param CaseRecord                       $client_case  Case.
	 * @param array<string, string>            $input title, body, stage ('' for none), visible, notify.
	 * @param array<int, array<string, mixed>> $files Uploaded files.
	 * @param int                              $actor Acting user.
	 * @return int|WP_Error Update ID.
	 */
	public function publish( CaseRecord $client_case, array $input, array $files, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$title   = Text::line( $input['title'] ?? '' );
		$body    = Text::block( $input['body'] ?? '' );
		$stage   = '' === ( $input['stage'] ?? '' ) ? null : (int) $input['stage'];
		$visible = '1' === ( $input['visible'] ?? '' );
		$errors  = $this->validate( $title, $body, $stage, count( $files ) );

		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		// Attachments are stored after the update is saved: refuse now what
		// would be refused then, so an update is never published without them.
		$attachments = $this->check_files( $files );

		if ( null !== $attachments ) {
			return $attachments;
		}

		$now = $this->app->clock->mysql();
		$id  = $this->app->updates->insert( $client_case->id, $title, $body, $stage, $visible, $actor, $now );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'update.published', $client_case->id, 'update', $id, array( 'stage' => $stage ), $now );

		if ( null !== $stage && $stage !== $client_case->stage ) {
			$this->app->case_service->move_to_stage( $client_case, $stage, $actor );
		}

		foreach ( $files as $file ) {
			$kind = str_starts_with( (string) ( $file['type'] ?? '' ), 'image/' ) ? 'photo' : 'other';
			$this->app->document_service->upload( $client_case, $file, $kind, '', $visible, $actor, false, $id );
		}

		if ( $visible && '1' === ( $input['notify'] ?? '' ) ) {
			$this->app->notifier->enqueue( $client_case->id, 'update_published', $id, false );
		}

		return $id;
	}

	/**
	 * Can every attachment be stored? The key must be usable and each file
	 * must pass the upload checks.
	 *
	 * @param array<int, array<string, mixed>> $files Uploaded files.
	 * @return WP_Error|null
	 */
	private function check_files( array $files ): ?WP_Error {
		if ( array() === $files ) {
			return null;
		}

		if ( null !== $this->app->document_service->storage_error() ) {
			return Errors::invalid( array( 'files' => 'storage' ) );
		}

		foreach ( $files as $file ) {
			$problem = $this->app->document_service->precheck( $file );

			if ( null !== $problem ) {
				return Errors::invalid( array( 'files' => (string) ( Errors::fields( $problem )['file'] ?? 'missing' ) ) );
			}
		}

		return null;
	}

	/**
	 * Field checks.
	 *
	 * @param string   $title Title.
	 * @param string   $body  Body.
	 * @param int|null $stage Stage.
	 * @param int      $files Number of files.
	 * @return array<string, string>
	 */
	private function validate( string $title, string $body, ?int $stage, int $files ): array {
		$errors = array();

		if ( '' === $title ) {
			$errors['title'] = 'required';
		} elseif ( Text::length( $title ) > 200 ) {
			$errors['title'] = 'too_long';
		}

		if ( Text::length( $body ) > 5000 ) {
			$errors['body'] = 'too_long';
		}

		if ( null !== $stage && ( $stage < 1 || $stage > Stages::COUNT ) ) {
			$errors['stage'] = 'invalid';
		}

		if ( $files > self::MAX_FILES ) {
			$errors['files'] = 'too_many';
		}

		return $errors;
	}
}
