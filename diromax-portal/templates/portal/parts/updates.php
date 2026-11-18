<?php
/**
 * Portal part: progress updates timeline.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<section class="dxp-panel" aria-labelledby="dxp-upd-title">
	<h2 id="dxp-upd-title" class="dxp-sr"><?php esc_html_e( 'עדכונים', 'diromax-portal' ); ?></h2>
	<?php if ( array() === $view['updates'] ) : ?>
		<p class="dxp-lead"><?php esc_html_e( 'עדיין אין עדכונים. כל התקדמות בתיק תופיע כאן, וגם תגיע אליך במייל.', 'diromax-portal' ); ?></p>
	<?php else : ?>
		<ol class="dxp-tl">
			<?php foreach ( $view['updates'] as $update ) : ?>
				<li>
					<time datetime="<?php echo esc_attr( (string) $update['iso'] ); ?>" class="dxp-num"><?php echo esc_html( (string) $update['date'] ); ?></time>
					<div>
						<h3><?php echo esc_html( (string) $update['title'] ); ?></h3>
						<?php if ( '' !== $update['body'] ) : ?>
							<p><?php echo nl2br( esc_html( (string) $update['body'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $update['stage'] ) : ?>
							<p class="dxp-tl__stage"><?php echo esc_html( (string) $update['stage'] ); ?></p>
						<?php endif; ?>
						<?php if ( array() !== $update['files'] ) : ?>
							<ul class="dxp-tl__files">
								<?php foreach ( $update['files'] as $file ) : ?>
									<li><a href="<?php echo esc_url( (string) $file['url'] ); ?>"><?php echo esc_html( (string) $file['title'] ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</section>
