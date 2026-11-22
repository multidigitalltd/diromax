<?php
/**
 * Hook registration.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Portal\Controller;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Portal\Webhooks;
use Diromax\Portal\Security\LoginGate;
use Diromax\Portal\Security\ClientGuard;
use Diromax\Portal\Service\DailyJobs;
use Diromax\Portal\Service\PasswordService;
use Diromax\Portal\Service\Postmark;
use Diromax\Portal\Service\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Every add_action / add_filter of the plugin, in one place.
 */
final class Hooks {

	/**
	 * Register.
	 *
	 * @param Plugin $app Object graph.
	 */
	public static function register( Plugin $app ): void {
		$retention = new Retention( $app );

		add_action( 'init', array( Router::class, 'add_rules' ) );
		add_filter( 'query_vars', array( Router::class, 'query_vars' ) );
		add_action( 'template_redirect', array( new Controller( $app ), 'dispatch' ), 0 );

		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes: the email batch window needs it (spec 8.2).
		add_action( Plugin::CRON_NOTIFY, array( $app->notifier, 'run' ) );
		add_action(
			Plugin::CRON_NOTIFY,
			static function () use ( $app ): void {
				( new DailyJobs( $app ) )->maybe_run();
			},
			20
		);
		add_action(
			Plugin::CRON_CLEANUP,
			static function () use ( $retention ): void {
				$retention->cleanup();
			}
		);
		add_action( 'init', array( Plugin::class, 'schedule' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( $retention, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $retention, 'register_eraser' ) );

		( new LoginGate( $app ) )->register();
		( new Postmark() )->register();
		( new Webhooks( $app ) )->register();
		add_filter( 'dx_nav_links', array( self::class, 'nav_link' ), 10, 2 );
		add_filter(
			'attach_session_information',
			static fn( array $session ): array => ( new PasswordService( Plugin::instance() ) )->stamp_session( $session )
		);

		ClientGuard::register( $app );

		if ( is_admin() ) {
			( new Admin( $app ) )->register();
		}
	}

	/**
	 * Five-minute schedule for the notification worker.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['dx_portal_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'כל 5 דקות', 'diromax-portal' ),
		);

		return $schedules;
	}

	/**
	 * Add "אזור אישי" to the Diromax theme's header navigation.
	 *
	 * The same link for everyone, so a cached page never shows per-user markup.
	 *
	 * @param array<int, array{url:string, label:string, current:bool}> $links    Links.
	 * @param string                                                    $location Menu location.
	 * @return array<int, array{url:string, label:string, current:bool}>
	 */
	public static function nav_link( array $links, string $location ): array {
		if ( 'primary' !== $location || ! apply_filters( 'dx_portal_show_nav_link', true ) ) {
			return $links;
		}

		$in_portal = '' !== (string) get_query_var( Router::VIEW );

		if ( $in_portal ) {
			// The theme reads an unknown page as its articles view; inside the portal nothing else is current.
			$links = array_map(
				static function ( array $link ): array {
					$link['current'] = false;
					return $link;
				},
				$links
			);
		}

		$links[] = array(
			'url'     => Router::portal_url(),
			'label'   => __( 'אזור אישי', 'diromax-portal' ),
			'current' => $in_portal,
		);

		return $links;
	}
}
