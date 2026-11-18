<?php
/**
 * Admin tab: budget lines.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Domain\Money;

defined( 'ABSPATH' ) || exit;

$form  = new Form( $view['flash'], 'budget' );
$total = array_sum( $view['planned'] );
?>
<p class="dxa-intro"><?php esc_html_e( 'הסכום המתוכנן לכל סעיף, כולל מע״מ. הלקוח רואה את סך התקציב ואת ההשוואה לביצוע בכל סעיף. סעיף ריק לא מוצג.', 'diromax-portal' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="form-budget">
	<input type="hidden" name="action" value="dx_portal_save_budget">
	<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
	<?php wp_nonce_field( 'dx_portal_save_budget_' . $case->id ); ?>
	<table class="form-table" role="presentation">
		<?php foreach ( $view['categories'] as $key => $label ) : ?>
			<?php
			$value       = $form->old( $key, isset( $view['planned'][ $key ] ) ? Money::input( $view['planned'][ $key ] ) : '' );
			$field_error = $form->error( $key );
			?>
			<tr>
				<th scope="row"><label for="dxa-budget-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input type="text" inputmode="decimal" dir="ltr" class="dxa-amount" id="dxa-budget-<?php echo esc_attr( $key ); ?>" name="budget[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"<?php echo '' !== $field_error ? ' aria-invalid="true" aria-describedby="dxa-budget-' . esc_attr( $key ) . '-error"' : ''; ?>> ₪
					<?php if ( '' !== $field_error ) : ?>
						<p class="dxa-error" id="dxa-budget-<?php echo esc_attr( $key ); ?>-error"><?php echo esc_html( $field_error ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'סה״כ תקציב מאושר', 'diromax-portal' ); ?></th>
			<td><strong dir="ltr"><?php echo esc_html( Money::format( $total ) ); ?></strong></td>
		</tr>
	</table>
	<?php submit_button( __( 'שמירת התקציב', 'diromax-portal' ) ); ?>
</form>
