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
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\Stages;

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
	<p class="description"><?php esc_html_e( 'לכל שלב סטטוס משלו, ואפשר לסמן כמה שלבים "בתהליך" באותו זמן. תאריכי ההתחלה והסיום נרשמים ביום השמירה.', 'diromax-portal' ); ?></p>
	<div class="dxa-stages" id="dxa-stages">
		<?php foreach ( $view['stages'] as $number => $name ) : ?>
			<?php
			$dxa_state = $case->stages[ $number ] ?? array(
				'status'  => 'pending',
				'started' => null,
				'done'    => null,
			);
			$dxa_when  = Labels::stage_when( $dxa_state );
			?>
			<fieldset class="dxa-stage-row dxa-stage-row--<?php echo esc_attr( $dxa_state['status'] ); ?>">
				<legend>
					<span class="dxa-stage-row__n"><?php echo esc_html( Stages::number( (int) $number ) ); ?></span>
					<?php echo esc_html( (string) $name ); ?>
					<?php if ( '' !== $dxa_when ) : ?>
						<span class="dxa-stage-row__when"><?php echo esc_html( $dxa_when ); ?></span>
					<?php endif; ?>
				</legend>
				<span class="dxa-seg">
					<?php foreach ( $view['stage_statuses'] as $dxa_value => $dxa_label ) : ?>
						<?php $dxa_id = 'dxa-stage-' . $number . '-' . $dxa_value; ?>
						<input type="radio" class="dxa-seg__in dxa-seg__in--<?php echo esc_attr( (string) $dxa_value ); ?>" id="<?php echo esc_attr( $dxa_id ); ?>" name="stage_status[<?php echo esc_attr( (string) $number ); ?>]" value="<?php echo esc_attr( (string) $dxa_value ); ?>"<?php checked( $dxa_state['status'], $dxa_value ); ?>>
						<label for="<?php echo esc_attr( $dxa_id ); ?>"><?php echo esc_html( (string) $dxa_label ); ?></label>
					<?php endforeach; ?>
				</span>
			</fieldset>
		<?php endforeach; ?>
	</div>
	<?php $form->print_error( 'stages' ); ?>
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
