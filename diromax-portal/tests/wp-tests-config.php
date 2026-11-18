<?php
/**
 * WordPress test-suite configuration.
 *
 * The database named here is emptied on every run. Point it only at a
 * throwaway test database — never at real data.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own configuration constants.
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/vendor/roots/wordpress-no-content/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

/**
 * Environment value or a local default.
 *
 * @param string $name     Variable.
 * @param string $fallback Default.
 * @return string
 */
function dx_portal_test_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return false === $value || '' === $value ? $fallback : $value;
}

define( 'DB_NAME', dx_portal_test_env( 'WP_TESTS_DB_NAME', 'wp_tests' ) );
define( 'DB_USER', dx_portal_test_env( 'WP_TESTS_DB_USER', 'wp' ) );
define( 'DB_PASSWORD', dx_portal_test_env( 'WP_TESTS_DB_PASSWORD', 'wp' ) );
define( 'DB_HOST', dx_portal_test_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the test suite.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Diromax Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// A fixed test key: 32 bytes, base64. Never used outside the test suite.
define( 'DX_PORTAL_KEY', base64_encode( str_repeat( "\x01", 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key encoding.
// phpcs:enable
