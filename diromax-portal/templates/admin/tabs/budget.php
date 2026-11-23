<?php
/**
 * Admin tab: the case's budget, sections and sub-sections (spec 19.2).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Domain\BudgetTree;
use Diromax\Portal\Domain\Money;

defined( 'ABSPATH' ) || exit;

$form   = new Form( $view['flash'], 'budget' );
$tree   = $view['tree']; // BudgetTree.
$in_use = $view['in_use']; // Entries per key.
$lines  = $view['lines']; // Rolled-up numbers per key, from the case summary.
$vat    = (int) $view['vat']; // VAT rate, basis points.

/**
 * One text input with its hidden label and its error.
 *
 * @param string $field Flat field ("{key}.label", "add.{key}.planned", ...).
 * @param string $name  Form name.
 * @param string $value Value.
 * @param string $label Accessible label.
 * @param bool   $money Amount field.
 */
$dx_budget_input = static function ( string $field, string $name, string $value, string $label, bool $money ) use ( $form ): void {
	$id    = 'dxa-budget-' . str_replace( array( '.', '*' ), array( '-', 'new' ), $field );
	$error = $form->error( $field );
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
	<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $form->old( $field, $value ) ); ?>"
		<?php echo $money ? ' inputmode="decimal" dir="ltr" class="dxa-amount"' : ' class="dxa-budget__name" maxlength="' . esc_attr( (string) BudgetTree::MAX_LABEL ) . '"'; ?>
		<?php echo '' !== $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : ''; ?>>
	<?php if ( '' !== $error ) : ?>
		<p class="dxa-error" id="<?php echo esc_attr( $id ); ?>-error"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>
	<?php
};

/**
 * Whether the amount next to it already includes VAT. It always shows
 * "includes" again after a save: the amount kept is the total.
 *
 * @param string $line  Line prefix ("{key}", "add.{key}", "add.*").
 * @param string $name  Form name.
 * @param string $label Line label, for the accessible name.
 */
$dx_budget_vat = static function ( string $line, string $name, string $label ) use ( $form ): void {
	$id = 'dxa-budget-' . str_replace( array( '.', '*' ), array( '-', 'new' ), $line ) . '-vat';
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
		<?php
		/* translators: %s: budget line. */
		echo esc_html( sprintf( __( 'מע״מ בסכום של %s', 'diromax-portal' ), $label ) );
		?>
	</label>
	<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="dxa-budget__vat">
		<option value="included"><?php esc_html_e( 'כולל מע״מ', 'diromax-portal' ); ?></option>
		<option value="excluded"<?php selected( $form->old( $line . '.vat', 'included' ), 'excluded' ); ?>><?php esc_html_e( '+ מע״מ', 'diromax-portal' ); ?></option>
	</select>
	<?php
};

/**
 * Up and down buttons. They submit the whole form, so edits are kept.
 *
 * @param string $key   Line key.
 * @param string $label Line label.
 * @param bool   $first First among its siblings.
 * @param bool   $last  Last among its siblings.
 */
$dx_budget_moves = static function ( string $key, string $label, bool $first, bool $last ): void {
	?>
	<span class="dxa-budget__moves">
		<?php if ( ! $first ) : ?>
			<button type="submit" class="button button-small" name="move" value="<?php echo esc_attr( $key . ':up' ); ?>"><span aria-hidden="true">↑</span><span class="screen-reader-text">
			<?php
			/* translators: %s: budget line. */
			echo esc_html( sprintf( __( 'להזיז למעלה: %s', 'diromax-portal' ), $label ) );
			?>
			</span></button>
		<?php endif; ?>
		<?php if ( ! $last ) : ?>
			<button type="submit" class="button button-small" name="move" value="<?php echo esc_attr( $key . ':down' ); ?>"><span aria-hidden="true">↓</span><span class="screen-reader-text">
			<?php
			/* translators: %s: budget line. */
			echo esc_html( sprintf( __( 'להזיז למטה: %s', 'diromax-portal' ), $label ) );
			?>
			</span></button>
		<?php endif; ?>
	</span>
	<?php
};

/**
 * Paid and committed so far, for one line.
 *
 * @param string $key Line key.
 */
$dx_budget_actual = static function ( string $key ) use ( $lines ): void {
	$line = $lines[ $key ] ?? null;

	if ( null === $line || 0 === $line['paid'] + $line['committed'] ) {
		echo '<span class="dxa-muted">—</span>';
		return;
	}

	echo '<span dir="ltr">' . esc_html( Money::format( $line['paid'] ) ) . '</span>';

	if ( $line['committed'] > 0 ) {
		/* translators: %s: committed amount. */
		echo '<br><small>' . esc_html( sprintf( __( 'התחייבות %s', 'diromax-portal' ), Money::format( $line['committed'] ) ) ) . '</small>';
	}
};

$dx_sections = $tree->sections();
?>
<p class="dxa-intro"><?php esc_html_e( 'הסעיפים של התיק הזה. אפשר לשנות שם וסכום, להוסיף או למחוק סעיפים וסעיפי משנה, ולשנות סדר. הלקוח רואה כל סעיף עם הסה״כ שלו, וסעיפי המשנה נפתחים בלחיצה. סעיף בלי סכום ובלי תנועות לא מוצג ללקוח.', 'diromax-portal' ); ?></p>
<p class="dxa-intro">
	<?php
	/* translators: %s: VAT rate, e.g. 18%. */
	echo esc_html( sprintf( __( 'הסכומים נשמרים כוללים מע״מ, כמו התנועות. סכום לפני מע״מ: בוחרים "+ מע״מ" לידו, ובשמירה מתווסף מע״מ של %s.', 'diromax-portal' ), rtrim( rtrim( number_format( $vat / 100, 2, '.', '' ), '0' ), '.' ) . '%' ) );
	?>
</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="form-budget" class="dxa-budget-form">
	<input type="hidden" name="action" value="dx_portal_save_budget">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
	<?php wp_nonce_field( 'dx_portal_save_budget_' . $case->id ); ?>
	<?php // The first submit button is what Enter in a field presses: saving, not moving a line. ?>
	<p class="dxa-budget__top"><button type="submit" class="button button-primary"><?php esc_html_e( 'שמירת התקציב', 'diromax-portal' ); ?></button></p>

	<div class="dxa-scroll"><table class="widefat dxa-table dxa-budget">
		<caption class="screen-reader-text"><?php esc_html_e( 'סעיפי התקציב', 'diromax-portal' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'סעיף', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'מתוכנן (₪)', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'שולם עד היום', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'סדר', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'מחיקה', 'diromax-portal' ); ?></th>
			</tr>
		</thead>
		<?php foreach ( $dx_sections as $dx_index => $dx_section ) : ?>
			<?php
			$dx_key      = $dx_section['key'];
			$dx_children = $tree->children( $dx_key );
			$dx_used     = ( $in_use[ $dx_key ] ?? 0 ) + array_sum( array_map( static fn( array $child ): int => $in_use[ $child['key'] ] ?? 0, $dx_children ) );
			?>
			<tbody class="dxa-budget__section">
				<tr class="dxa-budget__head">
					<th scope="row"><?php $dx_budget_input( $dx_key . '.label', 'budget[lines][' . $dx_key . '][label]', $dx_section['label'], __( 'שם הסעיף', 'diromax-portal' ), false ); ?></th>
					<td>
						<?php
						/* translators: %s: section. */
						$dx_budget_input( $dx_key . '.planned', 'budget[lines][' . $dx_key . '][planned]', $dx_section['planned'] > 0 ? Money::input( $dx_section['planned'] ) : '', sprintf( array() === $dx_children ? __( 'סכום מתוכנן: %s', 'diromax-portal' ) : __( 'סכום כללי (מחוץ לסעיפי המשנה): %s', 'diromax-portal' ), $dx_section['label'] ), true );
						$dx_budget_vat( $dx_key, 'budget[lines][' . $dx_key . '][vat]', $dx_section['label'] );
						?>
						<?php if ( array() !== $dx_children ) : ?>
							<small class="dxa-budget__total">
								<?php
								/* translators: %s: section total. */
								echo esc_html( sprintf( __( 'כללי. סה״כ הסעיף: %s', 'diromax-portal' ), Money::format( $tree->section_total( $dx_key ) ) ) );
								?>
							</small>
						<?php endif; ?>
					</td>
					<td><?php $dx_budget_actual( $dx_key ); ?></td>
					<td><?php $dx_budget_moves( $dx_key, $dx_section['label'], 0 === $dx_index, count( $dx_sections ) - 1 === $dx_index ); ?></td>
					<td>
						<?php if ( $dx_used > 0 ) : ?>
							<small class="dxa-muted">
								<?php
								/* translators: %d: number of entries. */
								echo esc_html( sprintf( _n( '%d תנועה בסעיף', '%d תנועות בסעיף', $dx_used, 'diromax-portal' ), $dx_used ) );
								?>
							</small>
							<?php $form->print_error( $dx_key . '.remove' ); ?>
						<?php else : ?>
							<label><input type="checkbox" name="budget[lines][<?php echo esc_attr( $dx_key ); ?>][remove]" value="1"> <?php esc_html_e( 'למחוק', 'diromax-portal' ); ?><span class="screen-reader-text"> <?php echo esc_html( $dx_section['label'] ); ?></span></label>
						<?php endif; ?>
					</td>
				</tr>
				<?php foreach ( $dx_children as $dx_child_index => $dx_child ) : ?>
					<?php $dx_child_key = $dx_child['key']; ?>
					<tr class="dxa-budget__child">
						<th scope="row">
							<span class="dxa-budget__branch" aria-hidden="true">↳</span>
							<?php
							/* translators: %s: section. */
							$dx_budget_input( $dx_child_key . '.label', 'budget[lines][' . $dx_child_key . '][label]', $dx_child['label'], sprintf( __( 'שם סעיף המשנה ב%s', 'diromax-portal' ), $dx_section['label'] ), false );
							?>
						</th>
						<td>
							<?php
							/* translators: %s: sub-section. */
							$dx_budget_input( $dx_child_key . '.planned', 'budget[lines][' . $dx_child_key . '][planned]', $dx_child['planned'] > 0 ? Money::input( $dx_child['planned'] ) : '', sprintf( __( 'סכום מתוכנן: %s', 'diromax-portal' ), $tree->label( $dx_child_key ) ), true );
							$dx_budget_vat( $dx_child_key, 'budget[lines][' . $dx_child_key . '][vat]', $tree->label( $dx_child_key ) );
							?>
						</td>
						<td><?php $dx_budget_actual( $dx_child_key ); ?></td>
						<td><?php $dx_budget_moves( $dx_child_key, $tree->label( $dx_child_key ), 0 === $dx_child_index, count( $dx_children ) - 1 === $dx_child_index ); ?></td>
						<td>
							<label><input type="checkbox" name="budget[lines][<?php echo esc_attr( $dx_child_key ); ?>][remove]" value="1"> <?php esc_html_e( 'למחוק', 'diromax-portal' ); ?><span class="screen-reader-text"> <?php echo esc_html( $tree->label( $dx_child_key ) ); ?></span></label>
							<?php if ( ( $in_use[ $dx_child_key ] ?? 0 ) > 0 ) : ?>
								<br><small class="dxa-muted"><?php esc_html_e( 'התנועות שלו יעברו לסעיף', 'diromax-portal' ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr class="dxa-budget__add">
					<th scope="row">
						<span class="dxa-budget__branch" aria-hidden="true">+</span>
						<?php
						/* translators: %s: section. */
						$dx_budget_input( 'add.' . $dx_key . '.label', 'budget[add][' . $dx_key . '][label]', '', sprintf( __( 'סעיף משנה חדש ב%s', 'diromax-portal' ), $dx_section['label'] ), false );
						?>
					</th>
					<td>
						<?php
						/* translators: %s: section. */
						$dx_budget_input( 'add.' . $dx_key . '.planned', 'budget[add][' . $dx_key . '][planned]', '', sprintf( __( 'סכום לסעיף המשנה החדש ב%s', 'diromax-portal' ), $dx_section['label'] ), true );
						/* translators: %s: section. */
						$dx_budget_vat( 'add.' . $dx_key, 'budget[add][' . $dx_key . '][vat]', sprintf( __( 'סעיף המשנה החדש ב%s', 'diromax-portal' ), $dx_section['label'] ) );
						?>
					</td>
					<td colspan="3"><small class="dxa-muted"><?php esc_html_e( 'סעיף משנה חדש: שם וסכום, ושמירה.', 'diromax-portal' ); ?></small></td>
				</tr>
			</tbody>
		<?php endforeach; ?>
		<tbody class="dxa-budget__new">
			<tr>
				<th scope="row"><?php $dx_budget_input( 'add.*.label', 'budget[add][*][label]', '', __( 'סעיף חדש', 'diromax-portal' ), false ); ?></th>
				<td>
					<?php
					$dx_budget_input( 'add.*.planned', 'budget[add][*][planned]', '', __( 'סכום לסעיף החדש', 'diromax-portal' ), true );
					$dx_budget_vat( 'add.*', 'budget[add][*][vat]', __( 'הסעיף החדש', 'diromax-portal' ) );
					?>
				</td>
				<td colspan="3"><small class="dxa-muted"><?php esc_html_e( 'סעיף חדש בסוף הרשימה.', 'diromax-portal' ); ?></small></td>
			</tr>
		</tbody>
		<tfoot>
			<tr>
				<th scope="row"><?php esc_html_e( 'סה״כ תקציב מאושר', 'diromax-portal' ); ?></th>
				<td><strong dir="ltr"><?php echo esc_html( Money::format( $tree->total() ) ); ?></strong></td>
				<td colspan="3"></td>
			</tr>
		</tfoot>
	</table></div>
	<p class="description"><?php esc_html_e( 'מחיקת סעיף משנה מעבירה את התנועות שלו לסעיף עצמו. סעיף שיש בו תנועות אי אפשר למחוק: קודם מעבירים את התנועות לסעיף אחר. ברירת המחדל לתיקים חדשים נקבעת בהגדרות.', 'diromax-portal' ); ?></p>
	<?php submit_button( __( 'שמירת התקציב', 'diromax-portal' ) ); ?>
</form>
