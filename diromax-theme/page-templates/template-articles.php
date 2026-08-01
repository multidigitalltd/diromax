<?php
/**
 * Template Name: דירומקס — מאמרים
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$dx_paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
$dx_posts = new WP_Query(
	array(
		'post_type'              => 'post',
		'posts_per_page'         => 12,
		'paged'                  => $dx_paged,
		'ignore_sticky_posts'    => true,
		'update_post_meta_cache' => false,
	)
);
?>

<section class="dx-section" aria-labelledby="dx-articles-title">
	<div class="dx-wrap dx-pad-sm">
		<?php dx_eyebrow( __( 'מאמרים', 'diromax' ), 'teal', 'dx-eyebrow--page' ); ?>
		<h1 class="dx-h1--page dx-mw-24" id="dx-articles-title"><?php esc_html_e( 'איך חושבים על נכס שיש לו עוד מה לתת.', 'diromax' ); ?></h1>
	</div>
</section>

<section class="dx-section">
	<div class="dx-wrap dx-pad-xs">
		<?php if ( $dx_posts->have_posts() ) : ?>

			<div class="dx-articles">
				<?php
				while ( $dx_posts->have_posts() ) :
					$dx_posts->the_post();
					get_template_part( 'template-parts/article-card' );
				endwhile;
				?>
			</div>

			<?php
			$dx_pagination = paginate_links(
				array(
					'total'     => $dx_posts->max_num_pages,
					'current'   => $dx_paged,
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

			wp_reset_postdata();
			?>

		<?php else : ?>
			<p class="dx-intro"><?php esc_html_e( 'עדיין אין מאמרים. אפשר להוסיף אותם מלוח הניהול, תחת "פוסטים".', 'diromax' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>
