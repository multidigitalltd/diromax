<?php
/**
 * Payment reminders.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\ReminderPolicy;
use Diromax\Portal\Plugin;
use Diromax\Portal\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends reminders for open payment requests (spec 15.2): automatically once a
 * day, or on demand from the finance tab.
 *
 * A reminder is a 'request_reminder' event that leaves at once, so it goes
 * through the same queue, retry and mail log as every other email.
 */
final class ReminderService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Daily run: queue every reminder that is due, then send once per case.
	 *
	 * @return int Reminders queued.
	 */
	public function run(): int {
		$settings = Settings::all();
		$policy   = new ReminderPolicy( $settings['remind_before_days'], $settings['remind_overdue_every'], $settings['remind_overdue_max'] );
		$today    = $this->app->clock->today();
		$now      = $this->app->clock->mysql();
		$cases    = array();

		foreach ( $this->app->ledger->reminder_candidates() as $row ) {
			$entry = $row->entry;
			$kind  = $policy->due( $today, (string) $entry->due_date, self::local_day( $row->created_at ), $row->last_reminder_on, $row->overdue_reminders );

			if ( null === $kind || ! $this->app->ledger->mark_reminded( $entry->id, $today, ReminderPolicy::OVERDUE === $kind ) ) {
				continue;
			}

			$this->app->events->add( $entry->case_id, 'request_reminder', $entry->id, true, $now );
			$this->app->audit->record( 'reminder.sent', $entry->case_id, 'ledger', $entry->id, array( 'kind' => $kind ), $now );
			$cases[ $entry->case_id ] = ( $cases[ $entry->case_id ] ?? 0 ) + 1;
		}

		foreach ( array_keys( $cases ) as $case_id ) {
			$this->app->notifier->process_case( $case_id, true );
		}

		return array_sum( $cases );
	}

	/**
	 * Manager action: remind the client of one request now.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $entry_id    Request.
	 * @param int        $actor       Acting user.
	 * @return bool|WP_Error
	 */
	public function send_now( CaseRecord $client_case, int $entry_id, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$row = $this->app->ledger->find( $entry_id );

		if ( null === $row || $row->entry->case_id !== $client_case->id ) {
			return Errors::not_found();
		}

		if ( ! $row->entry->is_open_request() || ! $row->entry->visible ) {
			return new WP_Error( 'dx_not_open', __( 'אפשר לשלוח תזכורת רק על דרישת תשלום פתוחה שגלויה ללקוח.', 'diromax-portal' ) );
		}

		if ( ! $this->app->ledger->mark_reminded( $entry_id, $this->app->clock->today(), false ) ) {
			return new WP_Error( 'dx_reminded_today', __( 'כבר נשלחה היום תזכורת על הדרישה הזו.', 'diromax-portal' ) );
		}

		$this->app->audit->record( 'reminder.sent', $client_case->id, 'ledger', $entry_id, array( 'kind' => 'manual' ), $this->app->clock->mysql() );
		$this->app->notifier->enqueue( $client_case->id, 'request_reminder', $entry_id, true );

		return true;
	}

	/**
	 * The local day of a UTC DATETIME.
	 *
	 * @param string $utc UTC DATETIME.
	 * @return string Y-m-d in the site's time zone.
	 */
	private static function local_day( string $utc ): string {
		$time = strtotime( $utc . ' UTC' );

		return false === $time ? '' : (string) wp_date( 'Y-m-d', $time );
	}
}
