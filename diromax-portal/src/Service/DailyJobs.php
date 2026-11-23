<?php
/**
 * The once-a-day slot.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Plugin;
use Diromax\Portal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the daily emails once per local day, after the configured hour
 * (spec 15.1). It piggybacks on the five-minute notification job, so there
 * is no second schedule to keep alive, and a day the server missed in the
 * morning still runs later that day.
 */
final class DailyJobs {

	/** Option: the local day (Y-m-d) the slot last ran. */
	public const OPTION = 'dx_portal_daily_ran';

	/** Lock against two overlapping workers. */
	private const LOCK = 'dx_portal_daily_lock';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Run the daily jobs if their time has come today.
	 *
	 * @return bool Whether they ran.
	 */
	public function maybe_run(): bool {
		$local = ( new \DateTimeImmutable( '@' . $this->app->clock->now() ) )->setTimezone( wp_timezone() );
		$today = $local->format( 'Y-m-d' );

		if ( (int) $local->format( 'G' ) < Settings::all()['daily_hour'] || get_option( self::OPTION ) === $today || get_transient( self::LOCK ) ) {
			return false;
		}

		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		update_option( self::OPTION, $today, false );

		try {
			$digests = new DigestService( $this->app );
			( new ReminderService( $this->app ) )->run();
			( new MeetingService( $this->app ) )->send_reminders();
			$digests->daily();
			$digests->monthly();
			( new MorningSummary( $this->app ) )->run();
		} finally {
			delete_transient( self::LOCK );
		}

		return true;
	}
}
