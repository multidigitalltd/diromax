<?php
/**
 * Admin tab: decisions put to the client (spec 15.5).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$form     = new Form( $view['flash'], 'decision' );
$flash    = $view['flash'];
$open     = is_array( $flash ) && 'error' === $flash['type'] ? (string) $flash['form'] : '';
$statuses = Labels::decision_statuses();
?>
<details class="dxa-box dxa-box--add" id="form-decision"<?php echo array() === $view['decisions'] || 'decision' === $open ? ' open' : ''; ?>>
	<summary><h2><?php esc_html_e( 'בקשת החלטה מהלקוח', 'diromax-portal' ); ?></h2></summary>
	<p class="description"><?php esc_html_e( 'הלקוח מאשר או דוחה באזור האישי. התשובה נרשמת בתיק עם התאריך, השעה וטביעת אצבע של מה שהוצג לו. זה אישור עסקי, לא חתימה דיגיטלית.', 'diromax-portal' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dx_portal_add_decision">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_add_decision_' . $case->id ); ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'title' ) ); ?>"><?php esc_html_e( 'מה מבקשים לאשר', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $form->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'title' ) ); ?>" maxlength="200" required placeholder="<?php esc_attr_e( 'לדוגמה: אישור הצעת המחיר של הקבלן', 'diromax-portal' ); ?>">
			<?php $form->print_error( 'title' ); ?>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'body' ) ); ?>"><?php esc_html_e( 'הפרטים: מה בדיוק מאשרים ולמה', 'diromax-portal' ); ?></label>
			<textarea <?php echo $form->attrs( 'body' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="5" maxlength="4000" required><?php echo esc_textarea( $form->old( 'body' ) ); ?></textarea>
			<?php $form->print_error( 'body' ); ?>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'amount' ) ); ?>"><?php esc_html_e( 'סכום כולל מע״מ (רשות)', 'diromax-portal' ); ?></label>
			<input type="text" inputmode="decimal" dir="ltr" <?php echo $form->attrs( 'amount' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'amount' ) ); ?>">
			<?php $form->print_error( 'amount' ); ?>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'due_date' ) ); ?>"><?php esc_html_e( 'מבקשים תשובה עד (רשות)', 'diromax-portal' ); ?></label>
			<input type="date" <?php echo $form->attrs( 'due_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'due_date' ) ); ?>">
			<?php $form->print_error( 'due_date' ); ?>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'document_id' ) ); ?>"><?php esc_html_e( 'מסמך מצורף (רשות)', 'diromax-portal' ); ?></label>
			<select <?php echo $form->attrs( 'document_id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
				<option value=""><?php esc_html_e( 'בלי מסמך', 'diromax-portal' ); ?></option>
				<?php foreach ( $view['documents'] as $document ) : ?>
					<option value="<?php echo esc_attr( (string) $document->id ); ?>"<?php selected( $form->old( 'document_id' ), (string) $document->id ); ?>><?php echo esc_html( $document->title ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'רק מסמכים שגלויים ללקוח. מעלים קודם בלשונית "הסכם ומסמכים".', 'diromax-portal' ); ?></span>
			<?php $form->print_error( 'document_id' ); ?>
		</p>
		<fieldset class="dxa-field dxa-field--wide dxa-checks">
			<legend class="screen-reader-text"><?php esc_html_e( 'עדכון הלקוח', 'diromax-portal' ); ?></legend>
			<span><input type="checkbox" id="dxa-decision-notify" name="notify" value="1" checked> <label for="dxa-decision-notify"><?php esc_html_e( 'לשלוח ללקוח מייל עכשיו', 'diromax-portal' ); ?></label></span>
		</fieldset>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'שליחת הבקשה ללקוח', 'diromax-portal' ); ?></button></p>
	</form>
</details>

<h2><?php esc_html_e( 'החלטות בתיק', 'diromax-portal' ); ?></h2>
<?php if ( array() === $view['decisions'] ) : ?>
	<p><?php esc_html_e( 'עדיין לא נשלחו החלטות ללקוח.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<ul class="dxa-list dxa-decisions">
		<?php foreach ( $view['decisions'] as $decision ) : ?>
			<?php $withdraw = new Form( $flash, 'withdraw-' . $decision->id ); ?>
			<li class="dxa-decision dxa-decision--<?php echo esc_attr( $decision->status->value ); ?>">
				<p>
					<strong><?php echo esc_html( $decision->title ); ?></strong>
					<span class="dxa-badge dxa-badge--<?php echo esc_attr( $decision->status->value ); ?>"><?php echo esc_html( $statuses[ $decision->status->value ] ?? '' ); ?></span>
				</p>
				<p class="dxa-muted">
					<?php
					$meta = array(
						/* translators: %s: date and time. */
						sprintf( __( 'נשלחה %s', 'diromax-portal' ), Labels::datetime( $decision->created_at ) ),
					);

					if ( null !== $decision->amount ) {
						$meta[] = Money::format( $decision->amount );
					}

					if ( null !== $decision->due_date ) {
						/* translators: %s: date. */
						$meta[] = sprintf( __( 'תשובה עד %s', 'diromax-portal' ), Labels::date( $decision->due_date ) );
					}

					echo esc_html( implode( ' · ', $meta ) );
					?>
				</p>
				<p><?php echo nl2br( esc_html( $decision->body ) ); ?></p>
				<?php if ( null !== $decision->answered_at ) : ?>
					<p class="dxa-answer">
						<?php
						/* translators: 1: answer, 2: who, 3: date and time. */
						echo esc_html( sprintf( __( '%1$s על ידי %2$s, %3$s', 'diromax-portal' ), $statuses[ $decision->status->value ] ?? '', (string) ( $view['names'][ $decision->answered_by ] ?? '' ), Labels::datetime( $decision->answered_at ) ) );
						?>
						<?php if ( '' !== $decision->answer_note ) : ?>
							<br><q><?php echo esc_html( $decision->answer_note ); ?></q>
						<?php endif; ?>
						<br><span class="dxa-muted" dir="ltr">SHA-256 <?php echo esc_html( substr( $decision->fingerprint, 0, 16 ) ); ?>…</span>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $decision->withdraw_reason ) : ?>
					<p class="dxa-muted">
						<?php
						/* translators: %s: reason. */
						echo esc_html( sprintf( __( 'בוטלה: %s', 'diromax-portal' ), $decision->withdraw_reason ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( $decision->is_pending() ) : ?>
					<details id="form-withdraw-<?php echo esc_attr( (string) $decision->id ); ?>"<?php echo 'withdraw-' . $decision->id === $open ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'ביטול הבקשה', 'diromax-portal' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="dx_portal_withdraw_decision">
							<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
							<input type="hidden" name="decision_id" value="<?php echo esc_attr( (string) $decision->id ); ?>">
							<?php wp_nonce_field( 'dx_portal_withdraw_decision_' . $case->id ); ?>
							<p><label for="<?php echo esc_attr( $withdraw->id( 'reason' ) ); ?>"><?php esc_html_e( 'סיבה (תוצג ללקוח)', 'diromax-portal' ); ?></label>
							<input type="text" <?php echo $withdraw->attrs( 'reason' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> maxlength="200" required><?php $withdraw->print_error( 'reason' ); ?></p>
							<p><button type="submit" class="button button-link-delete"><?php esc_html_e( 'ביטול הבקשה', 'diromax-portal' ); ?></button></p>
						</form>
					</details>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
