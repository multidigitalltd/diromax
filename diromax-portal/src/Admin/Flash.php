<?php
/**
 * Post/redirect/get messages.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a notice, field errors and the submitted values across the redirect
 * after a form post, per user, for one minute.
 */
final class Flash {

	/**
	 * Store for the next page load.
	 *
	 * @param string                $type    success or error.
	 * @param string                $message Notice text.
	 * @param array<string, string> $fields  Field => error code.
	 * @param array<string, string> $old     Submitted values to refill the form.
	 * @param string                $form    Which form the errors belong to.
	 */
	public static function set( string $type, string $message, array $fields = array(), array $old = array(), string $form = '' ): void {
		set_transient(
			self::key(),
			array(
				'type'    => $type,
				'message' => $message,
				'fields'  => $fields,
				'old'     => $old,
				'form'    => $form,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear.
	 *
	 * @return array{type:string, message:string, fields:array<string, string>, old:array<string, string>, form:string}|null
	 */
	public static function take(): ?array {
		$data = get_transient( self::key() );
		delete_transient( self::key() );

		if ( ! is_array( $data ) || ! isset( $data['type'], $data['message'] ) ) {
			return null;
		}

		return array(
			'type'    => (string) $data['type'],
			'message' => (string) $data['message'],
			'fields'  => is_array( $data['fields'] ?? null ) ? $data['fields'] : array(),
			'old'     => is_array( $data['old'] ?? null ) ? $data['old'] : array(),
			'form'    => (string) ( $data['form'] ?? '' ),
		);
	}

	/**
	 * Per-user key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return 'dx_portal_flash_' . get_current_user_id();
	}
}
