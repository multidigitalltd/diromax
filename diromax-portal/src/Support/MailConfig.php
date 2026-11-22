<?php
/**
 * Mail provider settings from wp-config.php.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The Postmark settings (spec 17), read from constants in wp-config.php so
 * no key is kept in the database (standard 4.6):
 *
 *   DX_POSTMARK_TOKEN        Server API token.
 *   DX_POSTMARK_FROM         Sender address on a verified domain.
 *   DX_POSTMARK_STREAM       Outbound message stream (default "outbound").
 *   DX_POSTMARK_INBOUND      The server's inbound address.
 *   DX_POSTMARK_WEBHOOK_KEY  Password the webhooks must carry.
 *
 * The dx_portal_mail_config filter can supply them instead (tests, or a
 * host that injects secrets another way).
 */
final class MailConfig {

	/** Setting name => constant. */
	private const CONSTANTS = array(
		'token'       => 'DX_POSTMARK_TOKEN',
		'from'        => 'DX_POSTMARK_FROM',
		'stream'      => 'DX_POSTMARK_STREAM',
		'inbound'     => 'DX_POSTMARK_INBOUND',
		'webhook_key' => 'DX_POSTMARK_WEBHOOK_KEY',
	);

	/**
	 * One setting, trimmed; '' when not set.
	 *
	 * @param string $name token, from, stream, inbound or webhook_key.
	 * @return string
	 */
	public static function get( string $name ): string {
		$constant = self::CONSTANTS[ $name ] ?? '';
		$value    = '' !== $constant && defined( $constant ) ? (string) constant( $constant ) : '';

		return trim( (string) apply_filters( 'dx_portal_mail_config', $value, $name ) );
	}
}
