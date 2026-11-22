<?php
/**
 * Admin tab: the team's tasks in this case (spec 16.2). Internal: clients
 * never see them.
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

$form      = new Form( $view['flash'], 'task' );
$tasks     = (array) $view['tasks'];
$assignees = (array) $view['assignees'];
$today     = (string) $view['today'];
$open      = array_values( array_filter( $tasks, static fn( array $task ): bool => null === $task['done_at'] ) );
$done      = array_values( array_filter( $tasks, static fn( array $task ): bool => null !== $task['done_at'] ) );
$who       = static fn( int $user_id ): string => (string) ( $assignees[ $user_id ] ?? ( get_userdata( $user_id )->display_name ?? '' ) );
$task_form = static function ( string $action, array $task, string $label, string $css ) use ( $case ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
		<input type="hidden" name="action" value="dx_portal_<?php echo esc_attr( $action ); ?>">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<input type="hidden" name="task_id" value="<?php echo esc_attr( (string) $task['id'] ); ?>">
		<?php if ( 'task_done' === $action ) : ?>
			<input type="hidden" name="done" value="<?php echo null === $task['done_at'] ? '1' : '0'; ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'dx_portal_' . $action . '_' . $case->id ); ?>
		<button type="submit" class="<?php echo esc_attr( $css ); ?>">
			<?php echo esc_html( $label ); ?>
			<span class="screen-reader-text"><?php echo esc_html( ': ' . $task['title'] ); ?></span>
		</button>
	</form>
	<?php
};
?>
<details class="dxa-box dxa-box--add" id="form-task"<?php echo array() === $tasks || $form->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
	<summary><h2><?php esc_html_e( 'משימה חדשה', 'diromax-portal' ); ?></h2></summary>
	<p class="description"><?php esc_html_e( 'משימות פנימיות לצוות. הלקוח לא רואה אותן. משימה שהגיע מועדה מופיעה בלוח הבקרה ובסיכום הבוקר של מי שאחראי עליה.', 'diromax-portal' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dx_portal_add_task">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_add_task_' . $case->id ); ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'title' ) ); ?>"><?php esc_html_e( 'מה צריך לעשות', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $form->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'title' ) ); ?>" maxlength="200" required placeholder="<?php esc_attr_e( 'לדוגמה: לתאם מדידה עם המודד', 'diromax-portal' ); ?>">
			<?php $form->print_error( 'title' ); ?>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'assignee_id' ) ); ?>"><?php esc_html_e( 'אחראי', 'diromax-portal' ); ?></label>
			<select <?php echo $form->attrs( 'assignee_id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
				<?php foreach ( $assignees as $user_id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $user_id ); ?>"<?php selected( $form->old( 'assignee_id', (string) get_current_user_id() ), (string) $user_id ); ?>><?php echo esc_html( (string) $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php $form->print_error( 'assignee_id' ); ?>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'due_date' ) ); ?>"><?php esc_html_e( 'עד תאריך (רשות)', 'diromax-portal' ); ?></label>
			<input type="date" <?php echo $form->attrs( 'due_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'due_date' ) ); ?>">
			<?php $form->print_error( 'due_date' ); ?>
		</p>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'הוספת המשימה', 'diromax-portal' ); ?></button></p>
	</form>
</details>

<h2><?php esc_html_e( 'משימות פתוחות', 'diromax-portal' ); ?></h2>
<?php if ( array() === $open ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'אין משימות פתוחות בתיק.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<ul class="dxa-tasks">
		<?php foreach ( $open as $task ) : ?>
			<?php $late = null !== $task['due_date'] && $task['due_date'] < $today; ?>
			<li class="dxa-task<?php echo $late ? ' is-late' : ''; ?>">
				<span class="dxa-task__t"><?php echo esc_html( $task['title'] ); ?></span>
				<span class="dxa-task__m">
					<?php echo esc_html( $who( $task['assignee_id'] ) ); ?>
					<?php if ( null !== $task['due_date'] ) : ?>
						·
						<?php if ( $late ) : ?>
							<span class="dxa-late">
								<?php
								/* translators: %s: date. */
								echo esc_html( sprintf( __( 'באיחור, היה עד %s', 'diromax-portal' ), Labels::date( $task['due_date'] ) ) );
								?>
							</span>
						<?php else : ?>
							<?php
							/* translators: %s: date. */
							echo esc_html( $task['due_date'] === $today ? __( 'להיום', 'diromax-portal' ) : sprintf( __( 'עד %s', 'diromax-portal' ), Labels::date( $task['due_date'] ) ) );
							?>
						<?php endif; ?>
					<?php endif; ?>
				</span>
				<span class="dxa-task__a">
					<?php $task_form( 'task_done', $task, __( 'בוצע', 'diromax-portal' ), 'button button-primary' ); ?>
					<?php $task_form( 'delete_task', $task, __( 'מחיקה', 'diromax-portal' ), 'button-link button-link-delete' ); ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( array() !== $done ) : ?>
	<details class="dxa-box">
		<summary><h2>
			<?php
			/* translators: %d: number of tasks. */
			echo esc_html( sprintf( _n( '%d משימה שבוצעה', '%d משימות שבוצעו', count( $done ), 'diromax-portal' ), count( $done ) ) );
			?>
		</h2></summary>
		<ul class="dxa-tasks">
			<?php foreach ( $done as $task ) : ?>
				<li class="dxa-task is-done">
					<span class="dxa-task__t"><?php echo esc_html( $task['title'] ); ?></span>
					<span class="dxa-task__m">
						<?php
						/* translators: 1: who, 2: date and time. */
						echo esc_html( sprintf( __( 'בוצע על ידי %1$s, %2$s', 'diromax-portal' ), $who( (int) $task['done_by'] ), Labels::datetime( $task['done_at'] ) ) );
						?>
					</span>
					<span class="dxa-task__a"><?php $task_form( 'task_done', $task, __( 'החזרה לפתוחות', 'diromax-portal' ), 'button' ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</details>
<?php endif; ?>
