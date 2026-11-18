<?php
/**
 * VatMode enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * How the entered amount relates to VAT.
 */
enum VatMode: string {

	/** The amount already includes VAT. */
	case Included = 'included';

	/** VAT is added on top of the amount. */
	case Excluded = 'excluded';

	/** No VAT (exempt supplier). */
	case Exempt = 'exempt';
}
