<?php
/**
 * A case's payment plan.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The payments agreed for a case, each due when a stage is done, on a date,
 * or when the manager says so (spec 21). An amount is fixed, or a share of
 * the case's base: the amount set for the plan, else the budget's total.
 * When a payment falls due it becomes a draft request the manager checks and
 * sends (decision 33).
 *
 * Pure: no WordPress, no database. Amounts in agorot; shares in basis points
 * (10% = 1000).
 *
 * @phpstan-type PlanFields array{label:string, trigger_on:string, stage:int, trigger_date:string|null, kind:string, amount:int, percent:int, pay_to:string, payee:string, days:int}
 */
final class PaymentPlan {

	/** When a payment falls due. */
	public const TRIGGERS = array( 'stage', 'date', 'manual' );

	/** How its amount is set. */
	public const KINDS = array( 'percent', 'fixed' );

	/** Who is paid. */
	public const PAYEES = array( 'diromax', 'supplier' );

	/** Longest label. */
	private const MAX_LABEL = 120;

	/** Most days to pay. */
	public const MAX_DAYS = 90;

	/**
	 * The amount of a payment.
	 *
	 * @param array{kind:string, amount:int, percent:int} $payment Payment.
	 * @param int                                         $base    The plan's base, agorot.
	 * @return int Agorot.
	 */
	public static function amount( array $payment, int $base ): int {
		return 'percent' === $payment['kind'] ? Money::divide_rounded( $base * $payment['percent'], Money::BPS_WHOLE ) : $payment['amount'];
	}

	/**
	 * The plan's totals from what each payment comes to now: a planned share
	 * at today's base, a draft or a request at its own amount. Cancelled
	 * payments are left out.
	 *
	 * @param array<int, array{kind:string, percent:int, amount:int, state:string}> $rows Payments as shown.
	 * @return array{percent:int, fixed:int, total:int} Basis points of the shares; agorot of the fixed ones and of the whole.
	 */
	public static function totals( array $rows ): array {
		$percent = 0;
		$fixed   = 0;
		$total   = 0;

		foreach ( $rows as $row ) {
			if ( 'cancelled' === $row['state'] ) {
				continue;
			}

			if ( 'percent' === $row['kind'] ) {
				$percent += $row['percent'];
			} else {
				$fixed += $row['amount'];
			}

			$total += $row['amount'];
		}

		return array(
			'percent' => $percent,
			'fixed'   => $fixed,
			'total'   => $total,
		);
	}

	/**
	 * Check one payment of the plan form.
	 *
	 * @param array<string, string> $input        label, trigger_on, stage, trigger_date (Y-m-d), kind, value (shekels, or percent), pay_to, payee, days.
	 * @param int                   $stages       Number of stages.
	 * @param bool                  $allow_dates  A date trigger is allowed (not in the office's template).
	 * @return array{fields:PlanFields|null, errors:array<string, string>}
	 */
	public static function validate( array $input, int $stages, bool $allow_dates ): array {
		$errors  = array();
		$label   = Text::line( $input['label'] ?? '' );
		$trigger = (string) ( $input['trigger_on'] ?? '' );
		$stage   = (int) ( $input['stage'] ?? 0 );
		$date    = (string) ( $input['trigger_date'] ?? '' );
		$kind    = (string) ( $input['kind'] ?? '' );
		$pay_to  = (string) ( $input['pay_to'] ?? 'diromax' );
		$payee   = Text::line( $input['payee'] ?? '' );
		$days    = trim( (string) ( $input['days'] ?? '' ) );

		if ( '' === $label ) {
			$errors['label'] = 'required';
		} elseif ( Text::length( $label ) > self::MAX_LABEL ) {
			$errors['label'] = 'too_long';
		}

		if ( ! in_array( $trigger, self::TRIGGERS, true ) || ( 'date' === $trigger && ! $allow_dates ) ) {
			$errors['trigger_on'] = 'invalid';
		} elseif ( 'stage' === $trigger && ( $stage < 1 || $stage > $stages ) ) {
			$errors['stage'] = 'invalid';
		} elseif ( 'date' === $trigger && ! self::is_date( $date ) ) {
			$errors['trigger_date'] = 'invalid';
		}

		[ $amount, $percent ] = self::figure( $kind, (string) ( $input['value'] ?? '' ), $errors );

		if ( ! in_array( $pay_to, self::PAYEES, true ) ) {
			$errors['pay_to'] = 'invalid';
		} elseif ( 'supplier' === $pay_to && '' === $payee ) {
			$errors['payee'] = 'required';
		}

		if ( '' !== $days && ( ! ctype_digit( $days ) || (int) $days > self::MAX_DAYS ) ) {
			$errors['days'] = 'invalid';
		}

		if ( array() !== $errors ) {
			return array(
				'fields' => null,
				'errors' => $errors,
			);
		}

		return array(
			'fields' => array(
				'label'        => $label,
				'trigger_on'   => $trigger,
				'stage'        => 'stage' === $trigger ? $stage : 0,
				'trigger_date' => 'date' === $trigger ? $date : null,
				'kind'         => $kind,
				'amount'       => $amount,
				'percent'      => $percent,
				'pay_to'       => $pay_to,
				'payee'        => 'supplier' === $pay_to ? mb_substr( $payee, 0, 120 ) : '',
				'days'         => '' === $days ? 14 : (int) $days,
			),
			'errors' => array(),
		);
	}

	/**
	 * A share as typed ("12.5") in basis points.
	 *
	 * @param string $value Percent, up to two decimals.
	 * @return int|null Null when it is not a share of 0.01–100%.
	 */
	public static function parse_percent( string $value ): ?int {
		$value = str_replace( array( '%', ' ' ), '', trim( $value ) );

		if ( 1 !== preg_match( '/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $match ) ) {
			return null;
		}

		$bps = (int) $match[1] * 100 + (int) str_pad( $match[2] ?? '', 2, '0' );

		return $bps > 0 && $bps <= Money::BPS_WHOLE ? $bps : null;
	}

	/**
	 * A share as it is typed and shown, exact: 1250 → "12.5", 1000 → "10".
	 *
	 * @param int $bps Basis points.
	 * @return string
	 */
	public static function percent_text( int $bps ): string {
		$bps  = max( 0, $bps );
		$rest = $bps % 100;

		return 0 === $rest ? (string) intdiv( $bps, 100 ) : rtrim( intdiv( $bps, 100 ) . '.' . str_pad( (string) $rest, 2, '0', STR_PAD_LEFT ), '0' );
	}

	/**
	 * Amount or share, by kind.
	 *
	 * @param string                $kind   percent or fixed.
	 * @param string                $value  As typed.
	 * @param array<string, string> $errors Errors, by reference.
	 * @return array{0:int, 1:int} Agorot, basis points.
	 */
	private static function figure( string $kind, string $value, array &$errors ): array {
		if ( 'percent' === $kind ) {
			$percent = self::parse_percent( $value );

			if ( null === $percent ) {
				$errors['value'] = 'percent';
			}

			return array( 0, (int) $percent );
		}

		if ( 'fixed' !== $kind ) {
			$errors['kind'] = 'invalid';
			return array( 0, 0 );
		}

		$amount = Money::parse( $value );

		if ( null === $amount || $amount <= 0 || $amount > Money::MAX ) {
			$errors['value'] = 'invalid';
			return array( 0, 0 );
		}

		return array( $amount, 0 );
	}

	/**
	 * A real Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
