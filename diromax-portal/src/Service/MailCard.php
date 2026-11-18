<?php
/**
 * One card of an update email.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

defined( 'ABSPATH' ) || exit;

/**
 * What the email template prints for one event. Built only by MailItems.
 */
final readonly class MailCard {

	/**
	 * Constructor.
	 *
	 * @param string                                $tone    Badge colour: orange, blue, teal, ink or grey.
	 * @param string                                $badge   Badge text.
	 * @param string                                $title   Title.
	 * @param array<int, array{0:string, 1:string}> $rows    Label/value rows.
	 * @param string|null                           $amount  Formatted amount, or null when hidden.
	 * @param string                                $note    Free text under the rows.
	 * @param int|null                              $stage   Stage the case moved to.
	 * @param array{label:string, url:string}|null  $link    Link into the portal.
	 * @param string                                $subject Subject when this card is alone.
	 */
	public function __construct(
		public string $tone,
		public string $badge,
		public string $title,
		public array $rows,
		public ?string $amount,
		public string $note,
		public ?int $stage,
		public ?array $link,
		public string $subject,
	) {}
}
