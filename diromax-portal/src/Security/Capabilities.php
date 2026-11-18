<?php
/**
 * Roles and capabilities.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own capabilities and the two roles that carry them.
 *
 * Code checks capabilities, never role names (standard 4.7.6). Which user may
 * touch which case is decided in AccessPolicy, on top of these.
 */
final class Capabilities {

	/** Open the case screens and manage assigned cases. */
	public const MANAGE_CASES = 'dx_manage_cases';

	/** Manage every case, not only assigned ones; assign managers. */
	public const MANAGE_ALL = 'dx_manage_all_cases';

	/** Change portal settings. */
	public const MANAGE_SETTINGS = 'dx_manage_portal';

	/** Permanently delete a closed case. */
	public const DELETE_CASES = 'dx_delete_cases';

	/** Sign in to the client portal. */
	public const VIEW_PORTAL = 'dx_view_portal';

	/** Project manager role. */
	public const ROLE_MANAGER = 'dx_manager';

	/** Client role. */
	public const ROLE_CLIENT = 'dx_client';

	/**
	 * Create the roles and grant administrators the management capabilities.
	 */
	public static function install(): void {
		add_role(
			self::ROLE_MANAGER,
			__( 'מנהל פרויקטים', 'diromax-portal' ),
			array(
				'read'             => true,
				self::MANAGE_CASES => true,
			)
		);

		add_role(
			self::ROLE_CLIENT,
			__( 'לקוח אזור אישי', 'diromax-portal' ),
			array( self::VIEW_PORTAL => true )
		);

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( array( self::MANAGE_CASES, self::MANAGE_ALL, self::MANAGE_SETTINGS, self::DELETE_CASES ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the roles and the administrator capabilities.
	 */
	public static function uninstall(): void {
		remove_role( self::ROLE_MANAGER );
		remove_role( self::ROLE_CLIENT );

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( array( self::MANAGE_CASES, self::MANAGE_ALL, self::MANAGE_SETTINGS, self::DELETE_CASES ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
