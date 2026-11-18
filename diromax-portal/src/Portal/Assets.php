<?php
/**
 * Portal assets.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Security\Turnstile;

defined( 'ABSPATH' ) || exit;

/**
 * One stylesheet and one deferred script, on portal pages only (standard 9.3.1).
 */
final class Assets {

	/**
	 * Enqueue the portal assets; the Turnstile script only on the sign-in form.
	 *
	 * @param bool $turnstile Load the Turnstile widget.
	 */
	public static function enqueue( bool $turnstile ): void {
		$debug  = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$suffix = $debug ? '' : '.min';

		wp_enqueue_style( 'dx-portal', self::url( 'assets/css/portal' . $suffix . '.css', 'assets/css/portal.css' ), array(), DX_PORTAL_VERSION );
		wp_enqueue_script(
			'dx-portal',
			self::url( 'assets/js/portal' . $suffix . '.js', 'assets/js/portal.js' ),
			array(),
			DX_PORTAL_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		if ( $turnstile && Turnstile::enabled() ) {
			// Required third-party script (standard 15.3); loaded on this one page only.
			wp_enqueue_script( 'dx-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare serves the current widget; a version would break caching.
		}
	}

	/**
	 * URL of the minified file, falling back to the source.
	 *
	 * @param string $preferred Preferred relative path.
	 * @param string $fallback  Source relative path.
	 * @return string
	 */
	private static function url( string $preferred, string $fallback ): string {
		return DX_PORTAL_URL . ( file_exists( DX_PORTAL_DIR . $preferred ) ? $preferred : $fallback );
	}
}
