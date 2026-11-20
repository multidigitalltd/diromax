<?php
/**
 * The plugin's static files and their cache-busting versions.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the minified file when it exists and gives each file a version made
 * of the plugin version and a short hash of the file's content. A changed
 * stylesheet or script gets a new URL by itself, so a browser, a page cache
 * or a CDN never keeps serving the old one after an update.
 */
final class AssetFile {

	/**
	 * Versions already computed in this request, by relative path.
	 *
	 * @var array<string, string>
	 */
	private static array $versions = array();

	/**
	 * Relative path of the file to serve: the minified one unless SCRIPT_DEBUG.
	 *
	 * @param string $name Base name without extension, e.g. admin.
	 * @param string $ext  css or js.
	 * @return non-empty-string e.g. assets/css/admin.min.css.
	 */
	public static function path( string $name, string $ext ): string {
		$min = 'assets/' . $ext . '/' . $name . '.min.' . $ext;

		return ( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) && file_exists( DX_PORTAL_DIR . $min ) ? $min : 'assets/' . $ext . '/' . $name . '.' . $ext;
	}

	/**
	 * Public URL of a file.
	 *
	 * @param non-empty-string $relative Relative path from path().
	 * @return non-empty-string
	 */
	public static function url( string $relative ): string {
		return DX_PORTAL_URL . $relative;
	}

	/**
	 * Version for the file's URL: plugin version and content hash.
	 *
	 * @param string $relative Relative path from path().
	 * @return string e.g. 1.4.0-3f2a9c1d.
	 */
	public static function version( string $relative ): string {
		if ( ! isset( self::$versions[ $relative ] ) ) {
			$hash = is_readable( DX_PORTAL_DIR . $relative ) ? md5_file( DX_PORTAL_DIR . $relative ) : false;

			self::$versions[ $relative ] = DX_PORTAL_VERSION . ( false === $hash ? '' : '-' . substr( $hash, 0, 8 ) );
		}

		return self::$versions[ $relative ];
	}
}
