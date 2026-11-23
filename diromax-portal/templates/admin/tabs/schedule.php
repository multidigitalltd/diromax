<?php
/**
 * Admin tab: the case's timeline (spec 22).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;

defined( 'ABSPATH' ) || exit;

$rows    = (array) $view['rows'];
$layout  = $view['layout'];
$closed  = (bool) $view['closed'];
$form    = new Form( $view['flash'], 'schedule' );
$compute = new Form( $view['flash'], 'compute' );
$adding  = new Form( $view['flash'], 'milestone' );

/**
 * Hidden fields every timeline form carries.
 *
 * @param string $action  Action key, without the prefix.
 * @param int    $case_id Case.
 * @param int    $id      Milestone, 0 for none.
 */
$dx_schedule_hidden = static function ( string $action, int $case_id, int $id = 0 ): void {
	?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'dx_portal_' . $action ); ?>">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>">
	<?php if ( 0 !== $id ) : ?>
		<input type="hidden" name="milestone_id" value="<?php echo esc_attr( (string) $id ); ?>">
	<?php endif; ?>
	<?php
	wp_nonce_field( 'dx_portal_' . $action . '_' . $case_id );
};

/**
 * The fields of a milestone, new or existing.
 *
 * @param Form                      $form      Form helper.
 * @param array<string, mixed>|null $milestone Milestone being edited.
 */
$dx_milestone_fields = static function ( Form $form, ?array $milestone ) use ( $view ): void {
	?>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'label' ) ); ?>"><?php esc_html_e( 'אבן הדרך', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'label' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'label', (string) ( $milestone['label'] ?? '' ) ) ); ?>" maxlength="120" required placeholder="<?php esc_attr_e( 'לדוגמה: קבלת היתר בנייה', 'diromax-portal' ); ?>">
		<?php $form->print_error( 'label' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'stage' ) ); ?>"><?php esc_html_e( 'בשלב', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'stage' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php foreach ( (array) $view['stages'] as $number => $name ) : ?>
				<option value="<?php echo esc_attr( (string) $number ); ?>"<?php selected( $form->old( 'stage', (string) ( $milestone['stage'] ?? 1 ) ), (string) $number ); ?>><?php echo esc_html( $number . '. ' . $name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php $form->print_error( 'stage' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'date' ) ); ?>"><?php esc_html_e( 'תאריך', 'diromax-portal' ); ?></label>
		<input type="date" <?php echo $form->attrs( 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'date', (string) ( $milestone['date'] ?? '' ) ) ); ?>" required>
		<?php $form->print_error( 'date' ); ?>
	</p>
	<?php if ( null !== $milestone && $view['visible'] ) : ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'note' ) ); ?>"><?php esc_html_e( 'אם התאריך זז: למה (הלקוח יראה)', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $form->attrs( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'note', '' ) ); ?>" maxlength="300">
		</p>
	<?php endif; ?>
	<?php
};
?>
<p class="description"><?php esc_html_e( 'מתי כל שלב מתוכנן להתחיל ולהסתיים, ואבני הדרך בדרך. הלקוח רואה את הלוח רק אחרי שמסמנים "להציג ללקוח". מאז, כשתאריך זז, הלקוח רואה את התאריך החדש, את התאריך המקורי, ואת ההסבר שכותבים כאן.', 'diromax-portal' ); ?></p>
<p class="dxa-plan-sum__status">
	<span class="dxa-plan-state dxa-plan-state--<?php echo $view['visible'] ? 'sent' : 'planned'; ?>"><?php echo esc_html( $view['visible'] ? __( 'מוצג ללקוח', 'diromax-portal' ) : __( 'עדיין לא מוצג ללקוח', 'diromax-portal' ) ); ?></span>
</p>

<?php if ( null !== $layout ) : ?>
	<?php
	$dx_gantt_prefix = 'dxa';
	require __DIR__ . '/../../portal/parts/gantt.php';
	?>
<?php else : ?>
	<p class="dxa-muted"><?php esc_html_e( 'עדיין אין תאריכים בלוח. ממלאים תאריכים לשלבים למטה, או מחשבים אותם לפי משכי השלבים.', 'diromax-portal' ); ?></p>
<?php endif; ?>

<?php if ( ! $closed ) : ?>
	<?php if ( $view['weeks'] ) : ?>
		<details class="dxa-box" id="form-compute"<?php echo $compute->failed() || null === $layout ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'חישוב תאריכים לפי משכי השלבים', 'diromax-portal' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
				<?php $dx_schedule_hidden( 'compute_schedule', $case->id ); ?>
				<p class="dxa-field">
					<label for="<?php echo esc_attr( $compute->id( 'start_on' ) ); ?>"><?php esc_html_e( 'תאריך התחלה', 'diromax-portal' ); ?></label>
					<input type="date" <?php echo $compute->attrs( 'start_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $compute->old( 'start_on', (string) $view['today'] ) ); ?>" required>
					<?php $compute->print_error( 'start_on' ); ?>
				</p>
				<p class="description dxa-field--wide"><?php esc_html_e( 'השלבים מקבלים תאריכים לפי המשכים שבהגדרות, שלב אחרי שלב. התאריכים מחליפים את מה שכתוב בטבלה למטה, ולא נשלח שום מייל.', 'diromax-portal' ); ?></p>
				<p class="submit dxa-field--wide"><button type="submit" class="button"><?php esc_html_e( 'חישוב', 'diromax-portal' ); ?></button></p>
			</form>
		</details>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="form-schedule" class="dxa-schedule-form">
		<?php $dx_schedule_hidden( 'save_schedule', $case->id ); ?>
		<div class="dxa-scroll"><table class="widefat dxa-table dxa-schedule">
			<caption class="screen-reader-text"><?php esc_html_e( 'תאריכים מתוכננים לכל שלב', 'diromax-portal' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'שלב', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'התחלה מתוכננת', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'סיום מתוכנן (יעד)', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'בפועל', 'diromax-portal' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php $number = (int) $row['number']; ?>
				<tr>
					<th scope="row">
						<?php echo esc_html( $number . '. ' . $row['label'] ); ?>
						<?php if ( $row['late'] ) : ?>
							<span class="dxa-late"><?php esc_html_e( 'עבר את היעד', 'diromax-portal' ); ?></span>
						<?php endif; ?>
					</th>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $form->id( 'start-' . $number ) ); ?>">
							<?php
							/* translators: %s: stage name. */
							echo esc_html( sprintf( __( 'התחלה מתוכננת: %s', 'diromax-portal' ), $row['label'] ) );
							?>
						</label>
						<input type="date" id="<?php echo esc_attr( $form->id( 'start-' . $number ) ); ?>" name="<?php echo esc_attr( 'start[' . $number . ']' ); ?>" value="<?php echo esc_attr( $form->old( 'start-' . $number, (string) $row['start'] ) ); ?>"<?php echo '' !== $form->error( 'start-' . $number ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $form->id( 'start-' . $number ) . '-error' ) . '"' : ''; ?>>
						<?php $form->print_error( 'start-' . $number ); ?>
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $form->id( 'end-' . $number ) ); ?>">
							<?php
							/* translators: %s: stage name. */
							echo esc_html( sprintf( __( 'סיום מתוכנן: %s', 'diromax-portal' ), $row['label'] ) );
							?>
						</label>
						<input type="date" id="<?php echo esc_attr( $form->id( 'end-' . $number ) ); ?>" name="<?php echo esc_attr( 'end[' . $number . ']' ); ?>" value="<?php echo esc_attr( $form->old( 'end-' . $number, (string) $row['end'] ) ); ?>"<?php echo '' !== $form->error( 'end-' . $number ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $form->id( 'end-' . $number ) . '-error' ) . '"' : ''; ?>>
						<?php $form->print_error( 'end-' . $number ); ?>
						<?php if ( $row['moved'] ) : ?>
							<span class="dxa-muted">
								<?php
								/* translators: %s: date first shown to the client. */
								echo esc_html( sprintf( __( 'במקור: %s', 'diromax-portal' ), $row['first_label'] ) );
								?>
							</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( '' !== $row['actual'] ? (string) $row['actual'] : (string) $row['status_label'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'note' ) ); ?>"><?php esc_html_e( 'אם תאריכים זזים: למה (הלקוח יראה את ההסבר ליד התאריכים שזזו)', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $form->attrs( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'note', '' ) ); ?>" maxlength="300" placeholder="<?php esc_attr_e( 'לדוגמה: הוועדה המקומית דחתה את הדיון בחודש', 'diromax-portal' ); ?>">
		</p>
		<p class="dxa-field dxa-field--wide">
			<label><input type="checkbox" name="visible" value="1"<?php checked( $form->failed() ? '1' === $form->old( 'visible' ) : (bool) $view['visible'] ); ?>> <?php esc_html_e( 'להציג את לוח הזמנים ללקוח באזור האישי', 'diromax-portal' ); ?></label>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label><input type="checkbox" name="notify" value="1"<?php checked( $form->failed() && '1' === $form->old( 'notify' ) ); ?>> <?php esc_html_e( 'לשלוח ללקוח מייל על השינוי (רק כשהלוח מוצג לו)', 'diromax-portal' ); ?></label>
		</p>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת לוח הזמנים', 'diromax-portal' ); ?></button></p>
	</form>
<?php endif; ?>

<h2 id="dxa-milestones"><?php esc_html_e( 'אבני דרך', 'diromax-portal' ); ?></h2>
<?php
$milestones = array();

foreach ( $rows as $row ) {
	foreach ( (array) $row['milestones'] as $milestone ) {
		$milestones[] = $milestone + array( 'stage_label' => $row['label'] );
	}
}
?>
<?php if ( array() === $milestones ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'אין עדיין אבני דרך. אבן דרך היא אירוע עם תאריך בתוך שלב: היתר, יציקה, טופס 4.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<ul class="dxa-meetings dxa-milestones">
		<?php foreach ( $milestones as $milestone ) : ?>
			<?php $edit = new Form( $view['flash'], 'milestone' . $milestone['id'] ); ?>
			<li class="dxa-meeting<?php echo $milestone['done'] ? ' dxa-milestone--done' : ''; ?>">
				<p class="dxa-meeting__when">
					<strong><?php echo esc_html( (string) $milestone['end_label'] ); ?></strong> · <?php echo esc_html( (string) $milestone['stage_label'] ); ?>
					<?php if ( $milestone['done'] ) : ?>
						<span class="dxa-plan-state dxa-plan-state--paid">
							<?php
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'הושגה ב-%s', 'diromax-portal' ), $milestone['done_label'] ) );
							?>
						</span>
					<?php elseif ( $milestone['late'] ) : ?>
						<span class="dxa-late"><?php esc_html_e( 'עבר את התאריך', 'diromax-portal' ); ?></span>
					<?php endif; ?>
				</p>
				<h3 class="dxa-meeting__title"><?php echo esc_html( (string) $milestone['label'] ); ?></h3>
				<?php if ( $milestone['moved'] ) : ?>
					<p class="dxa-muted">
						<?php
						/* translators: 1: date first shown, 2: note. */
						echo esc_html( trim( sprintf( __( 'במקור: %1$s. %2$s', 'diromax-portal' ), $milestone['first_label'], $milestone['note'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( ! $closed ) : ?>
					<details class="dxa-box" id="form-milestone<?php echo esc_attr( (string) $milestone['id'] ); ?>"<?php echo $edit->failed() ? ' open' : ''; ?>>
						<summary>
							<?php esc_html_e( 'עריכה', 'diromax-portal' ); ?>
							<span class="screen-reader-text"><?php echo esc_html( ': ' . $milestone['label'] ); ?></span>
						</summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
							<?php $dx_schedule_hidden( 'update_milestone', $case->id, (int) $milestone['id'] ); ?>
							<?php $dx_milestone_fields( $edit, $milestone ); ?>
							<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת אבן הדרך', 'diromax-portal' ); ?></button></p>
						</form>
					</details>
					<div class="dxa-inline-group">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
							<?php $dx_schedule_hidden( 'milestone_done', $case->id, (int) $milestone['id'] ); ?>
							<input type="hidden" name="done" value="<?php echo $milestone['done'] ? '0' : '1'; ?>">
							<button type="submit" class="button">
								<?php echo esc_html( $milestone['done'] ? __( 'לא הושגה', 'diromax-portal' ) : __( 'הושגה היום', 'diromax-portal' ) ); ?>
								<span class="screen-reader-text"><?php echo esc_html( ': ' . $milestone['label'] ); ?></span>
							</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
							<?php $dx_schedule_hidden( 'delete_milestone', $case->id, (int) $milestone['id'] ); ?>
							<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'למחוק את אבן הדרך?', 'diromax-portal' ); ?>">
								<?php esc_html_e( 'מחיקה', 'diromax-portal' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( ': ' . $milestone['label'] ); ?></span>
							</button>
						</form>
					</div>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( ! $closed ) : ?>
	<details class="dxa-box dxa-box--add" id="form-milestone"<?php echo $adding->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?>>
		<summary><h2><?php esc_html_e( 'אבן דרך חדשה', 'diromax-portal' ); ?></h2></summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
			<?php $dx_schedule_hidden( 'add_milestone', $case->id ); ?>
			<?php $dx_milestone_fields( $adding, null ); ?>
			<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'הוספת אבן הדרך', 'diromax-portal' ); ?></button></p>
		</form>
	</details>
<?php else : ?>
	<p class="dxa-muted"><?php esc_html_e( 'התיק סגור, ולכן אי אפשר לשנות את לוח הזמנים.', 'diromax-portal' ); ?></p>
<?php endif; ?>
