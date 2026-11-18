<?php
/**
 * Email part: a short note under the intro (login and invitation emails).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 * @var string               $font
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<tr><td class="dx-px" style="padding:20px 32px 0;">
	<table role="presentation" class="dx-item" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#f4f4f2" style="background:#f4f4f2;border:2px solid #15171c;">
		<tr><td class="dx-body" style="padding:16px 20px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;font-size:15px;line-height:1.6;color:#3a3d44;">
			<?php echo esc_html( (string) ( $view['notice'] ?? '' ) ); ?>
		</td></tr>
	</table>
</td></tr>
