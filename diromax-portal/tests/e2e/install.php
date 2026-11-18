<?php
/**
 * E2E only: run from the command line against a throwaway WordPress.
 *
 * @package Diromax\Portal
 */

// phpcs:ignoreFile -- Test fixture script, never deployed.
// Dev install: WordPress, theme, plugin, Hebrew, pretty permalinks.
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8088';
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = wp_install( 'דירומקס', 'dxadmin', 'admin@example.org', true, '', 'Admin-Dev-Pass-2026!', 'he_IL' );
update_option( 'WPLANG', 'he_IL' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'timezone_string', 'Asia/Jerusalem' );
switch_theme( 'diromax' );
$activated = activate_plugin( 'diromax-portal/diromax-portal.php' );
echo is_wp_error( $activated ) ? $activated->get_error_message() : "installed, plugin active\n";
