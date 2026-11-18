<?php
/**
 * Text normalisation.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises free text before validation (standard section 4.1.6).
 */
final class Text {

	/**
	 * Normalise a single line: NFC, no control characters, collapsed spaces, trimmed.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function line( string $value ): string {
		$value = self::nfc( $value );
		// Control and format characters (bidi overrides included) become spaces;
		// the LRM/RLM marks Hebrew text legitimately carries are kept.
		$value = (string) preg_replace( '/(?![\x{200E}\x{200F}])[\p{Cc}\p{Cf}]/u', ' ', $value );
		$value = (string) preg_replace( '/\s+/u', ' ', $value );

		return trim( $value );
	}

	/**
	 * Normalise multi-line text: like line(), but keeps single line breaks.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function block( string $value ): string {
		$value = self::nfc( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
		$lines = array_map( array( self::class, 'line' ), explode( "\n", $value ) );
		$value = implode( "\n", $lines );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $value ) );
	}

	/**
	 * Character length, Unicode-aware.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	public static function length( string $value ): int {
		return mb_strlen( $value, 'UTF-8' );
	}

	/**
	 * Unicode NFC form when the intl extension is present; the value as is otherwise.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function nfc( string $value ): string {
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return '';
		}

		if ( class_exists( \Normalizer::class ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_C );

			return false === $normalized ? '' : $normalized;
		}

		return $value; // @codeCoverageIgnore -- only on hosts without the intl extension.
	}
}
