<?php
/**
 * Blog archive, category, tag and search results — the same card grid as the
 * articles page.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<section class="dx-section" aria-labelledby="dx-archive-title">
	<div class="dx-wrap dx-pad-sm">
		<?php dx_eyebrow( __( 'מאמרים', 'diromax' ), 'teal', 'dx-eyebrow--page' ); ?>
		<h1 class="dx-h1--page dx-mw-24" id="dx-archive-title">
			<?php
			if ( is_category() || is_tag() || is_tax() ) {
				single_term_title();
			} elseif ( is_search() ) {
				printf(
					/* translators: %s: search term. */
					esc_html__( 'תוצאות חיפוש: %s', 'diromax' ),
					esc_html( get_search_query() )
				);
			} else {
				esc_html_e( 'איך חושבים על נכס שיש לו עוד מה לתת.', 'diromax' );
			}
			?>
		</h1>
	</div>
</section>

<section class="dx-section">
	<div class="dx-wrap dx-pad-xs">
		<?php if ( have_posts() ) : ?>

			<div class="dx-articles">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/article-card' );
				endwhile;
				?>
			</div>

			<?php
			$dx_pagination = paginate_links(
				array(
					'prev_text' => __( 'הקודם', 'diromax' ),
					'next_text' => __( 'הבא', 'diromax' ),
				)
			);

			if ( $dx_pagination ) :
				?>
				<nav class="dx-pagination" aria-label="<?php esc_attr_e( 'ניווט בין עמודי המאמרים', 'diromax' ); ?>">
					<?php echo wp_kses_post( $dx_pagination ); ?>
				</nav>
				<?php
			endif;
			?>

		<?php else : ?>
			<p class="dx-intro"><?php esc_html_e( 'לא נמצאו מאמרים.', 'diromax' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>
