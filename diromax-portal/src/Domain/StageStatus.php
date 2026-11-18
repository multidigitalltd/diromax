<?php
/**
 * StageStatus enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * State of one of the seven process stages in a case.
 */
enum StageStatus: string {

	/** Not started yet. */
	case Pending = 'pending';

	/** In progress. */
	case Active = 'active';

	/** Completed. */
	case Done = 'done';

	/** Not relevant for this case. */
	case Skipped = 'skipped';
}
