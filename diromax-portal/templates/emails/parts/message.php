<?php
/**
 * Email part: a client's reply, forwarded to the project manager.
 *
 * The reply is shown as escaped text only: the client's own HTML never
 * reaches the email (standard 4.2.2).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 * @var string               $font
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$dx_reply   = is_array( $view['reply'] ?? null ) ? $view['reply'] : array();
$dx_warning = (string) ( $view['warning'] ?? '' );
$dx_files   = is_array( $dx_reply['files'] ?? null ) ? $dx_reply['files'] : array();
?>
<?php if ( '' !== $dx_warning ) : ?>
<tr><td class="dx-px" style="padding:20px 32px 0;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#fdf0e4" style="background:#fdf0e4;border:2px solid #9a4a06;">
		<tr><td class="dx-body" style="padding:14px 20px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;font-size:15px;line-height:1.6;font-weight:700;color:#5c2c03;">
			<?php echo esc_html( $dx_warning ); ?>
		</td></tr>
	</table>
</td></tr>
<?php endif; ?>
<tr><td class="dx-px" style="padding:20px 32px 0;">
	<table role="presentation" class="dx-item" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#f4f4f2" style="background:#f4f4f2;border:2px solid #15171c;">
		<tr><td class="dx-body" style="padding:16px 20px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;font-size:15px;line-height:1.6;color:#3a3d44;">
			<p style="margin:0 0 4px;font-size:13px;font-weight:800;color:#55585f;">
				<?php echo esc_html( (string) ( $dx_reply['name'] ?? '' ) ); ?>
				<span dir="ltr">&lt;<?php echo esc_html( (string) ( $dx_reply['email'] ?? '' ) ); ?>&gt;</span>
			</p>
			<?php if ( '' !== (string) ( $dx_reply['subject'] ?? '' ) ) : ?>
				<p class="dx-text" style="margin:0 0 12px;font-size:16px;font-weight:800;color:#15171c;"><?php echo esc_html( (string) $dx_reply['subject'] ); ?></p>
			<?php endif; ?>
			<div dir="auto"><?php echo nl2br( esc_html( (string) ( $dx_reply['text'] ?? '' ) ) ); ?></div>
		</td></tr>
	</table>
</td></tr>
<?php if ( array() !== $dx_files ) : ?>
<tr><td class="dx-px" style="padding:16px 32px 0;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;">
	<p class="dx-text" style="margin:0 0 6px;font-size:15px;font-weight:800;color:#15171c;"><?php esc_html_e( 'קבצים בהודעה', 'diromax-portal' ); ?></p>
	<ul class="dx-body" style="margin:0;padding:0 18px 0 0;font-size:15px;line-height:1.6;color:#3a3d44;">
		<?php foreach ( $dx_files as $dx_file ) : ?>
			<li>
				<span dir="auto"><?php echo esc_html( (string) $dx_file['name'] ); ?></span> ·
				<?php
				if ( $dx_file['kept'] && $dx_file['attached'] ) {
					esc_html_e( 'מצורף, ונשמר בתיק', 'diromax-portal' );
				} elseif ( $dx_file['kept'] ) {
					esc_html_e( 'נשמר בתיק (גדול מדי לצירוף)', 'diromax-portal' );
				} elseif ( $dx_file['attached'] ) {
					esc_html_e( 'מצורף, לא נשמר בתיק', 'diromax-portal' );
				} else {
					esc_html_e( 'לא צורף: סוג קובץ שלא מתקבל, או גדול מדי', 'diromax-portal' );
				}
				?>
			</li>
		<?php endforeach; ?>
	</ul>
</td></tr>
<?php endif; ?>
