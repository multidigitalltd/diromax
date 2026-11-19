<?php
/**
 * Template Name: דירומקס — הצהרת נגישות
 *
 * The statement text lives in inc/content.php; contact details are injected
 * from the Customizer at render time so the page can never quote a number the
 * office no longer uses.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$dx_a11y_name  = dx_opt( 'a11y_name', '' );
$dx_a11y_phone = dx_opt( 'a11y_phone', '' );
$dx_a11y_mail  = dx_opt( 'a11y_email', '' );
$dx_updated_at = strtotime( dx_opt( 'a11y_updated', '' ) );
$dx_updated    = false === $dx_updated_at ? false : wp_date( get_option( 'date_format' ), $dx_updated_at );

if ( '' === $dx_a11y_phone ) {
	$dx_a11y_phone = dx_phone();
}
if ( ! is_email( $dx_a11y_mail ) ) {
	$dx_a11y_mail = dx_email();
}
?>

<section class="dx-section" aria-labelledby="dx-a11y-page-title">
	<div class="dx-wrap dx-wrap--narrow dx-pad-sm">
		<?php dx_eyebrow( __( 'נגישות', 'diromax' ), 'teal', 'dx-eyebrow--page' ); ?>
		<h1 class="dx-h1--page dx-mw-24" id="dx-a11y-page-title"><?php the_title(); ?></h1>
	</div>
</section>

<section class="dx-section">
	<div class="dx-wrap dx-wrap--narrow dx-pad-xs">
		<div class="dx-post">
			<?php foreach ( dx_a11y_statement() as $dx_block ) : ?>
				<h2><?php echo esc_html( $dx_block['h'] ); ?></h2>

				<?php if ( ! empty( $dx_block['p'] ) ) : ?>
					<?php foreach ( $dx_block['p'] as $dx_paragraph ) : ?>
						<p><?php echo esc_html( $dx_paragraph ); ?></p>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php if ( ! empty( $dx_block['l'] ) ) : ?>
					<ul>
						<?php foreach ( $dx_block['l'] as $dx_line ) : ?>
							<li><?php echo esc_html( $dx_line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'פניות בנושא נגישות', 'diromax' ); ?></h2>
			<p><?php esc_html_e( 'נתקלת בבעיית נגישות באתר? נשמח לשמוע, ונטפל בה. אפשר לפנות אלינו בכל אחת מהדרכים הבאות:', 'diromax' ); ?></p>

			<dl class="dx-rows dx-rows--flat">
				<?php if ( '' !== $dx_a11y_name ) : ?>
					<div class="dx-row">
						<dt class="dx-row__k"><?php esc_html_e( 'רכז/ת נגישות', 'diromax' ); ?></dt>
						<dd><span class="dx-row__v dx-row__v--text"><?php echo esc_html( $dx_a11y_name ); ?></span></dd>
					</div>
				<?php endif; ?>
				<div class="dx-row">
					<dt class="dx-row__k"><?php esc_html_e( 'טלפון', 'diromax' ); ?></dt>
					<dd><a class="dx-row__v" href="tel:<?php echo esc_attr( dx_phone_digits( $dx_a11y_phone ) ); ?>"><?php echo esc_html( $dx_a11y_phone ); ?></a></dd>
				</div>
				<div class="dx-row">
					<dt class="dx-row__k"><?php esc_html_e( 'דוא״ל', 'diromax' ); ?></dt>
					<dd><a class="dx-row__v dx-row__v--mail" href="mailto:<?php echo esc_attr( $dx_a11y_mail ); ?>"><?php echo esc_html( $dx_a11y_mail ); ?></a></dd>
				</div>
			</dl>

			<p><?php esc_html_e( 'נשתדל להשיב לכל פנייה בהקדם ולתקן את הליקוי במהירות האפשרית.', 'diromax' ); ?></p>

			<?php if ( is_string( $dx_updated ) ) : ?>
				<p class="dx-meta">
					<?php
					printf(
						/* translators: %s: statement update date. */
						esc_html__( 'תאריך עדכון ההצהרה: %s', 'diromax' ),
						esc_html( $dx_updated )
					);
					?>
				</p>
			<?php endif; ?>

			<?php
			// Anything the client writes into the page itself is appended here.
			while ( have_posts() ) :
				the_post();
				the_content();
			endwhile;
			?>
		</div>
	</div>
</section>

<?php get_footer(); ?>
