<?php
/**
 * Israeli ID number check.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Teudat zehut: up to 9 digits, the last a check digit (alternating weights
 * 1 and 2, digits of each product summed, total divisible by 10).
 */
final class IsraeliId {

	/**
	 * The ID with leading zeros to 9 digits, or null when it is not valid.
	 *
	 * @param string $raw As typed: spaces and dashes are ignored.
	 * @return string|null
	 */
	public static function normalize( string $raw ): ?string {
		$digits = str_replace( array( ' ', '-' ), '', trim( $raw ) );

		if ( ! preg_match( '/^\d{5,9}$/', $digits ) ) {
			return null;
		}

		$id = str_pad( $digits, 9, '0', STR_PAD_LEFT );

		if ( '000000000' === $id ) {
			return null;
		}

		$sum = 0;

		for ( $i = 0; $i < 9; $i++ ) {
			$product = (int) $id[ $i ] * ( 0 === $i % 2 ? 1 : 2 );
			$sum    += $product > 9 ? $product - 9 : $product;
		}

		return 0 === $sum % 10 ? $id : null;
	}

	/**
	 * The ID with all but its last 3 digits hidden, for screens.
	 *
	 * @param string $id Normalized ID.
	 * @return string
	 */
	public static function masked( string $id ): string {
		return str_repeat( '•', max( 0, strlen( $id ) - 3 ) ) . substr( $id, -3 );
	}
}
