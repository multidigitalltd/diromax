<?php
/**
 * Member record.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\MemberRole;

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
	) {}

	/**
	 * Build from a joined row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			case_id: (int) $row['case_id'],
			user_id: (int) $row['user_id'],
			role: MemberRole::tryFrom( (string) $row['role'] ) ?? MemberRole::Viewer,
			notify: '1' === (string) $row['notify'],
			invited_at: $row['invited_at'] ?? null,
			last_seen_at: $row['last_seen_at'] ?? null,
			name: (string) $row['display_name'],
			email: (string) $row['user_email'],
		);
	}
}
