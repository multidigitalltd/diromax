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
				<p><?php dx_the_text( 'general.footer_tagline' ); ?></p>
			</div>

			<nav aria-label="<?php esc_attr_e( 'עמודי האתר', 'diromax' ); ?>">
				<h2 class="dx-footer__h"><?php dx_the_text( 'general.footer_col_site' ); ?></h2>
				<div class="dx-footer__links">
					<?php foreach ( dx_nav_items() as $dx_view => $dx_label ) : ?>
						<a href="<?php echo esc_url( dx_view_url( $dx_view ) ); ?>">
							<?php echo esc_html( 'improve' === $dx_view ? (string) dx_content( 'general.footer_improve' ) : $dx_label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</nav>

			<div>
				<h2 class="dx-footer__h"><?php dx_the_text( 'general.footer_col_mail' ); ?></h2>
				<div class="dx-footer__links">
					<a class="dx-footer__phone" href="tel:<?php echo esc_attr( dx_phone_tel() ); ?>"><?php echo esc_html( dx_phone() ); ?></a>
					<a class="dx-footer__mail" href="mailto:<?php echo esc_attr( dx_email() ); ?>"><?php echo esc_html( dx_email() ); ?></a>
					<a href="<?php echo esc_url( dx_wa_url() ); ?>" target="_blank" rel="noopener">
						<?php dx_the_text( 'general.sticky_wa' ); ?>
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
					<h2 class="dx-footer__h"><?php dx_the_text( 'general.footer_col_info' ); ?></h2>
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
					/* translators: 1: current year, 2: site name, 3: rights sentence. */
					'© %1$s %2$s. %3$s',
					esc_html( wp_date( 'Y' ) ),
					esc_html( get_bloginfo( 'name' ) ),
					esc_html( (string) dx_content( 'general.footer_rights' ) )
				);
				?>
			</span>
			<span><?php dx_the_text( 'general.footer_note' ); ?></span>
		</div>
	</div>
</footer>
