<?php
/**
 * Portal part: the signature fields — full name, a drawing pad, and the
 * accessible alternative (the typed name is the signature). The pad needs
 * JavaScript; without it, the "cannot draw" box is the way to sign.
 *
 * @package Diromax\Portal
 *
 * @var array{id:string, name_field:string, image_field:string, name:string, errors:array<string, string>, required?:bool} $pad
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$dxp_pad_errors = $pad['errors'];
$dxp_pad_error  = static function ( string $field, string $id, string $text ) use ( $dxp_pad_errors ): void {
	if ( isset( $dxp_pad_errors[ $field ] ) ) {
		printf( '<p class="dxp-field__err" id="%1$s">%2$s</p>', esc_attr( $id ), esc_html( $text ) );
	}
};
?>
<p class="dxp-field">
	<label for="<?php echo esc_attr( $pad['id'] . '-name' ); ?>"><?php esc_html_e( 'שם מלא', 'diromax-portal' ); ?></label>
	<input type="text" id="<?php echo esc_attr( $pad['id'] . '-name' ); ?>" name="<?php echo esc_attr( $pad['name_field'] ); ?>" value="<?php echo esc_attr( $pad['name'] ); ?>" maxlength="100"<?php echo false === ( $pad['required'] ?? true ) ? '' : ' required'; ?> autocomplete="name"<?php echo isset( $dxp_pad_errors['name'] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $pad['id'] . '-name-err' ) . '"' : ''; ?>>
	<?php $dxp_pad_error( 'name', $pad['id'] . '-name-err', __( 'צריך שם מלא, לפחות שני תווים.', 'diromax-portal' ) ); ?>
</p>
<div class="dxp-pad" data-dxp-pad hidden>
	<p class="dxp-pad__label" id="<?php echo esc_attr( $pad['id'] . '-pad' ); ?>"><?php esc_html_e( 'החתימה: מציירים באצבע או בעכבר בתוך המסגרת', 'diromax-portal' ); ?></p>
	<canvas class="dxp-pad__canvas" width="600" height="200" aria-labelledby="<?php echo esc_attr( $pad['id'] . '-pad' ); ?>"></canvas>
	<input type="hidden" name="<?php echo esc_attr( $pad['image_field'] ); ?>" value="" data-dxp-pad-value>
	<p class="dxp-pad__tools"><button type="button" class="dxp-btn dxp-btn--line" data-dxp-pad-clear><?php esc_html_e( 'ניקוי החתימה', 'diromax-portal' ); ?></button></p>
</div>
<p class="dxp-check">
	<input type="checkbox" id="<?php echo esc_attr( $pad['id'] . '-nodraw' ); ?>" name="no_draw" value="1"<?php echo isset( $dxp_pad_errors['image'] ) ? ' aria-describedby="' . esc_attr( $pad['id'] . '-image-err' ) . '"' : ''; ?>>
	<label for="<?php echo esc_attr( $pad['id'] . '-nodraw' ); ?>"><?php esc_html_e( 'אין לי אפשרות לצייר: השם המלא שהקלדתי הוא החתימה שלי', 'diromax-portal' ); ?></label>
</p>
<?php $dxp_pad_error( 'image', $pad['id'] . '-image-err', __( 'צריך לצייר חתימה, או לסמן שאין אפשרות לצייר.', 'diromax-portal' ) ); ?>
