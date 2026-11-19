<?php
/**
 * Lead form.
 *
 * $args: variant = 'light' | 'blue'.
 *
 * Works without JavaScript (normal POST to admin-post.php) and is enhanced by
 * assets/js/forms.js into an in-place AJAX submission. Every field has a real
 * label; errors are wired to their field and announced.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dx_variant = ( isset( $args['variant'] ) && 'blue' === $args['variant'] ) ? 'blue' : 'light';
$dx_is_blue = ( 'blue' === $dx_variant );
$dx_uid     = wp_unique_id( 'dx-form-' );
$dx_notice  = dx_lead_notice();
$dx_sent    = ( $dx_notice && 'success' === $dx_notice['status'] );
?>
<div class="<?php echo esc_attr( $dx_is_blue ? 'dx-form-card dx-form-card--blue' : 'dx-form-card' ); ?>" id="dx-form">

	<?php if ( $dx_sent ) : ?>

		<div class="dx-sent" role="status">
			<div class="dx-rule" aria-hidden="true"></div>
			<?php if ( $dx_is_blue ) : ?>
				<h2><?php dx_the_text( 'form.ok_blue_h' ); ?></h2>
				<p><?php dx_the_text( 'form.ok_blue_p' ); ?></p>
			<?php else : ?>
				<h3><?php dx_the_text( 'form.ok_light_h' ); ?></h3>
				<p><?php dx_the_text( 'form.ok_light_p' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<form
			class="dx-lead-form"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			aria-labelledby="<?php echo esc_attr( $dx_uid ); ?>-title"
			novalidate
		>
			<input type="hidden" name="action" value="dx_lead">
			<input type="hidden" name="dx_source" value="<?php echo esc_url( dx_current_url() ); ?>">
			<?php wp_nonce_field( 'dx_lead', 'nonce', false ); ?>
			<input type="hidden" name="dx_utm" value="" data-dx-utm>

			<p class="dx-hp" aria-hidden="true">
				<label for="<?php echo esc_attr( $dx_uid ); ?>-hp"><?php esc_html_e( 'אל תמלא שדה זה', 'diromax' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $dx_uid ); ?>-hp" name="dx_hp" tabindex="-1" autocomplete="off">
			</p>

			<?php if ( $dx_is_blue ) : ?>
				<h2 id="<?php echo esc_attr( $dx_uid ); ?>-title"><?php dx_the_text( 'form.blue_title' ); ?></h2>
			<?php else : ?>
				<h3 id="<?php echo esc_attr( $dx_uid ); ?>-title"><?php dx_the_text( 'form.light_title' ); ?></h3>
			<?php endif; ?>

			<div class="dx-fields">

				<div class="dx-alert" role="alert" tabindex="-1" data-dx-alert>
					<?php if ( $dx_notice && 'error' === $dx_notice['status'] ) : ?>
						<?php echo esc_html( $dx_notice['message'] ); ?>
					<?php endif; ?>
				</div>

				<p class="dx-label">
					<label for="<?php echo esc_attr( $dx_uid ); ?>-name">
						<?php dx_the_text( 'form.label_name' ); ?>
						<span class="dx-req"><?php esc_html_e( '(חובה)', 'diromax' ); ?></span>
					</label>
					<input
						class="dx-input"
						type="text"
						id="<?php echo esc_attr( $dx_uid ); ?>-name"
						name="dx_name"
						required
						aria-required="true"
						autocomplete="name"
						maxlength="80">
				</p>

				<p class="dx-label">
					<label for="<?php echo esc_attr( $dx_uid ); ?>-phone">
						<?php dx_the_text( 'form.label_phone' ); ?>
						<span class="dx-req"><?php esc_html_e( '(חובה)', 'diromax' ); ?></span>
					</label>
					<input
						class="dx-input"
						type="tel"
						id="<?php echo esc_attr( $dx_uid ); ?>-phone"
						name="dx_phone"
						required
						aria-required="true"
						autocomplete="tel"
						inputmode="tel"
						aria-describedby="<?php echo esc_attr( $dx_uid ); ?>-phone-hint"
						maxlength="20">
					<span class="dx-hint" id="<?php echo esc_attr( $dx_uid ); ?>-phone-hint">
						<?php dx_the_text( 'form.hint_phone' ); ?>
					</span>
				</p>

				<p class="dx-label">
					<label for="<?php echo esc_attr( $dx_uid ); ?>-address"><?php dx_the_text( 'form.label_address' ); ?></label>
					<input
						class="dx-input"
						type="text"
						id="<?php echo esc_attr( $dx_uid ); ?>-address"
						name="dx_address"
						autocomplete="street-address"
						aria-describedby="<?php echo esc_attr( $dx_uid ); ?>-address-hint"
						maxlength="150">
					<span class="dx-hint" id="<?php echo esc_attr( $dx_uid ); ?>-address-hint">
						<?php dx_the_text( 'form.hint_address' ); ?>
					</span>
				</p>

				<?php if ( $dx_is_blue ) : ?>

					<p class="dx-label">
						<label for="<?php echo esc_attr( $dx_uid ); ?>-subject"><?php dx_the_text( 'form.label_subject' ); ?></label>
						<select class="dx-input" id="<?php echo esc_attr( $dx_uid ); ?>-subject" name="dx_subject">
							<?php foreach ( dx_contact_subjects() as $dx_subject ) : ?>
								<option value="<?php echo esc_attr( $dx_subject ); ?>"><?php echo esc_html( $dx_subject ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

					<p class="dx-label">
						<label for="<?php echo esc_attr( $dx_uid ); ?>-message">
							<?php dx_the_text( 'form.label_message' ); ?>
							<span class="dx-opt"><?php esc_html_e( '(לא חובה)', 'diromax' ); ?></span>
						</label>
						<textarea
							class="dx-input"
							id="<?php echo esc_attr( $dx_uid ); ?>-message"
							name="dx_message"
							rows="3"
							maxlength="2000"></textarea>
					</p>

					<?php if ( dx_turnstile_enabled() ) : ?>
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( dx_turnstile_site_key() ); ?>" data-action="dx_lead" data-language="he" data-refresh-expired="auto"></div>
					<?php endif; ?>
					<button class="dx-btn dx-btn--orange dx-btn--block" type="submit"><?php dx_the_text( 'form.btn_blue' ); ?></button>

				<?php else : ?>

					<p class="dx-label">
						<label for="<?php echo esc_attr( $dx_uid ); ?>-type"><?php dx_the_text( 'form.label_type' ); ?></label>
						<select class="dx-input" id="<?php echo esc_attr( $dx_uid ); ?>-type" name="dx_type">
							<?php foreach ( dx_property_types() as $dx_type ) : ?>
								<option value="<?php echo esc_attr( $dx_type ); ?>"><?php echo esc_html( $dx_type ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

					<?php if ( dx_turnstile_enabled() ) : ?>
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( dx_turnstile_site_key() ); ?>" data-action="dx_lead" data-language="he" data-refresh-expired="auto"></div>
					<?php endif; ?>
					<button class="dx-btn dx-btn--blue dx-btn--block" type="submit"><?php dx_the_text( 'form.btn_light' ); ?></button>
					<p class="dx-note"><?php dx_the_text( 'form.note' ); ?></p>

				<?php endif; ?>
			</div>
		</form>

	<?php endif; ?>
</div>
