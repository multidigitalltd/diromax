<?php
/**
 * Admin part: case detail fields (new case and details tab).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>              $view
 * @var \Diromax\Portal\Admin\Form        $form
 * @var \Diromax\Portal\Data\CaseRecord|null $case
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$current  = $case ?? null;
$fields   = array(
	'title'         => array( __( 'כתובת הנכס', 'diromax-portal' ), __( 'כפי שתופיע ללקוח, למשל: רח׳ הזית 14, קריית אונו', 'diromax-portal' ), 200, true ),
	'scope'         => array( __( 'מה עושים בנכס', 'diromax-portal' ), __( 'למשל: הוספת יחידת דיור על הגג', 'diromax-portal' ), 300, false ),
	'property_type' => array( __( 'סוג הנכס', 'diromax-portal' ), '', 60, false ),
	'track'         => array( __( 'מסלול', 'diromax-portal' ), __( 'למשל: מימון מתוך הפרויקט', 'diromax-portal' ), 120, false ),
	'fee_terms'     => array( __( 'שכר ניהול לפי ההסכם', 'diromax-portal' ), __( 'למשל: 10% מהתקציב המאושר, ייפרע מהתמורה', 'diromax-portal' ), 300, false ),
);
$managers = is_array( $view['managers'] ?? null ) ? $view['managers'] : array();
?>
<table class="form-table" role="presentation">
	<?php foreach ( $fields as $name => [ $label, $hint, $max, $required ] ) : ?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $form->id( $name ) ); ?>"><?php echo esc_html( $label ); ?><?php echo $required ? ' <span class="dxa-req">' . esc_html__( '(חובה)', 'diromax-portal' ) . '</span>' : ''; ?></label></th>
			<td>
				<input type="text" class="regular-text" <?php echo $form->attrs( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( $name, null === $current ? (string) ( $view['prefill'][ $name ] ?? '' ) : (string) $current->{$name} ) ); ?>" maxlength="<?php echo esc_attr( (string) $max ); ?>"<?php echo $required ? ' required' : ''; ?>>
				<?php $form->print_error( $name ); ?>
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	<tr>
		<th scope="row"><label for="<?php echo esc_attr( $form->id( 'signed_on' ) ); ?>"><?php esc_html_e( 'תאריך חתימת ההסכם', 'diromax-portal' ); ?></label></th>
		<td>
			<input type="date" <?php echo $form->attrs( 'signed_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'signed_on', (string) ( $current->signed_on ?? '' ) ) ); ?>">
			<?php $form->print_error( 'signed_on' ); ?>
		</td>
	</tr>
	<?php if ( array() !== $managers ) : ?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $form->id( 'manager_id' ) ); ?>"><?php esc_html_e( 'מנהל הפרויקט', 'diromax-portal' ); ?></label></th>
			<td>
				<select <?php echo $form->attrs( 'manager_id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
					<?php $selected = $form->old( 'manager_id', (string) ( $current->manager_id ?? $view['user'] ?? get_current_user_id() ) ); ?>
					<?php foreach ( $managers as $manager_id => $name ) : ?>
						<option value="<?php echo esc_attr( (string) $manager_id ); ?>"<?php selected( (string) $manager_id, $selected ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php $form->print_error( 'manager_id' ); ?>
			</td>
		</tr>
	<?php endif; ?>
</table>
