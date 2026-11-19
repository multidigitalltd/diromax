<?php
/**
 * Agreement read confirmations.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * "I have read the agreement" (spec 15.6). Not a signature: a dated record
 * that a member saw a specific file, identified by its SHA-256.
 */
final class AgreementService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Record that a member read an agreement. Repeating it is harmless.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $document_id Agreement document.
	 * @param int        $user_id     Member.
	 * @return true|WP_Error
	 */
	public function acknowledge( CaseRecord $client_case, int $document_id, int $user_id ): bool|WP_Error {
		$document = $this->app->documents->find( $document_id );

		if ( null === $document || ! self::is_agreement_of( $document, $client_case ) || ! $this->app->policy->can_view_case( $user_id, $client_case ) || ! $this->app->members->is_member( $client_case->id, $user_id ) ) {
			return Errors::not_found();
		}

		$now = $this->app->clock->mysql();

		if ( $this->app->acknowledgements->add( $document, $user_id, $now ) ) {
			$this->app->audit->record( 'agreement.acknowledged', $client_case->id, 'document', $document->id, array( 'sha256' => $document->sha256 ), $now );
			$this->tell_manager( $client_case, $document, $user_id );
		}

		return true;
	}

	/**
	 * Agreements in the case the member has not confirmed yet.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $user_id     Member.
	 * @return DocumentRecord[]
	 */
	public function waiting_for( CaseRecord $client_case, int $user_id ): array {
		if ( ! $this->app->members->is_member( $client_case->id, $user_id ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$this->app->documents->for_case( $client_case->id, true ),
				fn( DocumentRecord $document ): bool => 'agreement' === $document->kind && ! $this->app->acknowledgements->exists( $document->id, $user_id )
			)
		);
	}

	/**
	 * A visible agreement of this case?
	 *
	 * @param DocumentRecord $document    Document.
	 * @param CaseRecord     $client_case Case.
	 * @return bool
	 */
	private static function is_agreement_of( DocumentRecord $document, CaseRecord $client_case ): bool {
		return $document->case_id === $client_case->id && $document->visible && 'agreement' === $document->kind;
	}

	/**
	 * Email the project manager.
	 *
	 * @param CaseRecord     $client_case Case.
	 * @param DocumentRecord $document    Agreement.
	 * @param int            $user_id     Member.
	 */
	private function tell_manager( CaseRecord $client_case, DocumentRecord $document, int $user_id ): void {
		$user = get_userdata( $user_id );
		$card = new MailCard(
			tone: 'teal',
			badge: __( 'הלקוח אישר שקרא את ההסכם', 'diromax-portal' ),
			title: $document->title,
			rows: array( array( __( 'מי אישר', 'diromax-portal' ), $user ? (string) $user->display_name : '' ) ),
			amount: null,
			note: '',
			stage: null,
			link: null,
			subject: __( 'אישור קריאת הסכם', 'diromax-portal' ),
		);

		$this->app->notifier->send_to_manager( $client_case, $this->app->renderer->manager_notice( $client_case, $card, Admin::case_url( $client_case->id, 'documents' ) ) );
	}
}
