<?php
/**
 * Portal view models.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Data\LedgerRow;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\CaseSummary;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\StageProgress;
use Diromax\Portal\Domain\StageStatus;
use Diromax\Portal\Plugin;
use Diromax\Portal\Service\Contact;
use Diromax\Portal\Service\DecisionService;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\MessageService;
use Diromax\Portal\Service\PhotoService;
use Diromax\Portal\Service\SignatureService;
use Diromax\Portal\Service\Stages;
use Diromax\Portal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds what the portal templates print. Only data the client may see goes
 * in: visible entries, documents and updates; no internal notes; no audit log.
 */
final class CaseView {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * The "my cases" list.
	 *
	 * @param CaseRecord[] $cases Cases the user may view.
	 * @return array{cases: array<int, array{title:string, number:string, scope:string, stage:string, status:string, url:string}>}
	 */
	public static function list( array $cases ): array {
		return array(
			'cases' => array_map(
				static fn( CaseRecord $client_case ): array => array(
					'title'  => $client_case->title,
					'number' => $client_case->number,
					'scope'  => $client_case->scope,
					'stage'  => Stages::summary( $client_case->stages ),
					'status' => Labels::case_statuses()[ $client_case->status->value ] ?? '',
					'url'    => Router::case_url( $client_case ),
				),
				$cases
			),
		);
	}

	/**
	 * The case page.
	 *
	 * @param CaseRecord $client_case   Case.
	 * @param int        $viewer Viewing user.
	 * @param string     $tab    Requested tab.
	 * @return array<string, mixed>
	 */
	public function build( CaseRecord $client_case, int $viewer, string $tab ): array {
		$rows       = $this->app->ledger->for_case( $client_case->id, true );
		$summary    = $this->app->case_service->summary( $client_case, true );
		$signatures = new SignatureService( $this->app );
		$signer     = $signatures->may_sign( $client_case, $viewer );

		return array(
			'case'       => array(
				'title'  => $client_case->title,
				'number' => $client_case->number,
				'scope'  => $client_case->scope,
				'track'  => $client_case->track,
				'closed' => 'closed' === $client_case->status->value,
			),
			'is_manager' => $this->app->policy->can_manage_case( $viewer, $client_case ),
			'manager'    => Contact::manager( $client_case->manager_id ),
			'stages'     => self::stages( $client_case ),
			'request'    => $this->request( $rows, $summary ),
			'kpis'       => self::kpis( $summary ),
			'entries'    => $this->entries( $rows ),
			'totals'     => array(
				'cost'      => Money::format( $summary->cost_to_date ),
				'committed' => Money::format( $summary->committed ),
				'payments'  => Money::format( $summary->payments ),
			),
			'categories' => self::categories( $summary ),
			'balance'    => $summary->has_project_account ? array(
				'payments' => Money::format( $summary->payments - $summary->refunds ),
				'spent'    => Money::format( $summary->payments - $summary->refunds - $summary->project_balance ),
				'balance'  => Money::format( $summary->project_balance ),
			) : null,
			'agreement'  => $this->agreement( $client_case, $viewer ),
			'decisions'  => $this->decisions( $client_case ),
			'can_answer' => DecisionService::may_answer( $this->app->members->role( $client_case->id, $viewer ) ),
			'to_sign'    => array_map(
				static fn( DocumentRecord $document ): array => array(
					'id'    => $document->id,
					'title' => $document->title,
				),
				$signatures->waiting_for( $client_case, $viewer )
			),
			'requests'   => $signer ? array_map(
				static fn( array $request ): array => array(
					'id'    => $request['id'],
					'title' => $request['title'],
					'note'  => $request['note'],
				),
				$this->app->doc_requests->for_case( $client_case->id, true )
			) : array(),
			'can_upload' => $signer && CaseStatus::Closed !== $client_case->status,
			'signing'    => $this->signing( $client_case, $viewer, $signatures ),
			'report'     => Router::report_url( $client_case ),
			'case_id'    => $client_case->id,
			'documents'  => $this->documents( $client_case ),
			'updates'    => $this->updates( $client_case ),
			'photos'     => ( new PhotoService( $this->app ) )->journal( $client_case, true ),
			'messages'   => ( new MessageService( $this->app ) )->thread( $client_case, $viewer, false ),
			'can_write'  => MessageService::may_write( $this->app->members->role( $client_case->id, $viewer ) ) && CaseStatus::Closed !== $client_case->status,
			'tab'        => in_array( $tab, Router::CASE_TABS, true ) ? $tab : 'finance',
			'filters'    => array(
				'types'      => Labels::types(),
				'categories' => Settings::categories(),
			),
		);
	}

	/**
	 * Stage tracker. Several stages can be in progress; only a single one
	 * is marked as the current step for assistive technology. Due dates are
	 * internal: they are given only for staff screens, when $today is set.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $today       Y-m-d for staff screens; '' for the client.
	 * @return array<int, array{number:string, name:string, status:string, label:string, when:string, current:bool, due:string, late:bool}>
	 */
	public static function stages( CaseRecord $client_case, string $today = '' ): array {
		$labels = Labels::stage_statuses();
		$single = 1 === count( StageProgress::active( $client_case->stages ) );
		$list   = array();

		foreach ( Stages::names() as $number => $name ) {
			$state  = $client_case->stages[ $number ] ?? null;
			$status = null !== $state && isset( $labels[ $state['status'] ] ) ? $state['status'] : StageStatus::Pending->value;
			$staff  = '' !== $today && null !== $state;
			$list[] = array(
				'number'  => Stages::number( $number ),
				'name'    => $name,
				'status'  => $status,
				'label'   => $labels[ $status ],
				'when'    => null === $state ? '' : Labels::stage_when( $state ),
				'current' => $single && StageStatus::Active->value === $status,
				'due'     => $staff && null !== $state['due'] && ! in_array( $status, array( 'done', 'skipped' ), true ) ? Labels::date( $state['due'] ) : '',
				'late'    => $staff && StageProgress::is_late( $state, $today ),
			);
		}

		return $list;
	}

	/**
	 * The open request with the nearest due date, for the orange banner.
	 *
	 * @param LedgerRow[] $rows    Visible entries.
	 * @param CaseSummary $summary Client summary.
	 * @return array{amount:string, due:string, description:string, pay_to:string, details:string, overdue:bool, more:int}|null
	 */
	private function request( array $rows, CaseSummary $summary ): ?array {
		foreach ( $rows as $row ) {
			if ( $row->entry->id !== $summary->next_request_id ) {
				continue;
			}

			$details = '' !== $row->payment_details ? $row->payment_details : ( '' === $row->entry->payee ? Settings::all()['payment_details'] : '' );

			return array(
				'amount'      => Money::format( $row->entry->total ),
				'due'         => Labels::date( $row->entry->due_date ),
				'description' => $row->entry->description,
				'pay_to'      => Labels::pay_to( $row->entry->pay_to, $row->entry->payee ),
				'details'     => $details,
				'overdue'     => $row->entry->is_overdue( $this->app->clock->today() ),
				'more'        => $summary->open_request_count - 1,
			);
		}

		return null;
	}

	/**
	 * KPI tiles. The project-account tile appears only when the account is used.
	 *
	 * @param CaseSummary $summary Client summary.
	 * @return array<int, array{label:string, value:string, note:string, highlight:bool, bar:array{paid:int, forecast:int}|null}>
	 */
	private static function kpis( CaseSummary $summary ): array {
		$tiles = array(
			array(
				'label'     => __( 'תקציב מאושר', 'diromax-portal' ),
				'value'     => Money::format( $summary->budget ),
				'note'      => __( 'כולל שכר ניהול ורזרבה', 'diromax-portal' ),
				'highlight' => false,
				'bar'       => null,
			),
			array(
				'label'     => __( 'עלות עד היום', 'diromax-portal' ),
				'value'     => Money::format( $summary->cost_to_date ),
				/* translators: 1: paid share, 2: share including commitments. */
				'note'      => $summary->budget > 0 ? sprintf( __( '%1$s שולם · %2$s כולל התחייבויות', 'diromax-portal' ), Money::percent( $summary->utilization_bps() ), Money::percent( $summary->forecast_bps() ) ) : '',
				'highlight' => false,
				'bar'       => $summary->budget > 0 ? array(
					'paid'     => min( 10000, $summary->utilization_bps() ),
					'forecast' => min( 10000, $summary->forecast_bps() ),
				) : null,
			),
			array(
				'label'     => __( 'התחייבויות פתוחות', 'diromax-portal' ),
				'value'     => Money::format( $summary->committed ),
				'note'      => __( 'הסכמים חתומים שטרם שולמו', 'diromax-portal' ),
				'highlight' => false,
				'bar'       => null,
			),
		);

		if ( $summary->has_project_account ) {
			$tiles[] = array(
				'label'     => __( 'יתרה בחשבון הפרויקט', 'diromax-portal' ),
				'value'     => Money::format( $summary->project_balance ),
				'note'      => $summary->project_balance >= 0 ? __( 'זמין להוצאות הבאות', 'diromax-portal' ) : __( 'דירומקס הקדימה את הסכום', 'diromax-portal' ),
				'highlight' => true,
				'bar'       => null,
			);
		}

		if ( $summary->deferred > 0 ) {
			$tiles[] = array(
				'label'     => __( 'לפירעון מהתמורה', 'diromax-portal' ),
				'value'     => Money::format( $summary->deferred ),
				'note'      => __( 'ייפרע מכספי המכירה', 'diromax-portal' ),
				'highlight' => false,
				'bar'       => null,
			);
		}

		return $tiles;
	}

	/**
	 * Ledger rows for the table.
	 *
	 * @param LedgerRow[] $rows Visible entries.
	 * @return array<int, array<string, mixed>>
	 */
	private function entries( array $rows ): array {
		$documents = array();

		foreach ( $this->app->documents->for_case( $rows[0]->entry->case_id ?? 0, true ) as $document ) {
			$documents[ $document->id ] = $document;
		}

		$list = array();

		foreach ( $rows as $row ) {
			$entry    = $row->entry;
			$document = null === $entry->document_id ? null : ( $documents[ $entry->document_id ] ?? null );
			$list[]   = array(
				'date'        => Labels::date( $entry->entry_date ),
				'type'        => $entry->type->value,
				'tag'         => Labels::entry_tag( $entry ),
				'tone'        => self::tone( $entry->type, $entry->status ),
				'description' => $entry->description,
				'sub'         => self::subline( $row ),
				'category'    => EntryType::Expense === $entry->type || '' !== $entry->category ? Settings::category_label( $entry->category ) : '—',
				'category_id' => $entry->category,
				'funding'     => null === $entry->funding ? '—' : ( Labels::funding()[ $entry->funding->value ] ?? '' ),
				'amount'      => ( EntryType::Payment === $entry->type ? '+' : '' ) . Money::format( $entry->total ),
				'vat'         => $entry->vat > 0 ? sprintf( /* translators: 1: net amount, 2: VAT. */ __( 'לפני מע״מ %1$s · מע״מ %2$s', 'diromax-portal' ), Money::format( $entry->net ), Money::format( $entry->vat ) ) : '',
				'positive'    => EntryType::Payment === $entry->type,
				'voided'      => $entry->voided,
				'void_reason' => $row->void_reason,
				'doc_url'     => null === $document ? '' : Router::document_url( $document ),
				'doc_label'   => null === $document ? '' : ( Labels::document_kinds()[ $document->kind ] ?? '' ),
				'counted'     => ! $entry->voided && EntryType::Request !== $entry->type,
			);
		}

		return $list;
	}

	/**
	 * Second line under the description.
	 *
	 * @param LedgerRow $row Row.
	 * @return string
	 */
	private static function subline( LedgerRow $row ): string {
		$entry = $row->entry;

		if ( EntryType::Request === $entry->type ) {
			$state = EntryStatus::Open === $entry->status ? __( 'פתוחה', 'diromax-portal' ) : __( 'שולמה', 'diromax-portal' );

			/* translators: 1: open/paid, 2: due date, 3: addressee. */
			return sprintf( __( '%1$s · עד %2$s · %3$s', 'diromax-portal' ), $state, Labels::date( $entry->due_date ), Labels::pay_to( $entry->pay_to, $entry->payee ) );
		}

		$parts = array_filter(
			array(
				EntryType::Expense === $entry->type ? $entry->payee : '',
				EntryType::Payment === $entry->type ? ( Labels::methods()[ $row->method ] ?? '' ) : '',
				'' !== $row->reference ? sprintf( /* translators: %s: reference. */ __( 'אסמכתא %s', 'diromax-portal' ), $row->reference ) : '',
				EntryType::Expense === $entry->type && EntryStatus::Committed === $entry->status ? __( 'טרם שולם', 'diromax-portal' ) : '',
			)
		);

		return implode( ' · ', $parts );
	}

	/**
	 * Tag style per entry.
	 *
	 * @param EntryType   $type   Type.
	 * @param EntryStatus $status Status.
	 * @return string exp, com, pay, req.
	 */
	private static function tone( EntryType $type, EntryStatus $status ): string {
		return match ( $type ) {
			EntryType::Expense => EntryStatus::Committed === $status ? 'com' : 'exp',
			EntryType::Payment, EntryType::Refund => 'pay',
			EntryType::Request => 'req',
		};
	}

	/**
	 * Budget against actuals per category.
	 *
	 * @param CaseSummary $summary Client summary.
	 * @return array<int, array{label:string, paid:string, planned:string, committed:string, paid_pct:float, committed_pct:float}>
	 */
	private static function categories( CaseSummary $summary ): array {
		$list = array();

		foreach ( $summary->categories as $key => $line ) {
			$base   = max( $line['planned'], $line['paid'] + $line['committed'], 1 );
			$list[] = array(
				'label'         => Settings::category_label( (string) $key ),
				'paid'          => Money::plain( $line['paid'] ),
				'planned'       => Money::format( $line['planned'] ),
				'committed'     => $line['committed'] > 0 ? Money::plain( $line['committed'] ) : '',
				'paid_pct'      => round( $line['paid'] * 100 / $base, 1 ),
				'committed_pct' => round( ( $line['paid'] + $line['committed'] ) * 100 / $base, 1 ),
			);
		}

		return $list;
	}

	/**
	 * Agreement card: terms from the case, file from the newest agreement document.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $viewer      Viewing user.
	 * @return array{signed:string, scope:string, track:string, fee:string, url:string, size:string, id:int, confirmed:string, can_ack:bool}
	 */
	private function agreement( CaseRecord $client_case, int $viewer ): array {
		$url  = '';
		$size = '';
		$id   = 0;

		foreach ( $this->app->documents->for_case( $client_case->id, true ) as $document ) {
			if ( 'agreement' === $document->kind ) {
				$url  = Router::document_url( $document );
				$size = size_format( $document->size, 1 );
				$id   = $document->id;
				break;
			}
		}

		$confirmed = '';

		foreach ( $this->app->acknowledgements->for_case( $client_case->id )[ $id ] ?? array() as $ack ) {
			if ( $ack['user_id'] === $viewer ) {
				$confirmed = Labels::datetime( $ack['created_at'] );
			}
		}

		return array(
			'signed'    => Labels::date( $client_case->signed_on ),
			'scope'     => $client_case->scope,
			'track'     => $client_case->track,
			'fee'       => $client_case->fee_terms,
			'url'       => $url,
			'size'      => (string) $size,
			'id'        => $id,
			'confirmed' => $confirmed,
			'can_ack'   => 0 !== $id && $this->app->members->is_member( $client_case->id, $viewer ),
		);
	}

	/**
	 * The printable report: the case page's data, every tab at once, and the
	 * signatures on visible documents and on decisions.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $viewer      Viewing user.
	 * @return array<string, mixed>
	 */
	public function report( CaseRecord $client_case, int $viewer ): array {
		$view      = $this->build( $client_case, $viewer, 'finance' );
		$visible   = array();
		$decisions = array();
		$signed    = array();

		foreach ( $this->app->documents->for_case( $client_case->id, true ) as $document ) {
			$visible[ $document->id ] = $document;
		}

		foreach ( $this->app->decisions->for_case( $client_case->id ) as $decision ) {
			$decisions[ $decision->id ] = $decision;
		}

		foreach ( $this->app->signatures->for_case( $client_case->id ) as $signature ) {
			$document = 'document' === $signature['subject_type'] ? ( $visible[ $signature['subject_id'] ] ?? null ) : null;
			$decision = 'decision' === $signature['subject_type'] ? ( $decisions[ $signature['subject_id'] ] ?? null ) : null;
			$valid    = null !== $document ? hash_equals( $document->sha256, $signature['sha256'] ) : null !== $decision;

			if ( ! $valid ) {
				continue;
			}

			$signed[] = array(
				'what'   => null !== $document ? $document->title : (string) $decision?->title,
				'kind'   => null !== $document ? __( 'מסמך', 'diromax-portal' ) : __( 'החלטה', 'diromax-portal' ),
				'signer' => $signature['signer_name'],
				'when'   => Labels::datetime( $signature['signed_at'] ),
				'how'    => 'link' === $signature['method'] ? __( 'קישור במייל וקוד', 'diromax-portal' ) : __( 'אזור אישי', 'diromax-portal' ),
				'sha256' => $signature['sha256'],
			);
		}

		$view['signed']  = $signed;
		$view['summary'] = Stages::summary( $client_case->stages );

		return $view;
	}

	/**
	 * Signing state of each signable document, for the documents tab.
	 *
	 * @param CaseRecord       $client_case Case.
	 * @param int              $viewer      Viewing user.
	 * @param SignatureService $signatures  Service.
	 * @return array<int, array{signed:string, can_sign:bool}> Document ID => state.
	 */
	private function signing( CaseRecord $client_case, int $viewer, SignatureService $signatures ): array {
		$may   = $signatures->may_sign( $client_case, $viewer );
		$state = array();

		foreach ( $this->app->documents->for_case( $client_case->id, true ) as $document ) {
			if ( ! SignatureService::is_signable( $document ) ) {
				continue;
			}

			$mine = '';

			foreach ( $signatures->of_document( $document ) as $signature ) {
				if ( $signature['user_id'] === $viewer ) {
					$mine = Labels::datetime( (string) $signature['signed_at'] );
				}
			}

			$state[ $document->id ] = array(
				'signed'   => $mine,
				'can_sign' => $may && '' === $mine,
			);
		}

		return $state;
	}

	/**
	 * Decisions of the case for the portal.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array<string, mixed>>
	 */
	private function decisions( CaseRecord $client_case ): array {
		$statuses = Labels::decision_statuses();
		$list     = array();

		foreach ( $this->app->decisions->for_case( $client_case->id ) as $decision ) {
			$document = null === $decision->document_id ? null : $this->app->documents->find( $decision->document_id );
			$answerer = null === $decision->answered_by ? false : get_userdata( $decision->answered_by );
			$list[]   = array(
				'id'       => $decision->id,
				'title'    => $decision->title,
				'body'     => $decision->body,
				'amount'   => null === $decision->amount ? '' : Money::format( $decision->amount ),
				'due'      => Labels::date( $decision->due_date ),
				'status'   => $decision->status->value,
				'label'    => $statuses[ $decision->status->value ] ?? '',
				'pending'  => $decision->is_pending(),
				'sent'     => Labels::date( $decision->created_at ),
				/* translators: 1: who, 2: date and time. */
				'answered' => null === $decision->answered_at ? '' : sprintf( __( '%1$s, %2$s', 'diromax-portal' ), $answerer ? (string) $answerer->display_name : '', Labels::datetime( $decision->answered_at ) ),
				'note'     => $decision->answer_note,
				'reason'   => $decision->withdraw_reason,
				'doc'      => null !== $document && $document->visible ? array(
					'title' => $document->title,
					'url'   => Router::document_url( $document ),
				) : null,
			);
		}

		return $list;
	}

	/**
	 * Visible documents grouped by kind.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<string, array<int, array{id:int, title:string, url:string, date:string, meta:string}>>
	 */
	private function documents( CaseRecord $client_case ): array {
		$kinds  = Labels::document_kinds();
		$groups = array();

		foreach ( $this->app->documents->for_case( $client_case->id, true ) as $document ) {
			$label              = $kinds[ $document->kind ] ?? $kinds['other'];
			$groups[ $label ][] = array(
				'id'    => $document->id,
				'title' => $document->title,
				'url'   => Router::document_url( $document ),
				'date'  => Labels::date( $document->created_at ),
				'meta'  => strtoupper( (string) pathinfo( $document->original_name, PATHINFO_EXTENSION ) ) . ' · ' . size_format( $document->size, 1 ),
			);
		}

		return $groups;
	}

	/**
	 * Visible updates, newest first, with their attachments.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array{date:string, iso:string, title:string, body:string, stage:string, files:array<int, array{title:string, url:string}>}>
	 */
	private function updates( CaseRecord $client_case ): array {
		$files = array();

		foreach ( $this->app->documents->for_case( $client_case->id, true ) as $document ) {
			if ( null !== $document->update_id ) {
				$files[ $document->update_id ][] = array(
					'title' => $document->title,
					'url'   => Router::document_url( $document ),
				);
			}
		}

		return array_map(
			static fn( array $update ): array => array(
				'date'  => Labels::date( $update['created_at'] ),
				'iso'   => substr( $update['created_at'], 0, 10 ),
				'title' => $update['title'],
				'body'  => $update['body'],
				/* translators: 1: stage number, 2: stage name. */
				'stage' => null === $update['stage'] ? '' : sprintf( __( 'עברנו לשלב %1$s: %2$s', 'diromax-portal' ), str_pad( (string) $update['stage'], 2, '0', STR_PAD_LEFT ), Stages::name( $update['stage'] ) ),
				'files' => $files[ $update['id'] ] ?? array(),
			),
			$this->app->updates->for_case( $client_case->id, true )
		);
	}
}
