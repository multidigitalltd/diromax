<?php
/**
 * Single article.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$dx_cats = get_the_category();
	?>
	<article>
		<header class="dx-section">
			<div class="dx-wrap dx-wrap--narrow dx-pad-sm">
				<div class="dx-eyebrow dx-eyebrow--page">
					<span class="dx-dot" aria-hidden="true"></span>
					<span class="dx-eyebrow__t"><?php echo esc_html( $dx_cats ? $dx_cats[0]->name : (string) dx_content( 'articles.default_cat' ) ); ?></span>
				</div>
				<h1 class="dx-h1--page dx-mb-18"><?php the_title(); ?></h1>
				<p class="dx-meta">
					<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				</p>
			</div>
		</header>

		<?php if ( has_post_thumbnail() || dx_default_thumb_id() ) : ?>
			<div class="dx-section dx-hero-img">
				<?php dx_post_thumbnail( 'full', array( 'loading' => 'eager', 'fetchpriority' => 'high' ) ); ?>
			</div>
		<?php endif; ?>

		<div class="dx-section">
			<div class="dx-wrap dx-wrap--narrow dx-pad-xs">
				<div class="dx-post"><?php the_content(); ?></div>
			</div>
		</div>
	</article>

	<section class="dx-section dx-section--orange">
		<div class="dx-wrap dx-pad-strip">
			<div class="dx-strip">
				<p class="dx-mw-32"><?php dx_the_text( 'general.post_cta_text' ); ?></p>
				<a class="dx-btn dx-btn--blue dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>"><?php dx_the_text( 'general.post_cta_btn' ); ?></a>
			</div>
		</div>
	</section>

	<?php
	$dx_prev = get_previous_post();
	$dx_next = get_next_post();

	if ( $dx_prev || $dx_next ) :
		?>
		<nav class="dx-section" aria-label="<?php esc_attr_e( 'מאמרים סמוכים', 'diromax' ); ?>">
			<div class="dx-wrap dx-wrap--narrow dx-pad-xs">
				<div class="dx-postnav">
					<?php if ( $dx_prev ) : ?>
						<a class="dx-postnav__i" href="<?php echo esc_url( get_permalink( $dx_prev ) ); ?>">
							<span class="dx-postnav__k"><?php dx_the_text( 'general.post_prev' ); ?></span>
							<span class="dx-postnav__t"><?php echo esc_html( get_the_title( $dx_prev ) ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $dx_next ) : ?>
						<a class="dx-postnav__i" href="<?php echo esc_url( get_permalink( $dx_next ) ); ?>">
							<span class="dx-postnav__k"><?php dx_the_text( 'general.post_next' ); ?></span>
							<span class="dx-postnav__t"><?php echo esc_html( get_the_title( $dx_next ) ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</nav>
		<?php
	endif;
endwhile;

get_footer();
