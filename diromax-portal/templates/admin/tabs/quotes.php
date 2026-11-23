<?php
/**
 * Admin tab: quote comparisons (spec 24).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\BudgetSelect;
use Diromax\Portal\Admin\Form;

defined( 'ABSPATH' ) || exit;

$comparisons = (array) $view['comparisons'];
$closed      = (bool) $view['closed'];
$creating    = new Form( $view['flash'], 'quote' );
$states      = array(
	'draft'     => __( 'טיוטה', 'diromax-portal' ),
	'open'      => __( 'ממתינה לבחירת הלקוח', 'diromax-portal' ),
	'chosen'    => __( 'הלקוח בחר', 'diromax-portal' ),
	'withdrawn' => __( 'בוטלה', 'diromax-portal' ),
);

/**
 * Hidden fields every comparison form carries.
 *
 * @param string $action  Action key, without the prefix.
 * @param int    $case_id Case.
 * @param string $key     comparison_id or offer_id.
 * @param int    $id      Its ID, 0 for none.
 */
$dx_quote_hidden = static function ( string $action, int $case_id, string $key = '', int $id = 0 ): void {
	?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'dx_portal_' . $action ); ?>">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>">
	<?php if ( 0 !== $id ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $id ); ?>">
	<?php endif; ?>
	<?php
	wp_nonce_field( 'dx_portal_' . $action . '_' . $case_id );
};
?>
<p class="description"><?php esc_html_e( 'לכל עבודה פותחים השוואה, מוסיפים את ההצעות שהתקבלו, ושולחים ללקוח לבחירה (לפחות שתי הצעות). הלקוח בוחר באזור האישי, ואתם מקבלים על כך מייל. אחרי הבחירה רושמים את ההצעה בכספים כהתחייבות, בלחיצה אחת.', 'diromax-portal' ); ?></p>

<?php if ( ! $closed ) : ?>
	<details class="dxa-box dxa-box--add" id="form-quote"<?php echo array() === $comparisons || $creating->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
		<summary><h2><?php esc_html_e( 'השוואה חדשה', 'diromax-portal' ); ?></h2></summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry">
			<?php $dx_quote_hidden( 'create_quote', $case->id ); ?>
			<p class="dxa-field dxa-field--wide">
				<label for="<?php echo esc_attr( $creating->id( 'title' ) ); ?>"><?php esc_html_e( 'העבודה', 'diromax-portal' ); ?></label>
				<input type="text" <?php echo $creating->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $creating->old( 'title', '' ) ); ?>" maxlength="160" required placeholder="<?php esc_attr_e( 'לדוגמה: קבלן שלד', 'diromax-portal' ); ?>">
				<?php $creating->print_error( 'title' ); ?>
			</p>
			<p class="dxa-field">
				<label for="<?php echo esc_attr( $creating->id( 'category' ) ); ?>"><?php esc_html_e( 'סעיף בתקציב', 'diromax-portal' ); ?></label>
				<select <?php echo $creating->attrs( 'category' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
					<option value=""><?php esc_html_e( 'בלי סעיף', 'diromax-portal' ); ?></option>
					<?php echo BudgetSelect::options( $view['budget'], $creating->old( 'category', '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in BudgetSelect::options(). ?>
				</select>
				<?php $creating->print_error( 'category' ); ?>
			</p>
			<p class="dxa-field">
				<label for="<?php echo esc_attr( $creating->id( 'due_on' ) ); ?>"><?php esc_html_e( 'לבחור עד (רשות)', 'diromax-portal' ); ?></label>
				<input type="date" <?php echo $creating->attrs( 'due_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $creating->old( 'due_on', '' ) ); ?>">
				<?php $creating->print_error( 'due_on' ); ?>
			</p>
			<p class="dxa-field dxa-field--wide">
				<label for="<?php echo esc_attr( $creating->id( 'note' ) ); ?>"><?php esc_html_e( 'הסבר ללקוח (רשות)', 'diromax-portal' ); ?></label>
				<textarea <?php echo $creating->attrs( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="3" maxlength="2000"><?php echo esc_textarea( $creating->old( 'note', '' ) ); ?></textarea>
				<?php $creating->print_error( 'note' ); ?>
			</p>
			<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'פתיחת ההשוואה', 'diromax-portal' ); ?></button></p>
		</form>
	</details>
<?php else : ?>
	<p class="dxa-muted"><?php esc_html_e( 'התיק סגור, ולכן אי אפשר לשנות את ההשוואות.', 'diromax-portal' ); ?></p>
<?php endif; ?>

<?php if ( array() === $comparisons ) : ?>
	<p class="dxa-muted"><?php esc_html_e( 'עדיין אין השוואות בתיק.', 'diromax-portal' ); ?></p>
<?php endif; ?>

<?php foreach ( $comparisons as $comparison ) : ?>
	<?php
	$comparison_id = (int) $comparison['id'];
	$offer_form    = new Form( $view['flash'], 'offer' . $comparison_id );
	$send_form     = new Form( $view['flash'], 'send' . $comparison_id );
	$commit_form   = new Form( $view['flash'], 'commit' . $comparison_id );
	$is_draft      = 'draft' === $comparison['status'];
	?>
	<section class="dxa-box dxa-quote dxa-quote--<?php echo esc_attr( (string) $comparison['status'] ); ?>" id="dxa-quote-<?php echo esc_attr( (string) $comparison_id ); ?>" aria-labelledby="dxa-quote-title-<?php echo esc_attr( (string) $comparison_id ); ?>">
		<h2 id="dxa-quote-title-<?php echo esc_attr( (string) $comparison_id ); ?>">
			<?php echo esc_html( (string) $comparison['title'] ); ?>
			<span class="dxa-plan-state dxa-plan-state--<?php echo esc_attr( 'chosen' === $comparison['status'] ? 'paid' : ( 'open' === $comparison['status'] ? 'draft' : 'planned' ) ); ?>"><?php echo esc_html( $states[ $comparison['status'] ] ?? '' ); ?></span>
		</h2>
		<p class="dxa-muted">
			<?php
			echo esc_html(
				implode(
					' · ',
					array_filter(
						array(
							'' !== $comparison['line'] ? sprintf( /* translators: %s: budget line. */ __( 'סעיף: %s', 'diromax-portal' ), $comparison['line'] ) : '',
							'' !== $comparison['due_label'] ? sprintf( /* translators: %s: date. */ __( 'לבחור עד %s', 'diromax-portal' ), $comparison['due_label'] ) : '',
							count( $comparison['offers'] ) > 1 ? sprintf( /* translators: %s: amount. */ __( 'פער בין ההצעות: %s', 'diromax-portal' ), $comparison['gap'] ) : '',
						)
					)
				)
			);
			?>
		</p>
		<?php if ( '' !== $comparison['note'] ) : ?>
			<p><?php echo nl2br( esc_html( (string) $comparison['note'] ) ); ?></p>
		<?php endif; ?>

		<?php if ( array() !== $comparison['offers'] ) : ?>
			<div class="dxa-scroll"><table class="widefat dxa-table dxa-offers">
				<caption class="screen-reader-text">
					<?php
					/* translators: %s: job. */
					echo esc_html( sprintf( __( 'הצעות מחיר ל%s', 'diromax-portal' ), $comparison['title'] ) );
					?>
				</caption>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'ספק', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'מחיר', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'משך', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'הערה', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'פעולות', 'diromax-portal' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( (array) $comparison['offers'] as $offer ) : ?>
					<tr class="<?php echo (int) $offer['id'] === (int) $comparison['chosen'] ? 'dxa-offer--chosen' : ''; ?>">
						<th scope="row">
							<?php echo esc_html( (string) $offer['supplier'] ); ?>
							<?php if ( $offer['recommended'] ) : ?>
								<span class="dxa-plan-state dxa-plan-state--sent"><?php esc_html_e( 'מומלצת', 'diromax-portal' ); ?></span>
							<?php endif; ?>
							<?php if ( (int) $offer['id'] === (int) $comparison['chosen'] ) : ?>
								<span class="dxa-plan-state dxa-plan-state--paid"><?php esc_html_e( 'נבחרה', 'diromax-portal' ); ?></span>
							<?php endif; ?>
						</th>
						<td>
							<?php echo esc_html( (string) $offer['amount'] ); ?>
							<?php if ( $offer['lowest'] ) : ?>
								<br><span class="dxa-muted"><?php esc_html_e( 'הזולה ביותר', 'diromax-portal' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $offer['duration'] ); ?></td>
						<td>
							<?php echo esc_html( (string) $offer['note'] ); ?>
							<?php if ( '' !== $offer['document'] ) : ?>
								<br><a href="<?php echo esc_url( (string) $offer['document'] ); ?>">
									<?php esc_html_e( 'ההצעה המקורית', 'diromax-portal' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( ': ' . $offer['supplier'] ); ?></span>
								</a>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $is_draft && ! $closed ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
									<?php $dx_quote_hidden( 'delete_offer', $case->id, 'offer_id', (int) $offer['id'] ); ?>
									<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'להסיר את ההצעה?', 'diromax-portal' ); ?>">
										<?php esc_html_e( 'הסרה', 'diromax-portal' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( ': ' . $offer['supplier'] ); ?></span>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>

		<?php if ( 'chosen' === $comparison['status'] ) : ?>
			<p>
				<?php
				/* translators: 1: who, 2: when. */
				echo esc_html( sprintf( __( 'בחר: %1$s, %2$s', 'diromax-portal' ), $comparison['chosen_by'], $comparison['chosen_date'] ) );
				?>
			</p>
			<?php if ( '' !== $comparison['chosen_note'] ) : ?>
				<p class="dxa-visit__summary"><?php echo nl2br( esc_html( (string) $comparison['chosen_note'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( null !== $comparison['entry_id'] ) : ?>
				<p><a href="<?php echo esc_url( Admin::case_url( $case->id, 'finance' ) . '#dxa-ledger-table' ); ?>"><?php esc_html_e( 'נרשמה בכספים כהתחייבות', 'diromax-portal' ); ?></a></p>
			<?php elseif ( ! $closed ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry" id="form-commit<?php echo esc_attr( (string) $comparison_id ); ?>">
					<?php $dx_quote_hidden( 'commit_quote', $case->id, 'comparison_id', $comparison_id ); ?>
					<p class="dxa-field">
						<label for="<?php echo esc_attr( $commit_form->id( 'funding' ) ); ?>"><?php esc_html_e( 'ממה משולם', 'diromax-portal' ); ?></label>
						<select <?php echo $commit_form->attrs( 'funding' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
							<?php foreach ( (array) $view['fundings'] as $key => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $key ); ?>"<?php selected( $commit_form->old( 'funding', 'client' ), (string) $key ); ?>><?php echo esc_html( (string) $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php $commit_form->print_error( 'funding' ); ?>
						<?php $commit_form->print_error( 'category' ); ?>
					</p>
					<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'רישום בכספים כהתחייבות', 'diromax-portal' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $is_draft && ! $closed ) : ?>
			<details class="dxa-box dxa-box--add" id="form-offer<?php echo esc_attr( (string) $comparison_id ); ?>"<?php echo $offer_form->failed() || count( $comparison['offers'] ) < 2 ? ' open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'הצעה חדשה', 'diromax-portal' ); ?>
					<span class="screen-reader-text"><?php echo esc_html( ': ' . $comparison['title'] ); ?></span>
				</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry" enctype="multipart/form-data">
					<?php $dx_quote_hidden( 'add_offer', $case->id, 'comparison_id', $comparison_id ); ?>
					<p class="dxa-field">
						<label for="<?php echo esc_attr( $offer_form->id( 'supplier' ) ); ?>"><?php esc_html_e( 'ספק', 'diromax-portal' ); ?></label>
						<input type="text" <?php echo $offer_form->attrs( 'supplier' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $offer_form->old( 'supplier', '' ) ); ?>" maxlength="120" required>
						<?php $offer_form->print_error( 'supplier' ); ?>
					</p>
					<p class="dxa-field">
						<label for="<?php echo esc_attr( $offer_form->id( 'amount' ) ); ?>"><?php esc_html_e( 'מחיר (₪, כולל מע״מ)', 'diromax-portal' ); ?></label>
						<input type="text" inputmode="decimal" dir="ltr" <?php echo $offer_form->attrs( 'amount' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $offer_form->old( 'amount', '' ) ); ?>" maxlength="16" required>
						<?php $offer_form->print_error( 'amount' ); ?>
					</p>
					<p class="dxa-field">
						<label for="<?php echo esc_attr( $offer_form->id( 'duration' ) ); ?>"><?php esc_html_e( 'משך (רשות)', 'diromax-portal' ); ?></label>
						<input type="text" <?php echo $offer_form->attrs( 'duration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $offer_form->old( 'duration', '' ) ); ?>" maxlength="60" placeholder="<?php esc_attr_e( 'לדוגמה: 14 שבועות', 'diromax-portal' ); ?>">
						<?php $offer_form->print_error( 'duration' ); ?>
					</p>
					<p class="dxa-field dxa-field--wide">
						<label for="<?php echo esc_attr( $offer_form->id( 'note' ) ); ?>"><?php esc_html_e( 'הערה ללקוח (רשות)', 'diromax-portal' ); ?></label>
						<input type="text" <?php echo $offer_form->attrs( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $offer_form->old( 'note', '' ) ); ?>" maxlength="500">
						<?php $offer_form->print_error( 'note' ); ?>
					</p>
					<?php if ( $view['key_ready'] ) : ?>
						<p class="dxa-field dxa-field--wide">
							<label for="<?php echo esc_attr( $offer_form->id( 'quote_file' ) ); ?>"><?php esc_html_e( 'ההצעה המקורית (רשות, PDF או צילום)', 'diromax-portal' ); ?></label>
							<input type="file" id="<?php echo esc_attr( $offer_form->id( 'quote_file' ) ); ?>" name="quote_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx">
							<?php $offer_form->print_error( 'file' ); ?>
						</p>
					<?php endif; ?>
					<p class="dxa-field dxa-field--wide">
						<label><input type="checkbox" name="recommended" value="1"<?php checked( '1' === $offer_form->old( 'recommended' ) ); ?>> <?php esc_html_e( 'ההמלצה שלנו', 'diromax-portal' ); ?></label>
					</p>
					<p class="submit dxa-field--wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'הוספת ההצעה', 'diromax-portal' ); ?></button></p>
				</form>
			</details>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-entry" id="form-send<?php echo esc_attr( (string) $comparison_id ); ?>">
				<?php $dx_quote_hidden( 'send_quote', $case->id, 'comparison_id', $comparison_id ); ?>
				<?php $send_form->print_error( 'send' ); ?>
				<p class="dxa-field dxa-field--wide">
					<label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'לשלוח ללקוח מייל עם ההצעות', 'diromax-portal' ); ?></label>
				</p>
				<p class="submit dxa-field--wide">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'שליחה ללקוח לבחירה', 'diromax-portal' ); ?>
						<span class="screen-reader-text"><?php echo esc_html( ': ' . $comparison['title'] ); ?></span>
					</button>
				</p>
			</form>
		<?php endif; ?>

		<?php if ( in_array( $comparison['status'], array( 'draft', 'open' ), true ) && ! $closed ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
				<?php $dx_quote_hidden( 'withdraw_quote', $case->id, 'comparison_id', $comparison_id ); ?>
				<button type="submit" class="button button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'לבטל את ההשוואה? הלקוח לא יוכל לבחור בה.', 'diromax-portal' ); ?>">
					<?php esc_html_e( 'ביטול ההשוואה', 'diromax-portal' ); ?>
					<span class="screen-reader-text"><?php echo esc_html( ': ' . $comparison['title'] ); ?></span>
				</button>
			</form>
		<?php endif; ?>
	</section>
<?php endforeach; ?>
