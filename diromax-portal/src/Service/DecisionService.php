<?php
/**
 * Decisions put to the client.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DecisionRecord;
use Diromax\Portal\Domain\DecisionStatus;
use Diromax\Portal\Domain\EntryValidator;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Support\Transaction;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Open, withdraw and answer decisions (spec 15.5).
 *
 * The answer records a SHA-256 fingerprint of exactly what the client saw:
 * title, details, amount, due date and the attached file's own hash.
 */
final class DecisionService {

	/** Longest details text. */
	private const BODY_MAX = 4000;

	/** Longest client note. */
	private const NOTE_MAX = 500;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Put a decision to the case's clients.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       title, body, amount, document_id, due_date, notify.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error New decision ID.
	 */
	public function create( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$fields = $this->validate( $client_case, $input );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		$now = $this->app->clock->mysql();
		$id  = $this->app->decisions->insert( $client_case->id, $fields, $actor, $now );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'decision.created', $client_case->id, 'decision', $id, array( 'title' => $fields['title'] ), $now );

		if ( '1' === ( $input['notify'] ?? '' ) ) {
			$this->app->notifier->enqueue( $client_case->id, 'decision_created', $id, true );
		}

		return $id;
	}

	/**
	 * Withdraw a decision that is still pending.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Decision.
	 * @param string     $reason      Why.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function withdraw( CaseRecord $client_case, int $id, string $reason, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$decision = $this->app->decisions->find( $id );
		$reason   = mb_substr( Text::line( $reason ), 0, 200 );

		if ( null === $decision || $decision->case_id !== $client_case->id ) {
			return Errors::not_found();
		}

		if ( '' === $reason ) {
			return Errors::invalid( array( 'reason' => 'required' ) );
		}

		$now = $this->app->clock->mysql();

		if ( ! $this->app->decisions->withdraw( $id, $reason, $now ) ) {
			return self::not_pending();
		}

		$this->app->audit->record( 'decision.withdrawn', $client_case->id, 'decision', $id, array( 'reason' => $reason ), $now );

		return true;
	}

	/**
	 * The client approves or declines.
	 *
	 * Only an owner or partner of the case may answer; a viewer and the staff
	 * previewing the portal may not. The first answer wins.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $id          Decision.
	 * @param int                   $user_id     Answering user.
	 * @param string                $answer      approve or decline.
	 * @param string                $note        Optional note.
	 * @param bool                  $confirmed   The "I have read the details" box, required to approve.
	 * @param array<string, string> $signature   name, image, no_draw: approving is signing (spec 16.6).
	 * @return true|WP_Error
	 */
	public function answer( CaseRecord $client_case, int $id, int $user_id, string $answer, string $note, bool $confirmed, array $signature = array() ): bool|WP_Error {
		$decision = $this->app->decisions->find( $id );

		if ( null === $decision || $decision->case_id !== $client_case->id || ! $this->app->policy->can_view_case( $user_id, $client_case ) ) {
			return Errors::not_found();
		}

		if ( ! self::may_answer( $this->app->members->role( $client_case->id, $user_id ) ) ) {
			return Errors::forbidden();
		}

		$status = match ( $answer ) {
			'approve' => DecisionStatus::Approved,
			'decline' => DecisionStatus::Declined,
			default   => null,
		};
		$note = Text::block( $note );

		if ( null === $status || Text::length( $note ) > self::NOTE_MAX ) {
			return Errors::invalid( array( 'answer' => 'invalid' ) );
		}

		if ( DecisionStatus::Approved === $status && ! $confirmed ) {
			return Errors::invalid( array( 'confirm' => 'required' ) );
		}

		$signatures = new SignatureService( $this->app );
		$signed     = $signatures->check_portal( array( 'confirm' => '1' ) + $signature );

		if ( DecisionStatus::Approved === $status && $signed['errors'] ) {
			return Errors::invalid( $signed['errors'] );
		}

		if ( DecisionStatus::Approved === $status && '' !== $signed['image'] && ! $this->app->store->is_configured() ) {
			// The drawing is stored encrypted: without the key, nothing is answered.
			return Errors::storage();
		}

		$now         = $this->app->clock->mysql();
		$fingerprint = $this->fingerprint( $decision );
		$failure     = null;

		// The answer and, for an approval, its signature are stored together or not at all:
		// an approval never stands without the signature that makes it.
		$saved = Transaction::run(
			$this->app->db,
			function () use ( $client_case, $id, $status, $note, $user_id, $fingerprint, $now, $signatures, $signed, &$failure ): bool {
				if ( ! $this->app->decisions->answer( $id, $status, $note, $user_id, $fingerprint, $now ) ) {
					$failure = self::not_pending();
					return false;
				}

				if ( DecisionStatus::Approved !== $status ) {
					return true;
				}

				$user      = get_userdata( $user_id );
				$signature = $signatures->record( $client_case, 'decision', $id, $fingerprint, $signed, $user_id, $user ? (string) $user->user_email : '', 'portal', null, null );

				if ( $signature instanceof WP_Error ) {
					$failure = $signature;
					return false;
				}

				return true;
			}
		);

		if ( ! $saved ) {
			return $failure ?? Errors::storage();
		}

		$this->app->audit->record( 'decision.answered', $client_case->id, 'decision', $id, array( 'status' => $status->value ), $now );
		$this->tell_manager( $client_case, $decision, $status, $note, $user_id );

		return true;
	}

	/**
	 * May a member with this role answer decisions?
	 *
	 * @param MemberRole|null $role Role, or null for a non-member.
	 * @return bool
	 */
	public static function may_answer( ?MemberRole $role ): bool {
		return MemberRole::Owner === $role || MemberRole::Partner === $role;
	}

	/**
	 * SHA-256 of what the client is shown for a decision.
	 *
	 * @param DecisionRecord $decision Decision.
	 * @return string
	 */
	public function fingerprint( DecisionRecord $decision ): string {
		$document = null === $decision->document_id ? null : $this->app->documents->find( $decision->document_id );

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'title'    => $decision->title,
					'body'     => $decision->body,
					'amount'   => $decision->amount,
					'due_date' => $decision->due_date,
					'document' => $document?->sha256,
				)
			)
		);
	}

	/**
	 * Validate the manager's form.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Input.
	 * @return array{title:string, body:string, amount:int|null, document_id:int|null, due_date:string|null}|WP_Error
	 */
	private function validate( CaseRecord $client_case, array $input ): array|WP_Error {
		$title    = mb_substr( Text::line( $input['title'] ?? '' ), 0, 200 );
		$body     = Text::block( $input['body'] ?? '' );
		$raw      = trim( $input['amount'] ?? '' );
		$amount   = '' === $raw ? null : Money::parse( $raw );
		$doc_id   = absint( $input['document_id'] ?? 0 );
		$document = 0 === $doc_id ? null : $this->app->documents->find( $doc_id );
		$due      = trim( $input['due_date'] ?? '' );
		$errors   = array();

		if ( '' === $title ) {
			$errors['title'] = 'required';
		}

		if ( '' === $body || Text::length( $body ) > self::BODY_MAX ) {
			$errors['body'] = '' === $body ? 'required' : 'too_long';
		}

		if ( '' !== $raw && null === $amount ) {
			$errors['amount'] = 'invalid';
		}

		if ( 0 !== $doc_id && ( null === $document || $document->case_id !== $client_case->id || ! $document->visible ) ) {
			$errors['document_id'] = 'invalid';
		}

		if ( '' !== $due && ! EntryValidator::is_date( $due ) ) {
			$errors['due_date'] = 'invalid';
		}

		if ( array() !== $errors ) {
			return Errors::invalid( $errors );
		}

		return array(
			'title'       => $title,
			'body'        => $body,
			'amount'      => $amount,
			'document_id' => null === $document ? null : $document->id,
			'due_date'    => '' === $due ? null : $due,
		);
	}

	/**
	 * Email the project manager about the answer.
	 *
	 * @param CaseRecord     $client_case Case.
	 * @param DecisionRecord $decision    Decision as it was shown.
	 * @param DecisionStatus $status      Answer.
	 * @param string         $note        Client note.
	 * @param int            $user_id     Who answered.
	 */
	private function tell_manager( CaseRecord $client_case, DecisionRecord $decision, DecisionStatus $status, string $note, int $user_id ): void {
		$user  = get_userdata( $user_id );
		$badge = DecisionStatus::Approved === $status ? __( 'הלקוח אישר', 'diromax-portal' ) : __( 'הלקוח דחה', 'diromax-portal' );
		$card  = new MailCard(
			tone: DecisionStatus::Approved === $status ? 'teal' : 'grey',
			badge: $badge,
			title: $decision->title,
			rows: array( array( __( 'מי ענה', 'diromax-portal' ), $user ? (string) $user->display_name : '' ) ),
			amount: null === $decision->amount ? null : Money::format( $decision->amount ),
			note: $note,
			stage: null,
			link: null,
			subject: $badge . ': ' . $decision->title,
		);

		$this->app->notifier->send_to_manager( $client_case, $this->app->renderer->manager_notice( $client_case, $card, Admin::case_url( $client_case->id, 'decisions' ) ) );
	}

	/**
	 * The decision was answered or withdrawn already.
	 *
	 * @return WP_Error
	 */
	private static function not_pending(): WP_Error {
		return new WP_Error( 'dx_not_pending', __( 'ההחלטה כבר נענתה או בוטלה, ולכן אי אפשר לשנות אותה.', 'diromax-portal' ) );
	}
}
