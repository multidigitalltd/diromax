<?php
/**
 * E2E only. PHP built-in server router for a throwaway WordPress.
 *
 * @package Diromax\Portal
 */

// phpcs:ignoreFile -- Test harness for the PHP built-in server, never deployed.
// PHP built-in server router: real files as-is, directories to their index.php, the rest through WordPress.
$root = (string) getenv( 'DX_E2E_WP' );
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_dir( $root . $path ) && is_file( rtrim( $root . $path, '/' ) . '/index.php' ) && '/' !== $path ) {
	$path = rtrim( $path, '/' ) . '/index.php';
	$_SERVER['SCRIPT_NAME'] = $path;
	$_SERVER['PHP_SELF'] = $path;
}
if ( '/' !== $path && is_file( $root . $path ) && ! str_ends_with( $path, '.php' ) ) {
	// Text assets compressed and cached for a year, as Cloudflare and LiteSpeed serve them in
	// production, so the performance budget (perf.e2e.js) measures what visitors download.
	$types = array( 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml' );
	$ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( isset( $types[ $ext ] ) && str_contains( (string) ( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' ), 'gzip' ) ) {
		header( 'Content-Type: ' . $types[ $ext ] . '; charset=utf-8' );
		header( 'Content-Encoding: gzip' );
		header( 'Vary: Accept-Encoding' );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		echo gzencode( (string) file_get_contents( $root . $path ), 6 );
		return true;
	}
	return false;
}
if ( str_ends_with( $path, '.php' ) && is_file( $root . $path ) ) {
	chdir( dirname( $root . $path ) );
	require $root . $path;
	return true;
}
chdir( $root );
require $root . '/index.php';
