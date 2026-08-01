<?php
/**
 * Site footer block. Pulled in by footer.php.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dx_a11y_page = dx_a11y_url();
?>
<footer class="dx-footer">
	<div class="dx-wrap dx-pad-strip">
		<div class="dx-footer__grid">

			<div>
				<div class="dx-footer__word">DIROMAX</div>
				<p><?php esc_html_e( 'דירומקס — תיווך, יזמות וניהול השבחת נכסים. לקבל את המקסימום מהנכס.', 'diromax' ); ?></p>
			</div>

			<nav aria-label="<?php esc_attr_e( 'עמודי האתר', 'diromax' ); ?>">
				<h2 class="dx-footer__h"><?php esc_html_e( 'האתר', 'diromax' ); ?></h2>
				<div class="dx-footer__links">
					<?php foreach ( dx_nav_items() as $dx_view => $dx_label ) : ?>
						<a href="<?php echo esc_url( dx_view_url( $dx_view ) ); ?>">
							<?php echo esc_html( 'improve' === $dx_view ? __( 'ניהול השבחת נכסים', 'diromax' ) : $dx_label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</nav>

			<div>
				<h2 class="dx-footer__h"><?php esc_html_e( 'יצירת קשר', 'diromax' ); ?></h2>
				<div class="dx-footer__links">
					<a class="dx-footer__phone" href="tel:<?php echo esc_attr( dx_phone_tel() ); ?>"><?php echo esc_html( dx_phone() ); ?></a>
					<a class="dx-footer__mail" href="mailto:<?php echo esc_attr( dx_email() ); ?>"><?php echo esc_html( dx_email() ); ?></a>
					<a href="<?php echo esc_url( dx_wa_url() ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'וואטסאפ', 'diromax' ); ?>
						<span class="dx-sr-only"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax' ); ?></span>
					</a>
				</div>
			</div>

			<?php
			$dx_has_menu = has_nav_menu( 'footer' );
			$dx_legal    = $dx_has_menu ? dx_nav_links( 'footer' ) : array();
			?>
			<?php if ( $dx_has_menu || $dx_a11y_page ) : ?>
				<nav aria-label="<?php esc_attr_e( 'מידע משפטי ונגישות', 'diromax' ); ?>">
					<h2 class="dx-footer__h"><?php esc_html_e( 'מידע', 'diromax' ); ?></h2>
					<div class="dx-footer__links">
						<?php if ( $dx_has_menu ) : ?>
							<?php foreach ( $dx_legal as $dx_link ) : ?>
								<a href="<?php echo esc_url( $dx_link['url'] ); ?>"><?php echo esc_html( $dx_link['label'] ); ?></a>
							<?php endforeach; ?>
						<?php elseif ( $dx_a11y_page ) : ?>
							<a href="<?php echo esc_url( $dx_a11y_page ); ?>"><?php esc_html_e( 'הצהרת נגישות', 'diromax' ); ?></a>
						<?php endif; ?>
					</div>
				</nav>
			<?php endif; ?>

		</div>
		<div class="dx-footer__bottom">
			<span>
				<?php
				printf(
					/* translators: %s: current year. */
					esc_html__( '© %s דירומקס. כל הזכויות שמורות.', 'diromax' ),
					esc_html( wp_date( 'Y' ) )
				);
				?>
			</span>
			<span><?php esc_html_e( 'האמור באתר הוא מידע כללי ואינו מהווה ייעוץ תכנוני, משפטי או מיסויי.', 'diromax' ); ?></span>
		</div>
	</div>
</footer>
