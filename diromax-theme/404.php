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
		<?php dx_eyebrow( dx_content( 'general.e404_eyebrow' ), 'orange', 'dx-eyebrow--page' ); ?>
		<h1 class="dx-h1--page dx-mw-22 dx-mb-24" id="dx-404-title"><?php dx_the_lines( 'general.e404_title' ); ?></h1>
		<p class="dx-lead dx-mw-56 dx-mb-32"><?php dx_the_text( 'general.e404_lead' ); ?></p>
		<div class="dx-btnrow">
			<a class="dx-btn dx-btn--orange dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'improve' ) ); ?>"><?php dx_the_text( 'general.e404_btn_improve' ); ?></a>
			<a class="dx-btn dx-btn--outline dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>"><?php dx_the_text( 'general.e404_btn_contact' ); ?></a>
		</div>
	</div>
</section>
<?php get_footer(); ?>
