<?php
/**
 * Build the management app's home-screen icons from the theme's logo.
 *
 *   diromax-theme/assets/img/logo.jpg  ->  diromax-portal/assets/img/app-icon-{180,192,512}.png
 *
 * Takes the house mark above the word DIROMAX, centres it on white with the
 * margin Android's adaptive ("maskable") icons need: the mark stays inside
 * the central 60%. Development tool; needs PHP's GD extension.
 *
 * Usage: php bin/build-icons.php
 *
 * @package Diromax
 */

// phpcs:ignoreFile -- Build script, never deployed.

$root   = dirname( __DIR__ );
$source = imagecreatefromjpeg( $root . '/diromax-theme/assets/img/logo.jpg' );

if ( false === $source ) {
	fwrite( STDERR, "cannot read the logo\n" );
	exit( 1 );
}

$width  = imagesx( $source );
$height = imagesy( $source );

// The mark: everything darker than near-white above the lettering.
$text_top = (int) round( $height * 0.66 );
$box      = array( $width, $text_top, 0, 0 );

for ( $y = 0; $y < $text_top; $y++ ) {
	for ( $x = 0; $x < $width; $x++ ) {
		$rgb = imagecolorat( $source, $x, $y );
		$r   = ( $rgb >> 16 ) & 0xFF;
		$g   = ( $rgb >> 8 ) & 0xFF;
		$b   = $rgb & 0xFF;

		if ( $r < 235 || $g < 235 || $b < 235 ) {
			$box = array( min( $box[0], $x ), min( $box[1], $y ), max( $box[2], $x ), max( $box[3], $y ) );
		}
	}
}

[ $left, $top, $right, $bottom ] = $box;
$mark_w = $right - $left + 1;
$mark_h = $bottom - $top + 1;

foreach ( array( 180, 192, 512 ) as $size ) {
	$icon  = imagecreatetruecolor( $size, $size );
	$white = imagecolorallocate( $icon, 255, 255, 255 );
	imagefill( $icon, 0, 0, $white );

	$scale = ( $size * 0.6 ) / max( $mark_w, $mark_h );
	$dst_w = (int) round( $mark_w * $scale );
	$dst_h = (int) round( $mark_h * $scale );

	imagecopyresampled( $icon, $source, (int) ( ( $size - $dst_w ) / 2 ), (int) ( ( $size - $dst_h ) / 2 ), $left, $top, $dst_w, $dst_h, $mark_w, $mark_h );
	imagepng( $icon, $root . '/diromax-portal/assets/img/app-icon-' . $size . '.png', 9 );
	printf( "app-icon-%d.png  %s bytes\n", $size, number_format( (int) filesize( $root . '/diromax-portal/assets/img/app-icon-' . $size . '.png' ) ) );
}
