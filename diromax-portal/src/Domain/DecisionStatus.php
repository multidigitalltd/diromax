<?php
/**
 * Decision status.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Life cycle of a decision put to the client (spec 15.5).
 *
 * Pending is the only state that can change; every other state is final.
 */
enum DecisionStatus: string {

	/** Waiting for the client. */
	case Pending = 'pending';

	/** The client approved it. */
	case Approved = 'approved';

	/** The client declined it. */
	case Declined = 'declined';

	/** The project manager withdrew it before an answer. */
	case Withdrawn = 'withdrawn';
}
