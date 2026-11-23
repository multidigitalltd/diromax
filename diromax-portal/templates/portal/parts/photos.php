<?php
/**
 * Portal part: the photo journal, by stage and date (spec 18.2).
 *
 * Thumbnails carry their width and height (no layout shift) and load
 * lazily below the first row; each opens the full photo.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$dx_shown = 0;
?>
<section class="dxp-panel" aria-labelledby="dxp-photos-title">
	<h2 id="dxp-photos-title" class="dxp-sr"><?php esc_html_e( 'יומן צילומים', 'diromax-portal' ); ?></h2>
	<p class="dxp-lead"><?php esc_html_e( 'צילומים מהשטח לאורך שלבי התהליך. לחיצה על צילום פותחת אותו בגודל מלא.', 'diromax-portal' ); ?></p>
	<?php foreach ( (array) $view['photos'] as $dx_stage ) : ?>
		<section class="dxp-photos" aria-labelledby="<?php echo esc_attr( 'dxp-photos-' . $dx_stage['number'] ); ?>">
			<h3 id="<?php echo esc_attr( 'dxp-photos-' . $dx_stage['number'] ); ?>">
				<span class="dxp-num"><?php echo esc_html( (string) $dx_stage['number'] ); ?></span>
				<?php echo esc_html( (string) $dx_stage['name'] ); ?>
			</h3>
			<?php // The first photo is the page's largest paint: fetched first. Only the first row loads at once; the rest wait to be scrolled to. ?>
			<ul class="dxp-photos__grid">
				<?php foreach ( $dx_stage['photos'] as $dx_photo ) : ?>
					<?php ++$dx_shown; ?>
					<li>
						<figure class="dxp-photo">
							<a href="<?php echo esc_url( (string) $dx_photo['full'] ); ?>" target="_blank" rel="noopener">
								<img src="<?php echo esc_url( (string) $dx_photo['thumb'] ); ?>" width="<?php echo esc_attr( (string) $dx_photo['width'] ); ?>" height="<?php echo esc_attr( (string) $dx_photo['height'] ); ?>" alt="<?php echo esc_attr( (string) $dx_photo['alt'] ); ?>" decoding="async"<?php echo 1 === $dx_shown ? ' fetchpriority="high"' : ( $dx_shown > 2 ? ' loading="lazy"' : '' ); ?>>
								<span class="dxp-sr"><?php esc_html_e( '(נפתח בגודל מלא בחלון חדש)', 'diromax-portal' ); ?></span>
							</a>
							<figcaption>
								<time datetime="<?php echo esc_attr( (string) $dx_photo['iso'] ); ?>" class="dxp-num"><?php echo esc_html( (string) $dx_photo['date'] ); ?></time>
								<?php if ( '' !== $dx_photo['caption'] ) : ?>
									<span><?php echo esc_html( (string) $dx_photo['caption'] ); ?></span>
								<?php endif; ?>
							</figcaption>
						</figure>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
</section>
