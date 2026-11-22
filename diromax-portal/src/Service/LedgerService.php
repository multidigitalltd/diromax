<?php
/**
 * Ledger use cases.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\LedgerRow;
use Diromax\Portal\Domain\EntryDraft;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\EntryValidator;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\PayTo;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Settings;
use Diromax\Portal\Support\Transaction;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adding, editing, voiding and settling ledger entries.
 *
 * After every change: audit entry, refreshed summary cache, and — when the
 * entry is visible to the client and the manager asked for it — an event in
 * the notification queue.
 */
final class LedgerService {

	/** Document kind attached to each entry type. */
	private const DOCUMENT_KIND = array(
		'expense' => 'invoice',
		'payment' => 'receipt',
		'refund'  => 'receipt',
		'request' => 'invoice',
	);

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Add an entry, optionally with an attached file.
	 *
	 * @param CaseRecord                $client_case  Case.
	 * @param array<string, string>     $input Form fields.
	 * @param int                       $actor Acting user.
	 * @param array<string, mixed>|null $file  Uploaded file ($_FILES item) or null.
	 * @return int|WP_Error New entry ID.
	 */
	public function add( CaseRecord $client_case, array $input, int $actor, ?array $file = null ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$draft = $this->validator( $client_case )->validate( $input );

		if ( is_array( $draft ) ) {
			return Errors::invalid( $draft );
		}

		$document_id = null;

		if ( null !== $file ) {
			$document = $this->app->document_service->upload( $client_case, $file, self::DOCUMENT_KIND[ $draft->type->value ], $draft->description, $draft->visible, $actor, false );

			if ( $document instanceof WP_Error ) {
				return $document;
			}

			$document_id = $document->id;
		}

		$now = $this->app->clock->mysql();
		$id  = $this->app->ledger->insert( $client_case->id, $draft, $actor, $now, null, $document_id );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'ledger.created', $client_case->id, 'ledger', $id, self::summary_of( $draft ), $now );
		$this->after_change( $client_case, $draft, $id, EntryType::Request === $draft->type ? 'request_created' : 'entry_created' );

		return $id;
	}

	/**
	 * Edit an entry. The type cannot change; a settled request cannot be edited.
	 *
	 * @param CaseRecord            $client_case     Case.
	 * @param int                   $entry_id Entry.
	 * @param array<string, string> $input    Form fields.
	 * @param int                   $actor    Acting user.
	 * @return true|WP_Error
	 */
	public function update( CaseRecord $client_case, int $entry_id, array $input, int $actor ): bool|WP_Error {
		$row = $this->editable( $client_case, $entry_id, $actor );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$input['type'] = $row->entry->type->value;
		$draft         = $this->validator( $client_case )->validate( $input );

		if ( is_array( $draft ) ) {
			return Errors::invalid( $draft );
		}

		$now = $this->app->clock->mysql();

		if ( ! $this->app->ledger->update( $entry_id, $draft, $actor, $now ) ) {
			return Errors::storage();
		}

		$after = $this->app->ledger->find( $entry_id );
		$diff  = null === $after ? array() : Errors::diff( $row->entry, $after->entry, array( 'status', 'entry_date', 'due_date', 'category', 'payee', 'description', 'total', 'funding', 'visible' ) );

		$this->app->audit->record( 'ledger.updated', $client_case->id, 'ledger', $entry_id, $diff, $now );
		$this->after_change( $client_case, $draft, $entry_id, 'entry_updated' );

		return true;
	}

	/**
	 * Void an entry. Voiding the entry that settled a request reopens the request.
	 *
	 * @param CaseRecord $client_case     Case.
	 * @param int        $entry_id Entry.
	 * @param string     $reason   Reason, shown to the client.
	 * @param bool       $notify   Email the client.
	 * @param int        $actor    Acting user.
	 * @return true|WP_Error
	 */
	public function void( CaseRecord $client_case, int $entry_id, string $reason, bool $notify, int $actor ): bool|WP_Error {
		$row = $this->editable( $client_case, $entry_id, $actor );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$reason = Text::line( $reason );

		if ( '' === $reason || Text::length( $reason ) > 200 ) {
			return Errors::invalid( array( 'reason' => '' === $reason ? 'required' : 'too_long' ) );
		}

		$now = $this->app->clock->mysql();
		$ok  = Transaction::run(
			$this->app->db,
			function () use ( $row, $entry_id, $actor, $reason, $now ): bool {
				if ( ! $this->app->ledger->void( $entry_id, $actor, $reason, $now ) ) {
					return false;
				}

				return null === $row->entry->request_id || $this->app->ledger->reopen_request( $row->entry->request_id, $actor, $now );
			}
		);

		if ( ! $ok ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'ledger.voided', $client_case->id, 'ledger', $entry_id, array( 'reason' => $reason ), $now );
		$this->app->case_service->refresh_summary( $client_case->id );

		if ( $notify && $row->entry->visible ) {
			$this->app->notifier->enqueue( $client_case->id, 'entry_voided', $entry_id, false );
		}

		return true;
	}

	/**
	 * Mark an open request as paid, creating the entry that settles it:
	 * an expense paid by the client directly (request to a supplier) or a
	 * payment into the project account (request to Diromax).
	 *
	 * @param CaseRecord            $client_case       Case.
	 * @param int                   $request_id Request.
	 * @param array<string, string> $input      entry_date, category, method, reference, notify.
	 * @param int                   $actor      Acting user.
	 * @return int|WP_Error ID of the settling entry.
	 */
	public function settle( CaseRecord $client_case, int $request_id, array $input, int $actor ): int|WP_Error {
		$row = $this->editable( $client_case, $request_id, $actor );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		if ( EntryType::Request !== $row->entry->type ) {
			return Errors::invalid( array( 'type' => 'invalid' ) );
		}

		$draft = $this->validator( $client_case )->validate( self::settling_input( $row, $input ) );

		if ( is_array( $draft ) ) {
			return Errors::invalid( $draft );
		}

		$now = $this->app->clock->mysql();
		$id  = 0;
		$ok  = Transaction::run(
			$this->app->db,
			function () use ( $client_case, $draft, $actor, $now, $request_id, &$id ): bool {
				if ( ! $this->app->ledger->settle_request( $request_id, $actor, $now ) ) {
					return false;
				}

				$id = $this->app->ledger->insert( $client_case->id, $draft, $actor, $now, $request_id );

				return $id > 0;
			}
		);

		if ( ! $ok ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'ledger.request_settled', $client_case->id, 'ledger', $request_id, array( 'entry_id' => $id ), $now );
		$this->after_change( $client_case, $draft, $id, 'request_settled' );

		return $id;
	}

	/**
	 * Entry of this case that the actor may change: live and, for requests, still open.
	 *
	 * @param CaseRecord $client_case     Case.
	 * @param int        $entry_id Entry.
	 * @param int        $actor    Acting user.
	 * @return LedgerRow|WP_Error
	 */
	private function editable( CaseRecord $client_case, int $entry_id, int $actor ): LedgerRow|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$row = $this->app->ledger->find( $entry_id );

		if ( null === $row || $row->entry->case_id !== $client_case->id ) {
			return Errors::not_found();
		}

		if ( $row->entry->voided ) {
			return Errors::invalid( array( 'entry' => 'voided' ) );
		}

		if ( EntryType::Request === $row->entry->type && EntryStatus::Open !== $row->entry->status ) {
			return Errors::invalid( array( 'entry' => 'settled' ) );
		}

		return $row;
	}

	/**
	 * Form input for the entry that settles a request.
	 *
	 * @param LedgerRow             $request The open request.
	 * @param array<string, string> $input   Settlement form.
	 * @return array<string, string>
	 */
	private static function settling_input( LedgerRow $request, array $input ): array {
		$entry  = $request->entry;
		$common = array(
			'entry_date'  => $input['entry_date'] ?? '',
			'amount'      => Money::input( $entry->total ),
			'description' => $entry->description,
			'reference'   => $input['reference'] ?? '',
			'visible'     => $entry->visible ? '1' : '',
			'notify'      => $input['notify'] ?? '',
		);

		if ( PayTo::Supplier === $entry->pay_to ) {
			return $common + array(
				'type'     => EntryType::Expense->value,
				'status'   => EntryStatus::Paid->value,
				'category' => '' !== $entry->category ? $entry->category : ( $input['category'] ?? '' ),
				'payee'    => $entry->payee,
				'funding'  => 'client',
				'vat_mode' => 'included',
			);
		}

		return $common + array(
			'type'   => EntryType::Payment->value,
			'method' => $input['method'] ?? 'transfer',
		);
	}

	/**
	 * Summary cache and notification after a change.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param EntryDraft $draft What was saved.
	 * @param int        $id    Entry ID.
	 * @param string     $kind  Event kind.
	 */
	private function after_change( CaseRecord $client_case, EntryDraft $draft, int $id, string $kind ): void {
		$this->app->case_service->refresh_summary( $client_case->id );

		if ( $draft->visible && $draft->notify ) {
			$this->app->notifier->enqueue( $client_case->id, $kind, $id, 'request_created' === $kind );
		}
	}

	/**
	 * Validator configured with the case's budget lines (sections and
	 * sub-sections), the VAT rate and today's date.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return EntryValidator
	 */
	private function validator( CaseRecord $client_case ): EntryValidator {
		return new EntryValidator( $this->app->budget->tree( $client_case->id )->keys(), Settings::all()['vat_rate'], $this->app->clock->today() );
	}

	/**
	 * Audit details of a new entry.
	 *
	 * @param EntryDraft $draft Draft.
	 * @return array<string, string|int|bool>
	 */
	private static function summary_of( EntryDraft $draft ): array {
		return array(
			'type'    => $draft->type->value,
			'status'  => $draft->status->value,
			'total'   => $draft->total,
			'visible' => $draft->visible,
		);
	}
}
