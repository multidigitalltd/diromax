<?php
/**
 * Portal part: site visits and their findings (spec 23.5).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$visits = (array) $view['visits']['visits'];
$counts = (array) $view['visits']['totals'];
?>
<section class="dxp-panel" aria-labelledby="dxp-visits-title">
	<h2 id="dxp-visits-title"><?php esc_html_e( 'ביקורים באתר וממצאים', 'diromax-portal' ); ?></h2>
	<p class="dxp-lead">
		<?php
		/* translators: 1: open findings, 2: fixed findings. */
		echo esc_html( sprintf( __( 'ממצאים פתוחים: %1$d · תוקנו: %2$d', 'diromax-portal' ), (int) $counts['open'], (int) $counts['fixed'] ) );
		?>
	</p>
	<?php foreach ( $visits as $visit ) : ?>
		<article class="dxp-visit" aria-labelledby="dxp-visit-<?php echo esc_attr( (string) $visit['id'] ); ?>">
			<h3 id="dxp-visit-<?php echo esc_attr( (string) $visit['id'] ); ?>">
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'ביקור מ-%s', 'diromax-portal' ), $visit['date'] ) );
				?>
			</h3>
			<?php if ( '' !== $visit['attendees'] ) : ?>
				<p class="dxp-visit__who"><?php echo esc_html( (string) $visit['attendees'] ); ?></p>
			<?php endif; ?>
			<p><?php echo nl2br( esc_html( (string) $visit['summary'] ) ); ?></p>
			<?php if ( array() !== $visit['findings'] ) : ?>
				<ul class="dxp-findings">
					<?php foreach ( (array) $visit['findings'] as $finding ) : ?>
						<li class="dxp-finding dxp-finding--<?php echo esc_attr( (string) $finding['status'] ); ?>">
							<p class="dxp-finding__head">
								<b><?php echo esc_html( (string) $finding['title'] ); ?></b>
								<span class="dxp-finding__state"><?php echo esc_html( 'fixed' === $finding['status'] ? sprintf( /* translators: %s: date. */ __( 'תוקן ב-%s', 'diromax-portal' ), $finding['fixed_label'] ) : __( 'פתוח', 'diromax-portal' ) ); ?></span>
							</p>
							<p class="dxp-finding__meta">
								<?php
								echo esc_html(
									implode(
										' · ',
										array_filter(
											array(
												(string) $finding['severity_label'],
												(string) $finding['location'],
												'open' === $finding['status'] && '' !== $finding['due_label'] ? sprintf( /* translators: %s: date. */ __( 'לתיקון עד %s', 'diromax-portal' ), $finding['due_label'] ) : '',
											)
										)
									)
								);
								?>
							</p>
							<?php if ( '' !== $finding['fix_note'] && 'fixed' === $finding['status'] ) : ?>
								<p><?php echo esc_html( (string) $finding['fix_note'] ); ?></p>
							<?php endif; ?>
							<?php if ( null !== $finding['photo'] || null !== $finding['after'] ) : ?>
								<p class="dxp-finding__photos">
									<?php if ( null !== $finding['photo'] ) : ?>
										<a href="<?php echo esc_url( (string) $finding['photo']['full'] ); ?>"><img src="<?php echo esc_url( (string) $finding['photo']['thumb'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: finding. */ __( 'הממצא: %s', 'diromax-portal' ), $finding['title'] ) ); ?>" width="160" height="120" loading="lazy" decoding="async"></a>
									<?php endif; ?>
									<?php if ( null !== $finding['after'] ) : ?>
										<a href="<?php echo esc_url( (string) $finding['after']['full'] ); ?>"><img src="<?php echo esc_url( (string) $finding['after']['thumb'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: finding. */ __( 'אחרי התיקון: %s', 'diromax-portal' ), $finding['title'] ) ); ?>" width="160" height="120" loading="lazy" decoding="async"></a>
									<?php endif; ?>
								</p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
