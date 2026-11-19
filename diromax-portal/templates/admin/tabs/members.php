<?php
/**
 * Admin tab: members of the case.
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

$form = new Form( $view['flash'], 'member' );
?>
<?php if ( array() !== $view['members'] ) : ?>
<table class="widefat striped dxa-table">
	<caption class="screen-reader-text"><?php esc_html_e( 'לקוחות בתיק', 'diromax-portal' ); ?></caption>
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'שם', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'דוא״ל', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'תפקיד', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'מיילים', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'הוזמן / נכנס לאחרונה', 'diromax-portal' ); ?></th>
		<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'פעולות', 'diromax-portal' ); ?></span></th>
	</tr></thead>
	<tbody>
		<?php foreach ( $view['members'] as $member ) : ?>
			<tr>
				<td><?php echo esc_html( $member->name ); ?></td>
				<td dir="ltr"><?php echo esc_html( $member->email ); ?></td>
				<td><?php echo esc_html( $view['roles'][ $member->role->value ] ?? '' ); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
						<input type="hidden" name="action" value="dx_portal_member_mode">
						<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id ); ?>">
						<?php wp_nonce_field( 'dx_portal_member_mode_' . $case->id ); ?>
						<label class="screen-reader-text" for="dxa-mode-<?php echo esc_attr( (string) $member->user_id ); ?>">
							<?php
							/* translators: %s: member name. */
							echo esc_html( sprintf( __( 'מיילים ל%s', 'diromax-portal' ), $member->name ) );
							?>
						</label>
						<select id="dxa-mode-<?php echo esc_attr( (string) $member->user_id ); ?>" name="mode">
							<?php foreach ( $view['modes'] as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $member->mode()->value, $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-small"><?php esc_html_e( 'שמירה', 'diromax-portal' ); ?></button>
					</form>
				</td>
				<td><?php echo esc_html( Labels::datetime( $member->invited_at ) . ' / ' . ( null === $member->last_seen_at ? '—' : Labels::datetime( $member->last_seen_at ) ) ); ?></td>
				<td class="dxa-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dx_portal_invite">
						<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id ); ?>">
						<?php wp_nonce_field( 'dx_portal_invite_' . $case->id ); ?>
						<button type="submit" class="button-link"><?php esc_html_e( 'שליחת הזמנה שוב', 'diromax-portal' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dx_portal_remove_member">
						<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id ); ?>">
						<?php wp_nonce_field( 'dx_portal_remove_member_' . $case->id ); ?>
						<button type="submit" class="button-link button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'להסיר את הלקוח מהתיק? הוא לא יוכל לראות אותו יותר.', 'diromax-portal' ); ?>"><?php esc_html_e( 'הסרה מהתיק', 'diromax-portal' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<h2 id="form-member"><?php esc_html_e( 'הוספת לקוח לתיק', 'diromax-portal' ); ?></h2>
<p class="description"><?php esc_html_e( 'בעלים, שותפים, יורשים או מי שמלווה אותם. כל אחד מקבל כניסה משלו לאזור האישי.', 'diromax-portal' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="dx_portal_add_member">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
	<?php wp_nonce_field( 'dx_portal_add_member_' . $case->id ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $form->id( 'name' ) ); ?>"><?php esc_html_e( 'שם מלא', 'diromax-portal' ); ?> <span class="dxa-req"><?php esc_html_e( '(חובה)', 'diromax-portal' ); ?></span></label></th>
			<td><input type="text" class="regular-text" <?php echo $form->attrs( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'name' ) ); ?>" autocomplete="off" maxlength="80" required><?php $form->print_error( 'name' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $form->id( 'email' ) ); ?>"><?php esc_html_e( 'דוא״ל', 'diromax-portal' ); ?> <span class="dxa-req"><?php esc_html_e( '(חובה)', 'diromax-portal' ); ?></span></label></th>
			<td><input type="email" class="regular-text" dir="ltr" <?php echo $form->attrs( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'email' ) ); ?>" autocomplete="off" required><?php $form->print_error( 'email' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $form->id( 'role' ) ); ?>"><?php esc_html_e( 'תפקיד בתיק', 'diromax-portal' ); ?></label></th>
			<td>
				<select <?php echo $form->attrs( 'role' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
					<?php foreach ( $view['roles'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $form->old( 'role', 'owner' ), $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'אפשרויות', 'diromax-portal' ); ?></th>
			<td>
				<p><input type="checkbox" id="dxa-member-notify" name="notify" value="1" checked> <label for="dxa-member-notify"><?php esc_html_e( 'מקבל מייל על כל עדכון בתיק', 'diromax-portal' ); ?></label></p>
				<p><input type="checkbox" id="dxa-member-invite" name="invite" value="1" checked> <label for="dxa-member-invite"><?php esc_html_e( 'לשלוח עכשיו הזמנה לאזור האישי', 'diromax-portal' ); ?></label></p>
			</td>
		</tr>
	</table>
	<?php submit_button( __( 'הוספה לתיק', 'diromax-portal' ) ); ?>
</form>
