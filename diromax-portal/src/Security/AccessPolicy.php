<?php
/**
 * Access policy.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Security;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Data\MemberRepository;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Settings;
use Diromax\Portal\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Every authorization decision of the plugin, in one place (standard 4.7.7).
 *
 * Default deny. Each method answers for one user and one object, so a request
 * for someone else's case is refused even with a valid session (IDOR, 4.7.3).
 */
final class AccessPolicy {

	/**
	 * Constructor.
	 *
	 * @param MemberRepository $members Memberships.
	 * @param Clock            $clock   Clock.
	 */
	public function __construct(
		private readonly MemberRepository $members,
		private readonly Clock $clock,
	) {}

	/**
	 * May the user open the case screens at all?
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function can_use_admin( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Capabilities::MANAGE_CASES );
	}

	/**
	 * May the user see and manage every case and assign managers?
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function can_manage_all( int $user_id ): bool {
		return $this->can_use_admin( $user_id ) && user_can( $user_id, Capabilities::MANAGE_ALL );
	}

	/**
	 * May the user manage this case?
	 *
	 * @param int        $user_id User.
	 * @param CaseRecord $client_case    Case.
	 * @return bool
	 */
	public function can_manage_case( int $user_id, CaseRecord $client_case ): bool {
		if ( ! $this->can_use_admin( $user_id ) ) {
			return false;
		}

		return $this->can_manage_all( $user_id ) || $client_case->manager_id === $user_id;
	}

	/**
	 * May the user view this case in the portal?
	 *
	 * Managers of the case may (for "view as client"). A client may when they are a
	 * member and, for a closed case, while the post-closing access period lasts.
	 *
	 * @param int        $user_id User.
	 * @param CaseRecord $client_case    Case.
	 * @return bool
	 */
	public function can_view_case( int $user_id, CaseRecord $client_case ): bool {
		if ( $this->can_manage_case( $user_id, $client_case ) ) {
			return true;
		}

		if ( $user_id <= 0 || ! user_can( $user_id, Capabilities::VIEW_PORTAL ) ) {
			return false;
		}

		if ( ! $this->members->is_member( $client_case->id, $user_id ) ) {
			return false;
		}

		return ! $this->access_expired( $client_case );
	}

	/**
	 * May the user download this document?
	 *
	 * @param int            $user_id  User.
	 * @param CaseRecord     $client_case     The document's case.
	 * @param DocumentRecord $document Document.
	 * @return bool
	 */
	public function can_download( int $user_id, CaseRecord $client_case, DocumentRecord $document ): bool {
		if ( $document->case_id !== $client_case->id ) {
			return false;
		}

		if ( $this->can_manage_case( $user_id, $client_case ) ) {
			return true;
		}

		return $document->visible && $this->can_view_case( $user_id, $client_case );
	}

	/**
	 * May the user change portal settings?
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function can_manage_settings( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * May the user permanently delete this case, open or closed?
	 *
	 * @param int        $user_id User.
	 * @param CaseRecord $client_case    Case.
	 * @return bool
	 */
	public function can_delete_case( int $user_id, CaseRecord $client_case ): bool {
		// Any status (spec 19.1): administrators only, after typing the case number.
		return $client_case->id > 0
			&& $user_id > 0
			&& user_can( $user_id, Capabilities::DELETE_CASES );
	}

	/**
	 * Is the user a portal client and nothing more (no admin access)?
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function is_client_only( int $user_id ): bool {
		return $user_id > 0
			&& user_can( $user_id, Capabilities::VIEW_PORTAL )
			&& ! user_can( $user_id, Capabilities::MANAGE_CASES )
			&& ! user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Has the client's access to a closed case run out?
	 *
	 * @param CaseRecord $client_case Case.
	 * @return bool
	 */
	private function access_expired( CaseRecord $client_case ): bool {
		if ( CaseStatus::Closed !== $client_case->status || null === $client_case->closed_at ) {
			return false;
		}

		$closed = strtotime( $client_case->closed_at . ' UTC' );

		// Months a client keeps access after closing, read when needed so a settings change applies at once.
		$months = Settings::all()['access_months'];

		return false === $closed || $this->clock->now() > strtotime( '+' . $months . ' months', $closed );
	}
}
