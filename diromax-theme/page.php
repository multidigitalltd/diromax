<?php
/**
 * Generic page (privacy, terms, anything the client adds).
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<section class="dx-section">
		<div class="dx-wrap dx-wrap--narrow dx-pad-sm">
			<h1 class="dx-h1--page dx-mb-24"><?php the_title(); ?></h1>
			<div class="dx-post"><?php the_content(); ?></div>
			<?php
			wp_link_pages(
				array(
					'before' => '<nav class="dx-pagination" aria-label="' . esc_attr__( 'ניווט בתוך העמוד', 'diromax' ) . '">',
					'after'  => '</nav>',
				)
			);
			?>
		</div>
	</section>
	<?php
endwhile;

get_footer();
