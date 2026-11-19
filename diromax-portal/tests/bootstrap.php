<?php
/**
 * Bootstrap for the full suite: WordPress test library plus the plugin.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

$dx_portal_root = dirname( __DIR__, 2 );

require $dx_portal_root . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- How wp-phpunit finds its config.

$dx_portal_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
$dx_portal_tests_dir = false === $dx_portal_tests_dir || '' === $dx_portal_tests_dir ? $dx_portal_root . '/vendor/wp-phpunit/wp-phpunit' : $dx_portal_tests_dir;

require_once $dx_portal_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/diromax-portal.php';
	}
);

// The Diromax theme is active in the tests too, straight from the repository.
tests_add_filter( 'theme_root', static fn(): string => $dx_portal_root );
tests_add_filter( 'pre_option_template', static fn(): string => 'diromax-theme' );
tests_add_filter( 'pre_option_stylesheet', static fn(): string => 'diromax-theme' );

// Tests run inside a transaction; nested writes must use savepoints.
tests_add_filter( 'dx_portal_nested_transactions', '__return_true' );

// Private document store inside the system temp dir.
tests_add_filter(
	'dx_portal_private_dir',
	static fn(): string => sys_get_temp_dir() . '/dx-portal-test-store'
);

require $dx_portal_tests_dir . '/includes/bootstrap.php';

// Install once, outside any test transaction (DDL would end it).
Diromax\Portal\Plugin::activate();
