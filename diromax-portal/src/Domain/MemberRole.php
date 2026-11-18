<?php
/**
 * MemberRole enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Role of a person in a case.
 */
enum MemberRole: string {

	/** Owner of the property. */
	case Owner = 'owner';

	/** Co-owner or heir. */
	case Partner = 'partner';

	/** Follows the case, e.g. a relative or a lawyer. */
	case Viewer = 'viewer';
}
