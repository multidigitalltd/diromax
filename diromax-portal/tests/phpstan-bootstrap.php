<?php
/**
 * Constants PHPStan needs to analyse the plugin outside WordPress.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

define( 'DX_PORTAL_DIR', __DIR__ . '/../' );
define( 'DX_PORTAL_URL', 'https://example.test/wp-content/plugins/diromax-portal/' );
const DX_PORTAL_VERSION = '1.0.0';
const DX_PORTAL_FILE    = __DIR__ . '/../diromax-portal.php';
