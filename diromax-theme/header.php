<?php
/**
 * Header: colour strip, sticky bar with brand, nav and CTA.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="dx-skip" href="#dx-main"><?php esc_html_e( 'דלג לתוכן הראשי', 'diromax' ); ?></a>

<div class="dx-topstrip" aria-hidden="true"><span></span><span></span><span></span></div>

<header class="dx-header">
	<div class="dx-header__inner">

		<a class="dx-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php
			$dx_logo_id = (int) get_theme_mod( 'custom_logo' );

			if ( $dx_logo_id ) {
				// Decorative: the brand word beside it carries the accessible name.
				echo wp_get_attachment_image(
					$dx_logo_id,
					'full',
					false,
					array(
						'class'         => 'dx-brand__logo',
						'alt'           => '',
						'loading'       => 'eager',
						'fetchpriority' => 'high',
					)
				);
			} else {
				?>
				<img
					class="dx-brand__logo"
					src="<?php echo esc_url( DX_URI . '/assets/img/logo.jpg' ); ?>"
					width="300" height="275" alt=""
					loading="eager" fetchpriority="high" decoding="async">
				<?php
			}
			?>
			<span class="dx-brand__word">DIROMAX</span>
			<span class="dx-sr-only"><?php esc_html_e( '— לעמוד הבית', 'diromax' ); ?></span>
		</a>

		<nav class="dx-nav" aria-label="<?php esc_attr_e( 'תפריט ראשי', 'diromax' ); ?>">
			<?php foreach ( dx_nav_links( 'primary' ) as $dx_link ) : ?>
				<a
					href="<?php echo esc_url( $dx_link['url'] ); ?>"
					class="<?php echo esc_attr( $dx_link['current'] ? 'is-active' : '' ); ?>"
					<?php echo $dx_link['current'] ? 'aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>
				><?php echo esc_html( $dx_link['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="dx-header__cta">
			<a class="dx-btn dx-btn--orange dx-btn--sm" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>">
				<?php dx_the_text( 'general.header_cta' ); ?>
			</a>
		</div>

	</div>
</header>

<main id="dx-main" tabindex="-1">
