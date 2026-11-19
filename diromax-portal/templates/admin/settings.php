<?php
/**
 * Admin: portal settings.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$settings = $view['settings'];
$header   = array(
	'current' => 'settings',
	'eyebrow' => __( 'תיקי לקוחות', 'diromax-portal' ),
	'title'   => __( 'הגדרות האזור האישי', 'diromax-portal' ),
	'meta'    => array( __( 'כספים, מיילים ללקוחות, תזכורות ושמירת מידע. חלות על כל התיקים.', 'diromax-portal' ) ),
);
?>
<div class="wrap dxa">
	<?php require __DIR__ . '/parts/header.php'; ?>
	<?php require __DIR__ . '/parts/flash.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dx_portal_save_settings">
		<?php wp_nonce_field( 'dx_portal_save_settings' ); ?>

		<h2><?php esc_html_e( 'כספים', 'diromax-portal' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dxa-vat"><?php esc_html_e( 'שיעור מע״מ (%)', 'diromax-portal' ); ?></label></th>
				<td>
					<input type="number" id="dxa-vat" name="settings[vat_rate]" value="<?php echo esc_attr( (string) ( $settings['vat_rate'] / 100 ) ); ?>" min="0" max="50" step="0.01" dir="ltr" class="small-text">
					<p class="description"><?php esc_html_e( 'נשמר על כל תנועה בזמן ההזנה. שינוי כאן לא משנה תנועות קיימות.', 'diromax-portal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dxa-payment"><?php esc_html_e( 'פרטי חשבון לתשלום לדירומקס', 'diromax-portal' ); ?></label></th>
				<td>
					<textarea id="dxa-payment" name="settings[payment_details]" rows="3" class="large-text" maxlength="500"><?php echo esc_textarea( $settings['payment_details'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'מוצג בדרישות תשלום לדירומקס שלא נכתבו בהן פרטים אחרים. ברוב התיקים הלקוח משלם ישירות לספק, ואז הפרטים נכתבים בדרישה עצמה.', 'diromax-portal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dxa-categories"><?php esc_html_e( 'סעיפי תקציב', 'diromax-portal' ); ?></label></th>
				<td>
					<textarea id="dxa-categories" name="settings[categories]" rows="12" class="large-text code" dir="rtl"><?php echo esc_textarea( (string) $view['categories'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'סעיף בכל שורה: מפתח באנגלית, סימן שווה, שם בעברית. לא משנים מפתח של סעיף שכבר בשימוש.', 'diromax-portal' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'מיילים ללקוחות', 'diromax-portal' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dxa-quiet"><?php esc_html_e( 'איחוד עדכונים: המתנה אחרי העדכון האחרון (דקות)', 'diromax-portal' ); ?></label></th>
				<td><input type="number" id="dxa-quiet" name="settings[quiet_minutes]" value="<?php echo esc_attr( (string) $settings['quiet_minutes'] ); ?>" min="1" max="120" dir="ltr" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="dxa-maxwait"><?php esc_html_e( 'המתנה מרבית מהעדכון הראשון (דקות)', 'diromax-portal' ); ?></label></th>
				<td><input type="number" id="dxa-maxwait" name="settings[max_wait_minutes]" value="<?php echo esc_attr( (string) $settings['max_wait_minutes'] ); ?>" min="5" max="720" dir="ltr" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'סכומים במייל', 'diromax-portal' ); ?></th>
				<td>
					<input type="checkbox" id="dxa-hide" name="settings[hide_amounts]" value="1"<?php checked( $settings['hide_amounts'] ); ?>>
					<label for="dxa-hide"><?php esc_html_e( 'להסתיר סכומים מהמיילים (הלקוח רואה אותם רק באזור האישי)', 'diromax-portal' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dxa-hour"><?php esc_html_e( 'שעת השליחה היומית', 'diromax-portal' ); ?></label></th>
				<td>
					<input type="number" id="dxa-hour" name="settings[daily_hour]" value="<?php echo esc_attr( (string) $settings['daily_hour'] ); ?>" min="0" max="23" dir="ltr" class="small-text">
					<p class="description"><?php esc_html_e( 'תזכורות תשלום, סיכומים יומיים והסיכום החודשי יוצאים פעם ביום, אחרי השעה הזו (לפי אזור הזמן של האתר).', 'diromax-portal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'סיכום חודשי', 'diromax-portal' ); ?></th>
				<td>
					<input type="checkbox" id="dxa-monthly" name="settings[monthly_summary]" value="1"<?php checked( $settings['monthly_summary'] ); ?>>
					<label for="dxa-monthly"><?php esc_html_e( 'לשלוח ב-1 לכל חודש סיכום של החודש שעבר לכל תיק פעיל', 'diromax-portal' ); ?></label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'תזכורות תשלום', 'diromax-portal' ); ?></h2>
		<p><?php esc_html_e( 'רק על דרישה פתוחה שגלויה ללקוח, בתיק פעיל. 0 מכבה את סוג התזכורת.', 'diromax-portal' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dxa-before"><?php esc_html_e( 'תזכורת לפני המועד (ימים)', 'diromax-portal' ); ?></label></th>
				<td><input type="number" id="dxa-before" name="settings[remind_before_days]" value="<?php echo esc_attr( (string) $settings['remind_before_days'] ); ?>" min="0" max="14" dir="ltr" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="dxa-every"><?php esc_html_e( 'אחרי המועד: תזכורת כל (ימים)', 'diromax-portal' ); ?></label></th>
				<td><input type="number" id="dxa-every" name="settings[remind_overdue_every]" value="<?php echo esc_attr( (string) $settings['remind_overdue_every'] ); ?>" min="0" max="30" dir="ltr" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="dxa-max"><?php esc_html_e( 'אחרי המועד: עד כמה תזכורות', 'diromax-portal' ); ?></label></th>
				<td><input type="number" id="dxa-max" name="settings[remind_overdue_max]" value="<?php echo esc_attr( (string) $settings['remind_overdue_max'] ); ?>" min="0" max="10" dir="ltr" class="small-text"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'לוח בקרה', 'diromax-portal' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dxa-stale"><?php esc_html_e( 'תיק בלי עדכון ללקוח אחרי (ימים)', 'diromax-portal' ); ?></label></th>
				<td><input type="number" id="dxa-stale" name="settings[stale_days]" value="<?php echo esc_attr( (string) $settings['stale_days'] ); ?>" min="3" max="90" dir="ltr" class="small-text"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'גישה ושימור', 'diromax-portal' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dxa-access"><?php esc_html_e( 'גישת לקוח לתיק סגור (חודשים)', 'diromax-portal' ); ?></label></th>
				<td><input type="number" id="dxa-access" name="settings[access_months]" value="<?php echo esc_attr( (string) $settings['access_months'] ); ?>" min="1" max="120" dir="ltr" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'מפתח הצפנת מסמכים', 'diromax-portal' ); ?></th>
				<td><?php echo $view['key_ready'] ? esc_html__( 'מוגדר.', 'diromax-portal' ) : '<strong>' . esc_html__( 'לא מוגדר: העלאת מסמכים חסומה. ההוראות ב-README של התוסף.', 'diromax-portal' ) . '</strong>'; ?></td>
			</tr>
		</table>

		<?php submit_button( __( 'שמירת ההגדרות', 'diromax-portal' ) ); ?>
	</form>
</div>
