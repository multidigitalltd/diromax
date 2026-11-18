<?php
/**
 * Portal part: agreement card and documents by kind.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$agreement = $view['agreement'];
$groups    = $view['documents'];
$terms     = array_filter(
	array(
		__( 'נחתם', 'diromax-portal' )      => $agreement['signed'],
		__( 'היקף', 'diromax-portal' )      => $agreement['scope'],
		__( 'מסלול', 'diromax-portal' )     => $agreement['track'],
		__( 'שכר ניהול', 'diromax-portal' ) => $agreement['fee'],
	)
);
?>
<section class="dxp-panel" aria-labelledby="dxp-docs-title">
	<h2 id="dxp-docs-title" class="dxp-sr"><?php esc_html_e( 'הסכם ומסמכים', 'diromax-portal' ); ?></h2>
	<div class="dxp-split dxp-split--top">
		<div class="dxp-agr">
			<div class="dxp-agr__h">
				<h3><?php esc_html_e( 'הסכם ניהול השבחה', 'diromax-portal' ); ?></h3>
				<?php if ( '' !== $agreement['url'] ) : ?>
					<a class="dxp-btn dxp-btn--orange" href="<?php echo esc_url( (string) $agreement['url'] ); ?>"><?php esc_html_e( 'הורדת ההסכם', 'diromax-portal' ); ?> <small>(<?php echo esc_html( (string) $agreement['size'] ); ?>)</small></a>
				<?php endif; ?>
			</div>
			<?php if ( array() === $terms ) : ?>
				<p class="dxp-agr__empty"><?php esc_html_e( 'פרטי ההסכם יופיעו כאן אחרי החתימה.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<dl>
					<?php foreach ( $terms as $label => $value ) : ?>
						<dt><?php echo esc_html( (string) $label ); ?></dt>
						<dd><?php echo esc_html( (string) $value ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php if ( $agreement['can_ack'] && '' !== $agreement['confirmed'] ) : ?>
				<p class="dxp-ok">
					<?php
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'אישרת שקראת את ההסכם: %s', 'diromax-portal' ), $agreement['confirmed'] ) );
					?>
				</p>
			<?php elseif ( $agreement['can_ack'] ) : ?>
				<form class="dxp-ack" method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>" id="agreement-ack">
					<input type="hidden" name="dx_step" value="acknowledge">
					<input type="hidden" name="document" value="<?php echo esc_attr( (string) $agreement['id'] ); ?>">
					<?php wp_nonce_field( 'dx_portal_ack_' . (int) $agreement['id'], '_dx_nonce', false ); ?>
					<p class="dxp-check">
						<input type="checkbox" id="dxp-ack-ok" name="confirm" value="1" required>
						<label for="dxp-ack-ok"><?php esc_html_e( 'קראתי את ההסכם', 'diromax-portal' ); ?></label>
					</p>
					<p><button type="submit" class="dxp-btn dxp-btn--ink"><?php esc_html_e( 'אישור קריאה', 'diromax-portal' ); ?></button></p>
					<p class="dxp-dec__note"><?php esc_html_e( 'זה לא חתימה: רק אישור שהמסמך הגיע אליך וקראת אותו. נרשם התאריך והגרסה של הקובץ.', 'diromax-portal' ); ?></p>
				</form>
			<?php endif; ?>
		</div>

		<div class="dxp-docs">
			<?php if ( array() === $groups ) : ?>
				<p class="dxp-lead"><?php esc_html_e( 'עדיין אין מסמכים בתיק.', 'diromax-portal' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $groups as $label => $documents ) : ?>
				<h3><?php echo esc_html( (string) $label ); ?></h3>
				<ul>
					<?php foreach ( $documents as $document ) : ?>
						<li>
							<a href="<?php echo esc_url( (string) $document['url'] ); ?>"><?php echo esc_html( (string) $document['title'] ); ?></a>
							<small><?php echo esc_html( (string) $document['meta'] ); ?> · <span class="dxp-num"><?php echo esc_html( (string) $document['date'] ); ?></span></small>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</div>
	</div>
</section>
