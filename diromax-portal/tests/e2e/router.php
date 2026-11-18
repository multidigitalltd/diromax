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
	return false;
}
if ( str_ends_with( $path, '.php' ) && is_file( $root . $path ) ) {
	chdir( dirname( $root . $path ) );
	require $root . $path;
	return true;
}
chdir( $root );
require $root . '/index.php';
