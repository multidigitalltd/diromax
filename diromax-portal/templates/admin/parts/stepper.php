<?php
/**
 * Admin part: the case's seven stages, as the client sees them.
 *
 * @package Diromax\Portal
 *
 * @var array<int, array{number:string, name:string, status:string, label:string}> $stages
 * @var string                                                                      $stages_url
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="dxa-steps-wrap">
	<ol class="dxa-steps" aria-label="<?php esc_attr_e( 'שלבי התיק', 'diromax-portal' ); ?>">
		<?php foreach ( $stages as $dxa_stage ) : ?>
			<li class="dxa-step dxa-step--<?php echo esc_attr( $dxa_stage['status'] ); ?>"<?php echo 'active' === $dxa_stage['status'] ? ' aria-current="step"' : ''; ?>>
				<span class="dxa-step__n"><?php echo esc_html( $dxa_stage['number'] ); ?><?php echo 'done' === $dxa_stage['status'] ? ' <span aria-hidden="true">✓</span>' : ''; ?></span>
				<span class="dxa-step__t"><?php echo esc_html( $dxa_stage['name'] ); ?></span>
				<span class="dxa-step__s"><?php echo esc_html( $dxa_stage['label'] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>
	<a class="dxa-steps__edit" href="<?php echo esc_url( $stages_url ); ?>"><?php esc_html_e( 'עדכון שלבים', 'diromax-portal' ); ?></a>
</div>
