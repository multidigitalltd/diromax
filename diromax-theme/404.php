<?php
/**
 * 404.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<section class="dx-section" aria-labelledby="dx-404-title">
	<div class="dx-wrap dx-pad-md">
		<?php dx_eyebrow( __( 'העמוד לא נמצא', 'diromax' ), 'orange', 'dx-eyebrow--page' ); ?>
		<h1 class="dx-h1--page dx-mw-22 dx-mb-24" id="dx-404-title"><?php esc_html_e( 'הגעת לכתובת שלא קיימת.', 'diromax' ); ?></h1>
		<p class="dx-lead dx-mw-56 dx-mb-32"><?php esc_html_e( 'אבל אם יש לך נכס עם פוטנציאל — הגעת למקום הנכון.', 'diromax' ); ?></p>
		<div class="dx-btnrow">
			<a class="dx-btn dx-btn--orange dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'improve' ) ); ?>"><?php esc_html_e( 'לעמוד השבחת נכסים', 'diromax' ); ?></a>
			<a class="dx-btn dx-btn--outline dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>"><?php esc_html_e( 'צור קשר', 'diromax' ); ?></a>
		</div>
	</div>
</section>
<?php get_footer(); ?>
