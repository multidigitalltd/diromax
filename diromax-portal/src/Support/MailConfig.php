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
 * The Postmark settings (spec 17), as Integrations holds them: set on the
 * plugin's settings screen, or fixed in wp-config.php, which wins.
 *
 * Settings: token, the server API token (DX_POSTMARK_TOKEN), a secret kept
 * sealed; from, the sender address on a verified domain (DX_POSTMARK_FROM);
 * stream, default "outbound" (DX_POSTMARK_STREAM); inbound, the server's
 * inbound address (DX_POSTMARK_INBOUND).
 *
 * The webhook key is only ever compared as a hash: Integrations::webhook_hash().
 * The dx_portal_mail_config filter can supply a value instead (tests, or a
 * host that injects secrets another way).
 */
final class MailConfig {

	/** Settings read through here. */
	private const NAMES = array( 'token', 'from', 'stream', 'inbound' );

	/**
	 * One setting, trimmed; '' when not set.
	 *
	 * @param string $name token, from, stream or inbound.
	 * @return string
	 */
	public static function get( string $name ): string {
		$value = in_array( $name, self::NAMES, true ) ? Integrations::get( $name ) : '';

		return trim( (string) apply_filters( 'dx_portal_mail_config', $value, $name ) );
	}
}
