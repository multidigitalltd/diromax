<?php
/**
 * Email part: update cards and the money snapshot.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 * @var string               $font
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$tones  = array(
	'orange' => array( '#ee7c1a', '#15171c' ),
	'blue'   => array( '#1d3a8f', '#ffffff' ),
	'teal'   => array( '#17728c', '#ffffff' ),
	'ink'    => array( '#15171c', '#ffffff' ),
	'grey'   => array( '#55585f', '#ffffff' ),
);
$stages = is_array( $view['stages'] ?? null ) ? $view['stages'] : array();
$items  = is_array( $view['items'] ?? null ) ? $view['items'] : array();

/** Cards. @var \Diromax\Portal\Service\MailCard[] $items */

foreach ( $items as $index => $item ) :
	$tone = $tones[ $item->tone ] ?? $tones['ink'];
	?>
<tr><td class="dx-px" style="padding:<?php echo 0 === $index ? '24' : '16'; ?>px 32px 0;">
	<table role="presentation" class="dx-item" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#f4f4f2" style="background:#f4f4f2;border:2px solid #15171c;">
		<tr><td style="padding:20px 22px 6px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;">
			<span style="display:inline-block;background:<?php echo esc_attr( $tone[0] ); ?>;color:<?php echo esc_attr( $tone[1] ); ?>;font-size:12.5px;font-weight:800;padding:4px 10px;letter-spacing:0.3px;"><?php echo esc_html( $item->badge ); ?></span>
			<h2 class="dx-text" style="margin:12px 0 0;font-family:<?php echo esc_attr( $font ); ?>;font-size:19px;line-height:1.3;font-weight:800;color:#15171c;"><?php echo esc_html( $item->title ); ?></h2>
		</td></tr>

		<?php if ( array() !== $item->rows || null !== $item->amount ) : ?>
		<tr><td style="padding:6px 22px 4px;">
			<table width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="font-family:<?php echo esc_attr( $font ); ?>;font-size:15px;">
				<?php foreach ( $item->rows as $row ) : ?>
				<tr>
					<th scope="row" align="right" class="dx-muted dx-line" style="text-align:right;font-weight:500;color:#55585f;padding:9px 0;border-bottom:1px solid #cfcdc8;width:38%;"><?php echo esc_html( (string) $row[0] ); ?></th>
					<td align="right" class="dx-text dx-line" style="text-align:right;color:#15171c;padding:9px 0;border-bottom:1px solid #cfcdc8;"><?php echo esc_html( (string) $row[1] ); ?></td>
				</tr>
				<?php endforeach; ?>
				<?php if ( null !== $item->amount ) : ?>
				<tr>
					<th scope="row" align="right" class="dx-muted" style="text-align:right;font-weight:500;color:#55585f;padding:11px 0;"><?php esc_html_e( 'סכום, כולל מע״מ', 'diromax-portal' ); ?></th>
					<td align="right" class="dx-text" style="text-align:right;color:#15171c;padding:11px 0;font-size:20px;font-weight:800;"><span dir="ltr"><?php echo esc_html( (string) $item->amount ); ?></span></td>
				</tr>
				<?php endif; ?>
			</table>
		</td></tr>
		<?php endif; ?>

		<?php if ( '' !== $item->note ) : ?>
		<tr><td class="dx-body" style="padding:6px 22px 4px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;font-size:15.5px;line-height:1.6;color:#3a3d44;">
			<?php echo nl2br( esc_html( $item->note ) ); ?>
		</td></tr>
		<?php endif; ?>

		<?php
		if ( null !== $item->stage ) :
			$current = (int) $item->stage;
			?>
		<tr><td style="padding:16px 22px 4px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
				<?php for ( $n = 1; $n <= 7; $n++ ) : ?>
					<?php
					$step_status = $item->progress[ $n ] ?? ( $n < $current ? 'done' : ( $n === $current ? 'active' : 'pending' ) );
					$color       = 'done' === $step_status ? '#1d3a8f' : ( 'active' === $step_status ? '#ee7c1a' : '#cfcdc8' );
					?>
					<td width="13%" height="6" bgcolor="<?php echo esc_attr( $color ); ?>" style="height:6px;background:<?php echo esc_attr( $color ); ?>;font-size:0;line-height:0;">&nbsp;</td>
					<?php if ( $n < 7 ) : ?>
						<td width="1%" style="font-size:0;line-height:0;">&nbsp;</td>
					<?php endif; ?>
				<?php endfor; ?>
			</tr></table>
		</td></tr>
		<tr><td style="padding:4px 22px 4px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;font-size:14.5px;line-height:1.5;">
			<span class="dx-text" style="color:#15171c;font-weight:800;">
				<?php
				/* translators: 1: stage number, 2: stage name. */
				echo esc_html( sprintf( __( 'שלב %1$s מתוך 07: %2$s', 'diromax-portal' ), str_pad( (string) $current, 2, '0', STR_PAD_LEFT ), (string) ( $stages[ $current ] ?? '' ) ) );
				?>
			</span>
		</td></tr>
		<?php endif; ?>

		<tr><td style="padding:<?php echo null === $item->link ? '12' : '8'; ?>px 22px <?php echo null === $item->link ? '8' : '20'; ?>px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;font-size:15px;font-weight:700;">
			<?php if ( null !== $item->link ) : ?>
				<a href="<?php echo esc_url( $item->link['url'] ); ?>" class="dx-link" style="color:#1d3a8f;text-decoration:underline;"><?php echo esc_html( $item->link['label'] ); ?> &larr;</a>
			<?php endif; ?>
		</td></tr>
	</table>
</td></tr>
<?php endforeach; ?>

<?php
$money = is_array( $view['summary'] ?? null ) ? $view['summary'] : null;

if ( null !== $money ) :
	$paid = max( 1, min( 99, (int) round( $money['share_bps'] / 100 ) ) );
	?>
<tr><td class="dx-px" style="padding:24px 32px 0;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#1d3a8f" style="background:#1d3a8f;">
		<tr><td style="padding:22px 22px 4px;text-align:right;font-family:<?php echo esc_attr( $font ); ?>;">
			<p style="margin:0;font-size:13px;font-weight:800;letter-spacing:0.5px;color:#9fd4e6;"><?php esc_html_e( 'המצב הכספי בתיק, אחרי העדכון', 'diromax-portal' ); ?></p>
		</td></tr>
		<?php
		// Two columns of inline blocks: side by side when the card is wide, one under the other on a phone,
		// with or without the <style> block. Outlook on Windows gets a two-cell table instead.
		$dx_col_open  = '<div class="dx-col" style="display:inline-block;width:100%;max-width:240px;vertical-align:top;font-size:16px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr><td valign="top" style="padding:8px 0 12px 12px;text-align:right;font-family:' . esc_attr( $font ) . ';">';
		$dx_col_close = '</td></tr></table></div>';
		$dx_mso_split = '<!--[if mso]></td><td width="50%" valign="top"><![endif]-->';
		$dx_mso_open  = '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr><td width="50%" valign="top"><![endif]-->';
		$dx_mso_close = '<!--[if mso]></td></tr></table><![endif]-->';
		?>
		<tr><td dir="rtl" style="padding:10px 22px 0;font-size:0;text-align:right;">
			<?php echo $dx_mso_open . $dx_col_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built above from constants and an escaped font. ?>
				<p style="margin:0 0 2px;font-size:14px;color:#d8e2f4;"><?php esc_html_e( 'עלות עד היום', 'diromax-portal' ); ?></p>
				<p style="margin:0;font-size:26px;font-weight:800;color:#ffffff;"><span dir="ltr"><?php echo esc_html( (string) $money['cost'] ); ?></span></p>
				<?php if ( '' !== $money['budget'] ) : ?>
				<p style="margin:2px 0 0;font-size:13.5px;color:#d8e2f4;">
					<?php
					/* translators: 1: share of budget, 2: budget. */
					echo esc_html( sprintf( __( '%1$s מתקציב של %2$s', 'diromax-portal' ), (string) $money['share'], (string) $money['budget'] ) );
					?>
				</p>
				<?php endif; ?>
			<?php echo $dx_col_close . $dx_mso_split . $dx_col_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built above. ?>
				<?php if ( null !== $money['balance'] ) : ?>
				<p style="margin:0 0 2px;font-size:14px;color:#d8e2f4;"><?php esc_html_e( 'יתרה בחשבון הפרויקט', 'diromax-portal' ); ?></p>
				<p style="margin:0;font-size:26px;font-weight:800;color:#ffffff;"><span dir="ltr"><?php echo esc_html( (string) $money['balance'] ); ?></span></p>
				<?php elseif ( null !== $money['committed'] ) : ?>
				<p style="margin:0 0 2px;font-size:14px;color:#d8e2f4;"><?php esc_html_e( 'התחייבויות פתוחות', 'diromax-portal' ); ?></p>
				<p style="margin:0;font-size:26px;font-weight:800;color:#ffffff;"><span dir="ltr"><?php echo esc_html( (string) $money['committed'] ); ?></span></p>
				<?php endif; ?>
			<?php echo $dx_col_close . $dx_mso_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built above. ?>
		</td></tr>
		<?php if ( '' !== $money['budget'] ) : ?>
		<tr><td style="padding:4px 22px 4px;font-size:0;line-height:0;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl"><tr>
				<td width="<?php echo esc_attr( (string) $paid ); ?>%" height="8" bgcolor="#9fd4e6" style="height:8px;background:#9fd4e6;font-size:0;line-height:0;">&nbsp;</td>
				<td width="<?php echo esc_attr( (string) ( 100 - $paid ) ); ?>%" height="8" bgcolor="#3a57a8" style="height:8px;background:#3a57a8;font-size:0;line-height:0;">&nbsp;</td>
			</tr></table>
		</td></tr>
		<?php endif; ?>
		<?php if ( ( null !== $money['balance'] && null !== $money['committed'] ) || null !== $money['due'] ) : ?>
		<tr><td dir="rtl" style="padding:6px 22px 6px;font-size:0;text-align:right;">
			<?php echo $dx_mso_open . $dx_col_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built above. ?>
				<?php if ( null !== $money['balance'] && null !== $money['committed'] ) : ?>
				<p style="margin:0 0 2px;font-size:14px;color:#d8e2f4;"><?php esc_html_e( 'התחייבויות פתוחות', 'diromax-portal' ); ?></p>
				<p style="margin:0;font-size:19px;font-weight:800;color:#ffffff;"><span dir="ltr"><?php echo esc_html( (string) $money['committed'] ); ?></span></p>
				<?php endif; ?>
			<?php echo $dx_col_close . $dx_mso_split . $dx_col_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built above. ?>
				<?php if ( null !== $money['due'] ) : ?>
				<p style="margin:0 0 2px;font-size:14px;color:#d8e2f4;"><?php esc_html_e( 'דרישות תשלום פתוחות', 'diromax-portal' ); ?></p>
				<p style="margin:0;font-size:19px;font-weight:800;color:#f2984b;"><span dir="ltr"><?php echo esc_html( (string) $money['due'] ); ?></span></p>
				<?php endif; ?>
			<?php echo $dx_col_close . $dx_mso_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built above. ?>
		</td></tr>
		<?php endif; ?>
		<tr><td style="padding:0 0 12px;font-size:0;line-height:0;">&nbsp;</td></tr>
	</table>
</td></tr>
<?php endif; ?>
