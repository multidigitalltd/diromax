<?php
/**
 * Money: integer agorot in, integer agorot out.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Parsing and formatting of shekel amounts.
 *
 * Every amount in the system is an integer number of agorot. This class is the
 * only place that turns user text into agorot and agorot into display text.
 */
final class Money {

	/** Largest amount accepted from input: 999,999,999.99 ₪. */
	public const MAX = 99999999999;

	/** Agorot per shekel. */
	private const SUBUNITS = 100;

	/** Basis points in a whole (100%). */
	public const BPS_WHOLE = 10000;

	/**
	 * Parse a user-entered amount into agorot.
	 *
	 * Accepts thousands separators (comma, space, apostrophe), an optional ₪ sign
	 * and up to two decimals. Rejects negatives and anything that is not a number.
	 *
	 * @param string $input Raw amount as typed.
	 * @return int|null Agorot, or null when the text is not a valid amount.
	 */
	public static function parse( string $input ): ?int {
		$clean = str_replace( array( '₪', ',', ' ', "'", "\u{00A0}", "\u{202F}" ), '', trim( $input ) );

		if ( '' === $clean || strlen( $clean ) > 16 ) {
			return null;
		}

		if ( ! preg_match( '/^(\d{1,9})(?:\.(\d{1,2}))?$/', $clean, $match ) ) {
			return null;
		}

		$whole    = (int) $match[1];
		$fraction = isset( $match[2] ) ? (int) str_pad( $match[2], 2, '0' ) : 0;

		return $whole * self::SUBUNITS + $fraction;
	}

	/**
	 * Format agorot for display, e.g. "12,300 ₪" or "12,300.50 ₪".
	 *
	 * @param int $agorot Amount in agorot. Negative values get a leading minus.
	 * @return string
	 */
	public static function format( int $agorot ): string {
		return self::plain( $agorot ) . ' ₪';
	}

	/**
	 * Format agorot without the currency sign, e.g. "12,300".
	 *
	 * @param int $agorot Amount in agorot.
	 * @return string
	 */
	public static function plain( int $agorot ): string {
		$sign     = $agorot < 0 ? '−' : '';
		$absolute = abs( $agorot );
		$whole    = intdiv( $absolute, self::SUBUNITS );
		$fraction = $absolute % self::SUBUNITS;
		$text     = number_format( $whole, 0, '.', ',' );

		if ( 0 !== $fraction ) {
			$text .= '.' . str_pad( (string) $fraction, 2, '0', STR_PAD_LEFT );
		}

		return $sign . $text;
	}

	/**
	 * Amount as a plain decimal string for an editable input, e.g. "12300.5".
	 *
	 * @param int $agorot Amount in agorot, >= 0.
	 * @return string
	 */
	public static function input( int $agorot ): string {
		$whole    = intdiv( $agorot, self::SUBUNITS );
		$fraction = $agorot % self::SUBUNITS;

		return 0 === $fraction ? (string) $whole : $whole . '.' . str_pad( (string) $fraction, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Share of a total in basis points (1% = 100), rounded half up.
	 *
	 * @param int $part  Part, in agorot.
	 * @param int $total Total, in agorot.
	 * @return int Basis points; 0 when either value is not positive.
	 */
	public static function share_bps( int $part, int $total ): int {
		if ( $total <= 0 || $part <= 0 ) {
			return 0;
		}

		return self::divide_rounded( $part * self::BPS_WHOLE, $total );
	}

	/**
	 * Integer division rounded half up, for non-negative operands.
	 *
	 * @param int $dividend Dividend, >= 0.
	 * @param int $divisor  Divisor, > 0.
	 * @return int
	 */
	public static function divide_rounded( int $dividend, int $divisor ): int {
		return intdiv( 2 * $dividend + $divisor, 2 * $divisor );
	}

	/**
	 * Basis points as a percentage label, e.g. 1642 → "16.4%".
	 *
	 * @param int $bps Basis points; negatives are shown as 0%.
	 * @return string
	 */
	public static function percent( int $bps ): string {
		$tenths = self::divide_rounded( max( 0, $bps ), 10 );
		$whole  = intdiv( $tenths, 10 );
		$rest   = $tenths % 10;

		return ( 0 === $rest ? (string) $whole : $whole . '.' . $rest ) . '%';
	}
}
