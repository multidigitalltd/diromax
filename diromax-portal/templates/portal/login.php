<?php
/**
 * Portal: sign-in.
 *
 * States: form, sent, confirm, invalid, expired, captcha.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Portal\Router;
use Diromax\Portal\Security\Turnstile;

defined( 'ABSPATH' ) || exit;

$state = (string) ( $view['state'] ?? 'form' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
$signed_out = isset( $_GET['out'] );
?>
<section class="dxp-auth" aria-labelledby="dxp-auth-title">
	<div class="dxp-wrap dxp-auth__in">
		<p class="dxp-eyebrow"><span class="dxp-dot" aria-hidden="true"></span><?php esc_html_e( 'אזור אישי', 'diromax-portal' ); ?></p>

		<?php if ( 'sent' === $state ) : ?>
			<h1 id="dxp-auth-title"><?php esc_html_e( 'בדקו את תיבת הדואר', 'diromax-portal' ); ?></h1>
			<p class="dxp-lead" role="status"><?php esc_html_e( 'אם הכתובת רשומה אצלנו, נשלח אליה עכשיו קישור כניסה. הקישור תקף ל-15 דקות ולשימוש אחד.', 'diromax-portal' ); ?></p>
			<p class="dxp-note"><?php esc_html_e( 'לא הגיע מייל בתוך כמה דקות? כדאי לבדוק בתיקיית הספאם, או לפנות למנהל הפרויקט.', 'diromax-portal' ); ?></p>
			<p><a class="dxp-btn" href="<?php echo esc_url( Router::login_url() ); ?>"><?php esc_html_e( 'שליחה לכתובת אחרת', 'diromax-portal' ); ?></a></p>

		<?php elseif ( 'confirm' === $state ) : ?>
			<h1 id="dxp-auth-title"><?php esc_html_e( 'כניסה לאזור האישי', 'diromax-portal' ); ?></h1>
			<p class="dxp-lead"><?php esc_html_e( 'הקישור תקין. לחיצה על הכפתור מכניסה אותך לאזור האישי.', 'diromax-portal' ); ?></p>
			<form class="dxp-form" method="post" action="<?php echo esc_url( Router::login_url() ); ?>">
				<input type="hidden" name="dx_step" value="confirm">
				<input type="hidden" name="t" value="<?php echo esc_attr( (string) $view['token'] ); ?>">
				<?php wp_nonce_field( 'dx_portal_confirm', '_dx_nonce', false ); ?>
				<p class="dxp-check">
					<input type="checkbox" id="dxp-remember" name="remember" value="1">
					<label for="dxp-remember"><?php esc_html_e( 'להישאר מחובר במכשיר הזה ל-14 יום', 'diromax-portal' ); ?></label>
				</p>
				<button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'כניסה', 'diromax-portal' ); ?></button>
			</form>

		<?php else : ?>
			<h1 id="dxp-auth-title"><?php esc_html_e( 'כניסה לאזור האישי', 'diromax-portal' ); ?></h1>

			<?php if ( 'invalid' === $state ) : ?>
				<p class="dxp-alert" role="alert"><?php esc_html_e( 'הקישור פג תוקף או שכבר נעשה בו שימוש. אפשר לבקש קישור חדש כאן.', 'diromax-portal' ); ?></p>
			<?php elseif ( 'expired' === $state ) : ?>
				<p class="dxp-alert" role="alert"><?php esc_html_e( 'פג תוקף הטופס. אפשר לנסות שוב.', 'diromax-portal' ); ?></p>
			<?php elseif ( 'captcha' === $state ) : ?>
				<p class="dxp-alert" role="alert"><?php esc_html_e( 'לא הצלחנו לאמת שהבקשה נשלחה מדפדפן. אפשר לנסות שוב.', 'diromax-portal' ); ?></p>
			<?php elseif ( $signed_out ) : ?>
				<p class="dxp-ok" role="status"><?php esc_html_e( 'יצאת מהאזור האישי.', 'diromax-portal' ); ?></p>
			<?php endif; ?>

			<p class="dxp-lead"><?php esc_html_e( 'בלי סיסמה: מזינים את כתובת הדוא״ל שרשומה בתיק, ומקבלים קישור כניסה חד-פעמי.', 'diromax-portal' ); ?></p>

			<form class="dxp-form" method="post" action="<?php echo esc_url( Router::login_url() ); ?>" novalidate>
				<input type="hidden" name="dx_step" value="request">
				<?php wp_nonce_field( 'dx_portal_login', '_dx_nonce', false ); ?>
				<p class="dxp-field">
					<label for="dxp-email"><?php esc_html_e( 'כתובת דוא״ל', 'diromax-portal' ); ?></label>
					<input type="email" id="dxp-email" name="email" required autocomplete="email" inputmode="email" dir="ltr" maxlength="190">
				</p>
				<p class="dxp-hp" aria-hidden="true">
					<label for="dxp-website"><?php esc_html_e( 'לא למילוי', 'diromax-portal' ); ?></label>
					<input type="text" id="dxp-website" name="dx_website" tabindex="-1" autocomplete="off">
				</p>
				<?php if ( Turnstile::enabled() ) : ?>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( Turnstile::site_key() ); ?>" data-action="<?php echo esc_attr( Turnstile::ACTION ); ?>" data-language="he"></div>
				<?php endif; ?>
				<button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'שלחו לי קישור כניסה', 'diromax-portal' ); ?></button>
			</form>
			<p class="dxp-note"><?php esc_html_e( 'הכתובת משמשת רק לכניסה ולעדכונים על התיק.', 'diromax-portal' ); ?></p>
		<?php endif; ?>
	</div>
</section>
