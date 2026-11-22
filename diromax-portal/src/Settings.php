<?php
/**
 * Portal settings.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal;

use Diromax\Portal\Domain\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single settings option (autoload off).
 *
 * Every read goes through defaults and sanitize(), so a hand-edited option can
 * never feed an unexpected type or value to the rest of the plugin.
 *
 * @phpstan-type SettingsShape array{vat_rate:int, quiet_minutes:int, max_wait_minutes:int, hide_amounts:bool, payment_details:string, access_months:int, categories:array<string, string>, daily_hour:int, remind_before_days:int, remind_overdue_every:int, remind_overdue_max:int, monthly_summary:bool, stale_days:int, morning_summary:bool}
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
		);
	}

	/**
	 * Validate every field against its allowed range; fall back to the default.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return SettingsShape
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();

		return array(
			'vat_rate'             => self::int_in( $input['vat_rate'] ?? null, 0, 5000, $defaults['vat_rate'] ),
			'quiet_minutes'        => self::int_in( $input['quiet_minutes'] ?? null, 1, 120, $defaults['quiet_minutes'] ),
			'max_wait_minutes'     => self::int_in( $input['max_wait_minutes'] ?? null, 5, 720, $defaults['max_wait_minutes'] ),
			'hide_amounts'         => self::bool_of( $input['hide_amounts'] ?? null, $defaults['hide_amounts'] ),
			'payment_details'      => mb_substr( Text::block( is_string( $input['payment_details'] ?? null ) ? $input['payment_details'] : '' ), 0, 500 ),
			'access_months'        => self::int_in( $input['access_months'] ?? null, 1, 120, $defaults['access_months'] ),
			'categories'           => self::categories_from( $input['categories'] ?? null, $defaults['categories'] ),
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
}
