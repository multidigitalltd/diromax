<?php
/**
 * What the mail transport needs to know about the message in flight.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The mail call carries one body (wp_mail). The plugin's mailer sets the plain-text
 * version here for the transport, and the transport leaves the provider's
 * message ID for the mail log, which uses it to match bounce reports.
 */
final class MailTrace {

	/**
	 * Plain-text version of the message being sent, '' when none was given.
	 *
	 * @var string
	 */
	public static string $text = '';

	/**
	 * Provider message ID of the last message sent.
	 *
	 * @var string
	 */
	private static string $last_id = '';

	/**
	 * Remember the provider's ID of the message just sent.
	 *
	 * @param string $id Message ID.
	 */
	public static function sent( string $id ): void {
		self::$last_id = $id;
	}

	/**
	 * Forget the previous message (before sending a new one).
	 */
	public static function reset(): void {
		self::$last_id = '';
	}

	/**
	 * The ID of the last message sent, once.
	 *
	 * @return string '' when there is none.
	 */
	public static function take_id(): string {
		$id            = self::$last_id;
		self::$last_id = '';

		return $id;
	}
}
