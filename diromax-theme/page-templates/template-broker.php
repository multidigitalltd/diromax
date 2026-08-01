<?php
/**
 * Template Name: דירומקס — תיווך
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<section class="dx-section" aria-labelledby="dx-broker-title">
	<div class="dx-wrap dx-pad-md">
		<?php dx_eyebrow( dx_content( 'broker.eyebrow' ), 'teal', 'dx-eyebrow--page' ); ?>
		<h1 class="dx-h1--broker dx-mw-24 dx-mb-24" id="dx-broker-title"><?php dx_the_lines( 'broker.title' ); ?></h1>
		<p class="dx-lead dx-mw-62"><?php dx_the_text( 'broker.lead' ); ?></p>
	</div>
</section>

<section class="dx-section" aria-label="<?php esc_attr_e( 'שירותי התיווך', 'diromax' ); ?>">
	<div class="dx-wrap">
		<ol class="dx-broker">
			<?php foreach ( dx_broker_services() as $dx_service ) : ?>
				<li class="dx-broker__c">
					<span class="dx-broker__n" aria-hidden="true"><?php echo esc_html( $dx_service['n'] ); ?></span>
					<h2><?php echo esc_html( $dx_service['h'] ); ?></h2>
					<p><?php echo esc_html( $dx_service['p'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>

<section class="dx-section dx-section--blue">
	<div class="dx-wrap dx-pad-strip">
		<div class="dx-strip">
			<p class="dx-mw-32"><?php dx_the_text( 'broker.strip' ); ?></p>
			<a class="dx-btn dx-btn--orange dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'improve' ) ); ?>"><?php dx_the_text( 'broker.strip_btn' ); ?></a>
		</div>
	</div>
</section>

<?php
// Anything the editor adds to this page renders below the designed sections.
while ( have_posts() ) :
	the_post();

	if ( '' !== trim( get_the_content() ) ) {
		echo '<section class="dx-section"><div class="dx-wrap dx-wrap--narrow dx-pad-lg"><div class="dx-post">';
		the_content();
		echo '</div></div></section>';
	}
endwhile;

get_footer();
