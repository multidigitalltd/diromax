<?php
/**
 * Service errors.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The few error shapes services return. Messages are generic; detail goes to
 * the field map (validation) or the server log (storage), never to the page.
 */
final class Errors {

	/**
	 * Not allowed. Shown as "not found" to clients so object existence is not revealed.
	 *
	 * @return WP_Error
	 */
	public static function forbidden(): WP_Error {
		return new WP_Error( 'dx_forbidden', __( 'אין הרשאה לפעולה הזו.', 'diromax-portal' ), array( 'status' => 403 ) );
	}

	/**
	 * Object not found (or not in this case).
	 *
	 * @return WP_Error
	 */
	public static function not_found(): WP_Error {
		return new WP_Error( 'dx_not_found', __( 'הפריט לא נמצא.', 'diromax-portal' ), array( 'status' => 404 ) );
	}

	/**
	 * The case is closed: it can be read, not changed.
	 *
	 * @return WP_Error
	 */
	public static function closed(): WP_Error {
		return new WP_Error( 'dx_closed', __( 'התיק סגור, ואי אפשר לשנות אותו.', 'diromax-portal' ), array( 'status' => 409 ) );
	}

	/**
	 * Validation failed.
	 *
	 * @param array<string, string> $fields Field => error code.
	 * @return WP_Error
	 */
	public static function invalid( array $fields ): WP_Error {
		return new WP_Error(
			'dx_invalid',
			__( 'יש שדות שצריך לתקן.', 'diromax-portal' ),
			array(
				'status' => 422,
				'fields' => $fields,
			)
		);
	}

	/**
	 * Database or file failure.
	 *
	 * @return WP_Error
	 */
	public static function storage(): WP_Error {
		return new WP_Error( 'dx_storage', __( 'השמירה נכשלה. נסו שוב בעוד רגע.', 'diromax-portal' ), array( 'status' => 500 ) );
	}

	/**
	 * Field errors of a validation error.
	 *
	 * @param WP_Error $error Error.
	 * @return array<string, string>
	 */
	public static function fields( WP_Error $error ): array {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();
	}

	/**
	 * Before/after values of the given properties, only where they differ.
	 *
	 * @param object   $before Object before.
	 * @param object   $after  Object after.
	 * @param string[] $keys   Properties to compare.
	 * @return array<string, array{0:mixed, 1:mixed}>
	 */
	public static function diff( object $before, object $after, array $keys ): array {
		$old  = get_object_vars( $before );
		$new  = get_object_vars( $after );
		$diff = array();

		foreach ( $keys as $key ) {
			$from = $old[ $key ] ?? null;
			$to   = $new[ $key ] ?? null;

			if ( $from instanceof \BackedEnum ) {
				$from = $from->value;
			}

			if ( $to instanceof \BackedEnum ) {
				$to = $to->value;
			}

			if ( $from !== $to ) {
				$diff[ $key ] = array( $from, $to );
			}
		}

		return $diff;
	}
}
