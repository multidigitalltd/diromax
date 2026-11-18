<?php
/**
 * Rendered email.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Subject, HTML body and plain-text alternative of one email.
 */
final readonly class MailMessage {

	/**
	 * Constructor.
	 *
	 * @param string $subject Subject, one line.
	 * @param string $html    HTML body.
	 * @param string $text    Plain-text body.
	 */
	public function __construct(
		public string $subject,
		public string $html,
		public string $text,
	) {}
}
