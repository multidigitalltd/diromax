<?php
/**
 * Footer, fixed contact bar and the accessibility toolbar.
 * Closes the document opened in header.php.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
</main>

<?php get_template_part( 'template-parts/site-footer' ); ?>

<?php if ( dx_show_sticky() ) : ?>
	<nav class="dx-sticky" aria-label="<?php esc_attr_e( 'יצירת קשר מהירה', 'diromax' ); ?>">
		<a href="tel:<?php echo esc_attr( dx_phone_tel() ); ?>"><?php dx_the_text( 'general.sticky_call' ); ?></a>
		<a class="dx-sticky__wa" href="<?php echo esc_url( dx_wa_url() ); ?>" target="_blank" rel="noopener">
			<?php dx_the_text( 'general.sticky_wa' ); ?>
			<span class="dx-sr-only"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax' ); ?></span>
		</a>
		<a class="dx-sticky__cta" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>">
			<?php dx_the_text( 'general.sticky_cta' ); ?>
		</a>
	</nav>
	<div class="dx-sticky-spacer" aria-hidden="true"></div>
<?php endif; ?>

<?php
get_template_part( 'template-parts/privacy-notice' );
dx_a11y_toolbar();
wp_footer();
?>
</body>
</html>
