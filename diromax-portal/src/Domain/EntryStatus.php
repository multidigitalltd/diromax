<?php
/**
 * EntryStatus enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle state of a ledger entry. Which states a type may take is enforced by EntryRules.
 */
enum EntryStatus: string {

	/** Signed but not yet paid (expenses only). */
	case Committed = 'committed';

	/** Done: an expense paid, a payment or refund made, a request settled. */
	case Paid = 'paid';

	/** A payment request still waiting to be paid. */
	case Open = 'open';
}
