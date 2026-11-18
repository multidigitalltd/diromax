<?php
/**
 * PayTo enum.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Who a payment request asks the client to pay.
 */
enum PayTo: string {

	/** A supplier, directly. */
	case Supplier = 'supplier';

	/** Diromax, into the project account. */
	case Diromax = 'diromax';
}
