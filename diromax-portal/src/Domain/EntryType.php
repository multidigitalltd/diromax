<?php
/**
 * EntryType enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Kind of ledger entry.
 */
enum EntryType: string {

	/** Money paid, or committed, to a supplier for the project. */
	case Expense = 'expense';

	/** Money the client transferred into the project account. */
	case Payment = 'payment';

	/** Money returned to the client from the project account. */
	case Refund = 'refund';

	/** A request for the client to pay a supplier or Diromax. */
	case Request = 'request';
}
