<?php
/**
 * Email layout: brand strip, header, headline, body part, button, manager card, footer.
 *
 * Built for email clients: tables, inline styles, 600px, RTL on every table,
 * a VML button for Outlook. The <style> block holds only what cannot be
 * inlined (mobile and dark mode); clients that strip it still render correctly.
 *
 * Every value is escaped here, at the point of output.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$font     = "Heebo, Arial, 'Segoe UI', sans-serif";
$manager  = is_array( $view['manager'] ?? null ) ? $view['manager'] : null;
$cta      = is_array( $view['cta'] ?? null ) ? $view['cta'] : null;
$headline = (string) ( $view['headline'] ?? '' );
?>
<!DOCTYPE html>
<html lang="he" dir="rtl" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?php echo esc_html( $headline ); ?></title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>table,td,th,div,p,a,span{font-family:Arial,sans-serif!important;}</style><![endif]-->
<style>
body{margin:0;padding:0;-webkit-text-size-adjust:100%;}
a{text-decoration:none;}
@media (max-width:620px){.dx-container{width:100%!important}.dx-px{padding-left:20px!important;padding-right:20px!important}.dx-stack{display:block!important;width:100%!important;box-sizing:border-box}.dx-h1{font-size:25px!important}}
@media (prefers-color-scheme:dark){.dx-page{background:#0f1115!important}.dx-card{background:#171a20!important;border-color:#c9ccd2!important}.dx-item{background:#1d2129!important;border-color:#c9ccd2!important}.dx-text{color:#f4f4f2!important}.dx-body{color:#d0d2d6!important}.dx-muted{color:#a8abb2!important}.dx-line{border-color:#3a3d44!important}.dx-link{color:#9fd4e6!important}}
</style>
</head>
<body class="dx-page" dir="rtl" style="margin:0;padding:0;background:#eae9e6;">
<div class="dx-preheader" style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#eae9e6;"><?php echo esc_html( (string) ( $view['preheader'] ?? '' ) ); ?>&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>

<table role="presentation" class="dx-page" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#eae9e6" style="background:#eae9e6;">
<tr><td align="center" style="padding:28px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr><td><![endif]-->
<table role="presentation" class="dx-container dx-card" width="600" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#ffffff" style="width:600px;max-width:600px;background:#ffffff;border:2px solid #15171c;">

	<tr><td style="font-size:0;line-height:0;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
			<td width="33%" height="6" bgcolor="#1d3a8f" style="height:6px;background:#1d3a8f;font-size:0;line-height:0;">&nbsp;</td>
			<td width="34%" height="6" bgcolor="#2fa6c7" style="height:6px;background:#2fa6c7;font-size:0;line-height:0;">&nbsp;</td>
			<td width="33%" height="6" bgcolor="#ee7c1a" style="height:6px;background:#ee7c1a;font-size:0;line-height:0;">&nbsp;</td>
		</tr></table>
	</td></tr>

	<tr><td class="dx-px dx-line" style="padding:18px 32px;border-bottom:2px solid #15171c;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
			<td align="right" valign="middle" style="text-align:right;">
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
					<?php if ( '' !== $view['logo'] ) : ?>
						<td valign="middle" bgcolor="#ffffff" style="background:#ffffff;padding:2px;"><img src="<?php echo esc_url( (string) $view['logo'] ); ?>" width="44" height="40" alt="" style="display:block;width:44px;height:40px;border:0;"></td>
					<?php endif; ?>
					<td valign="middle" class="dx-link" style="padding-right:10px;font-family:Arial,sans-serif;font-size:18px;font-weight:800;letter-spacing:1px;color:#1d3a8f;">DIROMAX</td>
				</tr></table>
			</td>
			<td align="left" valign="middle" style="text-align:left;font-family:<?php echo esc_attr( $font ); ?>;font-size:14px;font-weight:700;">
				<a href="<?php echo esc_url( (string) $view['portal'] ); ?>" class="dx-link" style="color:#1d3a8f;text-decoration:none;"><?php esc_html_e( 'לאזור האישי', 'diromax-portal' ); ?></a>
			</td>
		</tr></table>
	</td></tr>

	<tr><td class="dx-px" style="padding:32px 32px 8px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;">
		<table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin-bottom:16px;"><tr>
			<td width="10" height="10" bgcolor="#ee7c1a" style="width:10px;height:10px;background:#ee7c1a;font-size:0;line-height:0;">&nbsp;</td>
			<td class="dx-link" style="padding-right:9px;font-size:13px;font-weight:800;letter-spacing:0.5px;color:#1d3a8f;"><?php echo esc_html( (string) ( $view['eyebrow'] ?? '' ) ); ?></td>
		</tr></table>
		<h1 class="dx-h1 dx-text" style="margin:0 0 14px;font-family:<?php echo esc_attr( $font ); ?>;font-size:30px;line-height:1.15;font-weight:900;color:#15171c;"><?php echo esc_html( $headline ); ?></h1>
		<p class="dx-body" style="margin:0;font-size:16px;line-height:1.6;color:#3a3d44;">
			<?php if ( '' !== (string) ( $view['greeting'] ?? '' ) ) : ?>
				<?php
				/* translators: %s: recipient name. */
				echo esc_html( sprintf( __( 'שלום %s,', 'diromax-portal' ), (string) $view['greeting'] ) );
				?>
				<br>
			<?php endif; ?>
			<?php echo esc_html( (string) ( $view['intro'] ?? '' ) ); ?>
		</p>
	</td></tr>

	<?php
	$part = in_array( $view['body'] ?? '', array( 'case-update', 'message' ), true ) ? (string) $view['body'] : 'notice';
	require __DIR__ . '/parts/' . $part . '.php';
	?>

	<?php if ( null !== $cta ) : ?>
	<tr><td class="dx-px" align="right" style="padding:28px 32px 8px;text-align:right;">
		<!--[if mso]><v:rect xmlns:v="urn:schemas-microsoft-com:vml" href="<?php echo esc_url( (string) $cta['url'] ); ?>" style="height:54px;v-text-anchor:middle;width:280px;" stroke="f" fillcolor="#ee7c1a"><w:anchorlock/><center style="color:#15171c;font-family:Arial,sans-serif;font-size:17px;font-weight:bold;"><?php echo esc_html( (string) $cta['label'] ); ?></center></v:rect><![endif]-->
		<!--[if !mso]><!-->
		<table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
			<td bgcolor="#ee7c1a" style="background:#ee7c1a;"><a href="<?php echo esc_url( (string) $cta['url'] ); ?>" style="display:inline-block;padding:17px 30px;font-family:<?php echo esc_attr( $font ); ?>;font-size:17px;font-weight:800;color:#15171c;text-decoration:none;"><?php echo esc_html( (string) $cta['label'] ); ?> &larr;</a></td>
		</tr></table>
		<!--<![endif]-->
	</td></tr>
	<?php endif; ?>

	<?php if ( null !== $manager ) : ?>
	<tr><td class="dx-px" style="padding:24px 32px 30px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
			<td class="dx-line" style="border-top:1px solid #cfcdc8;padding-top:20px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;">
				<p class="dx-muted" style="margin:0 0 4px;font-size:13px;font-weight:800;letter-spacing:0.5px;color:#55585f;"><?php esc_html_e( 'מנהל הפרויקט שלך', 'diromax-portal' ); ?></p>
				<p class="dx-text" style="margin:0 0 4px;font-size:17px;font-weight:800;color:#15171c;"><?php echo esc_html( (string) $manager['name'] ); ?></p>
				<?php if ( '' !== $manager['phone'] ) : ?>
				<p class="dx-body" style="margin:0 0 10px;font-size:15px;color:#3a3d44;">
					<a href="<?php echo esc_url( 'tel:' . $manager['tel'] ); ?>" class="dx-link" style="color:#1d3a8f;text-decoration:none;"><span dir="ltr"><?php echo esc_html( (string) $manager['phone'] ); ?></span></a>
					<?php if ( '' !== $manager['whatsapp'] ) : ?>
						&nbsp;·&nbsp;<a href="<?php echo esc_url( (string) $manager['whatsapp'] ); ?>" class="dx-link" style="color:#1d3a8f;text-decoration:none;"><?php esc_html_e( 'וואטסאפ', 'diromax-portal' ); ?></a>
					<?php endif; ?>
				</p>
				<?php endif; ?>
				<p class="dx-body" style="margin:0;font-size:15px;line-height:1.55;color:#3a3d44;">
				<?php
				if ( ! empty( $view['into_case'] ) ) {
					esc_html_e( 'יש שאלה? אפשר פשוט להשיב למייל הזה. התשובה נשמרת בתיק ומגיעה למנהל הפרויקט.', 'diromax-portal' );
				} else {
					esc_html_e( 'יש שאלה? אפשר פשוט להשיב למייל הזה. התשובה מגיעה ישירות למנהל הפרויקט.', 'diromax-portal' );
				}
				?>
				</p>
			</td>
		</tr></table>
	</td></tr>
	<?php else : ?>
	<tr><td style="padding:0 0 24px;font-size:0;line-height:0;">&nbsp;</td></tr>
	<?php endif; ?>

	<tr><td class="dx-px" bgcolor="#15171c" style="background:#15171c;padding:22px 32px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;font-size:13px;line-height:1.6;color:#a8abb2;">
		<p style="margin:0 0 6px;color:#f4f4f2;font-weight:700;"><?php esc_html_e( 'דירומקס · יזמות וניהול השבחת נכסים', 'diromax-portal' ); ?></p>
		<p style="margin:0 0 6px;">
			<?php
			if ( is_string( $view['case'] ?? null ) ) {
				/* translators: %s: case address. */
				echo esc_html( sprintf( __( 'המייל נשלח לכתובת הזו כי היא רשומה בתיק %s. הנתונים נכונים לשעת השליחה, והמעודכנים תמיד באזור האישי.', 'diromax-portal' ), $view['case'] ) );
			} else {
				esc_html_e( 'המייל נשלח כי התבקש קישור כניסה לכתובת הזו.', 'diromax-portal' );
			}
			?>
		</p>
		<p style="margin:0;"><a href="<?php echo esc_url( (string) $view['site_url'] ); ?>" style="color:#f4f4f2;text-decoration:underline;"><?php echo esc_html( (string) $view['site_host'] ); ?></a></p>
	</td></tr>

</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
