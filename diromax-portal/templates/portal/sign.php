<?php
/**
 * Portal: signing one document through an emailed link (spec 16.6).
 *
 * States: closed (link unknown, used, cancelled or expired), start (send a
 * code to the address), form (code and details), signed.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Security\Turnstile;

defined( 'ABSPATH' ) || exit;

$state       = (string) ( $view['state'] ?? 'closed' );
$notice      = $view['notice'] ?? null;
$sign_errors = (array) ( $view['errors'] ?? array() );
$sign_old    = (array) ( $view['old'] ?? array() );
$document    = $view['document'] ?? null;
$sign_req    = (array) ( $view['request'] ?? array() );
$err         = static function ( string $field, string $text ) use ( $sign_errors ): void {
	if ( isset( $sign_errors[ $field ] ) ) {
		printf( '<p class="dxp-field__err" id="dxp-sign-%1$s-err">%2$s</p>', esc_attr( $field ), esc_html( $text ) );
	}
};
$invalid     = static fn( string $field ): string => isset( $sign_errors[ $field ] ) ? ' aria-invalid="true" aria-describedby="dxp-sign-' . esc_attr( $field ) . '-err"' : '';
?>
<section class="dxp-auth" aria-labelledby="dxp-sign-title">
	<div class="dxp-wrap dxp-auth__in">
		<p class="dxp-eyebrow"><span class="dxp-dot" aria-hidden="true"></span><?php esc_html_e( 'חתימה על מסמך', 'diromax-portal' ); ?></p>

		<?php if ( 'closed' === $state ) : ?>
			<h1 id="dxp-sign-title"><?php esc_html_e( 'הקישור כבר לא פעיל', 'diromax-portal' ); ?></h1>
			<p class="dxp-lead"><?php esc_html_e( 'כבר חתמו דרכו, הבקשה בוטלה, או שעברו 14 יום. אם צריך לחתום, בקשו קישור חדש ממנהל הפרויקט בדירומקס.', 'diromax-portal' ); ?></p>

		<?php elseif ( 'signed' === $state ) : ?>
			<h1 id="dxp-sign-title"><?php esc_html_e( 'החתימה התקבלה. תודה!', 'diromax-portal' ); ?></h1>
			<p class="dxp-lead" role="status"><?php esc_html_e( 'שלחנו אליך מייל עם אישור החתימה וטביעת האצבע של הקובץ שעליו חתמת. אפשר לסגור את העמוד.', 'diromax-portal' ); ?></p>

		<?php else : ?>
			<h1 id="dxp-sign-title"><?php echo esc_html( null === $document ? '' : $document->title ); ?></h1>
			<p class="dxp-lead">
				<?php
				/* translators: 1: signer's name, 2: case address. */
				echo esc_html( sprintf( __( 'שלום %1$s. המסמך נשלח אליך לחתימה מדירומקס, בעניין %2$s.', 'diromax-portal' ), (string) ( $sign_req['signer_name'] ?? '' ), (string) ( $view['case']->title ?? '' ) ) );
				?>
			</p>
			<p><a class="dxp-btn dxp-btn--ink" href="<?php echo esc_url( (string) $view['file'] ); ?>"><?php esc_html_e( 'קריאת המסמך', 'diromax-portal' ); ?></a></p>

			<?php if ( null !== $notice ) : ?>
				<p class="dxp-notice dxp-notice--<?php echo esc_attr( (string) $notice['type'] ); ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><?php echo esc_html( (string) $notice['text'] ); ?></p>
			<?php endif; ?>

			<?php if ( 'start' === $state ) : ?>
				<form method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>" class="dxp-auth__form">
					<input type="hidden" name="dx_step" value="code">
					<?php wp_nonce_field( 'dx_portal_sign_' . (int) $sign_req['id'], '_dx_nonce', false ); ?>
					<p class="dxp-note"><?php esc_html_e( 'כדי לוודא שהחתימה שלך, נשלח קוד בן 6 ספרות לכתובת המייל שאליה הגיע הקישור.', 'diromax-portal' ); ?></p>
					<?php if ( Turnstile::enabled() ) : ?>
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( Turnstile::site_key() ); ?>" data-action="<?php echo esc_attr( Turnstile::ACTION ); ?>" data-language="he"></div>
					<?php endif; ?>
					<p><button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'שליחת קוד למייל', 'diromax-portal' ); ?></button></p>
				</form>

			<?php else : ?>
				<form method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>" class="dxp-auth__form" data-dxp-sign>
					<input type="hidden" name="dx_step" value="sign">
					<?php wp_nonce_field( 'dx_portal_sign_' . (int) $sign_req['id'], '_dx_nonce', false ); ?>
					<p class="dxp-field">
						<label for="dxp-sign-code"><?php esc_html_e( 'הקוד מהמייל', 'diromax-portal' ); ?></label>
						<input type="text" id="dxp-sign-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" dir="ltr" required<?php echo $invalid( 'code' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $invalid. ?>>
						<?php $err( 'code', 'expired' === ( $sign_errors['code'] ?? '' ) ? __( 'הקוד פג או שהוזן שגוי יותר מדי פעמים. אפשר לבקש קוד חדש למטה.', 'diromax-portal' ) : __( 'הקוד לא נכון. בדקו שוב במייל.', 'diromax-portal' ) ); ?>
					</p>
					<p class="dxp-field">
						<label for="dxp-sign-id"><?php esc_html_e( 'מספר תעודת זהות', 'diromax-portal' ); ?></label>
						<input type="text" id="dxp-sign-id" name="id_number" inputmode="numeric" maxlength="12" dir="ltr" required autocomplete="off" value="<?php echo esc_attr( (string) ( $sign_old['id_number'] ?? '' ) ); ?>"<?php echo $invalid( 'id_number' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $invalid. ?>>
						<?php $err( 'id_number', __( 'מספר תעודת הזהות לא תקין. בודקים את כל 9 הספרות, כולל ספרת הביקורת.', 'diromax-portal' ) ); ?>
					</p>
					<?php
					$pad = array(
						'id'          => 'dxp-sign-link',
						'name_field'  => 'name',
						'image_field' => 'image',
						'name'        => (string) ( $sign_old['name'] ?? ( $sign_req['signer_name'] ?? '' ) ),
						'errors'      => $sign_errors,
					);
					require __DIR__ . '/parts/signature-pad.php';
					?>
					<p class="dxp-check">
						<input type="checkbox" id="dxp-sign-ok" name="confirm" value="1" required<?php echo $invalid( 'confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $invalid. ?>>
						<label for="dxp-sign-ok"><?php esc_html_e( 'קראתי את המסמך ואני חותם/ת עליו', 'diromax-portal' ); ?></label>
					</p>
					<?php $err( 'confirm', __( 'צריך לסמן שקראת את המסמך.', 'diromax-portal' ) ); ?>
					<p class="dxp-note"><?php esc_html_e( 'נשמרים: השם, מספר תעודת הזהות והחתימה (מוצפנים), טביעת אצבע של הקובץ, התאריך והשעה, כתובת ה-IP והדפדפן.', 'diromax-portal' ); ?></p>
					<p><button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'חתימה על המסמך', 'diromax-portal' ); ?></button></p>
				</form>
				<form method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>">
					<input type="hidden" name="dx_step" value="code">
					<?php wp_nonce_field( 'dx_portal_sign_' . (int) $sign_req['id'], '_dx_nonce', false ); ?>
					<?php if ( Turnstile::enabled() ) : ?>
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( Turnstile::site_key() ); ?>" data-action="<?php echo esc_attr( Turnstile::ACTION ); ?>" data-language="he"></div>
					<?php endif; ?>
					<p><button type="submit" class="dxp-btn dxp-btn--line"><?php esc_html_e( 'שליחת קוד חדש', 'diromax-portal' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</section>
