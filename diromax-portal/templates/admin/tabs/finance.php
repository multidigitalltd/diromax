<?php
/**
 * Admin tab: quick entry, every ledger row, and per-row edit / void / settle.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\Form;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\PayTo;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Settings;

defined( 'ABSPATH' ) || exit;

$form   = new Form( $view['flash'], 'entry' );
$row    = null;
$prefix = 'dxa-new';
$flash  = $view['flash'];
$open   = is_array( $flash ) && 'error' === $flash['type'] ? $flash['form'] : '';
?>
<datalist id="dxa-payees">
	<?php foreach ( $view['payees'] as $payee ) : ?>
		<option value="<?php echo esc_attr( $payee ); ?>"></option>
	<?php endforeach; ?>
</datalist>

<details class="dxa-box" id="form-entry" open>
	<summary><h2><?php esc_html_e( 'תנועה חדשה', 'diromax-portal' ); ?></h2></summary>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="dx_portal_add_entry">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_add_entry_' . $case->id ); ?>
		<?php require __DIR__ . '/../parts/entry-fields.php'; ?>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירה', 'diromax-portal' ); ?></button></p>
	</form>
</details>

<h2><?php esc_html_e( 'כל התנועות', 'diromax-portal' ); ?></h2>
<?php if ( array() === $view['rows'] ) : ?>
	<p><?php esc_html_e( 'עדיין אין תנועות בתיק.', 'diromax-portal' ); ?></p>
<?php else : ?>
<table class="widefat dxa-table dxa-ledger">
	<caption class="screen-reader-text"><?php esc_html_e( 'תנועות התיק', 'diromax-portal' ); ?></caption>
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'תאריך', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'תיאור', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'סעיף', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'ללקוח', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'פעולות', 'diromax-portal' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $view['rows'] as $ledger_row ) : ?>
		<?php
		$entry    = $ledger_row->entry;
		$entry_id = $entry->id;
		$document = null === $entry->document_id ? null : ( $view['documents'][ $entry->document_id ] ?? null );
		$editable = ! $entry->voided && ! ( EntryType::Request === $entry->type && EntryStatus::Open !== $entry->status );
		?>
		<tr class="<?php echo $entry->voided ? 'dxa-voided' : ''; ?>">
			<td dir="ltr"><?php echo esc_html( Labels::date( $entry->entry_date ) ); ?></td>
			<td><span class="dxa-badge dxa-badge--<?php echo esc_attr( $entry->type->value ); ?>"><?php echo esc_html( Labels::entry_tag( $entry ) ); ?></span></td>
			<td>
				<strong><?php echo esc_html( $entry->description ); ?></strong>
				<?php if ( '' !== $entry->payee ) : ?>
					<br><span class="dxa-muted"><?php echo esc_html( $entry->payee ); ?></span>
				<?php endif; ?>
				<?php if ( EntryType::Request === $entry->type ) : ?>
					<br><span class="dxa-muted">
						<?php
						/* translators: 1: open/paid, 2: due date. */
						echo esc_html( sprintf( __( '%1$s · עד %2$s', 'diromax-portal' ), EntryStatus::Open === $entry->status ? __( 'פתוחה', 'diromax-portal' ) : __( 'שולמה', 'diromax-portal' ), Labels::date( $entry->due_date ) ) );
						?>
					</span>
					<?php if ( $ledger_row->reminders_sent > 0 ) : ?>
						<br><span class="dxa-muted">
							<?php
							/* translators: 1: number of reminders, 2: date of the last one. */
							echo esc_html( sprintf( __( 'תזכורות שנשלחו: %1$d · אחרונה %2$s', 'diromax-portal' ), $ledger_row->reminders_sent, Labels::date( $ledger_row->last_reminder_on ) ) );
							?>
						</span>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $entry->voided ) : ?>
					<br><span class="dxa-error">
						<?php
						/* translators: %s: reason. */
						echo esc_html( sprintf( __( 'בוטל: %s', 'diromax-portal' ), $ledger_row->void_reason ) );
						?>
					</span>
				<?php endif; ?>
				<?php if ( '' !== $ledger_row->internal_note ) : ?>
					<br><span class="dxa-note"><?php echo esc_html( $ledger_row->internal_note ); ?></span>
				<?php endif; ?>
				<?php if ( null !== $document ) : ?>
					<br><a href="
					<?php
					echo esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'action'      => 'dx_portal_download',
									'document_id' => $document->id,
								),
								admin_url( 'admin-post.php' )
							),
							'dx_portal_download_' . $document->id
						)
					);
					?>
									"><?php echo esc_html( $document->title ); ?></a>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( EntryType::Expense === $entry->type || '' !== $entry->category ? Settings::category_label( $entry->category ) : '—' ); ?></td>
			<td dir="ltr" class="dxa-num"><?php echo esc_html( Money::format( $entry->total ) ); ?></td>
			<td><?php echo $entry->visible ? esc_html__( 'גלוי', 'diromax-portal' ) : '<span class="dxa-muted">' . esc_html__( 'מוסתר', 'diromax-portal' ) . '</span>'; ?></td>
			<td class="dxa-actions">
				<?php if ( $editable ) : ?>
					<?php
					$form   = new Form( $flash, 'entry-' . $entry_id );
					$row    = $ledger_row;
					$prefix = 'dxa-e' . $entry_id;
					?>
					<details id="form-entry-<?php echo esc_attr( (string) $entry_id ); ?>"<?php echo 'entry-' . $entry_id === $open ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'עריכה', 'diromax-portal' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="dx_portal_update_entry">
							<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
							<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry_id ); ?>">
							<?php wp_nonce_field( 'dx_portal_update_entry_' . $case->id ); ?>
							<?php require __DIR__ . '/../parts/entry-fields.php'; ?>
							<p><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת השינויים', 'diromax-portal' ); ?></button></p>
						</form>
					</details>

					<?php if ( EntryType::Request === $entry->type ) : ?>
						<?php $settle = new Form( $flash, 'settle-' . $entry_id ); ?>
						<details id="form-settle-<?php echo esc_attr( (string) $entry_id ); ?>"<?php echo 'settle-' . $entry_id === $open ? ' open' : ''; ?>>
							<summary><?php esc_html_e( 'סימון כשולמה', 'diromax-portal' ); ?></summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="dx_portal_settle_request">
								<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
								<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry_id ); ?>">
								<?php wp_nonce_field( 'dx_portal_settle_request_' . $case->id ); ?>
								<p class="description">
									<?php
									echo esc_html(
										PayTo::Supplier === $entry->pay_to
											? __( 'תירשם הוצאה ששולמה ישירות על ידי הלקוח לספק.', 'diromax-portal' )
											: __( 'יירשם תשלום מהלקוח לחשבון הפרויקט.', 'diromax-portal' )
									);
									?>
								</p>
								<p><label for="<?php echo esc_attr( $settle->id( 'entry_date' ) ); ?>"><?php esc_html_e( 'תאריך התשלום', 'diromax-portal' ); ?></label>
								<input type="date" <?php echo $settle->attrs( 'entry_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( (string) $view['today'] ); ?>"><?php $settle->print_error( 'entry_date' ); ?></p>
								<?php if ( PayTo::Supplier === $entry->pay_to && '' === $entry->category ) : ?>
									<p><label for="<?php echo esc_attr( $settle->id( 'category' ) ); ?>"><?php esc_html_e( 'סעיף בתקציב', 'diromax-portal' ); ?></label>
									<select <?php echo $settle->attrs( 'category' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
										<?php foreach ( $view['categories'] as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select><?php $settle->print_error( 'category' ); ?></p>
								<?php elseif ( PayTo::Diromax === $entry->pay_to ) : ?>
									<p><label for="<?php echo esc_attr( $settle->id( 'method' ) ); ?>"><?php esc_html_e( 'אמצעי תשלום', 'diromax-portal' ); ?></label>
									<select <?php echo $settle->attrs( 'method' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
										<?php foreach ( Labels::methods() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select></p>
								<?php endif; ?>
								<p><label for="<?php echo esc_attr( $settle->id( 'reference' ) ); ?>"><?php esc_html_e( 'אסמכתא', 'diromax-portal' ); ?></label>
								<input type="text" dir="ltr" <?php echo $settle->attrs( 'reference' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> maxlength="60"></p>
								<p><input type="checkbox" id="dxa-settle-notify-<?php echo esc_attr( (string) $entry_id ); ?>" name="notify" value="1" checked> <label for="dxa-settle-notify-<?php echo esc_attr( (string) $entry_id ); ?>"><?php esc_html_e( 'לעדכן את הלקוח במייל', 'diromax-portal' ); ?></label></p>
								<p><button type="submit" class="button button-primary"><?php esc_html_e( 'סימון כשולמה', 'diromax-portal' ); ?></button></p>
							</form>
						</details>
						<?php if ( $entry->visible && $ledger_row->last_reminder_on !== $view['today'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="dx_portal_send_reminder">
								<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
								<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry_id ); ?>">
								<?php wp_nonce_field( 'dx_portal_send_reminder_' . $case->id ); ?>
								<button type="submit" class="button-link"><?php esc_html_e( 'שליחת תזכורת עכשיו', 'diromax-portal' ); ?></button>
							</form>
						<?php endif; ?>
					<?php endif; ?>

					<?php $void = new Form( $flash, 'void-' . $entry_id ); ?>
					<details id="form-void-<?php echo esc_attr( (string) $entry_id ); ?>"<?php echo 'void-' . $entry_id === $open ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'ביטול', 'diromax-portal' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="dx_portal_void_entry">
							<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
							<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry_id ); ?>">
							<?php wp_nonce_field( 'dx_portal_void_entry_' . $case->id ); ?>
							<p class="description"><?php esc_html_e( 'תנועה לא נמחקת: היא מבוטלת, יוצאת מהחישובים ומוצגת ללקוח מחוקה עם הסיבה.', 'diromax-portal' ); ?></p>
							<p><label for="<?php echo esc_attr( $void->id( 'reason' ) ); ?>"><?php esc_html_e( 'סיבת הביטול', 'diromax-portal' ); ?></label>
							<input type="text" <?php echo $void->attrs( 'reason' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> maxlength="200" required><?php $void->print_error( 'reason' ); ?></p>
							<p><input type="checkbox" id="dxa-void-notify-<?php echo esc_attr( (string) $entry_id ); ?>" name="notify" value="1"> <label for="dxa-void-notify-<?php echo esc_attr( (string) $entry_id ); ?>"><?php esc_html_e( 'לעדכן את הלקוח במייל', 'diromax-portal' ); ?></label></p>
							<p><button type="submit" class="button button-link-delete"><?php esc_html_e( 'ביטול התנועה', 'diromax-portal' ); ?></button></p>
						</form>
					</details>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
<p class="dxa-inline">
	<a href="<?php echo esc_url( Admin::case_url( $case->id, 'budget' ) ); ?>"><?php esc_html_e( 'עריכת התקציב', 'diromax-portal' ); ?></a>
	<a href="
	<?php
	echo esc_url(
		wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'dx_portal_export',
					'case_id' => $case->id,
				),
				admin_url( 'admin-post.php' )
			),
			'dx_portal_export_' . $case->id
		)
	);
	?>
	"><?php esc_html_e( 'ייצוא כל התנועות ל-CSV', 'diromax-portal' ); ?></a>
</p>
