<?php
/**
 * Email items.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Turns pending events into the cards of an update email.
 *
 * Only what the client may see becomes a card: hidden entries and documents,
 * objects of another case and entries voided before the client ever saw them
 * are dropped here, so the template never has to decide.
 */
final class MailItems {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Build the cards.
	 *
	 * @param CaseRecord                                    $client_case         Case.
	 * @param array<int, array{kind:string, object_id:int}> $events       Pending events, oldest first.
	 * @param bool                                          $hide_amounts Leave amounts out.
	 * @return MailCard[]
	 */
	public function build( CaseRecord $client_case, array $events, bool $hide_amounts ): array {
		$cards = array();
		$kinds = array();

		foreach ( $events as $event ) {
			$kind = $event['kind'];
			$key  = in_array( $kind, array( 'update_published', 'document_added', 'request_reminder', 'decision_created', 'doc_requested', 'photos_added', 'schedule_changed', 'visit_published', 'quote_sent' ), true ) ? $kind . ':' . $event['object_id'] : 'entry:' . $event['object_id'];

			if ( 'entry_voided' === $kind && 'entry_created' === ( $kinds[ $key ] ?? '' ) ) {
				// Created and voided within one batch: the client never needs to hear of it.
				unset( $cards[ $key ] );
				continue;
			}

			if ( isset( $kinds[ $key ] ) ) {
				continue;
			}

			$card = match ( $kind ) {
				'update_published' => $this->update_card( $client_case, $event['object_id'] ),
				'document_added'   => $this->document_card( $client_case, $event['object_id'] ),
				'decision_created' => $this->decision_card( $client_case, $event['object_id'], $hide_amounts ),
				'doc_requested'    => $this->doc_request_card( $client_case, $event['object_id'] ),
				'photos_added'     => $this->photos_card( $client_case, $event['object_id'] ),
				'schedule_changed' => $this->schedule_card( $client_case ),
				'visit_published'  => $this->visit_card( $client_case, $event['object_id'] ),
				'quote_sent'       => $this->quote_card( $client_case, $event['object_id'] ),
				default            => $this->entry_card( $client_case, $kind, $event['object_id'], $hide_amounts ),
			};

			$kinds[ $key ] = $kind;

			if ( null !== $card ) {
				$cards[ $key ] = $card;
			}
		}

		return array_values( $cards );
	}

	/**
	 * Card for a ledger event.
	 *
	 * @param CaseRecord $client_case         Case.
	 * @param string     $kind         Event kind.
	 * @param int        $id           Entry.
	 * @param bool       $hide_amounts Leave the amount out.
	 * @return MailCard|null
	 */
	private function entry_card( CaseRecord $client_case, string $kind, int $id, bool $hide_amounts ): ?MailCard {
		$row = $this->app->ledger->find( $id );

		if ( null === $row || $row->entry->case_id !== $client_case->id || ! $row->entry->visible || ( $row->entry->voided && 'entry_voided' !== $kind ) ) {
			return null;
		}

		if ( 'request_reminder' === $kind ) {
			if ( ! $row->entry->is_open_request() ) {
				// Paid or voided after the reminder was queued: nothing to remind of.
				return null;
			}

			$kind = $row->entry->is_overdue( $this->app->clock->today() ) ? 'reminder_late' : 'reminder';
		}

		$entry  = $row->entry;
		$amount = $hide_amounts ? null : Money::format( $entry->total );
		$rows   = array( array( __( 'תאריך', 'diromax-portal' ), Labels::date( $entry->entry_date ) ) );

		switch ( $entry->type ) {
			case EntryType::Expense:
				$rows[] = array( __( 'ספק', 'diromax-portal' ), $entry->payee );
				$rows[] = array( __( 'סעיף בתקציב', 'diromax-portal' ), Labels::budget_line( $this->app->budget->tree( $client_case->id ), $entry->category ) );
				$rows[] = array( __( 'שולם מ', 'diromax-portal' ), Labels::funding()[ $entry->funding->value ?? '' ] ?? '' );
				break;
			case EntryType::Payment:
				$rows[] = array( __( 'אמצעי תשלום', 'diromax-portal' ), Labels::methods()[ $row->method ] ?? '' );
				break;
			case EntryType::Request:
				$rows[] = array( __( 'לתשלום עד', 'diromax-portal' ), Labels::date( $entry->due_date ) );
				$rows[] = array( __( 'למי משלמים', 'diromax-portal' ), Labels::pay_to( $entry->pay_to, $entry->payee ) );
				break;
			case EntryType::Refund:
				break;
		}

		if ( '' !== $row->reference ) {
			$rows[] = array( __( 'אסמכתא', 'diromax-portal' ), $row->reference );
		}

		return new MailCard(
			tone: self::tone( $kind, $entry->type ),
			badge: self::badge( $kind, $entry->type, $entry->status ),
			title: $entry->description,
			rows: array_values( array_filter( $rows, static fn( array $r ): bool => '' !== $r[1] ) ),
			amount: $amount,
			note: 'entry_voided' === $kind ? $row->void_reason : ( EntryType::Request === $entry->type ? $row->payment_details : '' ),
			stage: null,
			link: null === $entry->document_id ? null : $this->document_link( $client_case, $entry->document_id ),
			subject: self::subject( $kind, $entry->type, $entry->description, $amount, Labels::date( $entry->due_date ) ),
		);
	}

	/**
	 * Card for a progress update.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id   Update.
	 * @return MailCard|null
	 */
	private function update_card( CaseRecord $client_case, int $id ): ?MailCard {
		$update = $this->app->updates->find( $id );

		if ( null === $update || $update['case_id'] !== $client_case->id || ! $update['visible'] ) {
			return null;
		}

		$subject = null === $update['stage']
			? __( 'עדכון מהשטח', 'diromax-portal' )
			/* translators: 1: stage number, 2: stage name. */
			: sprintf( __( 'התקדמנו לשלב %1$s: %2$s', 'diromax-portal' ), str_pad( (string) $update['stage'], 2, '0', STR_PAD_LEFT ), Stages::name( $update['stage'] ) );

		return new MailCard(
			tone: 'teal',
			badge: __( 'עדכון התקדמות', 'diromax-portal' ),
			title: $update['title'],
			rows: array(),
			amount: null,
			note: $update['body'],
			stage: $update['stage'],
			link: null,
			subject: $subject,
			progress: null === $update['stage'] ? array() : Stages::statuses( $client_case->stages ),
		);
	}

	/**
	 * Card for a new document.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id   Document.
	 * @return MailCard|null
	 */
	private function document_card( CaseRecord $client_case, int $id ): ?MailCard {
		$document = $this->app->documents->find( $id );

		if ( null === $document || $document->case_id !== $client_case->id || ! $document->visible ) {
			return null;
		}

		return new MailCard(
			tone: 'ink',
			badge: __( 'מסמך חדש', 'diromax-portal' ),
			title: $document->title,
			rows: array( array( __( 'סוג', 'diromax-portal' ), Labels::document_kinds()[ $document->kind ] ?? '' ) ),
			amount: null,
			note: '',
			stage: null,
			link: $this->document_link( $client_case, $document->id ),
			/* translators: %s: document title. */
			subject: sprintf( __( 'מסמך חדש בתיק: %s', 'diromax-portal' ), $document->title ),
		);
	}

	/**
	 * Card for photos added to the journal in one upload (spec 18.2): how
	 * many are shown, of which stage, and a link to the journal.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          First photo of the upload.
	 * @return MailCard|null
	 */
	private function photos_card( CaseRecord $client_case, int $id ): ?MailCard {
		$first = $this->app->photos->find( $id );

		if ( null === $first || $first['case_id'] !== $client_case->id ) {
			return null;
		}

		$shown = $this->app->photos->in_batch( $first['batch_key'], true );

		if ( array() === $shown ) {
			// Hidden or deleted before the email went out.
			return null;
		}

		$count   = count( $shown );
		$caption = $shown[0]['caption'];
		/* translators: %d: number of photos. */
		$title = sprintf( _n( 'צילום %d מהשטח', '%d צילומים מהשטח', $count, 'diromax-portal' ), $count );

		return new MailCard(
			tone: 'teal',
			badge: __( 'צילומים חדשים', 'diromax-portal' ),
			title: $title,
			rows: array(
				array( __( 'שלב', 'diromax-portal' ), Stages::label( $shown[0]['stage'] ) ),
				array( __( 'צולם', 'diromax-portal' ), Labels::date( $shown[0]['taken_on'] ) ),
			),
			amount: null,
			note: $caption,
			stage: null,
			link: array(
				'label' => __( 'ליומן הצילומים באזור האישי', 'diromax-portal' ),
				'url'   => Router::case_url( $client_case, 'photos' ),
			),
			/* translators: %s: stage label. */
			subject: sprintf( __( 'צילומים חדשים מהשטח: %s', 'diromax-portal' ), Stages::label( $shown[0]['stage'] ) ),
		);
	}

	/**
	 * Card for a change in the timeline (spec 22.4): the dates that moved
	 * since they were first shown, and why. Built when the email goes out,
	 * so several changes in one batch make one card.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return MailCard|null
	 */
	private function schedule_card( CaseRecord $client_case ): ?MailCard {
		$client_case = $this->app->cases->find( $client_case->id ) ?? $client_case;
		$service     = new ScheduleService( $this->app );

		if ( null === $service->for_client( $client_case ) ) {
			// Taken off the portal before the email went out.
			return null;
		}

		$moved = $service->moved( $client_case );
		$notes = array_values( array_unique( array_filter( array_column( $moved, 'note' ) ) ) );

		return new MailCard(
			tone: 'blue',
			badge: __( 'לוח הזמנים', 'diromax-portal' ),
			title: __( 'לוח הזמנים של הפרויקט עודכן', 'diromax-portal' ),
			rows: array_map(
				/* translators: 1: new date, 2: date first given. */
				static fn( array $item ): array => array( $item['label'], sprintf( __( '%1$s (במקור %2$s)', 'diromax-portal' ), $item['date'], $item['first'] ) ),
				array_slice( $moved, 0, 12 )
			),
			amount: null,
			note: implode( "\n", $notes ),
			stage: null,
			link: array(
				'label' => __( 'ללוח הזמנים באזור האישי', 'diromax-portal' ),
				'url'   => Router::case_url( $client_case, 'schedule' ),
			),
			subject: __( 'עדכון בלוח הזמנים של הפרויקט', 'diromax-portal' ),
		);
	}

	/**
	 * Card for a site visit shown to the client (spec 23.4): the date, the
	 * summary, and the findings still open.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Visit.
	 * @return MailCard|null
	 */
	private function visit_card( CaseRecord $client_case, int $id ): ?MailCard {
		$view  = ( new VisitService( $this->app ) )->for_client( $client_case );
		$visit = null;

		foreach ( null === $view ? array() : $view['visits'] as $candidate ) {
			$visit = $id === $candidate['id'] ? $candidate : $visit;
		}

		if ( null === $visit ) {
			// Hidden or deleted before the email went out.
			return null;
		}

		$open = array_values( array_filter( (array) $visit['findings'], static fn( array $finding ): bool => 'open' === $finding['status'] ) );

		return new MailCard(
			tone: 'ink',
			badge: __( 'ביקור באתר', 'diromax-portal' ),
			/* translators: %s: date. */
			title: sprintf( __( 'דוח ביקור באתר מ-%s', 'diromax-portal' ), $visit['date'] ),
			rows: array_map(
				static fn( array $finding ): array => array( (string) $finding['title'], '' !== $finding['due_label'] ? sprintf( /* translators: %s: date. */ __( 'לתיקון עד %s', 'diromax-portal' ), $finding['due_label'] ) : __( 'פתוח', 'diromax-portal' ) ),
				array_slice( $open, 0, 12 )
			),
			amount: null,
			note: (string) $visit['summary'],
			stage: null,
			link: array(
				'label' => __( 'לדוח המלא באזור האישי', 'diromax-portal' ),
				'url'   => Router::case_url( $client_case, 'visits' ),
			),
			/* translators: %s: date. */
			subject: sprintf( __( 'דוח ביקור באתר מ-%s', 'diromax-portal' ), $visit['date'] ),
		);
	}

	/**
	 * Card for quotes sent to the client to choose from (spec 24.3).
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Comparison.
	 * @return MailCard|null
	 */
	private function quote_card( CaseRecord $client_case, int $id ): ?MailCard {
		$comparison = null;

		foreach ( ( new QuoteService( $this->app ) )->for_client( $client_case ) ?? array() as $candidate ) {
			$comparison = $id === $candidate['id'] && 'open' === $candidate['status'] ? $candidate : $comparison;
		}

		if ( null === $comparison ) {
			// Withdrawn or already chosen before the email went out.
			return null;
		}

		return new MailCard(
			tone: 'orange',
			badge: __( 'הצעות מחיר לבחירתך', 'diromax-portal' ),
			title: (string) $comparison['title'],
			rows: array_map(
				static fn( array $offer ): array => array( (string) $offer['supplier'], $offer['amount'] . ( '' !== $offer['duration'] ? ' · ' . $offer['duration'] : '' ) ),
				(array) $comparison['offers']
			),
			amount: null,
			note: (string) $comparison['note'],
			stage: null,
			link: array(
				'label' => __( 'להשוואה ולבחירה באזור האישי', 'diromax-portal' ),
				'url'   => Router::case_url( $client_case, 'quotes' ),
			),
			/* translators: %s: job. */
			subject: sprintf( __( 'הצעות מחיר לבחירתך: %s', 'diromax-portal' ), $comparison['title'] ),
		);
	}

	/**
	 * Card for a decision put to the client.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param int        $id           Decision.
	 * @param bool       $hide_amounts Leave the amount out.
	 * @return MailCard|null
	 */
	private function decision_card( CaseRecord $client_case, int $id, bool $hide_amounts ): ?MailCard {
		$decision = $this->app->decisions->find( $id );

		if ( null === $decision || $decision->case_id !== $client_case->id || ! $decision->is_pending() ) {
			return null;
		}

		$rows = array();

		if ( null !== $decision->due_date ) {
			$rows[] = array( __( 'מבקשים תשובה עד', 'diromax-portal' ), Labels::date( $decision->due_date ) );
		}

		return new MailCard(
			tone: 'orange',
			badge: __( 'ממתין לאישורך', 'diromax-portal' ),
			title: $decision->title,
			rows: $rows,
			amount: $hide_amounts || null === $decision->amount ? null : Money::format( $decision->amount ),
			note: $decision->body,
			stage: null,
			link: array(
				'label' => __( 'לאישור או דחייה באזור האישי', 'diromax-portal' ),
				'url'   => Router::case_url( $client_case, 'decisions' ),
			),
			/* translators: %s: decision title. */
			subject: sprintf( __( 'מבקשים את אישורך: %s', 'diromax-portal' ), $decision->title ),
		);
	}

	/**
	 * Card for a document request, while it is still open.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Request.
	 * @return MailCard|null
	 */
	private function doc_request_card( CaseRecord $client_case, int $id ): ?MailCard {
		$request = $this->app->doc_requests->find( $id );

		if ( null === $request || $request['case_id'] !== $client_case->id || 'open' !== $request['status'] ) {
			return null;
		}

		return new MailCard(
			tone: 'orange',
			badge: __( 'מבקשים ממך מסמך', 'diromax-portal' ),
			title: $request['title'],
			rows: array(),
			amount: null,
			note: $request['note'],
			stage: null,
			link: array(
				'label' => __( 'להעלאת המסמך באזור האישי', 'diromax-portal' ),
				'url'   => Router::case_url( $client_case, 'documents' ),
			),
			/* translators: %s: what is requested. */
			subject: sprintf( __( 'מבקשים ממך מסמך: %s', 'diromax-portal' ), $request['title'] ),
		);
	}

	/**
	 * Link to a document in the portal, when the client may see it.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id   Document.
	 * @return array{label:string, url:string}|null
	 */
	private function document_link( CaseRecord $client_case, int $id ): ?array {
		$document = $this->app->documents->find( $id );

		if ( null === $document || ! $document->visible || $document->case_id !== $client_case->id ) {
			return null;
		}

		return array(
			'label' => __( 'לצפייה במסמך באזור האישי', 'diromax-portal' ),
			'url'   => Router::case_url( $client_case, 'documents' ),
		);
	}

	/**
	 * Badge text.
	 *
	 * @param string      $kind   Event kind.
	 * @param EntryType   $type   Entry type.
	 * @param EntryStatus $status Entry status.
	 * @return string
	 */
	private static function badge( string $kind, EntryType $type, EntryStatus $status ): string {
		return match ( true ) {
			'entry_voided' === $kind                                  => __( 'תנועה בוטלה', 'diromax-portal' ),
			'reminder' === $kind                                      => __( 'תזכורת: דרישת תשלום', 'diromax-portal' ),
			'reminder_late' === $kind                                 => __( 'דרישת תשלום באיחור', 'diromax-portal' ),
			'entry_updated' === $kind                                 => __( 'עודכנה תנועה', 'diromax-portal' ),
			'request_settled' === $kind                               => __( 'דרישת תשלום שולמה', 'diromax-portal' ),
			EntryType::Request === $type                              => __( 'דרישת תשלום', 'diromax-portal' ),
			EntryType::Payment === $type                              => __( 'התקבל תשלום', 'diromax-portal' ),
			EntryType::Refund === $type                               => __( 'בוצע החזר', 'diromax-portal' ),
			EntryStatus::Committed === $status                        => __( 'התחייבות חדשה', 'diromax-portal' ),
			default                                                   => __( 'הוצאה חדשה', 'diromax-portal' ),
		};
	}

	/**
	 * Badge colour.
	 *
	 * @param string    $kind Event kind.
	 * @param EntryType $type Entry type.
	 * @return string orange, blue, teal, ink or grey.
	 */
	private static function tone( string $kind, EntryType $type ): string {
		return match ( true ) {
			in_array( $kind, array( 'entry_voided', 'entry_updated' ), true ) => 'grey',
			'request_settled' === $kind                                         => 'teal',
			EntryType::Payment === $type, EntryType::Refund === $type           => 'blue',
			default                                                             => 'orange',
		};
	}

	/**
	 * Subject line used when this card is alone in the email.
	 *
	 * @param string      $kind        Event kind.
	 * @param EntryType   $type        Entry type.
	 * @param string      $description Description.
	 * @param string|null $amount      Formatted amount or null when hidden.
	 * @param string      $due         Due date of a request.
	 * @return string
	 */
	private static function subject( string $kind, EntryType $type, string $description, ?string $amount, string $due ): string {
		$tail = null === $amount ? $description : $amount;

		return match ( true ) {
			/* translators: %s: description. */
			'entry_voided' === $kind     => sprintf( __( 'בוטלה תנועה: %s', 'diromax-portal' ), $description ),
			/* translators: %s: description. */
			'entry_updated' === $kind    => sprintf( __( 'עודכנה תנועה: %s', 'diromax-portal' ), $description ),
			/* translators: %s: description. */
			'request_settled' === $kind  => sprintf( __( 'דרישת התשלום שולמה: %s', 'diromax-portal' ), $description ),
			/* translators: 1: amount or description, 2: due date. */
			'reminder' === $kind         => sprintf( __( 'תזכורת: דרישת תשלום של %1$s עד %2$s', 'diromax-portal' ), $tail, $due ),
			/* translators: 1: amount or description, 2: due date. */
			'reminder_late' === $kind    => sprintf( __( 'דרישת התשלום עברה את המועד: %1$s, לתשלום עד %2$s', 'diromax-portal' ), $tail, $due ),
			/* translators: 1: amount or description, 2: due date. */
			EntryType::Request === $type => sprintf( __( 'דרישת תשלום: %1$s עד %2$s', 'diromax-portal' ), $tail, $due ),
			/* translators: %s: amount or description. */
			EntryType::Payment === $type => sprintf( __( 'קיבלנו את התשלום · %s', 'diromax-portal' ), $tail ),
			/* translators: %s: amount or description. */
			EntryType::Refund === $type  => sprintf( __( 'בוצע החזר · %s', 'diromax-portal' ), $tail ),
			/* translators: %s: amount or description. */
			default                      => sprintf( __( 'הוצאה חדשה בתיק: %s', 'diromax-portal' ), $tail ),
		};
	}
}
