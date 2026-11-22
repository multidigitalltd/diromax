<?php
/**
 * Signature form input.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what a signer sends: a full name, a drawn signature as a PNG data
 * URL (or the statement that they cannot draw, when the typed name is the
 * signature), and the confirmation that they read the document. An ID
 * number is required when signing through an email link.
 */
final class SignatureInput {

	/** Largest signature image accepted, bytes. */
	public const MAX_IMAGE_BYTES = 200000;

	/** Largest signature image accepted, pixels each way. */
	private const MAX_SIDE = 2000;

	/**
	 * Validate.
	 *
	 * @param array<string, string> $input    name, image, no_draw, confirm, id_number.
	 * @param bool                  $need_id  An ID number is required.
	 * @return array{name:string, image:string, id_number:string, errors:array<string, string>} Image as raw PNG bytes, '' when not drawn.
	 */
	public static function check( array $input, bool $need_id ): array {
		$errors = array();
		$name   = Text::line( $input['name'] ?? '' );
		$length = Text::length( $name );

		if ( $length < 2 ) {
			$errors['name'] = 'required';
		} elseif ( $length > 100 ) {
			$errors['name'] = 'too_long';
		}

		$image = self::png( (string) ( $input['image'] ?? '' ) );

		if ( null === $image ) {
			$errors['image'] = 'invalid';
			$image           = '';
		} elseif ( '' === $image && '1' !== ( $input['no_draw'] ?? '' ) ) {
			$errors['image'] = 'required';
		}

		$id_number = '';

		if ( $need_id ) {
			$id_number = (string) IsraeliId::normalize( (string) ( $input['id_number'] ?? '' ) );

			if ( '' === $id_number ) {
				$errors['id_number'] = 'invalid';
			}
		}

		if ( '1' !== ( $input['confirm'] ?? '' ) ) {
			$errors['confirm'] = 'required';
		}

		return array(
			'name'      => $name,
			'image'     => $image,
			'id_number' => $id_number,
			'errors'    => $errors,
		);
	}

	/**
	 * The PNG bytes of a data URL: '' for none, null when it is anything but
	 * a small, real PNG image.
	 *
	 * @param string $data_url A data URL: data:image/png;base64,….
	 * @return string|null
	 */
	public static function png( string $data_url ): ?string {
		if ( '' === $data_url ) {
			return '';
		}

		$prefix = 'data:image/png;base64,';

		if ( ! str_starts_with( $data_url, $prefix ) || strlen( $data_url ) > (int) ( self::MAX_IMAGE_BYTES * 4 / 3 ) + 100 ) {
			return null;
		}

		$bytes = base64_decode( substr( $data_url, strlen( $prefix ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a drawn signature image; checked as a PNG below.

		if ( ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_IMAGE_BYTES || ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return null;
		}

		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken image is a validation failure, not an error to log.

		if ( false === $size || IMAGETYPE_PNG !== $size[2] || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE ) {
			return null;
		}

		return $bytes;
	}
}
