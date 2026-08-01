<?php
/**
 * Template Name: דירומקס — צור קשר
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<section class="dx-section" aria-labelledby="dx-contact-title">
	<div class="dx-wrap dx-pad-sm">
		<div class="dx-cta__grid">

			<div>
				<?php dx_eyebrow( __( 'צור קשר', 'diromax' ), 'orange', 'dx-eyebrow--page' ); ?>
				<h1 class="dx-h1--contact dx-mb-22" id="dx-contact-title"><?php esc_html_e( 'נתחיל בשיחה על הנכס שלך.', 'diromax' ); ?></h1>
				<p class="dx-intro--lg dx-intro--body dx-mw-48 dx-mb-40"><?php esc_html_e( 'ספר לנו איפה הנכס ומה מצבו. נבדוק את המידע התכנוני ונחזור אליך עם תמונת מצב ראשונית — ללא עלות וללא התחייבות.', 'diromax' ); ?></p>

				<dl class="dx-rows">
					<div class="dx-row">
						<dt class="dx-row__k"><?php esc_html_e( 'טלפון', 'diromax' ); ?></dt>
						<dd><a class="dx-row__v" href="tel:<?php echo esc_attr( dx_phone_tel() ); ?>"><?php echo esc_html( dx_phone() ); ?></a></dd>
					</div>
					<div class="dx-row">
						<dt class="dx-row__k"><?php esc_html_e( 'וואטסאפ', 'diromax' ); ?></dt>
						<dd>
							<a class="dx-row__v" href="<?php echo esc_url( dx_wa_url() ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( dx_phone() ); ?>
								<span class="dx-sr-only"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax' ); ?></span>
							</a>
						</dd>
					</div>
					<div class="dx-row">
						<dt class="dx-row__k"><?php esc_html_e( 'דוא״ל', 'diromax' ); ?></dt>
						<dd><a class="dx-row__v dx-row__v--mail" href="mailto:<?php echo esc_attr( dx_email() ); ?>"><?php echo esc_html( dx_email() ); ?></a></dd>
					</div>
					<div class="dx-row">
						<dt class="dx-row__k"><?php esc_html_e( 'אזור פעילות', 'diromax' ); ?></dt>
						<dd><span class="dx-row__v dx-row__v--text"><?php echo esc_html( dx_area() ); ?></span></dd>
					</div>
				</dl>

				<div class="dx-nextbox">
					<h2><?php esc_html_e( 'מה קורה אחרי שמשאירים פרטים', 'diromax' ); ?></h2>
					<ol class="dx-nextbox__list">
						<?php foreach ( dx_contact_steps() as $dx_step ) : ?>
							<li class="dx-nextbox__i">
								<span class="dx-nextbox__n" aria-hidden="true"><?php echo esc_html( $dx_step['n'] ); ?></span>
								<span class="dx-nextbox__t"><?php echo esc_html( $dx_step['t'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			</div>

			<?php get_template_part( 'template-parts/lead-form', null, array( 'variant' => 'blue' ) ); ?>

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
