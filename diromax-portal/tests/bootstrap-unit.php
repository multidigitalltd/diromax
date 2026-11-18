<?php
/**
 * Bootstrap for the unit suite: pure domain code, no WordPress.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

// Domain files only check that ABSPATH exists; nothing is loaded from it.
define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant, faked for unit tests.

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
