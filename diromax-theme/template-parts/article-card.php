<?php
/**
 * One article card. Used on the articles page and on the blog archive.
 *
 * The whole card is clickable through an overlay on the title link, so the
 * link's accessible name is the title alone rather than the entire card.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dx_cats = get_the_category();
$dx_cat  = $dx_cats ? $dx_cats[0]->name : '';
?>
<article class="dx-article">
	<div class="dx-article__thumb">
		<?php if ( ! dx_post_thumbnail( 'dx-card', array( 'loading' => 'lazy' ) ) ) : ?>
			<span class="dx-article__ph" aria-hidden="true">ARTICLE IMAGE</span>
		<?php endif; ?>
	</div>
	<div class="dx-article__body">
		<?php if ( $dx_cat ) : ?>
			<span class="dx-article__cat"><?php echo esc_html( $dx_cat ); ?></span>
		<?php endif; ?>
		<h2><a class="dx-article__link" href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<p><?php echo esc_html( wp_trim_words( get_the_excerpt(), 24, '…' ) ); ?></p>
	</div>
</article>
