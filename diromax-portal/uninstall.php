<?php
/**
 * Uninstall.
 *
 * Scheduled jobs, transients and roles always go. Tables, settings and the
 * encrypted documents are business records with a retention duty, so they are
 * deleted only when DX_PORTAL_PURGE_ON_UNINSTALL is true (EXCEPTIONS.md #3).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Data/Schema.php';
require_once __DIR__ . '/src/Security/Capabilities.php';

wp_clear_scheduled_hook( 'dx_portal_notify' );
wp_clear_scheduled_hook( 'dx_portal_cleanup' );
delete_transient( 'dx_portal_notify_lock' );

Diromax\Portal\Security\Capabilities::uninstall();

if ( defined( 'DX_PORTAL_PURGE_ON_UNINSTALL' ) && true === DX_PORTAL_PURGE_ON_UNINSTALL ) {
	Diromax\Portal\Data\Schema::drop_all();
	delete_option( 'dx_portal_settings' );

	$dx_portal_dir = defined( 'DX_PORTAL_PRIVATE_DIR' ) ? (string) DX_PORTAL_PRIVATE_DIR : wp_upload_dir( null, false )['basedir'] . '/dx-private';

	foreach ( (array) glob( $dx_portal_dir . '/*.bin' ) as $dx_portal_file ) {
		wp_delete_file( (string) $dx_portal_file );
	}
}
