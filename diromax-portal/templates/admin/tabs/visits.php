<?php
/**
 * Admin tab: site visits and the findings noted on them (spec 23).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;

defined( 'ABSPATH' ) || exit;

$visits = (array) $view['visits'];
$counts = (array) $view['totals'];
$closed = (bool) $view['closed'];
$adding = new Form( $view['flash'], 'visit' );

/**
 * Hidden fields every visit form carries.
 *
 * @param string $action  Action key, without the prefix.
 * @param int    $case_id Case.
 * @param string $key     visit_id or finding_id.
 * @param int    $id      Its ID, 0 for none.
 */
$dx_visit_hidden = static function ( string $action, int $case_id, string $key = '', int $id = 0 ): void {
	?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'dx_portal_' . $action ); ?>">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>">
	<?php if ( 0 !== $id ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $id ); ?>">
	<?php endif; ?>
	<?php
	wp_nonce_field( 'dx_portal_' . $action . '_' . $case_id );
};

/**
 * The fields of a visit, new or existing.
 *
 * @param Form                      $form  Form helper.
 * @param array<string, mixed>|null $visit Visit being edited.
 * @param string                    $today Y-m-d.
 */
$dx_visit_fields = static function ( Form $form, ?array $visit, string $today ): void {
	?>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'visit_on' ) ); ?>"><?php esc_html_e( 'תאריך הביקור', 'diromax-portal' ); ?></label>
		<input type="date" <?php echo $form->attrs( 'visit_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'visit_on', (string) ( $visit['visit_on'] ?? $today ) ) ); ?>" max="<?php echo esc_attr( $today ); ?>" required>
		<?php $form->print_error( 'visit_on' ); ?>
	</p>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'attendees' ) ); ?>"><?php esc_html_e( 'מי היה (רשות)', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'attendees' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'attendees', (string) ( $visit['attendees'] ?? '' ) ) ); ?>" maxlength="300" placeholder="<?php esc_attr_e( 'לדוגמה: מנהל הפרויקט, קבלן השלד, המפקח', 'diromax-portal' ); ?>">
		<?php $form->print_error( 'attendees' ); ?>
	</p>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'summary' ) ); ?>"><?php esc_html_e( 'סיכום הביקור', 'diromax-portal' ); ?></label>
		<textarea <?php echo $form->attrs( 'summary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="4" maxlength="3000" required><?php echo esc_textarea( $form->old( 'summary', (string) ( $visit['summary'] ?? '' ) ) ); ?></textarea>
		<?php $form->print_error( 'summary' ); ?>
	</p>
	<p class="dxa-field dxa-field--wide">
		<label><input type="checkbox" name="visible" value="1"<?php checked( $form->failed() ? '1' === $form->old( 'visible' ) : (bool) ( $visit['visible'] ?? true ) ); ?>> <?php esc_html_e( 'הלקוח רואה את הביקור', 'diromax-portal' ); ?></label>
	</p>
	<p class="dxa-field dxa-field--wide">
		<label><input type="checkbox" name="notify" value="1"<?php checked( $form->failed() && '1' === $form->old( 'notify' ) ); ?>> <?php esc_html_e( 'לשלוח ללקוח את הדוח במייל (כשהוא רואה אותו)', 'diromax-portal' ); ?></label>
	</p>
	<?php
};

/**
 * The fields of a finding, new or existing.
 *
 * @param Form                      $form       Form helper.
 * @param array<string, mixed>|null $finding    Finding being edited.
 * @param array<string, string>     $severities Severity names.
 */
$dx_finding_fields = static function ( Form $form, ?array $finding, array $severities ): void {
	?>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'title' ) ); ?>"><?php esc_html_e( 'מה נמצא', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'title', (string) ( $finding['title'] ?? '' ) ) ); ?>" maxlength="160" required placeholder="<?php esc_attr_e( 'לדוגמה: סדק בקיר המזרחי', 'diromax-portal' ); ?>">
		<?php $form->print_error( 'title' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'location' ) ); ?>"><?php esc_html_e( 'איפה (רשות)', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'location' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'location', (string) ( $finding['location'] ?? '' ) ) ); ?>" maxlength="120">
		<?php $form->print_error( 'location' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'severity' ) ); ?>"><?php esc_html_e( 'חומרה', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'severity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php foreach ( $severities as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $form->old( 'severity', (string) ( $finding['severity'] ?? 'medium' ) ), $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php $form->print_error( 'severity' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'supplier' ) ); ?>"><?php esc_html_e( 'מי מתקן (רשות)', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'supplier' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'supplier', (string) ( $finding['supplier'] ?? '' ) ) ); ?>" maxlength="120">
		<?php $form->print_error( 'supplier' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'due_on' ) ); ?>"><?php esc_html_e( 'לתקן עד (רשות)', 'diromax-portal' ); ?></label>
		<input type="date" <?php echo $form->attrs( 'due_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'due_on', (string) ( $finding['due_on'] ?? '' ) ) ); ?>">
		<?php $form->print_error( 'due_on' ); ?>
	</p>
	<p class="dxa-field dxa-field--wide">
		<label><input type="checkbox" name="visible" value="1"<?php checked( $form->failed() ? '1' === $form->old( 'visible' ) : (bool) ( $finding['visible'] ?? true ) ); ?>> <?php esc_html_e( 'הלקוח רואה את הממצא', 'diromax-portal' ); ?></label>
	</p>
	<?php
};
?>
<p class="description"><?php esc_html_e( 'דוח לכל ביקור באתר, עם הממצאים: מה נמצא, איפה, מי מתקן ועד מתי. ממצא שעבר את מועד התיקון מסומן, ומופיע בלוח הבקרה ובסיכום הבוקר. הלקוח רואה ביקורים וממצאים שמסומנים "הלקוח רואה".', 'diromax-portal' ); ?></p>
<ul class="dxa-visit-counts">
	<li>
		<?php
		/* translators: %d: number of open findings. */
		echo esc_html( sprintf( __( 'פתוחים: %d', 'diromax-portal' ), (int) $counts['open'] ) );
		?>
	</li>
	<li class="<?php echo (int) $counts['overdue'] > 0 ? 'dxa-late' : ''; ?>">
		<?php
		/* translators: %d: number of overdue findings. */
		echo esc_html( sprintf( __( 'עברו את המועד: %d', 'diromax-portal' ), (int) $counts['overdue'] ) );
		?>
	</li>
	<li>
		<?php
		/* translators: %d: number of fixed findings. */
		echo esc_html( sprintf( __( 'תוקנו: %d', 'diromax-portal' ), (int) $counts['fixed'] ) );
		?>
	</li>
</ul>

<?php if ( ! $closed ) : ?>
	<details class="dxa-box dxa-box--add" id="form-visit"<?php echo array() === $visits || $adding->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
		<summary><h2><?php esc_html_e( 'ביקור חדש', 'diromax-portal' ); ?></h2></summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
			<?php $dx_visit_hidden( 'add_visit', $case->id ); ?>
			<?php $dx_visit_fields( $adding, null, (string) $view['today'] ); ?>
			<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת הביקור', 'diromax-portal' ); ?></button></p>
		</form>
	</details>
<?php else : ?>
	<p class="dxa-muted"><?php esc_html_e( 'התיק סגור, ולכן אי אפשר לשנות את הביקורים.', 'diromax-portal' ); ?></p>
<?php endif; ?>

<?php if ( array() === $visits ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'עדיין אין ביקורים בתיק.', 'diromax-portal' ); ?></p>
<?php endif; ?>

<?php foreach ( $visits as $visit ) : ?>
	<?php
	$visit_id   = (int) $visit['id'];
	$edit_visit = new Form( $view['flash'], 'visit' . $visit_id );
	$add_find   = new Form( $view['flash'], 'finding' . $visit_id );
	?>
	<section class="dxa-box dxa-visit" id="dxa-visit-<?php echo esc_attr( (string) $visit_id ); ?>" aria-labelledby="dxa-visit-title-<?php echo esc_attr( (string) $visit_id ); ?>">
		<h2 id="dxa-visit-title-<?php echo esc_attr( (string) $visit_id ); ?>">
			<?php
			/* translators: %s: date. */
			echo esc_html( sprintf( __( 'ביקור מ-%s', 'diromax-portal' ), $visit['date'] ) );
			?>
			<?php if ( ! $visit['visible'] ) : ?>
				<span class="dxa-plan-state"><?php esc_html_e( 'פנימי', 'diromax-portal' ); ?></span>
			<?php endif; ?>
		</h2>
		<?php if ( '' !== $visit['attendees'] ) : ?>
			<p class="dxa-muted"><?php echo esc_html( (string) $visit['attendees'] ); ?></p>
		<?php endif; ?>
		<p class="dxa-visit__summary"><?php echo nl2br( esc_html( (string) $visit['summary'] ) ); ?></p>

		<?php if ( array() !== $visit['findings'] ) : ?>
			<ul class="dxa-findings">
				<?php foreach ( (array) $visit['findings'] as $finding ) : ?>
					<?php
					$finding_id = (int) $finding['id'];
					$edit_find  = new Form( $view['flash'], 'edit' . $finding_id );
					$fix_form   = new Form( $view['flash'], 'fix' . $finding_id );
					?>
					<li class="dxa-finding dxa-finding--<?php echo esc_attr( (string) $finding['status'] ); ?> dxa-finding--<?php echo esc_attr( (string) $finding['severity'] ); ?>">
						<div class="dxa-finding__head">
							<h3><?php echo esc_html( (string) $finding['title'] ); ?></h3>
							<span class="dxa-plan-state dxa-plan-state--<?php echo 'fixed' === $finding['status'] ? 'paid' : 'draft'; ?>"><?php echo esc_html( 'fixed' === $finding['status'] ? sprintf( /* translators: %s: date. */ __( 'תוקן ב-%s', 'diromax-portal' ), $finding['fixed_label'] ) : __( 'פתוח', 'diromax-portal' ) ); ?></span>
							<?php if ( $finding['overdue'] ) : ?>
								<span class="dxa-late"><?php esc_html_e( 'עבר את מועד התיקון', 'diromax-portal' ); ?></span>
							<?php endif; ?>
							<?php if ( ! $finding['visible'] ) : ?>
								<span class="dxa-plan-state"><?php esc_html_e( 'פנימי', 'diromax-portal' ); ?></span>
							<?php endif; ?>
						</div>
						<p class="dxa-muted">
							<?php
							echo esc_html(
								implode(
									' · ',
									array_filter(
										array(
											(string) $finding['severity_label'],
											(string) $finding['location'],
											'' !== $finding['supplier'] ? sprintf( /* translators: %s: supplier. */ __( 'מתקן: %s', 'diromax-portal' ), $finding['supplier'] ) : '',
											'' !== $finding['due_label'] ? sprintf( /* translators: %s: date. */ __( 'עד %s', 'diromax-portal' ), $finding['due_label'] ) : '',
										)
									)
								)
							);
							?>
						</p>
						<?php if ( null !== $finding['photo'] || null !== $finding['after'] ) : ?>
							<p class="dxa-finding__photos">
								<?php if ( null !== $finding['photo'] ) : ?>
									<a href="<?php echo esc_url( (string) $finding['photo']['full'] ); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( (string) $finding['photo']['thumb'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: finding. */ __( 'צילום: %s', 'diromax-portal' ), $finding['title'] ) ); ?>" width="120" height="90" loading="lazy" decoding="async"></a>
								<?php endif; ?>
								<?php if ( null !== $finding['after'] ) : ?>
									<a href="<?php echo esc_url( (string) $finding['after']['full'] ); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( (string) $finding['after']['thumb'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: finding. */ __( 'אחרי התיקון: %s', 'diromax-portal' ), $finding['title'] ) ); ?>" width="120" height="90" loading="lazy" decoding="async"></a>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<?php if ( '' !== $finding['fix_note'] ) : ?>
							<p><?php echo esc_html( (string) $finding['fix_note'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! $closed ) : ?>
							<?php if ( 'open' === $finding['status'] ) : ?>
								<details class="dxa-box" id="form-fix<?php echo esc_attr( (string) $finding_id ); ?>"<?php echo $fix_form->failed() ? ' open' : ''; ?>>
									<summary>
										<?php esc_html_e( 'סימון כתוקן', 'diromax-portal' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( ': ' . $finding['title'] ); ?></span>
									</summary>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry" enctype="multipart/form-data">
										<?php $dx_visit_hidden( 'fix_finding', $case->id, 'finding_id', $finding_id ); ?>
										<p class="dxa-field dxa-field--wide">
											<label for="<?php echo esc_attr( $fix_form->id( 'fix_note' ) ); ?>"><?php esc_html_e( 'מה נעשה (רשות)', 'diromax-portal' ); ?></label>
											<input type="text" <?php echo $fix_form->attrs( 'fix_note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $fix_form->old( 'fix_note', '' ) ); ?>" maxlength="300">
											<?php $fix_form->print_error( 'fix_note' ); ?>
										</p>
										<?php if ( $view['key_ready'] ) : ?>
											<p class="dxa-field dxa-field--wide">
												<label for="<?php echo esc_attr( $fix_form->id( 'after_photo' ) ); ?>"><?php esc_html_e( 'צילום אחרי התיקון (רשות)', 'diromax-portal' ); ?></label>
												<input type="file" id="<?php echo esc_attr( $fix_form->id( 'after_photo' ) ); ?>" name="after_photo" accept="image/jpeg,image/png,image/webp" capture="environment">
												<?php $fix_form->print_error( 'photo' ); ?>
											</p>
										<?php endif; ?>
										<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'תוקן', 'diromax-portal' ); ?></button></p>
									</form>
								</details>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
									<?php $dx_visit_hidden( 'reopen_finding', $case->id, 'finding_id', $finding_id ); ?>
									<button type="submit" class="button">
										<?php esc_html_e( 'פתיחה מחדש', 'diromax-portal' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( ': ' . $finding['title'] ); ?></span>
									</button>
								</form>
							<?php endif; ?>
							<details class="dxa-box" id="form-edit<?php echo esc_attr( (string) $finding_id ); ?>"<?php echo $edit_find->failed() ? ' open' : ''; ?>>
								<summary>
									<?php esc_html_e( 'עריכה', 'diromax-portal' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( ': ' . $finding['title'] ); ?></span>
								</summary>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
									<?php $dx_visit_hidden( 'update_finding', $case->id, 'finding_id', $finding_id ); ?>
									<?php $dx_finding_fields( $edit_find, $finding, (array) $view['severities'] ); ?>
									<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת הממצא', 'diromax-portal' ); ?></button></p>
								</form>
							</details>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
								<?php $dx_visit_hidden( 'delete_finding', $case->id, 'finding_id', $finding_id ); ?>
								<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'למחוק את הממצא? הצילומים שלו נשארים ביומן הצילומים.', 'diromax-portal' ); ?>">
									<?php esc_html_e( 'מחיקה', 'diromax-portal' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( ': ' . $finding['title'] ); ?></span>
								</button>
							</form>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! $closed ) : ?>
			<details class="dxa-box dxa-box--add" id="form-finding<?php echo esc_attr( (string) $visit_id ); ?>"<?php echo $add_find->failed() ? ' open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'ממצא חדש', 'diromax-portal' ); ?>
					<span class="screen-reader-text">
						<?php
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'בביקור מ-%s', 'diromax-portal' ), $visit['date'] ) );
						?>
					</span>
				</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry" enctype="multipart/form-data">
					<?php $dx_visit_hidden( 'add_finding', $case->id, 'visit_id', $visit_id ); ?>
					<?php $dx_finding_fields( $add_find, null, (array) $view['severities'] ); ?>
					<?php if ( $view['key_ready'] ) : ?>
						<p class="dxa-field dxa-field--wide">
							<label for="<?php echo esc_attr( $add_find->id( 'photo' ) ); ?>"><?php esc_html_e( 'צילום (רשות)', 'diromax-portal' ); ?></label>
							<input type="file" id="<?php echo esc_attr( $add_find->id( 'photo' ) ); ?>" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment"<?php echo '' !== $add_find->error( 'photo' ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $add_find->id( 'photo' ) . '-error' ) . '"' : ''; ?>>
							<?php $add_find->print_error( 'photo' ); ?>
						</p>
					<?php endif; ?>
					<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'הוספת הממצא', 'diromax-portal' ); ?></button></p>
				</form>
			</details>
			<details class="dxa-box" id="form-visit<?php echo esc_attr( (string) $visit_id ); ?>"<?php echo $edit_visit->failed() ? ' open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'עריכת הביקור', 'diromax-portal' ); ?>
					<span class="screen-reader-text"><?php echo esc_html( ': ' . $visit['date'] ); ?></span>
				</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
					<?php $dx_visit_hidden( 'update_visit', $case->id, 'visit_id', $visit_id ); ?>
					<?php $dx_visit_fields( $edit_visit, $visit, (string) $view['today'] ); ?>
					<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת הביקור', 'diromax-portal' ); ?></button></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
					<?php $dx_visit_hidden( 'delete_visit', $case->id, 'visit_id', $visit_id ); ?>
					<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'למחוק את הביקור ואת כל הממצאים שלו?', 'diromax-portal' ); ?>">
						<?php esc_html_e( 'מחיקת הביקור', 'diromax-portal' ); ?>
					</button>
				</form>
			</details>
		<?php endif; ?>
	</section>
<?php endforeach; ?>
