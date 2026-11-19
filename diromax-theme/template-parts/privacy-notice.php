<?php
/**
 * First-visit privacy notice (Multi Digital standard 15.5).
 *
 * Hidden by default and identical for every visitor; assets/js/privacy.js
 * shows it when the visitor has not seen this version of the policy in the
 * last 12 months. Non-modal: the site stays usable while it is open.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dx_policy = get_privacy_policy_url();
?>
<section class="dx-privacy" role="dialog" aria-modal="false" aria-labelledby="dx-privacy-title" aria-describedby="dx-privacy-text" tabindex="-1" data-dx-privacy="<?php echo esc_attr( dx_privacy_version() ); ?>" hidden>
	<div class="dx-privacy__in">
		<h2 class="dx-privacy__h" id="dx-privacy-title"><?php dx_the_text( 'general.privacy_title' ); ?></h2>
		<p id="dx-privacy-text">
			<?php dx_the_text( 'general.privacy_text' ); ?>
			<?php if ( '' !== $dx_policy ) : ?>
				<a href="<?php echo esc_url( $dx_policy ); ?>"><?php dx_the_text( 'general.privacy_link' ); ?></a>
			<?php endif; ?>
		</p>
		<button type="button" class="dx-btn dx-btn--blue" data-dx-privacy-ok><?php dx_the_text( 'general.privacy_ok' ); ?></button>
	</div>
</section>
