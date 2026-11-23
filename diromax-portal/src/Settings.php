<?php
/**
 * Portal settings.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal;

use Diromax\Portal\Domain\BudgetTree;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Service\Stages;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single settings option (autoload off).
 *
 * Every read goes through defaults and sanitize(), so a hand-edited option can
 * never feed an unexpected type or value to the rest of the plugin.
 *
 * @phpstan-type SettingsShape array{vat_rate:int, quiet_minutes:int, max_wait_minutes:int, hide_amounts:bool, payment_details:string, access_months:int, categories:array<string, string>, subcategories:array<string, array<string, string>>, plan_template:array<int, array{label:string, trigger_on:string, stage:int, trigger_date:null, kind:string, amount:int, percent:int, pay_to:string, payee:string, days:int}>, daily_hour:int, remind_before_days:int, remind_overdue_every:int, remind_overdue_max:int, monthly_summary:bool, stale_days:int, morning_summary:bool}
 */
final class Settings {

	/** Option name. */
	public const OPTION = 'dx_portal_settings';

	/**
	 * Settings with defaults applied.
	 *
	 * @return SettingsShape
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Budget categories: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return self::all()['categories'];
	}

	/**
	 * The default budget every new case starts from: sections and their
	 * sub-sections, with no amounts (spec 19.2).
	 *
	 * @return BudgetTree
	 */
	public static function budget_template(): BudgetTree {
		$settings = self::all();
		$lines    = array();

		foreach ( $settings['categories'] as $key => $label ) {
			$lines[] = array(
				'key'     => $key,
				'parent'  => '',
				'label'   => $label,
				'planned' => 0,
			);

			foreach ( $settings['subcategories'][ $key ] ?? array() as $child => $child_label ) {
				$lines[] = array(
					'key'     => $child,
					'parent'  => $key,
					'label'   => $child_label,
					'planned' => 0,
				);
			}
		}

		return new BudgetTree( $lines );
	}

	/**
	 * A default budget as the two settings that hold it.
	 *
	 * @param BudgetTree $tree Default budget.
	 * @return array{categories:array<string, string>, subcategories:array<string, array<string, string>>}
	 */
	public static function template_settings( BudgetTree $tree ): array {
		$sections = array();
		$children = array();

		foreach ( $tree->lines() as $line ) {
			if ( '' === $line['parent'] ) {
				$sections[ $line['key'] ] = $line['label'];
			} else {
				$children[ $line['parent'] ][ $line['key'] ] = $line['label'];
			}
		}

		return array(
			'categories'    => $sections,
			'subcategories' => $children,
		);
	}

	/**
	 * Label of a category, or the key itself when unknown.
	 *
	 * @param string $key Category key.
	 * @return string
	 */
	public static function category_label( string $key ): string {
		if ( '' === $key || 'other' === $key ) {
			return __( 'אחר', 'diromax-portal' );
		}

		return self::categories()[ $key ] ?? $key;
	}

	/**
	 * Save settings.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed> What was stored.
	 */
	public static function save( array $input ): array {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Defaults.
	 *
	 * @return SettingsShape
	 */
	public static function defaults(): array {
		return array(
			'vat_rate'             => 1800,
			'quiet_minutes'        => 10,
			'max_wait_minutes'     => 30,
			'hide_amounts'         => false,
			'payment_details'      => '',
			'access_months'        => 12,
			'daily_hour'           => 9,
			'remind_before_days'   => 3,
			'remind_overdue_every' => 7,
			'remind_overdue_max'   => 3,
			'monthly_summary'      => true,
			'morning_summary'      => true,
			'stale_days'           => 21,
			'categories'           => array(
				'research'     => __( 'בדיקות ומידע תכנוני', 'diromax-portal' ),
				'architecture' => __( 'אדריכלות ותכנון', 'diromax-portal' ),
				'consultants'  => __( 'יועצים ומדידות', 'diromax-portal' ),
				'fees'         => __( 'אגרות והיטלים', 'diromax-portal' ),
				'neighbors'    => __( 'שכנים והסכמות', 'diromax-portal' ),
				'contractor'   => __( 'ביצוע: קבלן ראשי', 'diromax-portal' ),
				'suppliers'    => __( 'ביצוע: ספקים וקבלני משנה', 'diromax-portal' ),
				'supervision'  => __( 'פיקוח', 'diromax-portal' ),
				'legal'        => __( 'משפטי ורישום', 'diromax-portal' ),
				'marketing'    => __( 'שיווק ומכירה', 'diromax-portal' ),
				'management'   => __( 'שכר ניהול דירומקס', 'diromax-portal' ),
				'reserve'      => __( 'רזרבה לבלתי צפוי', 'diromax-portal' ),
			),
			// The office's payment plan for a new case (spec 21): empty until the office sets it.
			'plan_template'        => array(),
			'subcategories'        => array(
				'consultants' => array(
					'consultants-structure' => __( 'קונסטרוקציה', 'diromax-portal' ),
					'consultants-survey'    => __( 'מדידות', 'diromax-portal' ),
					'consultants-soil'      => __( 'קרקע וביסוס', 'diromax-portal' ),
					'consultants-plumbing'  => __( 'אינסטלציה וניקוז', 'diromax-portal' ),
					'consultants-electric'  => __( 'חשמל', 'diromax-portal' ),
					'consultants-safety'    => __( 'בטיחות ונגישות', 'diromax-portal' ),
				),
				'fees'        => array(
					'fees-permit'      => __( 'אגרות בנייה', 'diromax-portal' ),
					'fees-betterment'  => __( 'היטל השבחה', 'diromax-portal' ),
					'fees-development' => __( 'היטלי פיתוח', 'diromax-portal' ),
				),
				'legal'       => array(
					'legal-lawyer'   => __( 'עורך דין', 'diromax-portal' ),
					'legal-registry' => __( 'רישום בטאבו', 'diromax-portal' ),
				),
			),
		);
	}

	/**
	 * Validate every field against its allowed range; fall back to the default.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return SettingsShape
	 */
	public static function sanitize( array $input ): array {
		$defaults   = self::defaults();
		$categories = self::categories_from( $input['categories'] ?? null, $defaults['categories'] );

		return array(
			'vat_rate'             => self::int_in( $input['vat_rate'] ?? null, 0, 5000, $defaults['vat_rate'] ),
			'quiet_minutes'        => self::int_in( $input['quiet_minutes'] ?? null, 1, 120, $defaults['quiet_minutes'] ),
			'max_wait_minutes'     => self::int_in( $input['max_wait_minutes'] ?? null, 5, 720, $defaults['max_wait_minutes'] ),
			'hide_amounts'         => self::bool_of( $input['hide_amounts'] ?? null, $defaults['hide_amounts'] ),
			'payment_details'      => mb_substr( Text::block( is_string( $input['payment_details'] ?? null ) ? $input['payment_details'] : '' ), 0, 500 ),
			'access_months'        => self::int_in( $input['access_months'] ?? null, 1, 120, $defaults['access_months'] ),
			'categories'           => $categories,
			'subcategories'        => self::subcategories_from( $input['subcategories'] ?? null, $categories, $defaults['subcategories'] ),
			'plan_template'        => self::plan_from( $input['plan_template'] ?? null ),
			'daily_hour'           => self::int_in( $input['daily_hour'] ?? null, 0, 23, $defaults['daily_hour'] ),
			'remind_before_days'   => self::int_in( $input['remind_before_days'] ?? null, 0, 14, $defaults['remind_before_days'] ),
			'remind_overdue_every' => self::int_in( $input['remind_overdue_every'] ?? null, 0, 30, $defaults['remind_overdue_every'] ),
			'remind_overdue_max'   => self::int_in( $input['remind_overdue_max'] ?? null, 0, 10, $defaults['remind_overdue_max'] ),
			'monthly_summary'      => self::bool_of( $input['monthly_summary'] ?? null, $defaults['monthly_summary'] ),
			'morning_summary'      => self::bool_of( $input['morning_summary'] ?? null, $defaults['morning_summary'] ),
			'stale_days'           => self::int_in( $input['stale_days'] ?? null, 3, 90, $defaults['stale_days'] ),
		);
	}

	/**
	 * A checkbox value: true, '1' and false, '0', '' are understood; anything else is the default.
	 *
	 * @param mixed $value    Raw value.
	 * @param bool  $fallback Fallback.
	 * @return bool
	 */
	private static function bool_of( mixed $value, bool $fallback ): bool {
		return match ( true ) {
			true === $value, '1' === $value => true,
			false === $value, '0' === $value, '' === $value => false,
			default => $fallback,
		};
	}

	/**
	 * Integer within a range, or the default.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $min     Minimum.
	 * @param int   $max     Maximum.
	 * @param int   $fallback Fallback.
	 * @return int
	 */
	private static function int_in( mixed $value, int $min, int $max, int $fallback ): int {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$number = (int) $value;

			if ( $number >= $min && $number <= $max ) {
				return $number;
			}
		}

		return $fallback;
	}

	/**
	 * Category map: keys are slugs, labels are single lines; at most 40 entries.
	 *
	 * @param mixed                 $value    Raw value.
	 * @param array<string, string> $fallback Defaults.
	 * @return array<string, string>
	 */
	private static function categories_from( mixed $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		$clean = array();

		foreach ( $value as $key => $label ) {
			$key   = sanitize_key( (string) $key );
			$label = is_string( $label ) ? mb_substr( Text::line( $label ), 0, 60 ) : '';

			if ( '' !== $key && '' !== $label && 'other' !== $key && strlen( $key ) <= 40 ) {
				$clean[ $key ] = $label;
			}
		}

		return array() === $clean ? $fallback : array_slice( $clean, 0, 40, true );
	}

	/**
	 * Sub-sections of the default budget: only under sections that exist,
	 * keys unique across the whole default, at most 30 under a section.
	 *
	 * @param mixed                                $value      Raw value.
	 * @param array<string, string>                $categories Sections.
	 * @param array<string, array<string, string>> $fallback   Defaults.
	 * @return array<string, array<string, string>>
	 */
	private static function subcategories_from( mixed $value, array $categories, array $fallback ): array {
		$used  = array_fill_keys( array_keys( $categories ), true );
		$clean = array();

		foreach ( is_array( $value ) ? $value : $fallback as $parent => $children ) {
			if ( ! isset( $categories[ $parent ] ) || ! is_array( $children ) ) {
				continue;
			}

			foreach ( $children as $key => $label ) {
				$key   = sanitize_key( (string) $key );
				$label = is_string( $label ) ? mb_substr( Text::line( $label ), 0, 60 ) : '';

				if ( '' === $key || '' === $label || isset( $used[ $key ] ) || 'other' === $key || strlen( $key ) > 40 || count( $clean[ $parent ] ?? array() ) >= BudgetTree::MAX_CHILDREN ) {
					continue;
				}

				$used[ $key ]             = true;
				$clean[ $parent ][ $key ] = $label;
			}
		}

		return $clean;
	}

	/**
	 * The payment plan template: payments on a stage or by hand (a date
	 * belongs to a case), each as stored by the settings screen. At most 20.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array{label:string, trigger_on:string, stage:int, trigger_date:null, kind:string, amount:int, percent:int, pay_to:string, payee:string, days:int}>
	 */
	private static function plan_from( mixed $value ): array {
		$clean = array();

		foreach ( is_array( $value ) ? array_slice( $value, 0, 20 ) : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label   = is_string( $row['label'] ?? null ) ? mb_substr( Text::line( $row['label'] ), 0, 120 ) : '';
			$trigger = in_array( $row['trigger_on'] ?? '', array( 'stage', 'manual' ), true ) ? (string) $row['trigger_on'] : '';
			$kind    = in_array( $row['kind'] ?? '', array( 'percent', 'fixed' ), true ) ? (string) $row['kind'] : '';
			$pay_to  = 'supplier' === ( $row['pay_to'] ?? '' ) ? 'supplier' : 'diromax';
			$stage   = self::int_in( $row['stage'] ?? null, 1, Stages::COUNT, 0 );
			$amount  = self::int_in( $row['amount'] ?? null, 0, Money::MAX, 0 );
			$percent = self::int_in( $row['percent'] ?? null, 0, Money::BPS_WHOLE, 0 );
			$payee   = 'supplier' === $pay_to && is_string( $row['payee'] ?? null ) ? mb_substr( Text::line( $row['payee'] ), 0, 120 ) : '';

			if ( '' === $label || '' === $trigger || '' === $kind || ( 'stage' === $trigger && 0 === $stage ) || ( 'percent' === $kind ? 0 === $percent : 0 === $amount ) || ( 'supplier' === $pay_to && '' === $payee ) ) {
				continue;
			}

			$clean[] = array(
				'label'        => $label,
				'trigger_on'   => $trigger,
				'stage'        => 'stage' === $trigger ? $stage : 0,
				'trigger_date' => null,
				'kind'         => $kind,
				'amount'       => 'fixed' === $kind ? $amount : 0,
				'percent'      => 'percent' === $kind ? $percent : 0,
				'pay_to'       => $pay_to,
				'payee'        => $payee,
				'days'         => self::int_in( $row['days'] ?? null, 0, 90, 14 ),
			);
		}

		return $clean;
	}
}
