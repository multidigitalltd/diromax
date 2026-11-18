<?php
/**
 * VAT split.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Splits an entered amount into net, VAT and total, in integer agorot.
 *
 * The rate is in basis points (18% = 1800) and is stored on every entry, so a
 * future change in the rate never rewrites history.
 */
final class Vat {

	/**
	 * Split an amount.
	 *
	 * @param int     $amount   Amount as entered, in agorot, >= 0.
	 * @param VatMode $mode     How the amount relates to VAT.
	 * @param int     $rate_bps VAT rate in basis points, >= 0.
	 * @return array{net:int, vat:int, total:int}
	 */
	public static function split( int $amount, VatMode $mode, int $rate_bps ): array {
		return match ( $mode ) {
			VatMode::Exempt   => array(
				'net'   => $amount,
				'vat'   => 0,
				'total' => $amount,
			),
			VatMode::Excluded => self::from_net( $amount, $rate_bps ),
			VatMode::Included => self::from_total( $amount, $rate_bps ),
		};
	}

	/**
	 * VAT added on top of a net amount.
	 *
	 * @param int $net      Net amount in agorot.
	 * @param int $rate_bps Rate in basis points.
	 * @return array{net:int, vat:int, total:int}
	 */
	private static function from_net( int $net, int $rate_bps ): array {
		$vat = Money::divide_rounded( $net * $rate_bps, Money::BPS_WHOLE );

		return array(
			'net'   => $net,
			'vat'   => $vat,
			'total' => $net + $vat,
		);
	}

	/**
	 * VAT extracted from a gross amount. The total is kept exactly as entered.
	 *
	 * @param int $total    Gross amount in agorot.
	 * @param int $rate_bps Rate in basis points.
	 * @return array{net:int, vat:int, total:int}
	 */
	private static function from_total( int $total, int $rate_bps ): array {
		$net = Money::divide_rounded( $total * Money::BPS_WHOLE, Money::BPS_WHOLE + $rate_bps );

		return array(
			'net'   => $net,
			'vat'   => $total - $net,
			'total' => $total,
		);
	}
}
