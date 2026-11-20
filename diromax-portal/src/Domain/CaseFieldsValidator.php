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
}
