<?php
/**
 * Funding enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Where the money for an expense came from. Drives the project-account balance.
 */
enum Funding: string {

	/** The client paid the supplier directly — the common case. */
	case Client = 'client';

	/** Paid from the project account held by Diromax. */
	case Project = 'project';

	/** Deferred: settled from the sale proceeds. */
	case Proceeds = 'proceeds';

	/** Carried by an improving partner. */
	case Partner = 'partner';
}
