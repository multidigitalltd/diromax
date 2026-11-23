<?php
/**
 * Admin: portal settings.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\PaymentPlan;
use Diromax\Portal\Domain\Timeline;

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
				<th scope="row"><label for="dxa-categories"><?php esc_html_e( 'תקציב ברירת מחדל לתיק חדש', 'diromax-portal' ); ?></label></th>
				<td>
					<textarea id="dxa-categories" name="settings[categories]" rows="20" class="large-text" dir="rtl" aria-describedby="dxa-categories-help"><?php echo esc_textarea( (string) $view['categories'] ); ?></textarea>
					<p class="description" id="dxa-categories-help"><?php esc_html_e( 'סעיף בכל שורה. סעיף משנה: שורה שמתחילה במקף (-), מתחת לסעיף שלו. כל תיק חדש מתחיל מהרשימה הזו, ובתוך התיק אפשר לשנות אותה בחופשיות. שינוי כאן לא משנה תיקים קיימים.', 'diromax-portal' ); ?></p>
				</td>
			</tr>
		</table>

		<h3 id="dxa-schedule-weeks"><?php esc_html_e( 'משך טיפוסי של כל שלב (לוח זמנים לתיק חדש)', 'diromax-portal' ); ?></h3>
		<p class="description" id="dxa-weeks-help"><?php esc_html_e( 'בשבועות. בלשונית "לוח זמנים" בתיק בוחרים תאריך התחלה, והתאריכים של כל שלב מחושבים מהמשכים האלה, שלב אחרי שלב. שלב עם 0 לא מחושב. אחרי החישוב אפשר לשנות כל תאריך.', 'diromax-portal' ); ?></p>
		<ul class="dxa-weeks" aria-describedby="dxa-weeks-help">
			<?php foreach ( (array) $view['stages'] as $stage_number => $stage_name ) : ?>
				<li>
					<label for="<?php echo esc_attr( 'dxa-weeks-' . $stage_number ); ?>"><?php echo esc_html( $stage_number . '. ' . $stage_name ); ?></label>
					<input type="number" dir="ltr" class="small-text" id="<?php echo esc_attr( 'dxa-weeks-' . $stage_number ); ?>" name="<?php echo esc_attr( 'settings[schedule_weeks][' . $stage_number . ']' ); ?>" value="<?php echo esc_attr( (string) ( $settings['schedule_weeks'][ $stage_number ] ?? 0 ) ); ?>" min="0" max="<?php echo esc_attr( (string) Timeline::MAX_WEEKS ); ?>">
				</li>
			<?php endforeach; ?>
		</ul>

		<h3 id="dxa-plan-template"><?php esc_html_e( 'תוכנית תשלומים לתיק חדש', 'diromax-portal' ); ?></h3>
		<p class="description" id="dxa-plan-help"><?php esc_html_e( 'התשלומים שכל תיק חדש מתחיל איתם. אחוז מחושב מהבסיס של התיק (סך התקציב, או סכום שקובעים בתיק). בתוך התיק אפשר לשנות, להוסיף תשלום בתאריך, ולמחוק. כדי למחוק שורה כאן, מרוקנים את השם ואת הסכום. שינוי כאן לא משנה תיקים קיימים.', 'diromax-portal' ); ?></p>
		<?php
		$plan_rows = array_values( (array) $settings['plan_template'] );
		$plan_size = min( 20, count( $plan_rows ) + 3 );
		?>
		<div class="dxa-scroll"><table class="widefat dxa-table dxa-plan-template" aria-describedby="dxa-plan-help">
			<caption class="screen-reader-text"><?php esc_html_e( 'תוכנית תשלומים לתיק חדש', 'diromax-portal' ); ?></caption>
			<thead><tr>
				<th scope="col">#</th>
				<th scope="col"><?php esc_html_e( 'שם התשלום', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'מתי', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'אחוז או סכום (₪)', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'למי', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'שם הספק', 'diromax-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'ימים לתשלום', 'diromax-portal' ); ?></th>
			</tr></thead>
			<tbody>
			<?php for ( $plan_index = 0; $plan_index < $plan_size; $plan_index++ ) : ?>
				<?php
				$plan_row  = $plan_rows[ $plan_index ] ?? null;
				$plan_name = 'settings[plan][' . $plan_index . ']';
				$plan_id   = 'dxa-plan-' . $plan_index;
				$plan_when = null === $plan_row ? '' : ( 'manual' === $plan_row['trigger_on'] ? 'manual' : 'stage:' . $plan_row['stage'] );
				$plan_kind = null === $plan_row ? 'percent' : $plan_row['kind'];
				$plan_line = $plan_index + 1;
				?>
				<tr>
					<td><?php echo esc_html( (string) $plan_line ); ?></td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $plan_id . '-label' ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'שם התשלום, שורה %d', 'diromax-portal' ), $plan_line ) );
							?>
						</label>
						<input type="text" id="<?php echo esc_attr( $plan_id . '-label' ); ?>" name="<?php echo esc_attr( $plan_name . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $plan_row['label'] ?? '' ) ); ?>" maxlength="120">
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $plan_id . '-when' ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'מתי, שורה %d', 'diromax-portal' ), $plan_line ) );
							?>
						</label>
						<select id="<?php echo esc_attr( $plan_id . '-when' ); ?>" name="<?php echo esc_attr( $plan_name . '[when]' ); ?>">
							<?php foreach ( (array) $view['stages'] as $stage_number => $stage_name ) : ?>
								<option value="<?php echo esc_attr( 'stage:' . $stage_number ); ?>"<?php selected( $plan_when, 'stage:' . $stage_number ); ?>>
									<?php
									/* translators: %s: stage name. */
									echo esc_html( sprintf( __( 'בסיום "%s"', 'diromax-portal' ), $stage_name ) );
									?>
								</option>
							<?php endforeach; ?>
							<option value="manual"<?php selected( $plan_when, 'manual' ); ?>><?php esc_html_e( 'ידני', 'diromax-portal' ); ?></option>
						</select>
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $plan_id . '-kind' ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'סוג, שורה %d', 'diromax-portal' ), $plan_line ) );
							?>
						</label>
						<select id="<?php echo esc_attr( $plan_id . '-kind' ); ?>" name="<?php echo esc_attr( $plan_name . '[kind]' ); ?>">
							<option value="percent"<?php selected( $plan_kind, 'percent' ); ?>><?php esc_html_e( 'אחוז', 'diromax-portal' ); ?></option>
							<option value="fixed"<?php selected( $plan_kind, 'fixed' ); ?>><?php esc_html_e( 'סכום קבוע', 'diromax-portal' ); ?></option>
						</select>
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $plan_id . '-value' ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'אחוז או סכום, שורה %d', 'diromax-portal' ), $plan_line ) );
							?>
						</label>
						<input type="text" inputmode="decimal" dir="ltr" class="small-text" id="<?php echo esc_attr( $plan_id . '-value' ); ?>" name="<?php echo esc_attr( $plan_name . '[value]' ); ?>" value="<?php echo esc_attr( null === $plan_row ? '' : ( 'percent' === $plan_row['kind'] ? PaymentPlan::percent_text( $plan_row['percent'] ) : Money::input( $plan_row['amount'] ) ) ); ?>" maxlength="16">
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $plan_id . '-pay_to' ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'למי, שורה %d', 'diromax-portal' ), $plan_line ) );
							?>
						</label>
						<select id="<?php echo esc_attr( $plan_id . '-pay_to' ); ?>" name="<?php echo esc_attr( $plan_name . '[pay_to]' ); ?>">
							<option value="diromax"<?php selected( (string) ( $plan_row['pay_to'] ?? 'diromax' ), 'diromax' ); ?>><?php esc_html_e( 'דירומקס', 'diromax-portal' ); ?></option>
							<option value="supplier"<?php selected( (string) ( $plan_row['pay_to'] ?? '' ), 'supplier' ); ?>><?php esc_html_e( 'ספק', 'diromax-portal' ); ?></option>
						</select>
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $plan_id . '-payee' ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'שם הספק, שורה %d', 'diromax-portal' ), $plan_line ) );
							?>
						</label>
						<input type="text" id="<?php echo esc_attr( $plan_id . '-payee' ); ?>" name="<?php echo esc_attr( $plan_name . '[payee]' ); ?>" value="<?php echo esc_attr( (string) ( $plan_row['payee'] ?? '' ) ); ?>" maxlength="120">
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( $plan_id . '-days' ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'ימים לתשלום, שורה %d', 'diromax-portal' ), $plan_line ) );
							?>
						</label>
						<input type="number" dir="ltr" class="small-text" id="<?php echo esc_attr( $plan_id . '-days' ); ?>" name="<?php echo esc_attr( $plan_name . '[days]' ); ?>" value="<?php echo esc_attr( (string) ( $plan_row['days'] ?? 14 ) ); ?>" min="0" max="<?php echo esc_attr( (string) PaymentPlan::MAX_DAYS ); ?>">
					</td>
				</tr>
			<?php endfor; ?>
			</tbody>
		</table></div>
		<p class="description"><?php esc_html_e( 'עד 20 תשלומים. אחרי השמירה מופיעות עוד שורות ריקות.', 'diromax-portal' ); ?></p>

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
			<tr>
				<th scope="row"><?php esc_html_e( 'סיכום בוקר לצוות', 'diromax-portal' ); ?></th>
				<td>
					<input type="checkbox" id="dxa-morning" name="settings[morning_summary]" value="1"<?php checked( $settings['morning_summary'] ); ?>>
					<label for="dxa-morning"><?php esc_html_e( 'לשלוח כל בוקר לכל איש צוות את המשימות שלו שהגיע מועדן ואת השלבים שעברו את היעד בתיקים שלו', 'diromax-portal' ); ?></label>
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
				<td>
					<?php if ( ! $view['key_ready'] ) : ?>
						<strong><?php esc_html_e( 'לא מוגדר: העלאת מסמכים חסומה. ההוראות ב-README של התוסף.', 'diromax-portal' ); ?></strong>
					<?php elseif ( 'changed' === $view['key_status'] ) : ?>
						<strong><?php esc_html_e( 'המפתח השתנה מאז שהועלו המסמכים הקיימים, והם לא ייפתחו עד שהמפתח הקודם יחזור. העלאה חסומה עד אז.', 'diromax-portal' ); ?></strong>
					<?php else : ?>
						<?php esc_html_e( 'מוגדר.', 'diromax-portal' ); ?>
					<?php endif; ?>
					<?php if ( null !== $view['fingerprint'] ) : ?>
						<p class="description">
							<?php esc_html_e( 'טביעת האצבע של המפתח הנוכחי:', 'diromax-portal' ); ?>
							<code dir="ltr"><?php echo esc_html( implode( '-', str_split( (string) $view['fingerprint'], 4 ) ) ); ?></code>.
							<?php esc_html_e( 'אותה טביעה בעותק השמור אצלכם = העותק נכון. איך בודקים: ב-README של התוסף, "גיבוי המפתח".', 'diromax-portal' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'שמירת ההגדרות', 'diromax-portal' ) ); ?>
	</form>

	<?php
	$mail      = $view['mail'];
	$dx_fields = $mail['fields'];
	$dx_form   = new \Diromax\Portal\Admin\Form( $view['flash'], 'integrations' );
	$dx_locked = __( 'קבוע ב-wp-config.php, ולכן לא נערך כאן.', 'diromax-portal' );
	$dx_input  = static function ( string $name, string $label, string $type, string $help ) use ( $dx_fields, $dx_form, $dx_locked, $mail ): void {
		$field  = $dx_fields[ $name ];
		$id     = 'dxa-integrations-' . $name;
		$secret = 'password' === $type;
		$error  = $dx_form->error( $name );
		$notes  = array_filter( array( $help, $field['locked'] ? $dx_locked : '', $secret && ! $mail['key_ready'] ? __( 'כדי לשמור מפתח צריך קודם את מפתח ההצפנה DX_PORTAL_KEY ב-wp-config.php.', 'diromax-portal' ) : '' ) );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="integrations[<?php echo esc_attr( $name ); ?>]" class="regular-text" dir="<?php echo esc_attr( 'from_name' === $name ? 'auto' : 'ltr' ); ?>" autocomplete="off" spellcheck="false"
					value="<?php echo esc_attr( $secret ? '' : $dx_form->old( $name, (string) $field['value'] ) ); ?>"
					<?php echo $secret && $field['set'] ? 'placeholder="' . esc_attr__( '•••••••• (שמור. ריק: בלי שינוי)', 'diromax-portal' ) . '"' : ''; ?>
					<?php disabled( $field['locked'] || ( $secret && ! $mail['key_ready'] ) ); ?>
					<?php echo '' !== $error ? ' aria-invalid="true"' : ''; ?>
					aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>">
				<?php if ( $secret && $field['set'] && ! $field['locked'] ) : ?>
					<label><input type="checkbox" name="integrations[<?php echo esc_attr( $name ); ?>_clear]" value="1"> <?php esc_html_e( 'למחוק', 'diromax-portal' ); ?></label>
				<?php endif; ?>
				<p class="description" id="<?php echo esc_attr( $id . '-help' ); ?>">
					<?php if ( $field['unreadable'] ) : ?>
						<strong><?php esc_html_e( 'המפתח השמור הוצפן במפתח הצפנה אחר ולא נפתח. מזינים אותו מחדש.', 'diromax-portal' ); ?></strong>
					<?php endif; ?>
					<?php if ( '' !== $error ) : ?>
						<strong class="dxa-error"><?php echo esc_html( $error ); ?></strong>
					<?php endif; ?>
					<?php echo esc_html( implode( ' ', $notes ) ); ?>
				</p>
			</td>
		</tr>
		<?php
	};
	?>
	<h2 id="dxa-mail-title"><?php esc_html_e( 'שליחה וקבלה של מיילים (Postmark)', 'diromax-portal' ); ?></h2>
	<p>
		<?php
		if ( $mail['postmark'] ) {
			esc_html_e( 'פעיל: כל המיילים של האתר יוצאים דרך Postmark.', 'diromax-portal' );
		} else {
			esc_html_e( 'לא מוגדר: המיילים יוצאים בדרך הרגילה של האתר. מזינים את ה-Server API Token מ-Postmark כדי להפעיל.', 'diromax-portal' );
		}
		?>
		<?php esc_html_e( 'מפתחות נשמרים מוצפנים ולא מוצגים שוב אחרי השמירה. הגדרה שקבועה ב-wp-config.php גוברת על מה שכאן.', 'diromax-portal' ); ?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dx_portal_save_integrations">
		<?php wp_nonce_field( 'dx_portal_save_integrations' ); ?>
		<table class="form-table" role="presentation">
			<?php
			$dx_input( 'token', __( 'Server API Token', 'diromax-portal' ), 'password', __( 'ב-Postmark: Servers ← השרת ← API Tokens.', 'diromax-portal' ) );
			$dx_input( 'from', __( 'כתובת השולח', 'diromax-portal' ), 'email', __( 'מהדומיין שאומת ב-Postmark. ריק: הכתובת הרגילה של האתר.', 'diromax-portal' ) );
			$dx_input( 'from_name', __( 'שם השולח', 'diromax-portal' ), 'text', __( 'השם שהנמען רואה, בכל המיילים של האתר, גם בלי Postmark. מייל בשם מנהל הפרויקט יוצא כ"השם · שם המנהל". ריק: דירומקס.', 'diromax-portal' ) );
			$dx_input( 'stream', __( 'זרם השליחה', 'diromax-portal' ), 'text', __( 'ריק: outbound.', 'diromax-portal' ) );
			$dx_input( 'inbound', __( 'כתובת הדואר הנכנס', 'diromax-portal' ), 'email', __( 'מ-Default Inbound Stream ← Settings. ריק: תשובות של לקוחות מגיעות ישירות למנהל הפרויקט.', 'diromax-portal' ) );
			?>
		</table>

		<h2 id="dxa-turnstile-title"><?php esc_html_e( 'הגנה על טפסים (Cloudflare Turnstile)', 'diromax-portal' ); ?></h2>
		<p><?php esc_html_e( 'אותם מפתחות משמשים את טופס הכניסה לאזור האישי ואת טופס יצירת הקשר באתר. בלי שני המפתחות הטפסים עובדים כרגיל, עם ההגנות הקיימות.', 'diromax-portal' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			$dx_input( 'turnstile_site', __( 'Site Key', 'diromax-portal' ), 'text', __( 'המפתח הציבורי מ-Cloudflare ← Turnstile.', 'diromax-portal' ) );
			$dx_input( 'turnstile_secret', __( 'Secret Key', 'diromax-portal' ), 'password', __( 'המפתח הסודי מאותו מסך.', 'diromax-portal' ) );
			?>
		</table>
		<?php submit_button( __( 'שמירת ההגדרות', 'diromax-portal' ) ); ?>
	</form>

	<h2 id="dxa-webhooks-title"><?php esc_html_e( 'כתובות ה-webhook ב-Postmark', 'diromax-portal' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'תשובות של לקוחות לתוך התיק', 'diromax-portal' ); ?></th>
			<td>
				<?php if ( $mail['inbound'] && $mail['webhook_key'] ) : ?>
					<strong><?php esc_html_e( 'פעיל: תשובה של לקוח נשמרת בתיק ומועברת למנהל הפרויקט.', 'diromax-portal' ); ?></strong>
				<?php elseif ( $mail['inbound'] ) : ?>
					<strong><?php esc_html_e( 'חסר מפתח webhook: התשובות לא יתקבלו. יוצרים מפתח כאן למטה.', 'diromax-portal' ); ?></strong>
				<?php else : ?>
					<?php esc_html_e( 'לא מוגדר: תשובות של לקוחות מגיעות ישירות למייל של מנהל הפרויקט.', 'diromax-portal' ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'הכתובות', 'diromax-portal' ); ?></th>
			<td>
				<?php if ( null !== $mail['new_key'] ) : ?>
					<div class="notice notice-warning inline" role="status">
						<p><strong><?php esc_html_e( 'המפתח החדש מוצג עכשיו פעם אחת בלבד.', 'diromax-portal' ); ?></strong> <?php esc_html_e( 'מעתיקים את שתי הכתובות ל-Postmark עכשיו. אחרי שעוזבים את העמוד אפשר רק ליצור מפתח חדש.', 'diromax-portal' ); ?></p>
						<p><?php esc_html_e( 'מיילים שחזרו וסימוני ספאם (Bounce, Spam Complaint):', 'diromax-portal' ); ?><br><code dir="ltr" class="dxa-copy"><?php echo esc_html( $mail['new_key']['events'] ); ?></code></p>
						<p><?php esc_html_e( 'דואר נכנס (Inbound):', 'diromax-portal' ); ?><br><code dir="ltr" class="dxa-copy"><?php echo esc_html( $mail['new_key']['inbound'] ); ?></code></p>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'מיילים שחזרו וסימוני ספאם (Bounce, Spam Complaint):', 'diromax-portal' ); ?><br><code dir="ltr"><?php echo esc_html( $mail['events_url'] ); ?></code></p>
					<p><?php esc_html_e( 'דואר נכנס (Inbound):', 'diromax-portal' ); ?><br><code dir="ltr"><?php echo esc_html( $mail['inbound_url'] ); ?></code></p>
					<p class="description">
						<?php
						if ( $mail['webhook_key'] ) {
							esc_html_e( 'KEY הוא מפתח ה-webhook. הוא נשמר רק כטביעת אצבע, ולכן לא מוצג שוב. אם הוא אבד, יוצרים חדש ומעדכנים את Postmark.', 'diromax-portal' );
						} else {
							esc_html_e( 'עדיין אין מפתח webhook. יוצרים אחד, והכתובות המלאות יוצגו פעם אחת.', 'diromax-portal' );
						}
						?>
					</p>
				<?php endif; ?>
				<?php if ( $mail['webhook_locked'] ) : ?>
					<p class="description"><?php echo esc_html( $dx_locked ); ?></p>
				<?php elseif ( $mail['key_ready'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dx_portal_new_webhook_key">
						<?php wp_nonce_field( 'dx_portal_new_webhook_key' ); ?>
						<button type="submit" class="button"<?php echo $mail['webhook_key'] ? ' data-dxa-confirm="' . esc_attr__( 'ליצור מפתח חדש? המפתח הנוכחי יפסיק לעבוד מיד, וצריך לעדכן את Postmark.', 'diromax-portal' ) . '"' : ''; ?>><?php echo esc_html( $mail['webhook_key'] ? __( 'יצירת מפתח webhook חדש', 'diromax-portal' ) : __( 'יצירת מפתח webhook', 'diromax-portal' ) ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dx_portal_test_mail">
		<?php wp_nonce_field( 'dx_portal_test_mail' ); ?>
		<?php submit_button( __( 'שליחת מייל בדיקה אליי', 'diromax-portal' ), 'secondary', 'submit', false ); ?>
	</form>
</div>
