<?php
/**
 * Private temporary files.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A temporary file in WordPress's temp directory. wp_tempnam() is defined in
 * wp-admin/includes/file.php, which WordPress loads only in the admin; the
 * portal and the webhooks run outside it, so load it here.
 */
final class TempFile {

	/**
	 * Create an empty temporary file.
	 *
	 * @param string $prefix Name prefix.
	 * @return string Path, '' when it could not be created.
	 */
	public static function create( string $prefix ): string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return (string) wp_tempnam( $prefix );
	}
}
