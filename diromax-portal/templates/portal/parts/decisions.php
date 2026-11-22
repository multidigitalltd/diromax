<?php
/**
 * Portal part: decisions put to the client (spec 15.5).
 *
 * Each pending decision is its own form posting back to this page; the
 * server checks the nonce, membership, role and that it is still pending.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$decisions = $view['decisions'];
?>
<section class="dxp-panel" aria-labelledby="dxp-dec-title">
	<h2 id="dxp-dec-title" class="dxp-sr"><?php esc_html_e( 'החלטות', 'diromax-portal' ); ?></h2>
	<?php if ( array() === $decisions ) : ?>
		<p class="dxp-lead"><?php esc_html_e( 'אין כרגע החלטות שמחכות לך.', 'diromax-portal' ); ?></p>
	<?php endif; ?>
	<ul class="dxp-decisions">
		<?php foreach ( $decisions as $decision ) : ?>
			<?php $field = 'dxp-dec-' . (int) $decision['id']; ?>
			<li class="dxp-dec dxp-dec--<?php echo esc_attr( (string) $decision['status'] ); ?>" id="decision-<?php echo esc_attr( (string) $decision['id'] ); ?>">
				<div class="dxp-dec__h">
					<h3><?php echo esc_html( (string) $decision['title'] ); ?></h3>
					<span class="dxp-tag dxp-tag--<?php echo esc_attr( (string) $decision['status'] ); ?>"><?php echo esc_html( (string) $decision['label'] ); ?></span>
				</div>
				<dl class="dxp-dec__meta">
					<dt><?php esc_html_e( 'נשלחה', 'diromax-portal' ); ?></dt>
					<dd class="dxp-num"><?php echo esc_html( (string) $decision['sent'] ); ?></dd>
					<?php if ( '' !== $decision['amount'] ) : ?>
						<dt><?php esc_html_e( 'סכום כולל מע״מ', 'diromax-portal' ); ?></dt>
						<dd class="dxp-num"><?php echo esc_html( (string) $decision['amount'] ); ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $decision['due'] ) : ?>
						<dt><?php esc_html_e( 'מבקשים תשובה עד', 'diromax-portal' ); ?></dt>
						<dd class="dxp-num"><?php echo esc_html( (string) $decision['due'] ); ?></dd>
					<?php endif; ?>
				</dl>
				<p class="dxp-dec__body"><?php echo nl2br( esc_html( (string) $decision['body'] ) ); ?></p>
				<?php if ( null !== $decision['doc'] ) : ?>
					<p><a href="<?php echo esc_url( (string) $decision['doc']['url'] ); ?>"><?php echo esc_html( (string) $decision['doc']['title'] ); ?></a></p>
				<?php endif; ?>

				<?php if ( '' !== $decision['answered'] ) : ?>
					<p class="dxp-dec__ans">
						<?php
						/* translators: 1: answer, 2: who and when. */
						echo esc_html( sprintf( __( '%1$s: %2$s', 'diromax-portal' ), $decision['label'], $decision['answered'] ) );
						?>
						<?php if ( '' !== $decision['note'] ) : ?>
							<br><q><?php echo esc_html( (string) $decision['note'] ); ?></q>
						<?php endif; ?>
					</p>
				<?php elseif ( '' !== $decision['reason'] ) : ?>
					<p class="dxp-dec__ans">
						<?php
						/* translators: %s: reason. */
						echo esc_html( sprintf( __( 'מנהל הפרויקט ביטל את הבקשה: %s', 'diromax-portal' ), $decision['reason'] ) );
						?>
					</p>
				<?php elseif ( $decision['pending'] && $view['can_answer'] ) : ?>
					<form class="dxp-dec__form" method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>">
						<input type="hidden" name="dx_step" value="decide">
						<input type="hidden" name="decision" value="<?php echo esc_attr( (string) $decision['id'] ); ?>">
						<?php wp_nonce_field( 'dx_portal_decide_' . (int) $decision['id'], '_dx_nonce', false ); ?>
						<p>
							<label for="<?php echo esc_attr( $field ); ?>-note"><?php esc_html_e( 'הערה למנהל הפרויקט (רשות)', 'diromax-portal' ); ?></label>
							<textarea id="<?php echo esc_attr( $field ); ?>-note" name="note" rows="2" maxlength="500"></textarea>
						</p>
						<fieldset class="dxp-dec__sign">
							<legend><?php esc_html_e( 'חתימה, לאישור בלבד', 'diromax-portal' ); ?></legend>
							<?php
							$pad = array(
								'id'          => $field . '-sign',
								'name_field'  => 'sign_name',
								'image_field' => 'sign_image',
								'name'        => (string) wp_get_current_user()->display_name,
								'errors'      => array(),
								'required'    => false,
							);
							require __DIR__ . '/signature-pad.php';
							?>
						</fieldset>
						<p class="dxp-check">
							<input type="checkbox" id="<?php echo esc_attr( $field ); ?>-ok" name="confirm" value="1">
							<label for="<?php echo esc_attr( $field ); ?>-ok"><?php esc_html_e( 'קראתי את הפרטים, ואם אני מאשר/ת, אני מאשר/ת בדיוק את מה שכתוב כאן.', 'diromax-portal' ); ?></label>
						</p>
						<p class="dxp-dec__btns">
							<button type="submit" name="answer" value="approve" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'אישור', 'diromax-portal' ); ?></button>
							<button type="submit" name="answer" value="decline" class="dxp-btn"><?php esc_html_e( 'דחייה', 'diromax-portal' ); ?></button>
						</p>
						<p class="dxp-dec__note"><?php esc_html_e( 'אישור נעשה בחתימה. התשובה סופית ונרשמת בתיק עם התאריך, השעה וטביעת אצבע של מה שאושר. אם תרצה לשנות אחר כך, דבר עם מנהל הפרויקט.', 'diromax-portal' ); ?></p>
					</form>
				<?php elseif ( $decision['pending'] ) : ?>
					<p class="dxp-dec__note"><?php esc_html_e( 'ההחלטה מחכה לתשובה של בעלי התיק.', 'diromax-portal' ); ?></p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
