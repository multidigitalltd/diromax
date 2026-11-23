<?php
/**
 * Payment plans.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\PaymentPlan;
use Diromax\Portal\Domain\StageStatus;
use Diromax\Portal\Plugin;
use Diromax\Portal\Settings;
use Diromax\Portal\Support\Transaction;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A case's payment plan (spec 21): payments due when a stage is done, on a
 * date (decision 31), or by hand; each a fixed amount or a share of the
 * plan's base (decision 32). When one falls due it becomes a draft, and the
 * manager checks it and sends it as a payment request (decision 33). New
 * cases start from the office's template (decision 34).
 *
 * @phpstan-import-type PlanRow from \Diromax\Portal\Data\PlanRepository
 */
final class PlanService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Add a payment to a case's plan.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Form.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error
	 */
	public function add( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		$fields = $this->check( $client_case, $input, $actor );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		$id = $this->app->plan->insert( $client_case->id, $fields, $this->app->clock->mysql() );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'plan.added', $client_case->id, 'plan', $id, array( 'label' => $fields['label'] ), $this->app->clock->mysql() );
		$this->catch_up( $client_case, $id );

		return $id;
	}

	/**
	 * Change a planned payment. Drafts and sent payments are not changed here.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $id          Payment.
	 * @param array<string, string> $input       Form.
	 * @param int                   $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function update( CaseRecord $client_case, int $id, array $input, int $actor ): bool|WP_Error {
		$fields = $this->check( $client_case, $input, $actor );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		if ( null === $this->owned( $client_case, $id, array( 'planned' ) ) ) {
			return Errors::not_found();
		}

		if ( ! $this->app->plan->update( $id, $fields, $this->app->clock->mysql() ) ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'plan.updated', $client_case->id, 'plan', $id, array( 'label' => $fields['label'] ), $this->app->clock->mysql() );
		$this->catch_up( $client_case, $id );

		return true;
	}

	/**
	 * Remove a planned or cancelled payment. A sent one stays: its request
	 * is in the ledger.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Payment.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function delete( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$payment = $this->owned( $client_case, $id, array( 'planned', 'cancelled' ) );

		if ( null === $payment ) {
			return Errors::not_found();
		}

		$this->app->plan->delete( $id );
		$this->app->audit->record( 'plan.deleted', $client_case->id, 'plan', $id, array( 'label' => $payment['label'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Set the base the shares are taken from; empty or 0 is the budget's total.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $value       Shekels as typed.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function set_base( CaseRecord $client_case, string $value, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$value  = trim( $value );
		$amount = '' === $value ? 0 : Money::parse( $value );

		if ( null === $amount || $amount < 0 || $amount > Money::MAX ) {
			return Errors::invalid( array( 'base' => 'invalid' ) );
		}

		if ( ! $this->app->cases->update( $client_case->id, array( 'plan_base' => $amount ), $this->app->clock->mysql() ) ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'plan.base', $client_case->id, 'case', $client_case->id, array( 'base' => $amount ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * The base: the amount set for the plan, else the budget's total.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return int Agorot.
	 */
	public function base( CaseRecord $client_case ): int {
		return $client_case->plan_base > 0 ? $client_case->plan_base : $this->app->budget->tree( $client_case->id )->total();
	}

	/**
	 * Give a new case the office's template.
	 *
	 * @param CaseRecord $client_case Case.
	 */
	public function seed( CaseRecord $client_case ): void {
		if ( array() !== $this->app->plan->for_case( $client_case->id ) ) {
			return;
		}

		foreach ( Settings::all()['plan_template'] as $row ) {
			$this->app->plan->insert( $client_case->id, $row, $this->app->clock->mysql() );
		}
	}

	/**
	 * Make a planned payment a draft now, by hand.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Payment.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function draft_now( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$payment = $this->owned( $client_case, $id, array( 'planned' ) );

		return null !== $payment && $this->to_draft( $client_case, $payment, 'manual' ) ? true : Errors::not_found();
	}

	/**
	 * Stages just marked done: their payments become drafts.
	 *
	 * @param CaseRecord $before The case before the change.
	 * @param CaseRecord $after  The case after it.
	 * @return int Drafts made.
	 */
	public function on_stage_change( CaseRecord $before, CaseRecord $after ): int {
		$done = array();

		foreach ( $after->stages as $number => $state ) {
			if ( StageStatus::Done->value === $state['status'] && StageStatus::Done->value !== ( $before->stages[ $number ]['status'] ?? '' ) ) {
				$done[] = (int) $number;
			}
		}

		$made = 0;

		foreach ( $this->app->plan->on_stages( $after->id, $done ) as $payment ) {
			$made += $this->to_draft( $after, $payment, 'stage' ) ? 1 : 0;
		}

		return $made;
	}

	/**
	 * The daily run: dated payments whose day has come become drafts.
	 *
	 * @return int Drafts made.
	 */
	public function run_dates(): int {
		$made = 0;

		foreach ( $this->app->plan->dated_until( $this->app->clock->today() ) as $payment ) {
			$client_case = $this->app->cases->find( $payment['case_id'] );
			$made       += null !== $client_case && $this->to_draft( $client_case, $payment, 'date' ) ? 1 : 0;
		}

		return $made;
	}

	/**
	 * Send a draft as a payment request, with the amount, due date and text
	 * the manager checked.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $id          Payment.
	 * @param array<string, string> $input       amount, due_date, description, notify.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error The request's ID.
	 */
	public function approve( CaseRecord $client_case, int $id, array $input, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$payment = $this->owned( $client_case, $id, array( 'draft' ) );

		if ( null === $payment ) {
			return Errors::not_found();
		}

		$request = 0;
		$error   = Errors::not_found();
		$sent    = Transaction::run(
			$this->app->db,
			function () use ( $client_case, $id, $payment, $input, $actor, &$request, &$error ): bool {
				// A second approval of the same draft waits on this lock, then finds it sent.
				if ( ! $this->app->plan->lock_draft( $id ) ) {
					return false;
				}

				$result = $this->app->ledger_service->add(
					$client_case,
					array(
						'type'        => 'request',
						'entry_date'  => $this->app->clock->today(),
						'due_date'    => $input['due_date'] ?? '',
						'amount'      => $input['amount'] ?? '',
						'description' => $input['description'] ?? '',
						'pay_to'      => $payment['pay_to'],
						'payee'       => $payment['payee'],
						'visible'     => '1',
						'notify'      => $input['notify'] ?? '',
					),
					$actor
				);

				if ( $result instanceof WP_Error ) {
					$error = $result;
					return false;
				}

				$request = $result;
				$this->app->plan->to_sent( $id, $request, $this->app->clock->mysql() );
				$this->app->audit->record( 'plan.sent', $client_case->id, 'plan', $id, array( 'request' => $request ), $this->app->clock->mysql() );

				return true;
			}
		);

		return $sent ? $request : $error;
	}

	/**
	 * Cancel a planned payment or a draft; or put a draft back to planned.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Payment.
	 * @param bool       $back        Back to planned instead of cancelled.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function withdraw( CaseRecord $client_case, int $id, bool $back, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$payment = $this->owned( $client_case, $id, $back ? array( 'draft' ) : array( 'planned', 'draft' ) );

		if ( null === $payment ) {
			return Errors::not_found();
		}

		$this->app->plan->set_status( $id, $back ? 'planned' : 'cancelled', $this->app->clock->mysql() );
		$this->app->audit->record( $back ? 'plan.reopened' : 'plan.cancelled', $client_case->id, 'plan', $id, array( 'label' => $payment['label'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * The plan for the staff tab.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array{base:int, base_set:bool, rows:array<int, array<string, mixed>>, totals:array{percent:int, fixed:int, total:int}}
	 */
	public function for_staff( CaseRecord $client_case ): array {
		$base     = $this->base( $client_case );
		$payments = $this->app->plan->for_case( $client_case->id );

		return array(
			'base'     => $base,
			'base_set' => $client_case->plan_base > 0,
			'rows'     => array_map( fn( array $payment ): array => $this->view( $payment, $base ), $payments ),
			'totals'   => PaymentPlan::totals( $payments, $base ),
		);
	}

	/**
	 * The plan as the client sees it: every payment that is not cancelled,
	 * with its amount, when it is due, and where it stands.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array{label:string, amount:string, when:string, state:string, state_label:string}>
	 */
	public function for_client( CaseRecord $client_case ): array {
		$base = $this->base( $client_case );
		$list = array();

		foreach ( $this->app->plan->for_case( $client_case->id ) as $payment ) {
			if ( 'cancelled' === $payment['status'] ) {
				continue;
			}

			$row    = $this->view( $payment, $base );
			$list[] = array(
				'label'       => $payment['label'],
				'amount'      => Money::format( (int) $row['amount'] ),
				'when'        => (string) $row['when'],
				// A draft is not sent yet: for the client it is still planned.
				'state'       => 'draft' === $row['state'] ? 'planned' : (string) $row['state'],
				'state_label' => 'draft' === $row['state'] ? self::state_labels()['planned'] : (string) $row['state_label'],
			);
		}

		return $list;
	}

	/**
	 * Drafts waiting for a manager, for the dashboard and the morning summary.
	 *
	 * @param int|null $manager Manager, or null for every case.
	 * @return array<int, array{payment:PlanRow, case_title:string, number:string}>
	 */
	public function drafts( ?int $manager ): array {
		return $this->app->plan->drafts( $manager );
	}

	/**
	 * Names of the states.
	 *
	 * @return array<string, string>
	 */
	public static function state_labels(): array {
		return array(
			'planned'   => __( 'מתוכנן', 'diromax-portal' ),
			'draft'     => __( 'טיוטה לאישור', 'diromax-portal' ),
			'sent'      => __( 'נשלחה דרישה', 'diromax-portal' ),
			'paid'      => __( 'שולם', 'diromax-portal' ),
			'cancelled' => __( 'בוטל', 'diromax-portal' ),
		);
	}

	/**
	 * Checks shared by add and update.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       Form.
	 * @param int                   $actor       Acting user.
	 * @return array{label:string, trigger_on:string, stage:int, trigger_date:string|null, kind:string, amount:int, percent:int, pay_to:string, payee:string, days:int}|WP_Error
	 */
	private function check( CaseRecord $client_case, array $input, int $actor ): array|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		if ( CaseStatus::Closed === $client_case->status ) {
			return Errors::invalid( array( 'label' => 'case_closed' ) );
		}

		$checked = PaymentPlan::validate( $input, Stages::COUNT, true );

		return null === $checked['fields'] ? Errors::invalid( $checked['errors'] ) : $checked['fields'];
	}

	/**
	 * A payment of this case in one of the given states.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Payment.
	 * @param string[]   $states      Allowed states.
	 * @return PlanRow|null
	 */
	private function owned( CaseRecord $client_case, int $id, array $states ): ?array {
		$payment = $this->app->plan->find( $id );

		return null !== $payment && $payment['case_id'] === $client_case->id && in_array( $payment['status'], $states, true ) ? $payment : null;
	}

	/**
	 * A payment added or changed after its time came (its stage is already
	 * done, its date has passed) becomes a draft at once, as it would have.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Payment.
	 */
	private function catch_up( CaseRecord $client_case, int $id ): void {
		$payment = $this->app->plan->find( $id );

		if ( null === $payment || 'planned' !== $payment['status'] || CaseStatus::Active !== $client_case->status ) {
			return;
		}

		$due = match ( $payment['trigger_on'] ) {
			'stage' => StageStatus::Done->value === ( $client_case->stages[ $payment['stage'] ]['status'] ?? '' ),
			'date'  => null !== $payment['trigger_date'] && $payment['trigger_date'] <= $this->app->clock->today(),
			default => false,
		};

		if ( $due ) {
			$this->to_draft( $client_case, $payment, $payment['trigger_on'] );
		}
	}

	/**
	 * Work out a payment's amount and due date, and make it a draft.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param array      $payment     Payment.
	 * @param string     $why         stage, date or manual.
	 * @phpstan-param PlanRow $payment
	 * @return bool
	 */
	private function to_draft( CaseRecord $client_case, array $payment, string $why ): bool {
		$amount = PaymentPlan::amount( $payment, $this->base( $client_case ) );
		$due    = ( new \DateTimeImmutable( $this->app->clock->today(), new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $payment['days'] . ' days' )->format( 'Y-m-d' );

		if ( ! $this->app->plan->to_draft( $payment['id'], $amount, $due, $this->app->clock->mysql() ) ) {
			return false;
		}

		$this->app->audit->record(
			'plan.drafted',
			$client_case->id,
			'plan',
			$payment['id'],
			array(
				'label'  => $payment['label'],
				'amount' => $amount,
				'why'    => $why,
			),
			$this->app->clock->mysql()
		);

		return true;
	}

	/**
	 * A payment for a screen.
	 *
	 * @param array $payment Payment.
	 * @param int   $base    Plan base.
	 * @phpstan-param PlanRow $payment
	 * @return array<string, mixed>
	 */
	private function view( array $payment, int $base ): array {
		$state   = $payment['status'];
		$request = null === $payment['request_id'] ? null : $this->app->ledger->find( $payment['request_id'] );
		$amount  = match ( $state ) {
			'draft' => $payment['draft_amount'],
			'sent'  => null === $request ? $payment['draft_amount'] : $request->entry->total,
			default => PaymentPlan::amount( $payment, $base ),
		};

		if ( 'sent' === $state && null !== $request ) {
			$state = match ( true ) {
				$request->entry->voided                        => 'cancelled',
				EntryStatus::Open !== $request->entry->status  => 'paid',
				default                                        => 'sent',
			};
		}

		// The worked-out amount replaces the stored one, which is 0 for a share.
		return array(
			'amount'      => $amount,
			'share'       => 'percent' === $payment['kind'] ? PaymentPlan::percent_text( $payment['percent'] ) . '%' : '',
			'when'        => self::when( $payment ),
			'state'       => $state,
			'state_label' => self::state_labels()[ $state ] ?? '',
		) + $payment;
	}

	/**
	 * When a payment falls due, in words.
	 *
	 * @param array $payment Payment.
	 * @phpstan-param PlanRow $payment
	 * @return string
	 */
	private static function when( array $payment ): string {
		return match ( $payment['trigger_on'] ) {
			/* translators: %s: stage name. */
			'stage' => sprintf( __( 'בסיום השלב "%s"', 'diromax-portal' ), Stages::label( $payment['stage'] ) ),
			/* translators: %s: date. */
			'date'  => sprintf( __( 'ב-%s', 'diromax-portal' ), Labels::date( $payment['trigger_date'] ) ),
			default => __( 'כשמנהל הפרויקט יקבע', 'diromax-portal' ),
		};
	}
}
