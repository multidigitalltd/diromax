<?php
/**
 * Admin tab: progress updates.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$form = new Form( $view['flash'], 'update' );
?>
<details class="dxa-box dxa-box--add" id="form-update"<?php echo array() === $view['updates'] || $form->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
	<summary><h2><?php esc_html_e( 'עדכון חדש', 'diromax-portal' ); ?></h2></summary>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="dx_portal_add_update">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_add_update_' . $case->id ); ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'title' ) ); ?>"><?php esc_html_e( 'כותרת', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $form->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'title' ) ); ?>" maxlength="200" required>
			<?php $form->print_error( 'title' ); ?>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'body' ) ); ?>"><?php esc_html_e( 'מה קרה ומה הלאה', 'diromax-portal' ); ?></label>
			<textarea <?php echo $form->attrs( 'body' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="5" maxlength="5000"><?php echo esc_textarea( $form->old( 'body' ) ); ?></textarea>
			<?php $form->print_error( 'body' ); ?>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'stage' ) ); ?>"><?php esc_html_e( 'שלב שמתחיל', 'diromax-portal' ); ?></label>
			<select <?php echo $form->attrs( 'stage' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
				<option value=""><?php esc_html_e( 'בלי שינוי שלב', 'diromax-portal' ); ?></option>
				<?php foreach ( $view['stages'] as $number => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $number ); ?>"><?php echo esc_html( str_pad( (string) $number, 2, '0', STR_PAD_LEFT ) . ' · ' . $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'stage_mode' ) ); ?>"><?php esc_html_e( 'השלבים האחרים', 'diromax-portal' ); ?></label>
			<select <?php echo $form->attrs( 'stage_mode' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
				<option value=""><?php esc_html_e( 'השלבים שלפניו הושלמו', 'diromax-portal' ); ?></option>
				<option value="parallel"><?php esc_html_e( 'במקביל: השלבים שבתהליך נשארים בתהליך', 'diromax-portal' ); ?></option>
			</select>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="dxa-update-files"><?php esc_html_e( 'תמונות או מסמכים (רשות, עד 10)', 'diromax-portal' ); ?></label>
			<input type="file" id="dxa-update-files" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx">
			<?php $form->print_error( 'files' ); ?>
		</p>
		<fieldset class="dxa-field dxa-field--wide dxa-checks">
			<legend class="screen-reader-text"><?php esc_html_e( 'נראות ועדכון הלקוח', 'diromax-portal' ); ?></legend>
			<span><input type="checkbox" id="dxa-update-visible" name="visible" value="1" checked> <label for="dxa-update-visible"><?php esc_html_e( 'גלוי ללקוח', 'diromax-portal' ); ?></label></span>
			<span><input type="checkbox" id="dxa-update-notify" name="notify" value="1" checked> <label for="dxa-update-notify"><?php esc_html_e( 'לעדכן את הלקוח במייל', 'diromax-portal' ); ?></label></span>
		</fieldset>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'פרסום העדכון', 'diromax-portal' ); ?></button></p>
	</form>
</details>

<h2><?php esc_html_e( 'עדכונים שפורסמו', 'diromax-portal' ); ?></h2>
<?php if ( array() === $view['updates'] ) : ?>
	<p><?php esc_html_e( 'עדיין אין עדכונים.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<ul class="dxa-list dxa-updates">
		<?php foreach ( $view['updates'] as $update ) : ?>
			<li>
				<strong><?php echo esc_html( $update['title'] ); ?></strong>
				<span class="dxa-muted"><?php echo esc_html( Labels::datetime( $update['created_at'] ) ); ?><?php echo $update['visible'] ? '' : ' · ' . esc_html__( 'מוסתר מהלקוח', 'diromax-portal' ); ?></span>
				<?php if ( '' !== $update['body'] ) : ?>
					<p><?php echo nl2br( esc_html( $update['body'] ) ); ?></p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
