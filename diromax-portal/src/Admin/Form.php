<?php
/**
 * Form helpers for admin templates.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Field errors and old values from the flash, and the accessible markup to
 * show them: the message is tied to its field with aria-describedby.
 */
final class Form {

	/**
	 * Constructor.
	 *
	 * @param array{type:string, message:string, fields:array<string, string>, old:array<string, string>, form:string}|null $flash Flash data.
	 * @param string                                                                                                        $form  This form's key.
	 */
	public function __construct( private readonly ?array $flash, private readonly string $form ) {}

	/**
	 * Did the last save of this form fail? Then it is shown open.
	 *
	 * @return bool
	 */
	public function failed(): bool {
		return null !== $this->flash && 'error' === $this->flash['type'] && $this->form === $this->flash['form'];
	}

	/**
	 * Submitted value after a failed save, or the default.
	 *
	 * @param string $field   Field.
	 * @param string $fallback Value otherwise.
	 * @return string
	 */
	public function old( string $field, string $fallback = '' ): string {
		if ( null !== $this->flash && $this->form === $this->flash['form'] && isset( $this->flash['old'][ $field ] ) ) {
			return (string) $this->flash['old'][ $field ];
		}

		return $fallback;
	}

	/**
	 * Error message for a field, or ''.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public function error( string $field ): string {
		if ( null === $this->flash || $this->form !== $this->flash['form'] || ! isset( $this->flash['fields'][ $field ] ) ) {
			return '';
		}

		return Messages::field( $field, (string) $this->flash['fields'][ $field ] );
	}

	/**
	 * Attributes for an input: id, name, aria-invalid and aria-describedby.
	 *
	 * @param string $field Field.
	 * @return string Escaped attribute string.
	 */
	public function attrs( string $field ): string {
		$id   = $this->id( $field );
		$html = sprintf( 'id="%1$s" name="%2$s"', esc_attr( $id ), esc_attr( $field ) );

		if ( '' !== $this->error( $field ) ) {
			$html .= sprintf( ' aria-invalid="true" aria-describedby="%s"', esc_attr( $id . '-error' ) );
		}

		return $html;
	}

	/**
	 * The error paragraph for a field, escaped; '' when there is none.
	 *
	 * @param string $field Field.
	 */
	public function print_error( string $field ): void {
		$message = $this->error( $field );

		if ( '' !== $message ) {
			printf( '<p class="dxa-error" id="%1$s">%2$s</p>', esc_attr( $this->id( $field ) . '-error' ), esc_html( $message ) );
		}
	}

	/**
	 * Element ID of a field.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public function id( string $field ): string {
		return 'dxa-' . sanitize_key( $this->form ) . '-' . sanitize_key( $field );
	}
}
