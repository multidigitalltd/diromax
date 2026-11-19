<?php
/**
 * Member record.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;

defined( 'ABSPATH' ) || exit;

/**
 * A member of a case with the account fields the screens need.
 */
final readonly class MemberRecord {

	/**
	 * Constructor.
	 *
	 * @param int         $case_id      Case.
	 * @param int         $user_id      User.
	 * @param MemberRole  $role         Role in the case.
	 * @param bool        $notify       Receives emails.
	 * @param string|null $invited_at   UTC.
	 * @param string|null $last_seen_at UTC.
	 * @param string      $name         Display name.
	 * @param string      $email        Email.
	 * @param bool        $digest       Daily digest instead of instant email.
	 * @param string|null $digest_sent_at Last digest, UTC.
	 * @param string      $joined_at    Added to the case, UTC.
	 */
	public function __construct(
		public int $case_id,
		public int $user_id,
		public MemberRole $role,
		public bool $notify,
		public ?string $invited_at,
		public ?string $last_seen_at,
		public string $name,
		public string $email,
		public bool $digest = false,
		public ?string $digest_sent_at = null,
		public string $joined_at = '',
	) {}

	/**
	 * Email preference.
	 *
	 * @return NotifyMode
	 */
	public function mode(): NotifyMode {
		return NotifyMode::from_flags( $this->notify, $this->digest );
	}

	/**
	 * Build from a joined row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$digest = (string) ( $row['digest'] ?? '0' );

		return new self(
			case_id: (int) $row['case_id'],
			user_id: (int) $row['user_id'],
			role: MemberRole::tryFrom( (string) $row['role'] ) ?? MemberRole::Viewer,
			notify: '1' === (string) $row['notify'],
			invited_at: $row['invited_at'] ?? null,
			last_seen_at: $row['last_seen_at'] ?? null,
			name: (string) $row['display_name'],
			email: (string) $row['user_email'],
			digest: '1' === $digest,
			digest_sent_at: $row['digest_sent_at'] ?? null,
			joined_at: (string) ( $row['created_at'] ?? '' ),
		);
	}
}
