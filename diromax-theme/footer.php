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
		<a href="tel:<?php echo esc_attr( dx_phone_tel() ); ?>"><?php esc_html_e( 'שיחה', 'diromax' ); ?></a>
		<a class="dx-sticky__wa" href="<?php echo esc_url( dx_wa_url() ); ?>" target="_blank" rel="noopener">
			<?php esc_html_e( 'וואטסאפ', 'diromax' ); ?>
			<span class="dx-sr-only"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax' ); ?></span>
		</a>
		<a class="dx-sticky__cta" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>">
			<?php esc_html_e( 'בדיקת פוטנציאל', 'diromax' ); ?>
		</a>
	</nav>
	<div class="dx-sticky-spacer" aria-hidden="true"></div>
<?php endif; ?>

<?php
dx_a11y_toolbar();
wp_footer();
?>
</body>
</html>
