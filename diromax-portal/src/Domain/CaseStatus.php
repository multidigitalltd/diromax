<?php
/**
 * CaseStatus enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * State of a client case.
 */
enum CaseStatus: string {

	/** Work in progress. */
	case Active = 'active';

	/** Paused. */
	case OnHold = 'on_hold';

	/** Finished; read-only for the client until access expires. */
	case Closed = 'closed';
}
