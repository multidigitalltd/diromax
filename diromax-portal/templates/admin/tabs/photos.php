<?php
/**
 * Admin tab: the case's photo journal (spec 18.2).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Service\PhotoService;
use Diromax\Portal\Service\Stages;

defined( 'ABSPATH' ) || exit;

$form       = new Form( $view['flash'], 'photos' );
$dx_journal = (array) $view['journal'];
$dx_action  = static function ( string $action, int $photo_id, string $label, string $extra = '' ) use ( $case ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
		<input type="hidden" name="action" value="dx_portal_<?php echo esc_attr( $action ); ?>">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<input type="hidden" name="photo_id" value="<?php echo esc_attr( (string) $photo_id ); ?>">
		<?php if ( '' !== $extra ) : ?>
			<input type="hidden" name="visible" value="<?php echo esc_attr( $extra ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'dx_portal_' . $action . '_' . $case->id ); ?>
		<button type="submit" class="button-link<?php echo 'delete_photo' === $action ? ' button-link-delete' : ''; ?>"<?php echo 'delete_photo' === $action ? ' data-dxa-confirm="' . esc_attr__( 'למחוק את הצילום לצמיתות?', 'diromax-portal' ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<?php if ( ! $view['key_ready'] ) : ?>
	<div class="notice notice-error inline"><p><?php esc_html_e( 'העלאת צילומים חסומה עד שיוגדר מפתח ההצפנה DX_PORTAL_KEY.', 'diromax-portal' ); ?></p></div>
<?php else : ?>
<details class="dxa-box dxa-box--add" id="form-photos"<?php echo array() === $dx_journal || $form->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
	<summary><h2><?php esc_html_e( 'הוספת צילומים מהשטח', 'diromax-portal' ); ?></h2></summary>
	<p class="description">
		<?php
		/* translators: %d: photos per upload. */
		echo esc_html( sprintf( __( 'עד %d צילומים בפעם אחת, גם ישר מהמצלמה בטלפון. הצילומים נשמרים מוצפנים, בלי נתוני המיקום שבהם.', 'diromax-portal' ), PhotoService::PER_UPLOAD ) );
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="dx_portal_upload_photos">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_upload_photos_' . $case->id ); ?>
		<p class="dxa-field dxa-field--wide">
			<label for="dxa-photos-files"><?php esc_html_e( 'צילומים: JPG, PNG או WEBP, עד 20MB כל אחד', 'diromax-portal' ); ?></label>
			<input type="file" id="dxa-photos-files" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required<?php echo '' !== $form->error( 'photos' ) ? ' aria-invalid="true" aria-describedby="dxa-photos-files-error"' : ''; ?>>
			<?php if ( '' !== $form->error( 'photos' ) ) : ?>
				<p class="dxa-error" id="dxa-photos-files-error"><?php echo esc_html( $form->error( 'photos' ) ); ?></p>
			<?php endif; ?>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'stage' ) ); ?>"><?php esc_html_e( 'שלב', 'diromax-portal' ); ?></label>
			<select <?php echo $form->attrs( 'stage' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
				<?php foreach ( Stages::names() as $dx_number => $dx_name ) : ?>
					<option value="<?php echo esc_attr( (string) $dx_number ); ?>"<?php selected( $form->old( 'stage', (string) $view['stage'] ), (string) $dx_number ); ?>><?php echo esc_html( Stages::label( $dx_number ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'taken_on' ) ); ?>"><?php esc_html_e( 'תאריך הצילום (ריק: לפי הצילום עצמו, או היום)', 'diromax-portal' ); ?></label>
			<input type="date" <?php echo $form->attrs( 'taken_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'taken_on' ) ); ?>" max="<?php echo esc_attr( (string) $view['today'] ); ?>" dir="ltr">
			<?php $form->print_error( 'taken_on' ); ?>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'caption' ) ); ?>"><?php esc_html_e( 'כיתוב (רשות, לכל הצילומים בהעלאה)', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $form->attrs( 'caption' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'caption' ) ); ?>" maxlength="300">
			<?php $form->print_error( 'caption' ); ?>
		</p>
		<fieldset class="dxa-field dxa-field--wide dxa-checks">
			<legend class="screen-reader-text"><?php esc_html_e( 'נראות ועדכון הלקוח', 'diromax-portal' ); ?></legend>
			<span><input type="checkbox" id="dxa-photos-visible" name="visible" value="1" checked> <label for="dxa-photos-visible"><?php esc_html_e( 'גלוי ללקוח', 'diromax-portal' ); ?></label></span>
			<span><input type="checkbox" id="dxa-photos-notify" name="notify" value="1" checked> <label for="dxa-photos-notify"><?php esc_html_e( 'לעדכן את הלקוח במייל', 'diromax-portal' ); ?></label></span>
		</fieldset>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'העלאה', 'diromax-portal' ); ?></button></p>
	</form>
</details>
<?php endif; ?>

<h2><?php esc_html_e( 'יומן הצילומים', 'diromax-portal' ); ?></h2>
<?php if ( array() === $dx_journal ) : ?>
	<p><?php esc_html_e( 'עדיין אין צילומים בתיק.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<?php foreach ( $dx_journal as $dx_stage ) : ?>
		<section class="dxa-photos" aria-labelledby="<?php echo esc_attr( 'dxa-photos-' . $dx_stage['number'] ); ?>">
			<h3 id="<?php echo esc_attr( 'dxa-photos-' . $dx_stage['number'] ); ?>"><?php echo esc_html( $dx_stage['number'] . ' · ' . $dx_stage['name'] ); ?></h3>
			<ul class="dxa-photos__grid">
				<?php foreach ( $dx_stage['photos'] as $dx_photo ) : ?>
					<li class="dxa-photo<?php echo $dx_photo['visible'] ? '' : ' is-hidden'; ?>">
						<a href="<?php echo esc_url( (string) $dx_photo['full'] ); ?>" target="_blank" rel="noopener">
							<img src="<?php echo esc_url( (string) $dx_photo['thumb'] ); ?>" width="<?php echo esc_attr( (string) $dx_photo['width'] ); ?>" height="<?php echo esc_attr( (string) $dx_photo['height'] ); ?>" alt="<?php echo esc_attr( (string) $dx_photo['alt'] ); ?>" loading="lazy" decoding="async">
							<span class="screen-reader-text"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax-portal' ); ?></span>
						</a>
						<p>
							<span class="dxa-muted" dir="ltr"><?php echo esc_html( (string) $dx_photo['date'] ); ?></span>
							<?php if ( '' !== $dx_photo['caption'] ) : ?>
								· <?php echo esc_html( (string) $dx_photo['caption'] ); ?>
							<?php endif; ?>
							<?php if ( ! $dx_photo['visible'] ) : ?>
								<span class="dxa-badge"><?php esc_html_e( 'פנימי', 'diromax-portal' ); ?></span>
							<?php endif; ?>
						</p>
						<div class="dxa-photo__actions">
							<?php $dx_action( 'photo_visibility', (int) $dx_photo['id'], $dx_photo['visible'] ? __( 'להסתיר מהלקוח', 'diromax-portal' ) : __( 'להציג ללקוח', 'diromax-portal' ), $dx_photo['visible'] ? '0' : '1' ); ?>
							<?php $dx_action( 'delete_photo', (int) $dx_photo['id'], __( 'מחיקה', 'diromax-portal' ) ); ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
<?php endif; ?>
