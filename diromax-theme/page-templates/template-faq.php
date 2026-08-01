<?php
/**
 * Template Name: דירומקס — שאלות ותשובות
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<section class="dx-section" aria-labelledby="dx-faq-title">
	<div class="dx-wrap dx-pad-sm">
		<?php dx_eyebrow( dx_content( 'faq.eyebrow' ), 'teal', 'dx-eyebrow--page' ); ?>
		<h1 class="dx-h1--page dx-mw-22" id="dx-faq-title"><?php dx_the_lines( 'faq.title' ); ?></h1>
	</div>
</section>

<section class="dx-section">
	<div class="dx-wrap dx-wrap--narrow dx-pad-xs">
		<div class="dx-faq">
			<?php dx_accordion( dx_faq_all(), true ); ?>
		</div>
		<div class="dx-pt-40">
			<a class="dx-btn dx-btn--orange dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>"><?php dx_the_text( 'faq.btn' ); ?></a>
		</div>
	</div>
</section>

<?php
while ( have_posts() ) :
	the_post();

	if ( '' !== trim( get_the_content() ) ) {
		echo '<section class="dx-section"><div class="dx-wrap dx-wrap--narrow dx-pad-sm"><div class="dx-post">';
		the_content();
		echo '</div></div></section>';
	}
endwhile;

get_footer();
