<?php
/**
 * Asset minifier.
 *
 * Generates the production CSS and JS from the single authoring source, so the
 * theme ships minified files without keeping two copies of anything by hand.
 *
 * Usage:  php bin/build-assets.php
 *
 * Sources                                   Output
 *   diromax-theme/style.css              ->  style.min.css
 *   diromax-theme/assets/js/forms.js     ->  assets/js/forms.min.js
 *   diromax-theme/assets/js/a11y.js      ->  assets/js/a11y.min.js
 *   diromax-theme/assets/js/privacy.js   ->  assets/js/privacy.min.js
 *   diromax-portal/assets/css/*.css      ->  *.min.css
 *   diromax-portal/assets/js/*.js        ->  *.min.js
 *
 * The theme falls back to the sources automatically when SCRIPT_DEBUG is on or
 * when a minified file is missing, so the build is never a hard requirement.
 *
 * @package Diromax
 */

declare( strict_types = 1 );

$theme  = dirname( __DIR__ ) . '/diromax-theme';
$portal = dirname( __DIR__ ) . '/diromax-portal';

/**
 * Strip comments and collapse whitespace in CSS.
 *
 * Comments that open with "/*!" are preserved: that is how the WordPress theme
 * header in style.css survives minification.
 *
 * @param string $css Source CSS.
 * @return string
 */
function dx_minify_css( string $css ): string {
	// Protect the theme header — WordPress parses it out of the served file.
	$header = '';
	if ( preg_match( '#^/\*.*?\*/#s', $css, $m ) ) {
		$header = $m[0] . "\n";
		$css    = substr( $css, strlen( $m[0] ) );
	}

	$css = preg_replace( '#/\*(?!!).*?\*/#s', '', $css );
	$css = preg_replace( '/\s*\n\s*/', "\n", (string) $css );
	$css = preg_replace( '/\s{2,}/', ' ', (string) $css );
	$css = preg_replace( '/\s*([{}:;,>~])\s*/', '$1', (string) $css );
	$css = preg_replace( '/;}/', '}', (string) $css );
	$css = str_replace( "\n", '', (string) $css );

	return $header . trim( (string) $css );
}

/**
 * Strip comments and needless whitespace from JavaScript.
 *
 * Deliberately conservative: it removes full-line comments and leading
 * indentation only. Nothing here rewrites identifiers or reorders statements,
 * so the output stays byte-for-byte debuggable against the source.
 *
 * @param string $js Source JavaScript.
 * @return string
 */
function dx_minify_js( string $js ): string {
	$out = array();

	foreach ( explode( "\n", $js ) as $line ) {
		$trimmed = trim( $line );

		if ( '' === $trimmed ) {
			continue;
		}
		// Whole-line comments only — never touch anything that shares a line with code.
		if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' )
			|| str_starts_with( $trimmed, '/*' ) || str_starts_with( $trimmed, '*/' ) ) {
			continue;
		}

		$out[] = $trimmed;
	}

	return implode( "\n", $out );
}

$jobs = array(
	array(
		'src'  => $theme . '/style.css',
		// Sits beside style.css on purpose: the @font-face URLs inside it are
		// relative, so moving the file into a subfolder would break every one.
		'dst'  => $theme . '/style.min.css',
		'func' => 'dx_minify_css',
	),
	array(
		'src'  => $theme . '/assets/js/forms.js',
		'dst'  => $theme . '/assets/js/forms.min.js',
		'func' => 'dx_minify_js',
	),
	array(
		'src'  => $theme . '/assets/js/a11y.js',
		'dst'  => $theme . '/assets/js/a11y.min.js',
		'func' => 'dx_minify_js',
	),
	array(
		'src'  => $theme . '/assets/js/privacy.js',
		'dst'  => $theme . '/assets/js/privacy.min.js',
		'func' => 'dx_minify_js',
	),
);

foreach ( array( 'portal', 'admin', 'manage' ) as $dx_name ) {
	$jobs[] = array(
		'src'  => $portal . '/assets/css/' . $dx_name . '.css',
		'dst'  => $portal . '/assets/css/' . $dx_name . '.min.css',
		'func' => 'dx_minify_css',
	);
}

foreach ( array( 'portal', 'admin' ) as $dx_name ) {
	$jobs[] = array(
		'src'  => $portal . '/assets/js/' . $dx_name . '.js',
		'dst'  => $portal . '/assets/js/' . $dx_name . '.min.js',
		'func' => 'dx_minify_js',
	);
}

$failed = false;

foreach ( $jobs as $job ) {
	if ( ! is_readable( $job['src'] ) ) {
		fwrite( STDERR, "missing source: {$job['src']}\n" );
		$failed = true;
		continue;
	}

	$source = (string) file_get_contents( $job['src'] );
	$result = $job['func']( $source );

	$dir = dirname( $job['dst'] );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
		fwrite( STDERR, "cannot create {$dir}\n" );
		$failed = true;
		continue;
	}

	file_put_contents( $job['dst'], $result );

	printf(
		"%-28s %7d -> %7d bytes  (-%d%%)\n",
		basename( $job['dst'] ),
		strlen( $source ),
		strlen( $result ),
		(int) round( ( 1 - strlen( $result ) / max( 1, strlen( $source ) ) ) * 100 )
	);
}

exit( $failed ? 1 : 0 );
