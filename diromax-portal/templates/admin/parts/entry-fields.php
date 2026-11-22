<?php
/**
 * Admin part: ledger entry fields, shared by the add and edit forms.
 *
 * Fields carry data-dxa-for with the entry types they apply to; the admin
 * script hides the others. Without the script every field shows with a note.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>               $view
 * @var \Diromax\Portal\Admin\Form         $form
 * @var \Diromax\Portal\Data\LedgerRow|null $row
 * @var string                             $prefix ID prefix, unique per form.
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\BudgetSelect;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$entry      = null === $row ? null : $row->entry;
$entry_type = null === $entry ? $form->old( 'type', 'expense' ) : $entry->type->value;
$value      = static fn( string $field, string $fallback ) => $form->old( $field, $fallback );
?>
<div class="dxa-entry" data-dxa-entry="<?php echo esc_attr( $entry_type ); ?>">
	<?php if ( null === $entry ) : ?>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'type' ) ); ?>"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></label>
			<select <?php echo $form->attrs( 'type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> data-dxa-type>
				<?php foreach ( Labels::types() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $entry_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	<?php endif; ?>

	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'entry_date' ) ); ?>"><?php esc_html_e( 'תאריך', 'diromax-portal' ); ?></label>
		<input type="date" <?php echo $form->attrs( 'entry_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'entry_date', $entry->entry_date ?? (string) $view['today'] ) ); ?>" required>
		<?php $form->print_error( 'entry_date' ); ?>
	</p>

	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'description' ) ); ?>"><?php esc_html_e( 'תיאור (הלקוח רואה אותו)', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'description' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'description', $entry->description ?? '' ) ); ?>" maxlength="200" required>
		<?php $form->print_error( 'description' ); ?>
	</p>

	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'amount' ) ); ?>"><?php esc_html_e( 'סכום (₪)', 'diromax-portal' ); ?></label>
		<input type="text" inputmode="decimal" dir="ltr" class="dxa-amount" <?php echo $form->attrs( 'amount' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'amount', null === $entry ? '' : Money::input( $entry->total ) ) ); ?>" required>
		<?php $form->print_error( 'amount' ); ?>
	</p>

	<p class="dxa-field" data-dxa-for="expense">
		<label for="<?php echo esc_attr( $form->id( 'vat_mode' ) ); ?>"><?php esc_html_e( 'מע״מ', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'vat_mode' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php
			$vat_default = null === $row || $row->vat_rate > 0 ? 'included' : 'exempt';
			$vat_modes   = array(
				'included' => __( 'הסכום כולל מע״מ', 'diromax-portal' ),
				'excluded' => __( 'להוסיף מע״מ לסכום', 'diromax-portal' ),
				'exempt'   => __( 'ללא מע״מ (עוסק פטור)', 'diromax-portal' ),
			);
			foreach ( $vat_modes as $key => $label ) :
				?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $value( 'vat_mode', $vat_default ), $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>

	<p class="dxa-field" data-dxa-for="expense">
		<label for="<?php echo esc_attr( $form->id( 'status' ) ); ?>"><?php esc_html_e( 'מצב', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<option value="paid"<?php selected( $value( 'status', $entry->status->value ?? 'paid' ), 'paid' ); ?>><?php esc_html_e( 'שולם', 'diromax-portal' ); ?></option>
			<option value="committed"<?php selected( $value( 'status', $entry->status->value ?? 'paid' ), 'committed' ); ?>><?php esc_html_e( 'התחייבות (נחתם, טרם שולם)', 'diromax-portal' ); ?></option>
		</select>
		<?php $form->print_error( 'status' ); ?>
	</p>

	<p class="dxa-field" data-dxa-for="request">
		<label for="<?php echo esc_attr( $form->id( 'pay_to' ) ); ?>"><?php esc_html_e( 'למי הלקוח משלם', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'pay_to' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<option value="supplier"<?php selected( $value( 'pay_to', $entry->pay_to->value ?? 'supplier' ), 'supplier' ); ?>><?php esc_html_e( 'לספק ישירות', 'diromax-portal' ); ?></option>
			<option value="diromax"<?php selected( $value( 'pay_to', $entry->pay_to->value ?? 'supplier' ), 'diromax' ); ?>><?php esc_html_e( 'לדירומקס (חשבון הפרויקט)', 'diromax-portal' ); ?></option>
		</select>
		<?php $form->print_error( 'pay_to' ); ?>
	</p>

	<p class="dxa-field" data-dxa-for="request">
		<label for="<?php echo esc_attr( $form->id( 'due_date' ) ); ?>"><?php esc_html_e( 'לתשלום עד', 'diromax-portal' ); ?></label>
		<input type="date" <?php echo $form->attrs( 'due_date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'due_date', $entry->due_date ?? '' ) ); ?>">
		<?php $form->print_error( 'due_date' ); ?>
	</p>

	<p class="dxa-field" data-dxa-for="expense request">
		<label for="<?php echo esc_attr( $form->id( 'payee' ) ); ?>"><?php esc_html_e( 'ספק', 'diromax-portal' ); ?></label>
		<input type="text" <?php echo $form->attrs( 'payee' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> list="dxa-payees" value="<?php echo esc_attr( $value( 'payee', $entry->payee ?? '' ) ); ?>" maxlength="120" autocomplete="off">
		<?php $form->print_error( 'payee' ); ?>
	</p>

	<p class="dxa-field" data-dxa-for="expense request">
		<label for="<?php echo esc_attr( $form->id( 'category' ) ); ?>"><?php esc_html_e( 'סעיף בתקציב', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'category' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<option value=""><?php esc_html_e( '— בחירה —', 'diromax-portal' ); ?></option>
			<?php echo BudgetSelect::options( $view['budget'], $value( 'category', $entry->category ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in BudgetSelect::options(). ?>
		</select>
		<?php $form->print_error( 'category' ); ?>
	</p>

	<p class="dxa-field" data-dxa-for="expense">
		<label for="<?php echo esc_attr( $form->id( 'funding' ) ); ?>"><?php esc_html_e( 'מי שילם', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'funding' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php foreach ( Labels::funding() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $value( 'funding', $entry->funding->value ?? 'client' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php $form->print_error( 'funding' ); ?>
	</p>

	<p class="dxa-field" data-dxa-for="payment">
		<label for="<?php echo esc_attr( $form->id( 'method' ) ); ?>"><?php esc_html_e( 'אמצעי תשלום', 'diromax-portal' ); ?></label>
		<select <?php echo $form->attrs( 'method' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
			<?php foreach ( Labels::methods() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $value( 'method', $row->method ?? 'transfer' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php $form->print_error( 'method' ); ?>
	</p>

	<p class="dxa-field">
		<label for="<?php echo esc_attr( $form->id( 'reference' ) ); ?>"><?php esc_html_e( 'אסמכתא / מספר חשבונית', 'diromax-portal' ); ?></label>
		<input type="text" dir="ltr" <?php echo $form->attrs( 'reference' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $value( 'reference', $row->reference ?? '' ) ); ?>" maxlength="60">
		<?php $form->print_error( 'reference' ); ?>
	</p>

	<p class="dxa-field dxa-field--wide" data-dxa-for="request">
		<label for="<?php echo esc_attr( $form->id( 'payment_details' ) ); ?>"><?php esc_html_e( 'פרטי תשלום ללקוח (בנק, חשבון, קישור)', 'diromax-portal' ); ?></label>
		<textarea <?php echo $form->attrs( 'payment_details' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="2" maxlength="500"><?php echo esc_textarea( $value( 'payment_details', $row->payment_details ?? '' ) ); ?></textarea>
		<span class="description"><?php esc_html_e( 'בדרישה לדירומקס, אם נשאר ריק יוצגו פרטי החשבון מההגדרות.', 'diromax-portal' ); ?></span>
	</p>

	<p class="dxa-field dxa-field--wide">
		<label for="<?php echo esc_attr( $form->id( 'internal_note' ) ); ?>"><?php esc_html_e( 'הערה פנימית (הלקוח לא רואה)', 'diromax-portal' ); ?></label>
		<textarea <?php echo $form->attrs( 'internal_note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="2" maxlength="1000"><?php echo esc_textarea( $value( 'internal_note', $row->internal_note ?? '' ) ); ?></textarea>
		<?php $form->print_error( 'internal_note' ); ?>
	</p>

	<?php if ( null === $entry ) : ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $prefix ); ?>-file"><?php esc_html_e( 'מסמך מצורף: חשבונית, קבלה או אישור העברה (רשות)', 'diromax-portal' ); ?></label>
			<input type="file" id="<?php echo esc_attr( $prefix ); ?>-file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx">
			<?php $form->print_error( 'file' ); ?>
		</p>
	<?php endif; ?>

	<fieldset class="dxa-field dxa-field--wide dxa-checks">
		<legend class="screen-reader-text"><?php esc_html_e( 'נראות ועדכון הלקוח', 'diromax-portal' ); ?></legend>
		<span><input type="checkbox" id="<?php echo esc_attr( $prefix ); ?>-visible" name="visible" value="1"<?php checked( $value( 'visible', null === $entry || $entry->visible ? '1' : '' ), '1' ); ?>> <label for="<?php echo esc_attr( $prefix ); ?>-visible"><?php esc_html_e( 'גלוי ללקוח', 'diromax-portal' ); ?></label></span>
		<span><input type="checkbox" id="<?php echo esc_attr( $prefix ); ?>-notify" name="notify" value="1"<?php checked( null === $entry ? '1' : '', '1' ); ?>> <label for="<?php echo esc_attr( $prefix ); ?>-notify"><?php esc_html_e( 'לעדכן את הלקוח במייל', 'diromax-portal' ); ?></label></span>
	</fieldset>
</div>
