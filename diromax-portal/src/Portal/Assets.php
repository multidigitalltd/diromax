<?php
/**
 * Portal assets.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Portal;

use Diromax\Portal\Security\Turnstile;
use Diromax\Portal\Support\AssetFile;

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
		$css = AssetFile::path( 'portal', 'css' );
		$js  = AssetFile::path( 'portal', 'js' );

		wp_enqueue_style( 'dx-portal', AssetFile::url( $css ), array(), AssetFile::version( $css ) );
		wp_enqueue_script(
			'dx-portal',
			AssetFile::url( $js ),
			array(),
			AssetFile::version( $js ),
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
}
