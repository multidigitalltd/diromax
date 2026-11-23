<?php
/**
 * Admin tab: meetings, site visits and calls in the case (spec 20.1).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;

defined( 'ABSPATH' ) || exit;

$meetings = (array) $view['meetings'];
$people   = (array) $view['people'];
$coming   = array_values( array_filter( $meetings, static fn( array $meeting ): bool => ! $meeting['past'] && 'scheduled' === $meeting['status'] ) );
$earlier  = array_values( array_filter( $meetings, static fn( array $meeting ): bool => $meeting['past'] || 'scheduled' !== $meeting['status'] ) );

/**
 * The fields of a meeting form, new or existing.
 *
 * @param Form                      $form    Form helper.
 * @param array<string, mixed>|null $meeting Meeting being edited.
 */
$dx_meeting_fields = static function ( Form $form, ?array $meeting ) use ( $view, $people ): void {
	$value   = static fn( string $field, string $fallback ): string => $form->old( $field, $fallback );
	$invited = $form->failed() ? array() : array_map( 'intval', (array) ( $meeting['participants'] ?? array_keys( array_filter( $people, static fn( array $person ): bool => $person['emails'] ) ) ) );
	?>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'title' ) ); ?>"><?php esc_html_e( 'נושא', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'title', (string) ( $meeting['title'] ?? '' ) ) ); ?>" maxlength="120" required placeholder="<?php esc_attr_e( 'לדוגמה: סיור בדירה עם האדריכלית', 'diromax-portal' ); ?>">
		<?php $form->print_error( 'title' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'kind' ) ); ?>"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'kind' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php foreach ( (array) $view['kinds'] as $key => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $key ); ?>"<?php selected( $value( 'kind', (string) ( $meeting['kind'] ?? 'office' ) ), (string) $key ); ?>><?php echo esc_html( (string) $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php $form->print_error( 'kind' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'date' ) ); ?>"><?php esc_html_e( 'תאריך', 'diromax-portal' ); ?></label>
		<input type="date" <?php echo $form->attrs( 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'date', (string) ( $meeting['date'] ?? '' ) ) ); ?>" min="<?php echo esc_attr( (string) $view['today'] ); ?>" required>
		<?php $form->print_error( 'date' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'time' ) ); ?>"><?php esc_html_e( 'שעה', 'diromax-portal' ); ?></label>
		<input type="time" <?php echo $form->attrs( 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'time', (string) ( $meeting['time'] ?? '' ) ) ); ?>" required>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'minutes' ) ); ?>"><?php esc_html_e( 'משך', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'minutes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php foreach ( (array) $view['lengths'] as $length ) : ?>
				<option value="<?php echo esc_attr( (string) $length ); ?>"<?php selected( $value( 'minutes', (string) ( $meeting['minutes'] ?? 60 ) ), (string) $length ); ?>>
					<?php
					/* translators: %d: minutes. */
					echo esc_html( sprintf( __( '%d דקות', 'diromax-portal' ), $length ) );
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php $form->print_error( 'minutes' ); ?>
	</p>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'location' ) ); ?>"><?php esc_html_e( 'מקום או קישור (רשות)', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'location' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'location', (string) ( $meeting['location'] ?? '' ) ) ); ?>" maxlength="200">
		<?php $form->print_error( 'location' ); ?>
	</p>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'note' ) ); ?>"><?php esc_html_e( 'הערה למוזמנים (רשות)', 'diromax-portal' ); ?></label>
		<textarea <?php echo $form->attrs( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="3" maxlength="1000"><?php echo esc_textarea( $value( 'note', (string) ( $meeting['note'] ?? '' ) ) ); ?></textarea>
		<?php $form->print_error( 'note' ); ?>
	</p>
	<fieldset class="dxa-field dxa-field--wide dxa-checks">
		<legend><?php esc_html_e( 'מי מהלקוחות מוזמן', 'diromax-portal' ); ?></legend>
		<?php if ( array() === $people ) : ?>
			<p class="dxa-muted"><?php esc_html_e( 'אין לקוחות בתיק. הפגישה תירשם ביומן של הצוות בלבד.', 'diromax-portal' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $people as $user_id => $person ) : ?>
			<label>
				<input type="checkbox" name="participants[]" value="<?php echo esc_attr( (string) $user_id ); ?>"<?php checked( in_array( (int) $user_id, $invited, true ) ); ?>>
				<?php echo esc_html( (string) $person['name'] ); ?>
				<?php if ( ! $person['emails'] ) : ?>
					<span class="dxa-muted"><?php esc_html_e( '(לא מקבל מיילים: יראה את הפגישה באזור האישי)', 'diromax-portal' ); ?></span>
				<?php endif; ?>
			</label>
		<?php endforeach; ?>
		<?php $form->print_error( 'participants' ); ?>
	</fieldset>
	<p class="dxa-field dxa-field--wide">
		<label><input type="checkbox" name="notify" value="1"<?php checked( ! $form->failed() || '1' === $form->old( 'notify' ) ); ?>> <?php echo esc_html( null === $meeting ? __( 'לשלוח למוזמנים הזמנה ליומן במייל', 'diromax-portal' ) : __( 'לשלוח למוזמנים את העדכון ליומן במייל', 'diromax-portal' ) ); ?></label>
	</p>
	<?php
};

$form = new Form( $view['flash'], 'meeting' );
?>
<?php if ( $view['closed'] ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'התיק סגור, ולכן אי אפשר לקבוע בו פגישות חדשות.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<details class="dxa-box dxa-box--add" id="form-meeting"<?php echo array() === $coming || $form->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
		<summary><h2><?php esc_html_e( 'פגישה חדשה', 'diromax-portal' ); ?></h2></summary>
		<p class="description"><?php esc_html_e( 'הלקוחות שמסמנים מקבלים הזמנה ליומן (Google, Outlook, iPhone), רואים את הפגישה באזור האישי, ומקבלים תזכורת יום לפני ובבוקר של יום הפגישה.', 'diromax-portal' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
			<input type="hidden" name="action" value="dx_portal_add_meeting">
			<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
			<?php wp_nonce_field( 'dx_portal_add_meeting_' . $case->id ); ?>
			<?php $dx_meeting_fields( $form, null ); ?>
			<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'קביעת הפגישה', 'diromax-portal' ); ?></button></p>
		</form>
	</details>
<?php endif; ?>

<h2><?php esc_html_e( 'פגישות קרובות', 'diromax-portal' ); ?></h2>
<?php if ( array() === $coming ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'אין פגישות קרובות בתיק.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<ul class="dxa-meetings">
		<?php foreach ( $coming as $meeting ) : ?>
			<?php $edit = new Form( $view['flash'], 'meeting' . $meeting['id'] ); ?>
			<li class="dxa-meeting" id="dxa-meeting-<?php echo esc_attr( (string) $meeting['id'] ); ?>">
				<p class="dxa-meeting__when"><strong><?php echo esc_html( (string) $meeting['when'] ); ?></strong> · <?php echo esc_html( (string) $meeting['kind_name'] ); ?></p>
				<h3 class="dxa-meeting__title"><?php echo esc_html( (string) $meeting['title'] ); ?></h3>
				<?php if ( '' !== $meeting['location'] ) : ?>
					<p><?php echo esc_html( (string) $meeting['location'] ); ?></p>
				<?php endif; ?>
				<p class="dxa-muted">
					<?php
					echo esc_html(
						array() === $meeting['names']
							? __( 'לא הוזמנו לקוחות.', 'diromax-portal' )
							/* translators: %s: names of invited clients. */
							: sprintf( __( 'מוזמנים: %s', 'diromax-portal' ), implode( ', ', $meeting['names'] ) )
					);
					?>
				</p>
				<?php if ( '' !== $meeting['note'] ) : ?>
					<p class="dxa-meeting__note"><?php echo nl2br( esc_html( (string) $meeting['note'] ) ); ?></p>
				<?php endif; ?>
				<details class="dxa-box" id="form-meeting<?php echo esc_attr( (string) $meeting['id'] ); ?>"<?php echo $edit->failed() ? ' open' : ''; ?>>
					<summary>
						<?php esc_html_e( 'עריכה', 'diromax-portal' ); ?>
						<span class="screen-reader-text"><?php echo esc_html( ': ' . $meeting['title'] ); ?></span>
					</summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
						<input type="hidden" name="action" value="dx_portal_update_meeting">
						<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
						<input type="hidden" name="meeting_id" value="<?php echo esc_attr( (string) $meeting['id'] ); ?>">
						<?php wp_nonce_field( 'dx_portal_update_meeting_' . $case->id ); ?>
						<?php $dx_meeting_fields( $edit, $meeting ); ?>
						<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת השינויים', 'diromax-portal' ); ?></button></p>
					</form>
				</details>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
					<input type="hidden" name="action" value="dx_portal_cancel_meeting">
					<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
					<input type="hidden" name="meeting_id" value="<?php echo esc_attr( (string) $meeting['id'] ); ?>">
					<?php wp_nonce_field( 'dx_portal_cancel_meeting_' . $case->id ); ?>
					<label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'להודיע למוזמנים', 'diromax-portal' ); ?></label>
					<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'לבטל את הפגישה?', 'diromax-portal' ); ?>">
						<?php esc_html_e( 'ביטול הפגישה', 'diromax-portal' ); ?>
						<span class="screen-reader-text"><?php echo esc_html( ': ' . $meeting['title'] ); ?></span>
					</button>
				</form>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( array() !== $earlier ) : ?>
	<h2><?php esc_html_e( 'פגישות שהיו או בוטלו', 'diromax-portal' ); ?></h2>
	<ul class="dxa-meetings dxa-meetings--past">
		<?php foreach ( $earlier as $meeting ) : ?>
			<li>
				<span><?php echo esc_html( (string) $meeting['when'] ); ?></span> ·
				<strong><?php echo esc_html( (string) $meeting['title'] ); ?></strong>
				<?php if ( 'cancelled' === $meeting['status'] ) : ?>
					<span class="dxa-tag-cancelled"><?php esc_html_e( 'בוטלה', 'diromax-portal' ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
