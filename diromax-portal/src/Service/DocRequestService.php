<?php
/**
 * Document requests.
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
 * The project manager asks the clients for a document (spec 16.4). The
 * clients are emailed at once; the request stays open until one of them
 * uploads the document (DocumentService::upload_from_client) or the
 * manager cancels it.
 */
final class DocRequestService {

	/** Longest explanation. */
	private const NOTE_MAX = 500;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Ask for a document.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       title, note.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error Request ID.
	 */
	public function create( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$title  = Text::line( $input['title'] ?? '' );
		$note   = Text::block( $input['note'] ?? '' );
		$errors = array();

		if ( '' === $title ) {
			$errors['title'] = 'required';
		} elseif ( Text::length( $title ) > 200 ) {
			$errors['title'] = 'too_long';
		}

		if ( Text::length( $note ) > self::NOTE_MAX ) {
			$errors['note'] = 'too_long';
		}

		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		$now = $this->app->clock->mysql();
		$id  = $this->app->doc_requests->insert( $client_case->id, $title, $note, $actor, $now );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'docrequest.created', $client_case->id, 'docrequest', $id, array( 'title' => $title ), $now );
		$this->app->notifier->enqueue( $client_case->id, 'doc_requested', $id, true );

		return $id;
	}

	/**
	 * Cancel an open request.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Request.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function cancel( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$request = $this->app->doc_requests->find( $id );

		if ( null === $request || $request['case_id'] !== $client_case->id ) {
			return Errors::not_found();
		}

		$now = $this->app->clock->mysql();

		if ( ! $this->app->doc_requests->close( $id, 'cancelled', null, $now ) ) {
			return new WP_Error( 'dx_not_open', __( 'הבקשה כבר טופלה או בוטלה.', 'diromax-portal' ) );
		}

		$this->app->audit->record( 'docrequest.cancelled', $client_case->id, 'docrequest', $id, array( 'title' => $request['title'] ), $now );

		return true;
	}
}
