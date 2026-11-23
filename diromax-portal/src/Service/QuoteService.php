<?php
/**
 * Quote comparisons.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\Funding;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\QuoteComparison;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Support\Transaction;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Quote comparisons (spec 24): the manager gathers quotes for a job, sends
 * them to the client once there are two or more (decision 43), the client
 * picks one in the portal, and the manager hears of it at once
 * (decision 44); the manager then records the choice in the ledger as a
 * commitment against the job's budget line (decision 45).
 *
 * @phpstan-import-type ComparisonRow from \Diromax\Portal\Data\QuoteRepository
 * @phpstan-import-type OfferRow from \Diromax\Portal\Data\QuoteRepository
 */
final class QuoteService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Start a comparison, as a draft.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       title, category, note, due_on.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error
	 */
	public function create( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$checked = QuoteComparison::comparison( $input, $this->app->budget->tree( $client_case->id )->keys() );

		if ( null === $checked['fields'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$id = $this->app->quotes->insert_comparison( $client_case->id, $checked['fields'], $actor, $this->app->clock->mysql() );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'quote.created', $client_case->id, 'comparison', $id, array( 'title' => $checked['fields']['title'] ), $this->app->clock->mysql() );

		return $id;
	}

	/**
	 * Add a quote to a draft, with its document when one is given.
	 *
	 * @param CaseRecord                $client_case   Case.
	 * @param int                       $comparison_id Comparison.
	 * @param array<string, string>     $input         supplier, amount, duration, note, recommended.
	 * @param array<string, mixed>|null $file          The supplier's quote ($_FILES item) or null.
	 * @param int                       $actor         Acting user.
	 * @return int|WP_Error
	 */
	public function add_offer( CaseRecord $client_case, int $comparison_id, array $input, ?array $file, int $actor ): int|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$comparison = $this->comparison( $client_case, $comparison_id, array( 'draft' ) );

		if ( null === $comparison ) {
			return Errors::not_found();
		}

		if ( count( $this->app->quotes->offers_of( array( $comparison_id ) )[ $comparison_id ] ?? array() ) >= QuoteComparison::MAX_OFFERS ) {
			return Errors::invalid( array( 'supplier' => 'too_many' ) );
		}

		$checked = QuoteComparison::offer( $input );

		if ( null === $checked['fields'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$document = null;

		if ( null !== $file ) {
			// Hidden until the comparison is sent: a draft is the staff's.
			$document = $this->app->document_service->upload( $client_case, $file, 'quote', $checked['fields']['supplier'] . ' · ' . $comparison['title'], false, $actor, false );

			if ( $document instanceof WP_Error ) {
				return $document;
			}
		}

		$id = $this->app->quotes->insert_offer( $comparison_id, $client_case->id, $checked['fields'], $document?->id, $this->app->clock->mysql() );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'quote.offer_added', $client_case->id, 'comparison', $comparison_id, array( 'supplier' => $checked['fields']['supplier'] ), $this->app->clock->mysql() );

		return $id;
	}

	/**
	 * Remove a quote from a draft. Its document stays in the case, hidden.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $offer_id    Quote.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function delete_offer( CaseRecord $client_case, int $offer_id, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$offer = $this->app->quotes->find_offer( $offer_id );

		if ( null === $offer || $offer['case_id'] !== $client_case->id || null === $this->comparison( $client_case, $offer['comparison_id'], array( 'draft' ) ) ) {
			return Errors::not_found();
		}

		$this->app->quotes->delete_offer( $offer_id );
		$this->app->audit->record( 'quote.offer_deleted', $client_case->id, 'comparison', $offer['comparison_id'], array( 'supplier' => $offer['supplier'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Send a draft to the client to choose: it needs two quotes or more.
	 * Their documents become visible; the client is emailed when asked.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Comparison.
	 * @param bool       $notify      Email the client.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function send( CaseRecord $client_case, int $id, bool $notify, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		if ( null === $this->comparison( $client_case, $id, array( 'draft' ) ) ) {
			return Errors::not_found();
		}

		$offers = $this->app->quotes->offers_of( array( $id ) )[ $id ] ?? array();

		if ( count( $offers ) < QuoteComparison::MIN_OFFERS ) {
			return Errors::invalid( array( 'send' => 'too_few' ) );
		}

		if ( ! $this->app->quotes->move( $id, array( 'draft' ), 'open', $this->app->clock->mysql() ) ) {
			return Errors::not_found();
		}

		foreach ( $this->app->documents->find_many( $client_case->id, array_filter( array_column( $offers, 'document_id' ) ) ) as $document ) {
			$this->app->documents->set_visible( $document->id, true );
		}

		$this->app->audit->record( 'quote.sent', $client_case->id, 'comparison', $id, array( 'offers' => count( $offers ) ), $this->app->clock->mysql() );

		if ( $notify ) {
			$this->app->notifier->enqueue( $client_case->id, 'quote_sent', $id, true );
		}

		return true;
	}

	/**
	 * Withdraw a draft or a comparison the client has not answered.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Comparison.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function withdraw( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$comparison = $this->comparison( $client_case, $id, array( 'draft', 'open' ) );

		if ( null === $comparison || ! $this->app->quotes->move( $id, array( 'draft', 'open' ), 'withdrawn', $this->app->clock->mysql() ) ) {
			return Errors::not_found();
		}

		$this->app->audit->record( 'quote.withdrawn', $client_case->id, 'comparison', $id, array( 'title' => $comparison['title'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * The client picks a quote (decision 44): owners and partners only, once,
	 * with an explicit confirmation. The manager is emailed at once.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Comparison.
	 * @param int        $offer_id    Quote.
	 * @param int        $user_id     Client.
	 * @param string     $note        Client's note.
	 * @param bool       $confirmed   The confirmation box.
	 * @return true|WP_Error
	 */
	public function choose( CaseRecord $client_case, int $id, int $offer_id, int $user_id, string $note, bool $confirmed ): bool|WP_Error {
		$comparison = $this->comparison( $client_case, $id, array( 'open', 'chosen', 'withdrawn' ) );

		if ( null === $comparison || ! $this->app->policy->can_view_case( $user_id, $client_case ) ) {
			return Errors::not_found();
		}

		if ( ! DecisionService::may_answer( $this->app->members->role( $client_case->id, $user_id ) ) ) {
			return Errors::forbidden();
		}

		// A closed case can still be read, not changed.
		if ( CaseStatus::Closed === $client_case->status ) {
			return Errors::closed();
		}

		$offer = $this->app->quotes->find_offer( $offer_id );

		if ( null === $offer || $offer['comparison_id'] !== $id ) {
			return Errors::invalid( array( 'offer' => 'required' ) );
		}

		$note = Text::block( $note );

		if ( Text::length( $note ) > QuoteComparison::MAX_SHORT ) {
			return Errors::invalid( array( 'note' => 'too_long' ) );
		}

		if ( ! $confirmed ) {
			return Errors::invalid( array( 'confirm' => 'required' ) );
		}

		if ( ! $this->app->quotes->choose( $id, $offer_id, $user_id, $note, $this->app->clock->mysql() ) ) {
			return new WP_Error( 'dx_not_pending', __( 'הבחירה כבר נעשתה, או שההשוואה בוטלה.', 'diromax-portal' ), array( 'status' => 409 ) );
		}

		$this->app->audit->record(
			'quote.chosen',
			$client_case->id,
			'comparison',
			$id,
			array(
				'supplier' => $offer['supplier'],
				'by'       => $user_id,
			),
			$this->app->clock->mysql()
		);
		$this->tell_manager( $client_case, $comparison, $offer, $note, $user_id );

		return true;
	}

	/**
	 * Record the chosen quote in the ledger as a commitment on the job's
	 * budget line (decision 45). Once only, even if two managers click.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Comparison.
	 * @param string     $funding     Who pays.
	 * @param int        $actor       Acting user.
	 * @return int|WP_Error The ledger entry.
	 */
	public function commit( CaseRecord $client_case, int $id, string $funding, int $actor ): int|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$comparison = $this->comparison( $client_case, $id, array( 'chosen' ) );
		$offer      = null === $comparison || null === $comparison['chosen_offer_id'] ? null : $this->app->quotes->find_offer( $comparison['chosen_offer_id'] );

		if ( null === $comparison || null === $offer || null !== $comparison['entry_id'] ) {
			return Errors::not_found();
		}

		if ( '' === $comparison['category'] ) {
			return Errors::invalid( array( 'category' => 'required' ) );
		}

		$entry = 0;
		$error = Errors::not_found();
		$done  = Transaction::run(
			$this->app->db,
			function () use ( $client_case, $id, $comparison, $offer, $funding, $actor, &$entry, &$error ): bool {
				if ( ! $this->app->quotes->lock_for_entry( $id ) ) {
					return false;
				}

				$result = $this->app->ledger_service->add(
					$client_case,
					array(
						'type'        => 'expense',
						'status'      => 'committed',
						'entry_date'  => $this->app->clock->today(),
						'amount'      => Money::input( $offer['amount'] ),
						'category'    => $comparison['category'],
						'payee'       => $offer['supplier'],
						'description' => $comparison['title'],
						'funding'     => $funding,
						'visible'     => '1',
					),
					$actor
				);

				if ( $result instanceof WP_Error ) {
					$error = $result;
					return false;
				}

				$entry = $result;
				$this->app->quotes->set_entry( $id, $entry, $this->app->clock->mysql() );
				$this->app->audit->record( 'quote.committed', $client_case->id, 'comparison', $id, array( 'entry' => $entry ), $this->app->clock->mysql() );

				return true;
			}
		);

		return $done ? $entry : $error;
	}

	/**
	 * Every comparison of the case, for the staff tab.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_staff( CaseRecord $client_case ): array {
		return $this->build( $client_case, array( 'draft', 'open', 'chosen', 'withdrawn' ), true );
	}

	/**
	 * What the client sees: comparisons sent to them and their choices; null
	 * when there are none.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array<string, mixed>>|null
	 */
	public function for_client( CaseRecord $client_case ): ?array {
		$list = $this->build( $client_case, array( 'open', 'chosen' ), false );

		return array() === $list ? null : $list;
	}

	/**
	 * Comparisons with their quotes, documents and budget line names, in
	 * three queries.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string[]   $states      States to list.
	 * @param bool       $staff       For staff: download links through wp-admin.
	 * @return array<int, array<string, mixed>>
	 */
	private function build( CaseRecord $client_case, array $states, bool $staff ): array {
		$comparisons = $this->app->quotes->comparisons_for( $client_case->id, $states );
		$offers      = $this->app->quotes->offers_of( array_column( $comparisons, 'id' ) );
		$documents   = $this->app->documents->find_many( $client_case->id, array_filter( array_column( array_merge( array(), ...array_values( $offers ) ), 'document_id' ) ) );
		$tree        = $this->app->budget->tree( $client_case->id );
		$list        = array();

		foreach ( $comparisons as $comparison ) {
			$quotes = $offers[ $comparison['id'] ] ?? array();
			$range  = QuoteComparison::range( $quotes );
			$list[] = array(
				'id'          => $comparison['id'],
				'title'       => $comparison['title'],
				'line'        => '' === $comparison['category'] ? '' : Labels::budget_line( $tree, $comparison['category'] ),
				'category'    => $comparison['category'],
				'note'        => $comparison['note'],
				'due_label'   => Labels::date( $comparison['due_on'] ),
				'status'      => $comparison['status'],
				'chosen'      => $comparison['chosen_offer_id'],
				'chosen_note' => $comparison['chosen_note'],
				'chosen_by'   => self::name( $comparison['chosen_by'] ),
				'chosen_date' => Labels::datetime( $comparison['chosen_at'] ),
				'entry_id'    => $comparison['entry_id'],
				'gap'         => Money::format( $range['gap'] ),
				'offers'      => array_map(
					static function ( array $offer ) use ( $documents, $range, $staff ): array {
						$document = null === $offer['document_id'] ? null : ( $documents[ $offer['document_id'] ] ?? null );

						return array(
							'id'          => $offer['id'],
							'supplier'    => $offer['supplier'],
							'amount'      => Money::format( $offer['amount'] ),
							'duration'    => $offer['duration'],
							'note'        => $offer['note'],
							'recommended' => $offer['recommended'],
							// The cheapest, when the quotes differ at all.
							'lowest'      => $range['lowest'] === $offer['id'] && $range['gap'] > 0,
							'document'    => null === $document || ( ! $staff && ! $document->visible ) ? '' : ( $staff ? self::staff_download( $document->id ) : Router::document_url( $document ) ),
						);
					},
					$quotes
				),
			);
		}

		return $list;
	}

	/**
	 * A user's display name, '' when unknown.
	 *
	 * @param int|null $user_id User.
	 * @return string
	 */
	private static function name( ?int $user_id ): string {
		$user = null === $user_id ? false : get_userdata( $user_id );

		return $user ? (string) $user->display_name : '';
	}

	/**
	 * The staff's download link of a document.
	 *
	 * @param int $document_id Document.
	 * @return string
	 */
	private static function staff_download( int $document_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'dx_portal_download',
					'document_id' => $document_id,
				),
				admin_url( 'admin-post.php' )
			),
			'dx_portal_download_' . $document_id
		);
	}

	/**
	 * Email the project manager about the client's choice.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param array      $comparison  Comparison.
	 * @param array      $offer       The quote chosen.
	 * @param string     $note        Client's note.
	 * @param int        $user_id     Who chose.
	 * @phpstan-param ComparisonRow $comparison
	 * @phpstan-param OfferRow $offer
	 */
	private function tell_manager( CaseRecord $client_case, array $comparison, array $offer, string $note, int $user_id ): void {
		$user = get_userdata( $user_id );
		$card = new MailCard(
			tone: 'teal',
			badge: __( 'הלקוח בחר הצעה', 'diromax-portal' ),
			title: $comparison['title'],
			rows: array(
				array( __( 'ההצעה', 'diromax-portal' ), $offer['supplier'] ),
				array( __( 'מי בחר', 'diromax-portal' ), $user ? (string) $user->display_name : '' ),
			),
			amount: Money::format( $offer['amount'] ),
			note: $note,
			stage: null,
			link: null,
			/* translators: 1: job, 2: supplier. */
			subject: sprintf( __( 'הלקוח בחר הצעה ל%1$s: %2$s', 'diromax-portal' ), $comparison['title'], $offer['supplier'] ),
		);

		$this->app->notifier->send_to_manager( $client_case, $this->app->renderer->manager_notice( $client_case, $card, Admin::case_url( $client_case->id, 'quotes' ) ) );
	}

	/**
	 * Why the actor may not change this case's comparisons now, if they may not.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $actor       Acting user.
	 * @return WP_Error|null
	 */
	private function refused( CaseRecord $client_case, int $actor ): ?WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		return CaseStatus::Closed === $client_case->status ? Errors::closed() : null;
	}

	/**
	 * A comparison of this case in one of the given states.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Comparison.
	 * @param string[]   $states      States.
	 * @return ComparisonRow|null
	 */
	private function comparison( CaseRecord $client_case, int $id, array $states ): ?array {
		$comparison = $this->app->quotes->find_comparison( $id );

		return null !== $comparison && $comparison['case_id'] === $client_case->id && in_array( $comparison['status'], $states, true ) ? $comparison : null;
	}

	/**
	 * Funding choices for the commitment.
	 *
	 * @return string[]
	 */
	public static function fundings(): array {
		return array_map( static fn( Funding $funding ): string => $funding->value, Funding::cases() );
	}
}
