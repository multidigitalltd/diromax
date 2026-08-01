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
					<span class="dx-eyebrow__t"><?php echo esc_html( $dx_cats ? $dx_cats[0]->name : __( 'מאמר', 'diromax' ) ); ?></span>
				</div>
				<h1 class="dx-h1--page dx-mb-18"><?php the_title(); ?></h1>
				<p class="dx-meta">
					<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				</p>
			</div>
		</header>

		<?php if ( has_post_thumbnail() ) : ?>
			<div class="dx-section dx-hero-img">
				<?php the_post_thumbnail( 'full', array( 'loading' => 'eager', 'fetchpriority' => 'high' ) ); ?>
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
				<p class="dx-mw-32"><?php esc_html_e( 'רוצה לדעת מה אפשר לעשות עם הנכס שלך?', 'diromax' ); ?></p>
				<a class="dx-btn dx-btn--blue dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>"><?php esc_html_e( 'בדיקת פוטנציאל — ללא עלות', 'diromax' ); ?></a>
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
							<span class="dx-postnav__k"><?php esc_html_e( 'המאמר הקודם', 'diromax' ); ?></span>
							<span class="dx-postnav__t"><?php echo esc_html( get_the_title( $dx_prev ) ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $dx_next ) : ?>
						<a class="dx-postnav__i" href="<?php echo esc_url( get_permalink( $dx_next ) ); ?>">
							<span class="dx-postnav__k"><?php esc_html_e( 'המאמר הבא', 'diromax' ); ?></span>
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
