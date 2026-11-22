<?php
/**
 * Plugin Name:       Diromax — אזור אישי ותיקי לקוחות
 * Plugin URI:        https://diromax.co.il
 * Description:       תיק דיגיטלי לכל לקוח: הסכם, תקציב, הוצאות, תשלומים ועדכונים, עם אזור אישי ללקוח ומיילים מעוצבים בכל עדכון.
 * Version:           1.7.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            Multi Digital
 * Author URI:        https://m-d.co.il
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       diromax-portal
 * Domain Path:       /languages
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const DX_PORTAL_VERSION = '1.7.0';
const DX_PORTAL_FILE    = __FILE__;
define( 'DX_PORTAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'DX_PORTAL_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4 autoloader for the plugin's own classes. The plugin ships without a
 * vendor directory: it has no runtime dependencies.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Diromax\\Portal\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$path     = DX_PORTAL_DIR . 'src/' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( \Diromax\Portal\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Diromax\Portal\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Diromax\Portal\Plugin::class, 'boot' ) );
