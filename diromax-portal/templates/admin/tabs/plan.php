<?php
/**
 * Admin tab: the case's payment plan (spec 21).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\Form;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\PaymentPlan;

defined( 'ABSPATH' ) || exit;

$rows        = (array) $view['rows'];
$drafts      = array_values( array_filter( $rows, static fn( array $payment ): bool => 'draft' === $payment['state'] ) );
$plan_totals = (array) $view['totals'];
$closed      = (bool) $view['closed'];

/**
 * Hidden fields every plan form carries.
 *
 * @param string $action  Action key, without the prefix.
 * @param int    $case_id Case.
 * @param int    $id      Payment, 0 for none.
 */
$dx_plan_hidden = static function ( string $action, int $case_id, int $id = 0 ): void {
	?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'dx_portal_' . $action ); ?>">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>">
	<?php if ( 0 !== $id ) : ?>
		<input type="hidden" name="payment_id" value="<?php echo esc_attr( (string) $id ); ?>">
	<?php endif; ?>
	<?php
	wp_nonce_field( 'dx_portal_' . $action . '_' . $case_id );
};

/**
 * The fields of a payment, new or existing.
 *
 * @param Form                      $form    Form helper.
 * @param array<string, mixed>|null $payment Payment being edited.
 */
$dx_plan_fields = static function ( Form $form, ?array $payment ) use ( $view ): void {
	$value = static fn( string $field, string $fallback ): string => $form->old( $field, $fallback );
	$typed = null === $payment ? '' : ( 'percent' === $payment['kind'] ? PaymentPlan::percent_text( (int) $payment['percent'] ) : Money::input( (int) $payment['amount'] ) );
	?>
	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'label' ) ); ?>"><?php esc_html_e( 'שם התשלום', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'label' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'label', (string) ( $payment['label'] ?? '' ) ) ); ?>" maxlength="120" required placeholder="<?php esc_attr_e( 'לדוגמה: תשלום שני, עם קבלת ההיתר', 'diromax-portal' ); ?>">
		<?php $form->print_error( 'label' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'trigger_on' ) ); ?>"><?php esc_html_e( 'מתי התשלום מגיע', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'trigger_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<option value="stage"<?php selected( $value( 'trigger_on', (string) ( $payment['trigger_on'] ?? 'stage' ) ), 'stage' ); ?>><?php esc_html_e( 'בסיום שלב', 'diromax-portal' ); ?></option>
			<option value="date"<?php selected( $value( 'trigger_on', (string) ( $payment['trigger_on'] ?? '' ) ), 'date' ); ?>><?php esc_html_e( 'בתאריך', 'diromax-portal' ); ?></option>
			<option value="manual"<?php selected( $value( 'trigger_on', (string) ( $payment['trigger_on'] ?? '' ) ), 'manual' ); ?>><?php esc_html_e( 'כשאחליט (ידני)', 'diromax-portal' ); ?></option>
		</select>
		<?php $form->print_error( 'trigger_on' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'stage' ) ); ?>"><?php esc_html_e( 'השלב (לתשלום בסיום שלב)', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'stage' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php foreach ( (array) $view['stages'] as $number => $name ) : ?>
				<option value="<?php echo esc_attr( (string) $number ); ?>"<?php selected( $value( 'stage', (string) ( $payment['stage'] ?? 1 ) ), (string) $number ); ?>><?php echo esc_html( $number . '. ' . $name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php $form->print_error( 'stage' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'trigger_date' ) ); ?>"><?php esc_html_e( 'התאריך (לתשלום בתאריך)', 'diromax-portal' ); ?></label>
		<input type="date" <?php echo $form->attrs( 'trigger_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'trigger_date', (string) ( $payment['trigger_date'] ?? '' ) ) ); ?>">
		<?php $form->print_error( 'trigger_date' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'kind' ) ); ?>"><?php esc_html_e( 'איך נקבע הסכום', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'kind' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<option value="percent"<?php selected( $value( 'kind', (string) ( $payment['kind'] ?? 'percent' ) ), 'percent' ); ?>><?php esc_html_e( 'אחוז מהבסיס', 'diromax-portal' ); ?></option>
			<option value="fixed"<?php selected( $value( 'kind', (string) ( $payment['kind'] ?? '' ) ), 'fixed' ); ?>><?php esc_html_e( 'סכום קבוע', 'diromax-portal' ); ?></option>
		</select>
		<?php $form->print_error( 'kind' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'value' ) ); ?>"><?php esc_html_e( 'אחוז או סכום (₪)', 'diromax-portal' ); ?></label>
		<input type="text" inputmode="decimal" dir="ltr" <?php echo $form->attrs( 'value' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'value', $typed ) ); ?>" maxlength="16" required>
		<?php $form->print_error( 'value' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'pay_to' ) ); ?>"><?php esc_html_e( 'למי משלמים', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'pay_to' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<option value="diromax"<?php selected( $value( 'pay_to', (string) ( $payment['pay_to'] ?? 'diromax' ) ), 'diromax' ); ?>><?php esc_html_e( 'לדירומקס', 'diromax-portal' ); ?></option>
			<option value="supplier"<?php selected( $value( 'pay_to', (string) ( $payment['pay_to'] ?? '' ) ), 'supplier' ); ?>><?php esc_html_e( 'לספק', 'diromax-portal' ); ?></option>
		</select>
		<?php $form->print_error( 'pay_to' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'payee' ) ); ?>"><?php esc_html_e( 'שם הספק (לתשלום לספק)', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'payee' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'payee', (string) ( $payment['payee'] ?? '' ) ) ); ?>" maxlength="120">
		<?php $form->print_error( 'payee' ); ?>
	</p>
	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'days' ) ); ?>"><?php esc_html_e( 'ימים לתשלום', 'diromax-portal' ); ?></label>
		<input type="number" dir="ltr" <?php echo $form->attrs( 'days' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'days', (string) ( $payment['days'] ?? 14 ) ) ); ?>" min="0" max="<?php echo esc_attr( (string) PaymentPlan::MAX_DAYS ); ?>" class="small-text">
		<?php $form->print_error( 'days' ); ?>
	</p>
	<?php
};

$base_form = new Form( $view['flash'], 'base' );
$add_form  = new Form( $view['flash'], 'plan' );
?>
<p class="description"><?php esc_html_e( 'התשלומים שסוכמו עם הלקוח. כשתשלום מגיע (בסיום השלב, בתאריך, או כשמחליטים), נוצרת טיוטה של דרישת תשלום. בודקים אותה, ורק אחרי האישור היא נשלחת ללקוח.', 'diromax-portal' ); ?></p>

<section class="dxa-box dxa-plan-sum" aria-labelledby="dxa-plan-sum-title">
	<h2 id="dxa-plan-sum-title"><?php esc_html_e( 'סיכום', 'diromax-portal' ); ?></h2>
	<dl class="dxa-plan-sum__list">
		<div>
			<dt><?php esc_html_e( 'בסיס לחישוב האחוזים', 'diromax-portal' ); ?></dt>
			<dd>
				<strong><?php echo esc_html( Money::format( (int) $view['base'] ) ); ?></strong>
				<span class="dxa-muted"><?php echo esc_html( $view['base_set'] ? __( '(נקבע לתיק)', 'diromax-portal' ) : __( '(סך התקציב)', 'diromax-portal' ) ); ?></span>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'סך התוכנית', 'diromax-portal' ); ?></dt>
			<dd>
				<strong><?php echo esc_html( Money::format( (int) $plan_totals['total'] ) ); ?></strong>
				<span class="dxa-muted">
					<?php
					/* translators: 1: share of the base, 2: fixed amounts. */
					echo esc_html( sprintf( __( '%1$s%% מהבסיס + %2$s בסכומים קבועים', 'diromax-portal' ), PaymentPlan::percent_text( (int) $plan_totals['percent'] ), Money::format( (int) $plan_totals['fixed'] ) ) );
					?>
				</span>
			</dd>
		</div>
	</dl>
	<?php if ( (int) $plan_totals['percent'] > 0 && 0 === (int) $view['base'] ) : ?>
		<p class="dxa-error"><?php esc_html_e( 'אין עדיין בסיס: התקציב ריק. קובעים תקציב, או סכום בסיס לתוכנית, כדי שהאחוזים יהפכו לסכומים.', 'diromax-portal' ); ?></p>
	<?php endif; ?>
	<?php if ( ! $closed ) : ?>
		<details class="dxa-box" id="form-base"<?php echo $base_form->failed() ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'שינוי הבסיס', 'diromax-portal' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
				<?php $dx_plan_hidden( 'plan_base', $case->id ); ?>
				<p class="dxa-field">
					<label for="<?php echo esc_attr( $base_form->id( 'base' ) ); ?>"><?php esc_html_e( 'סכום הבסיס (₪)', 'diromax-portal' ); ?></label>
					<input type="text" inputmode="decimal" dir="ltr" <?php echo $base_form->attrs( 'base' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $base_form->old( 'base', $view['base_set'] ? Money::input( (int) $view['base'] ) : '' ) ); ?>" maxlength="16" aria-describedby="dxa-base-help">
					<?php $base_form->print_error( 'base' ); ?>
				</p>
				<p class="description dxa-field--wide" id="dxa-base-help">
					<?php
					/* translators: %s: budget total. */
					echo esc_html( sprintf( __( 'למשל שכר הטרחה שסוכם, או עלות הפרויקט. ריק: סך התקציב (%s), ומתעדכן כשהתקציב משתנה. טיוטה שכבר נוצרה שומרת את הסכום שלה.', 'diromax-portal' ), Money::format( (int) $view['budget_total'] ) ) );
					?>
				</p>
				<p class="submit dxa-field--wide"><button type="submit" class="button"><?php esc_html_e( 'שמירת הבסיס', 'diromax-portal' ); ?></button></p>
			</form>
		</details>
	<?php endif; ?>
</section>

<?php if ( array() !== $drafts ) : ?>
	<h2 id="dxa-plan-drafts"><?php esc_html_e( 'דרישות תשלום שמחכות לאישור', 'diromax-portal' ); ?></h2>
	<ul class="dxa-plan-drafts">
		<?php foreach ( $drafts as $payment ) : ?>
			<?php $approve = new Form( $view['flash'], 'approve' . $payment['id'] ); ?>
			<li class="dxa-box dxa-plan-draft" id="form-approve<?php echo esc_attr( (string) $payment['id'] ); ?>">
				<h3><?php echo esc_html( (string) $payment['label'] ); ?></h3>
				<p class="dxa-muted">
					<?php
					/* translators: 1: when it fell due, 2: to whom. */
					echo esc_html( sprintf( __( '%1$s · %2$s', 'diromax-portal' ), (string) $payment['when'], 'supplier' === $payment['pay_to'] ? (string) $payment['payee'] : __( 'לדירומקס', 'diromax-portal' ) ) );
					?>
				</p>
				<?php if ( $closed ) : ?>
					<p class="dxa-muted"><?php esc_html_e( 'התיק סגור, ולכן הטיוטה לא תישלח.', 'diromax-portal' ); ?></p>
				<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
					<?php $dx_plan_hidden( 'plan_approve', $case->id, (int) $payment['id'] ); ?>
					<p class="dxa-field">
						<label for="<?php echo esc_attr( $approve->id( 'amount' ) ); ?>"><?php esc_html_e( 'סכום (₪, כולל מע״מ)', 'diromax-portal' ); ?></label>
						<input type="text" inputmode="decimal" dir="ltr" <?php echo $approve->attrs( 'amount' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $approve->old( 'amount', Money::input( (int) $payment['amount'] ) ) ); ?>" maxlength="16" required>
						<?php $approve->print_error( 'amount' ); ?>
					</p>
					<p class="dxa-field">
						<label for="<?php echo esc_attr( $approve->id( 'due_date' ) ); ?>"><?php esc_html_e( 'לתשלום עד', 'diromax-portal' ); ?></label>
						<input type="date" <?php echo $approve->attrs( 'due_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $approve->old( 'due_date', (string) $payment['draft_due'] ) ); ?>" min="<?php echo esc_attr( (string) $view['today'] ); ?>" required>
						<?php $approve->print_error( 'due_date' ); ?>
					</p>
					<p class="dxa-field dxa-field--wide">
						<label for="<?php echo esc_attr( $approve->id( 'description' ) ); ?>"><?php esc_html_e( 'תיאור בדרישה', 'diromax-portal' ); ?></label>
						<input type="text" <?php echo $approve->attrs( 'description' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $approve->old( 'description', (string) $payment['label'] ) ); ?>" maxlength="200" required>
						<?php $approve->print_error( 'description' ); ?>
						<?php $approve->print_error( 'payee' ); ?>
					</p>
					<p class="dxa-field dxa-field--wide">
						<label><input type="checkbox" name="notify" value="1"<?php checked( ! $approve->failed() || '1' === $approve->old( 'notify' ) ); ?>> <?php esc_html_e( 'לשלוח ללקוח מייל על הדרישה', 'diromax-portal' ); ?></label>
					</p>
					<p class="submit dxa-field--wide">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'אישור ושליחת הדרישה', 'diromax-portal' ); ?>
							<span class="screen-reader-text"><?php echo esc_html( ': ' . $payment['label'] ); ?></span>
						</button>
					</p>
				</form>
				<div class="dxa-inline-group">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
						<?php $dx_plan_hidden( 'plan_withdraw', $case->id, (int) $payment['id'] ); ?>
						<input type="hidden" name="back" value="1">
						<button type="submit" class="button">
							<?php esc_html_e( 'לא עכשיו: חזרה לתוכנית', 'diromax-portal' ); ?>
							<span class="screen-reader-text"><?php echo esc_html( ': ' . $payment['label'] ); ?></span>
						</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
						<?php $dx_plan_hidden( 'plan_withdraw', $case->id, (int) $payment['id'] ); ?>
						<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'לבטל את התשלום הזה? הוא לא יישלח ללקוח.', 'diromax-portal' ); ?>">
							<?php esc_html_e( 'ביטול התשלום', 'diromax-portal' ); ?>
							<span class="screen-reader-text"><?php echo esc_html( ': ' . $payment['label'] ); ?></span>
						</button>
					</form>
				</div>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<h2><?php esc_html_e( 'התשלומים בתוכנית', 'diromax-portal' ); ?></h2>
<?php if ( array() === $rows ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'אין עדיין תשלומים בתוכנית. מוסיפים אותם למטה, או קובעים תבנית בהגדרות לכל תיק חדש.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<div class="dxa-scroll"><table class="widefat dxa-table dxa-plan">
		<caption class="screen-reader-text"><?php esc_html_e( 'התשלומים בתוכנית', 'diromax-portal' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'תשלום', 'diromax-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'מתי', 'diromax-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'למי', 'diromax-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'מצב', 'diromax-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'פעולות', 'diromax-portal' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $rows as $payment ) : ?>
			<?php $edit = new Form( $view['flash'], 'plan' . $payment['id'] ); ?>
			<tr class="<?php echo 'cancelled' === $payment['state'] ? 'dxa-voided' : ''; ?>" id="dxa-plan-<?php echo esc_attr( (string) $payment['id'] ); ?>">
				<td><strong><?php echo esc_html( (string) $payment['label'] ); ?></strong></td>
				<td><?php echo esc_html( (string) $payment['when'] ); ?></td>
				<td>
					<?php echo esc_html( Money::format( (int) $payment['amount'] ) ); ?>
					<?php if ( '' !== $payment['share'] ) : ?>
						<br><span class="dxa-muted"><?php echo esc_html( (string) $payment['share'] ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( 'supplier' === $payment['pay_to'] ? (string) $payment['payee'] : __( 'דירומקס', 'diromax-portal' ) ); ?></td>
				<td><span class="dxa-plan-state dxa-plan-state--<?php echo esc_attr( (string) $payment['state'] ); ?>"><?php echo esc_html( (string) $payment['state_label'] ); ?></span></td>
				<td class="dxa-plan__actions">
					<?php if ( 'planned' === $payment['status'] && ! $closed ) : ?>
						<details class="dxa-box" id="form-plan<?php echo esc_attr( (string) $payment['id'] ); ?>"<?php echo $edit->failed() ? ' open' : ''; ?>>
							<summary>
								<?php esc_html_e( 'עריכה', 'diromax-portal' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( ': ' . $payment['label'] ); ?></span>
							</summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
								<?php $dx_plan_hidden( 'plan_update', $case->id, (int) $payment['id'] ); ?>
								<?php $dx_plan_fields( $edit, $payment ); ?>
								<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת התשלום', 'diromax-portal' ); ?></button></p>
							</form>
						</details>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
							<?php $dx_plan_hidden( 'plan_draft', $case->id, (int) $payment['id'] ); ?>
							<button type="submit" class="button">
								<?php esc_html_e( 'להכין דרישה עכשיו', 'diromax-portal' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( ': ' . $payment['label'] ); ?></span>
							</button>
						</form>
					<?php elseif ( 'draft' === $payment['status'] ) : ?>
						<a href="#form-approve<?php echo esc_attr( (string) $payment['id'] ); ?>"><?php esc_html_e( 'לאישור', 'diromax-portal' ); ?></a>
					<?php elseif ( 'sent' === $payment['status'] ) : ?>
						<a href="<?php echo esc_url( Admin::case_url( $case->id, 'finance' ) . '#dxa-ledger-table' ); ?>"><?php esc_html_e( 'לדרישה בכספים', 'diromax-portal' ); ?></a>
					<?php endif; ?>
					<?php if ( in_array( $payment['status'], array( 'planned', 'cancelled' ), true ) && ! $closed ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
							<?php $dx_plan_hidden( 'plan_delete', $case->id, (int) $payment['id'] ); ?>
							<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'למחוק את התשלום מהתוכנית?', 'diromax-portal' ); ?>">
								<?php esc_html_e( 'מחיקה', 'diromax-portal' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( ': ' . $payment['label'] ); ?></span>
							</button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
<?php endif; ?>

<?php if ( $closed ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'התיק סגור, ולכן אי אפשר לשנות את התוכנית.', 'diromax-portal' ); ?></p>
<?php else : ?>
	<details class="dxa-box dxa-box--add" id="form-plan"<?php echo array() === $rows || $add_form->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
		<summary><h2><?php esc_html_e( 'תשלום חדש בתוכנית', 'diromax-portal' ); ?></h2></summary>
		<p class="description"><?php esc_html_e( 'אחוז מחושב מהבסיס שבסיכום למעלה. תשלום שהזמן שלו כבר הגיע (השלב הסתיים, או שהתאריך עבר) הופך מיד לטיוטה לאישור.', 'diromax-portal' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
			<?php $dx_plan_hidden( 'plan_add', $case->id ); ?>
			<?php $dx_plan_fields( $add_form, null ); ?>
			<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'הוספה לתוכנית', 'diromax-portal' ); ?></button></p>
		</form>
	</details>
<?php endif; ?>
