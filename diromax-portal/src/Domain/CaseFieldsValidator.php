<?php
/**
 * Case details validation.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the editable details of a case.
 */
final class CaseFieldsValidator {

	/** Field => [maximum length, required]. */
	private const TEXT_FIELDS = array(
		'title'         => array( 200, true ),
		'property_type' => array( 60, false ),
		'track'         => array( 120, false ),
		'scope'         => array( 300, false ),
		'fee_terms'     => array( 300, false ),
	);

	/**
	 * Validate.
	 *
	 * @param array<string, string> $input Raw values.
	 * @return array{fields: array<string, string|null>, errors: array<string, string>}
	 */
	public static function validate( array $input ): array {
		$fields = array();
		$errors = array();

		foreach ( self::TEXT_FIELDS as $name => [ $max, $required ] ) {
			$value = Text::line( $input[ $name ] ?? '' );

			if ( $required && '' === $value ) {
				$errors[ $name ] = 'required';
			} elseif ( Text::length( $value ) > $max ) {
				$errors[ $name ] = 'too_long';
			}

			$fields[ $name ] = $value;
		}

		$signed = trim( $input['signed_on'] ?? '' );

		if ( '' !== $signed && ! EntryValidator::is_date( $signed ) ) {
			$errors['signed_on'] = 'invalid';
		}

		$fields['signed_on'] = '' === $signed ? null : $signed;

		return array(
			'fields' => $fields,
			'errors' => $errors,
		);
	}

	/**
	 * Stage states after moving a case to a stage.
	 *
	 * Stages before it become done (skipped ones stay skipped), the stage
	 * becomes active, later stages become pending (skipped ones stay skipped).
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages Current states, keyed 1–7.
	 * @param int                                                                     $stage  New current stage, 1–7.
	 * @param string                                                                  $today  Y-m-d.
	 * @return array<int, array{status:string, started:string|null, done:string|null}>
	 */
	public static function move_to_stage( array $stages, int $stage, string $today ): array {
		foreach ( $stages as $number => $state ) {
			if ( StageStatus::Skipped->value === $state['status'] ) {
				continue;
			}

			if ( $number < $stage ) {
				$state['status']    = StageStatus::Done->value;
				$state['started'] ??= $today;
				$state['done']    ??= $today;
			} elseif ( $number === $stage ) {
				$state['status']    = StageStatus::Active->value;
				$state['started'] ??= $today;
				$state['done']      = null;
			} else {
				$state['status'] = StageStatus::Pending->value;
				$state['done']   = null;
			}

			$stages[ $number ] = $state;
		}

		return $stages;
	}

	/**
	 * Mark a stage skipped or not, keeping the rest.
	 *
	 * @param array<int, array{status:string, started:string|null, done:string|null}> $stages  Current states.
	 * @param int[]                                                                   $skipped Stage numbers that are not relevant.
	 * @param int                                                                     $current Current stage.
	 * @param string                                                                  $today   Y-m-d.
	 * @return array<int, array{status:string, started:string|null, done:string|null}>
	 */
	public static function apply_skipped( array $stages, array $skipped, int $current, string $today ): array {
		foreach ( $stages as $number => $state ) {
			$is_skipped = in_array( $number, $skipped, true ) && $number !== $current;

			if ( $is_skipped ) {
				$stages[ $number ]['status'] = StageStatus::Skipped->value;
			} elseif ( StageStatus::Skipped->value === $state['status'] ) {
				$stages[ $number ]['status'] = StageStatus::Pending->value;
			}
		}

		return self::move_to_stage( $stages, $current, $today );
	}
}
