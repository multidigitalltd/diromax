<?php
/**
 * Retention and data-subject rights.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes what has outlived its retention period (spec section 9) and plugs
 * the portal into WordPress's export and erasure tools (standard 5.3).
 */
final class Retention {

	/** Mail log and unattached audit entries: 24 months. */
	private const LOG_MONTHS = 24;

	/** Finished notification events: 90 days. */
	private const EVENT_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Daily cleanup.
	 *
	 * @return array{tokens:int, mail:int, events:int, audit:int}
	 */
	public function cleanup(): array {
		$now = $this->app->clock->now();

		return array(
			'tokens' => $this->app->tokens->purge( gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ),
			'mail'   => $this->app->mail_log->purge( gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . self::LOG_MONTHS . ' months', $now ) ) ),
			'events' => $this->app->events->purge( gmdate( 'Y-m-d H:i:s', $now - self::EVENT_DAYS * DAY_IN_SECONDS ) ),
			'audit'  => $this->app->audit->purge_unattached( gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . self::LOG_MONTHS . ' months', $now ) ) ),
		);
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['diromax-portal'] = array(
			'exporter_friendly_name' => __( 'אזור אישי דירומקס', 'diromax-portal' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['diromax-portal'] = array(
			'eraser_friendly_name' => __( 'אזור אישי דירומקס', 'diromax-portal' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export a person's portal data: memberships and emails sent.
	 *
	 * @param string $email Email.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email ): array {
		$user = get_user_by( 'email', $email );
		$data = array();

		if ( $user ) {
			foreach ( $this->app->members->memberships( (int) $user->ID ) as $index => $row ) {
				$data[] = self::group( 'dx-portal-membership', __( 'חברות בתיקים', 'diromax-portal' ), 'membership-' . $index, $row );
			}

			foreach ( $this->app->mail_log->for_user( (int) $user->ID ) as $index => $row ) {
				$data[] = self::group( 'dx-portal-mail', __( 'מיילים שנשלחו', 'diromax-portal' ), 'mail-' . $index, $row );
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase a person's portal data. Ledger rows belong to the case and stay.
	 *
	 * @param string $email Email.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = 0;

		if ( $user ) {
			$removed += $this->app->members->remove_user( (int) $user->ID );
			$removed += $this->app->mail_log->anonymize_user( (int) $user->ID );
			$this->app->audit->record( 'privacy.erased', null, 'user', (int) $user->ID, array( 'rows' => $removed ), $this->app->clock->mysql() );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $removed > 0,
			'messages'       => $removed > 0 ? array( __( 'הרשומות הכספיות של התיק נשמרות אצל דירומקס, ללא קישור לחשבון.', 'diromax-portal' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * One export group item.
	 *
	 * @param string                     $group_id    Group.
	 * @param string                     $group_label Group label.
	 * @param string                     $item_id     Item.
	 * @param array<string, string|null> $row         Fields.
	 * @return array<string, mixed>
	 */
	private static function group( string $group_id, string $group_label, string $item_id, array $row ): array {
		$fields = array();

		foreach ( $row as $name => $value ) {
			$fields[] = array(
				'name'  => (string) $name,
				'value' => (string) $value,
			);
		}

		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => $fields,
		);
	}
}
