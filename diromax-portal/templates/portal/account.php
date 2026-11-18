<?php
/**
 * Portal: the client's account — email preference per case and password
 * (spec 15.3, 15.10).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Portal\Router;

defined( 'ABSPATH' ) || exit;

$notice = $view['notice'] ?? null;
?>
<section class="dxp-head" aria-labelledby="dxp-account-title">
	<div class="dxp-wrap">
		<p class="dxp-eyebrow"><span class="dxp-dot" aria-hidden="true"></span><?php esc_html_e( 'אזור אישי', 'diromax-portal' ); ?></p>
		<h1 id="dxp-account-title"><?php esc_html_e( 'החשבון שלי', 'diromax-portal' ); ?></h1>
		<?php require __DIR__ . '/parts/account.php'; ?>
		<p><a href="<?php echo esc_url( Router::portal_url() ); ?>"><?php esc_html_e( 'חזרה לתיקים', 'diromax-portal' ); ?></a></p>
	</div>
</section>

<div class="dxp-wrap dxp-pad">
	<?php if ( null !== $notice ) : ?>
		<p class="dxp-notice dxp-notice--<?php echo esc_attr( (string) $notice['type'] ); ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><?php echo esc_html( (string) $notice['text'] ); ?></p>
	<?php endif; ?>

	<div class="dxp-split dxp-split--top">
		<section class="dxp-box" aria-labelledby="dxp-modes-title">
			<h2 id="dxp-modes-title"><?php esc_html_e( 'מיילים על התיק', 'diromax-portal' ); ?></h2>
			<p class="dxp-note"><?php esc_html_e( 'דרישת תשלום, תזכורת והחלטה שמחכה לך נשלחות מיד גם בסיכום יומי. קישורי כניסה והודעות אבטחה נשלחים תמיד.', 'diromax-portal' ); ?></p>
			<form method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>">
				<input type="hidden" name="dx_step" value="modes">
				<?php wp_nonce_field( 'dx_portal_modes', '_dx_nonce', false ); ?>
				<?php foreach ( $view['modes'] as $index => $case_mode ) : ?>
					<fieldset class="dxp-radios">
						<legend><?php echo esc_html( (string) $case_mode['title'] ); ?></legend>
						<?php foreach ( $view['labels'] as $value => $label ) : ?>
							<?php $field = 'dxp-mode-' . (int) $index . '-' . $value; ?>
							<p class="dxp-check">
								<input type="radio" id="<?php echo esc_attr( $field ); ?>" name="mode[<?php echo esc_attr( (string) $case_mode['id'] ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>"<?php checked( $case_mode['mode'], $value ); ?>>
								<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( (string) $label ); ?></label>
							</p>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>
				<button type="submit" class="dxp-btn dxp-btn--ink"><?php esc_html_e( 'שמירת ההעדפות', 'diromax-portal' ); ?></button>
			</form>
		</section>

		<section class="dxp-box" aria-labelledby="dxp-password-title">
			<h2 id="dxp-password-title"><?php echo $view['has_password'] ? esc_html__( 'החלפת סיסמה', 'diromax-portal' ) : esc_html__( 'כניסה עם סיסמה', 'diromax-portal' ); ?></h2>
			<p class="dxp-note">
				<?php
				echo esc_html(
					$view['has_password']
						? __( 'אפשר להיכנס עם הסיסמה או עם קישור למייל, מה שנוח.', 'diromax-portal' )
						: __( 'לא חובה. הכניסה עם קישור למייל ממשיכה לעבוד גם אם תגדיר סיסמה.', 'diromax-portal' )
				);
				?>
			</p>
			<form class="dxp-form" method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>">
				<input type="hidden" name="dx_step" value="password">
				<?php wp_nonce_field( 'dx_portal_password', '_dx_nonce', false ); ?>
				<input type="text" name="username" value="<?php echo esc_attr( (string) wp_get_current_user()->user_email ); ?>" autocomplete="username" hidden>
				<?php if ( ! $view['recent'] ) : ?>
					<p class="dxp-field">
						<label for="dxp-current"><?php esc_html_e( 'הסיסמה הנוכחית', 'diromax-portal' ); ?></label>
						<input type="password" id="dxp-current" name="current" autocomplete="current-password" dir="ltr" aria-describedby="dxp-current-hint">
						<span class="dxp-hint" id="dxp-current-hint"><?php esc_html_e( 'אין סיסמה או שכחת? צא מהחשבון והיכנס שוב עם קישור למייל: אחרי כניסה כזו אפשר לקבוע סיסמה בלי הסיסמה הנוכחית, במשך 15 דקות.', 'diromax-portal' ); ?></span>
					</p>
				<?php endif; ?>
				<p class="dxp-field">
					<label for="dxp-password"><?php esc_html_e( 'סיסמה חדשה', 'diromax-portal' ); ?></label>
					<input type="password" id="dxp-password" name="password" required minlength="<?php echo esc_attr( (string) $view['min'] ); ?>" maxlength="200" autocomplete="new-password" dir="ltr" aria-describedby="dxp-password-hint">
					<span class="dxp-hint" id="dxp-password-hint">
						<?php
						/* translators: %d: minimum length. */
						echo esc_html( sprintf( __( 'לפחות %d תווים. משפט קצר שקל לזכור עדיף על מילה מסובכת.', 'diromax-portal' ), (int) $view['min'] ) );
						?>
					</span>
				</p>
				<p class="dxp-field">
					<label for="dxp-password2"><?php esc_html_e( 'שוב, לאימות', 'diromax-portal' ); ?></label>
					<input type="password" id="dxp-password2" name="password2" required minlength="<?php echo esc_attr( (string) $view['min'] ); ?>" maxlength="200" autocomplete="new-password" dir="ltr">
				</p>
				<button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'שמירת הסיסמה', 'diromax-portal' ); ?></button>
			</form>
		</section>
	</div>
</div>
