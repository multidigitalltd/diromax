<?php
/**
 * Admin tab: case details, stages, status and deletion.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;

defined( 'ABSPATH' ) || exit;

$form = new Form( $view['flash'], 'details' );
$view = array_merge( $view, array( 'managers' => $view['can_all'] ? $view['managers'] : array() ) );
$lead = null === $case->lead_id ? null : get_post( $case->lead_id );
?>
<?php if ( $lead instanceof WP_Post && current_user_can( 'edit_post', $lead->ID ) ) : ?>
	<p class="dxa-muted">
		<?php esc_html_e( 'התיק נפתח מליד:', 'diromax-portal' ); ?>
		<a href="<?php echo esc_url( (string) get_edit_post_link( $lead->ID ) ); ?>"><?php echo esc_html( get_the_title( $lead ) ); ?></a>
	</p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="form-details">
	<input type="hidden" name="action" value="dx_portal_update_case">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
	<?php wp_nonce_field( 'dx_portal_update_case_' . $case->id ); ?>
	<?php require __DIR__ . '/../parts/case-fields.php'; ?>

	<h2><?php esc_html_e( 'שלבי התהליך', 'diromax-portal' ); ?></h2>
	<fieldset class="dxa-stages">
		<legend><?php esc_html_e( 'השלב הנוכחי. השלבים שלפניו מסומנים כהושלמו.', 'diromax-portal' ); ?></legend>
		<?php foreach ( $view['stages'] as $number => $name ) : ?>
			<p>
				<input type="radio" id="dxa-stage-<?php echo esc_attr( (string) $number ); ?>" name="stage" value="<?php echo esc_attr( (string) $number ); ?>"<?php checked( $case->stage, $number ); ?>>
				<label for="dxa-stage-<?php echo esc_attr( (string) $number ); ?>"><?php echo esc_html( str_pad( (string) $number, 2, '0', STR_PAD_LEFT ) . ' · ' . $name ); ?></label>
				<span class="dxa-skip">
					<input type="checkbox" id="dxa-skip-<?php echo esc_attr( (string) $number ); ?>" name="skipped[]" value="<?php echo esc_attr( (string) $number ); ?>"<?php checked( 'skipped', $case->stages[ $number ]['status'] ?? '' ); ?>>
					<label for="dxa-skip-<?php echo esc_attr( (string) $number ); ?>"><?php esc_html_e( 'לא רלוונטי לתיק', 'diromax-portal' ); ?></label>
				</span>
			</p>
		<?php endforeach; ?>
	</fieldset>
	<?php submit_button( __( 'שמירת פרטי התיק', 'diromax-portal' ) ); ?>
</form>

<h2><?php esc_html_e( 'סטטוס התיק', 'diromax-portal' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
	<input type="hidden" name="action" value="dx_portal_case_status">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
	<?php wp_nonce_field( 'dx_portal_case_status_' . $case->id ); ?>
	<label for="dxa-status"><?php esc_html_e( 'סטטוס', 'diromax-portal' ); ?></label>
	<select id="dxa-status" name="status">
		<?php foreach ( $view['statuses'] as $value => $label ) : ?>
			<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $case->status->value, $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<button type="submit" class="button"><?php esc_html_e( 'עדכון סטטוס', 'diromax-portal' ); ?></button>
</form>
<p class="description"><?php esc_html_e( 'תיק סגור נשאר גלוי ללקוח לפי תקופת הגישה שבהגדרות, ואחר כך הגישה נסגרת.', 'diromax-portal' ); ?></p>

<?php if ( $view['can_purge'] ) : ?>
	<div class="dxa-danger">
		<h2><?php esc_html_e( 'מחיקת התיק לצמיתות', 'diromax-portal' ); ?></h2>
		<p><?php esc_html_e( 'מוחק את התיק, את כל התנועות, העדכונים והמסמכים. אי אפשר לשחזר.', 'diromax-portal' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline">
			<input type="hidden" name="action" value="dx_portal_delete_case">
			<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
			<?php wp_nonce_field( 'dx_portal_delete_case_' . $case->id ); ?>
			<label for="dxa-confirm">
				<?php
				/* translators: %s: case number. */
				echo esc_html( sprintf( __( 'לאישור, מקלידים את מספר התיק: %s', 'diromax-portal' ), $case->number ) );
				?>
			</label>
			<input type="text" id="dxa-confirm" name="confirm" autocomplete="off" dir="ltr">
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'מחיקה לצמיתות', 'diromax-portal' ); ?></button>
		</form>
	</div>
<?php endif; ?>
