<?php
/**
 * The improvement page — the core of the site.
 * Used by front-page.php and by page-templates/template-improvement.php.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php /* ============ HERO ============ */ ?>
<section class="dx-section" aria-labelledby="dx-hero-title">
	<div class="dx-wrap">
		<div class="dx-hero">

			<div class="dx-hero__text">
				<div class="dx-eyebrow">
					<span class="dx-dot" aria-hidden="true"></span>
					<span class="dx-eyebrow__t dx-eyebrow__t--nowrap"><?php esc_html_e( 'ניהול השבחת נכסים · מא׳ עד ת׳', 'diromax' ); ?></span>
				</div>

				<h1 class="dx-h1 dx-mb-26" id="dx-hero-title"><?php echo wp_kses( __( 'לנכס שלך יש זכויות<br>שאתה לא מנצל.', 'diromax' ), array( 'br' => array() ) ); ?></h1>

				<p class="dx-lead dx-mw-56 dx-mb-20"><?php esc_html_e( 'אנחנו בודקים מה באמת מותר לבנות על הנכס שלך, בונים תוכנית עבודה, מחתימים שכנים, מוציאים היתר, מנהלים את הביצוע — ואז מוכרים בשווי החדש.', 'diromax' ); ?></p>
				<p class="dx-lead dx-lead--strong dx-mb-38"><?php esc_html_e( 'יזמות בלי להיות יזם: אתה לא מתעסק בכלום — אתה רק מחליט.', 'diromax' ); ?></p>

				<div class="dx-btnrow dx-mb-34">
					<a class="dx-btn dx-btn--orange dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>"><?php esc_html_e( 'בדיקת פוטנציאל הנכס — ללא עלות', 'diromax' ); ?></a>
					<a class="dx-btn dx-btn--outline dx-btn--lg" href="#process"><?php esc_html_e( 'איך התהליך עובד', 'diromax' ); ?></a>
				</div>

				<ul class="dx-facts">
					<?php foreach ( dx_hero_facts() as $dx_fact ) : ?>
						<li class="dx-facts__i">
							<span class="dx-facts__t"><?php echo esc_html( $dx_fact['t'] ); ?></span>
							<span class="dx-facts__s"><?php echo esc_html( $dx_fact['s'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<?php $dx_hero_id = (int) get_theme_mod( 'dx_hero_image' ); ?>
			<div class="dx-hero__media">
				<?php if ( $dx_hero_id ) : ?>
					<?php
					/*
					 * Above the fold and the likely LCP element: eager, high priority,
					 * and never lazy-loaded.
					 */
					echo wp_get_attachment_image(
						$dx_hero_id,
						'dx-hero',
						false,
						array(
							'alt'           => dx_opt( 'hero_alt', __( 'נכס אחרי השבחה', 'diromax' ) ),
							'loading'       => 'eager',
							'fetchpriority' => 'high',
							'decoding'      => 'async',
						)
					);
					?>
				<?php else : ?>
					<span class="dx-hero__ph"><?php echo wp_kses( __( 'תמונת נכס · חזית בניין או פרויקט אחרי השבחה<br>(העלאה: התאמה אישית → דירומקס — תצוגה)', 'diromax' ), array( 'br' => array() ) ); ?></span>
				<?php endif; ?>
			</div>

		</div>
	</div>
</section>

<?php /* ============ BLUE STATEMENT ============ */ ?>
<section class="dx-section dx-section--blue" aria-label="<?php esc_attr_e( 'בשורה אחת', 'diromax' ); ?>">
	<div class="dx-wrap dx-pad-band">
		<div class="dx-band__grid">
			<div class="dx-band__main">
				<div class="dx-eyebrow dx-eyebrow--tight">
					<span class="dx-dot" aria-hidden="true"></span>
					<span class="dx-eyebrow__t dx-eyebrow__t--onblue"><?php esc_html_e( 'בשורה אחת', 'diromax' ); ?></span>
				</div>
				<p class="dx-band__quote dx-mw-19"><?php esc_html_e( 'נכס שלא מנוצל הוא כסף על הרצפה.', 'diromax' ); ?></p>
				<div class="dx-rule dx-rule--wide" aria-hidden="true"></div>
				<p class="dx-band__sub dx-mw-34"><?php esc_html_e( 'אם לא תרים אותו — מישהו אחר ירוויח אותו במקומך.', 'diromax' ); ?></p>
			</div>
			<div class="dx-band__side">
				<p class="dx-band__note"><?php esc_html_e( 'השבחה היא לא שיפוץ. זו הפעולה שמשנה את השווי של הנכס עצמו — לפני שמוכרים אותו. מי שקונה נכס לא מנוצל, קונה את הפוטנציאל שלך במחיר של היום.', 'diromax' ); ?></p>
				<a class="dx-btn dx-btn--onblue dx-btn--link" href="#process"><?php esc_html_e( 'איך זה עובד בפועל', 'diromax' ); ?> <span aria-hidden="true">&#8592;</span></a>
			</div>
		</div>
	</div>
</section>

<?php /* ============ 01 · WHAT OWNERS DO NOT KNOW ============ */ ?>
<section class="dx-section" aria-labelledby="dx-s01">
	<div class="dx-wrap dx-pad-lg">
		<?php dx_kicker( '01', __( 'מה רוב בעלי הנכסים לא יודעים', 'diromax' ) ); ?>
		<h2 class="dx-h2 dx-mw-24 dx-mb-52" id="dx-s01"><?php esc_html_e( 'הנכס שלך כנראה מחזיק שווי שלא נבנה מעולם.', 'diromax' ); ?></h2>
		<ul class="dx-cols">
			<?php foreach ( dx_unknowns() as $dx_item ) : ?>
				<li class="dx-cols__c">
					<div class="dx-rule" aria-hidden="true"></div>
					<h3><?php echo esc_html( $dx_item['h'] ); ?></h3>
					<p><?php echo esc_html( $dx_item['p'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>

<?php /* ============ 02 · TOOLS ============ */ ?>
<section class="dx-section dx-section--alt" aria-labelledby="dx-s02">
	<div class="dx-wrap dx-pad-lg">
		<?php dx_kicker( '02', __( 'מה אנחנו עושים בפועל', 'diromax' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-52" id="dx-s02"><?php esc_html_e( 'הכלים שמעלים שווי של נכס.', 'diromax' ); ?></h2>
		<ul class="dx-cards">
			<?php foreach ( dx_tools() as $dx_tool ) : ?>
				<li class="dx-card">
					<h3><?php echo esc_html( $dx_tool['h'] ); ?></h3>
					<p><?php echo esc_html( $dx_tool['p'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>

<?php /* ============ 03 · PROCESS ============ */ ?>
<section class="dx-section" id="process" aria-labelledby="dx-s03">
	<div class="dx-wrap dx-pad-lg">
		<?php dx_kicker( '03', __( 'התהליך', 'diromax' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-16" id="dx-s03"><?php esc_html_e( 'שבעה שלבים. כולם אצלנו.', 'diromax' ); ?></h2>
		<p class="dx-intro dx-mw-60 dx-mb-52"><?php esc_html_e( 'מרגע שנכנסנו לנכס ועד שהכסף אצלך — אתה מקבל דיווח, לא משימות.', 'diromax' ); ?></p>
		<ol class="dx-steps">
			<?php foreach ( dx_process_steps() as $dx_step ) : ?>
				<li class="dx-step">
					<span class="dx-step__n" aria-hidden="true"><?php echo esc_html( $dx_step['n'] ); ?></span>
					<h3 class="dx-step__t"><?php echo esc_html( $dx_step['t'] ); ?></h3>
					<p class="dx-step__b"><?php echo esc_html( $dx_step['b'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>

<?php /* ============ 04 · COMPARISON ============ */ ?>
<section class="dx-section dx-section--alt" aria-labelledby="dx-s04">
	<div class="dx-wrap dx-pad-lg">
		<?php dx_kicker( '04', __( 'השוואה', 'diromax' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-52" id="dx-s04"><?php esc_html_e( 'להשביח לבד, או איתנו.', 'diromax' ); ?></h2>
		<?php
		/*
		 * Scrollable region: focusable and labelled so keyboard users can reach the
		 * overflow. Its label is its own — reusing the section heading would give
		 * two landmarks the same accessible name.
		 */
		?>
		<div class="dx-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'טבלת השוואה — ניתן לגלול לצדדים', 'diromax' ); ?>">
			<table class="dx-table">
				<caption class="dx-sr-only"><?php esc_html_e( 'השוואה בין ניהול השבחה עצמאי לבין ליווי של דירומקס, לפי שלבי התהליך.', 'diromax' ); ?></caption>
				<thead>
					<tr>
						<th class="dx-th-stage" scope="col"><?php esc_html_e( 'השלב', 'diromax' ); ?></th>
						<th class="dx-th-alone" scope="col"><?php esc_html_e( 'להשביח לבד', 'diromax' ); ?></th>
						<th class="dx-th-us" scope="col"><?php esc_html_e( 'עם דירומקס', 'diromax' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( dx_compare_rows() as $dx_row ) : ?>
						<tr>
							<th class="dx-td-stage" scope="row"><?php echo esc_html( $dx_row['s'] ); ?></th>
							<td class="dx-td-alone"><?php echo esc_html( $dx_row['a'] ); ?></td>
							<td class="dx-td-us"><?php echo esc_html( $dx_row['u'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</section>

<?php /* ============ 05 · COST VS SAVING + NO-BUDGET TRACK ============ */ ?>
<section class="dx-section" aria-labelledby="dx-s05">
	<div class="dx-wrap dx-pad-lg">
		<?php dx_kicker( '05', __( 'העלות מול החיסכון', 'diromax' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-20" id="dx-s05"><?php esc_html_e( 'הליווי שלנו לא מוסיף לעלות. הוא מקטין אותה.', 'diromax' ); ?></h2>
		<p class="dx-intro dx-mw-64 dx-mb-52"><?php esc_html_e( 'זו השאלה שכל בעל נכס שואל: למה לשלם על ניהול. התשובה נמצאת בפער בין המחיר שאתה מקבל מספק — לבין המחיר שאנחנו מקבלים מאותו ספק.', 'diromax' ); ?></p>

		<ul class="dx-cards dx-mb-36">
			<?php foreach ( dx_cost_cards() as $dx_card ) : ?>
				<li class="dx-card dx-card--tall">
					<div class="dx-rule dx-rule--<?php echo esc_attr( $dx_card['rule'] ); ?>" aria-hidden="true"></div>
					<h3><?php echo esc_html( $dx_card['h'] ); ?></h3>
					<p><?php echo esc_html( $dx_card['p'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="dx-callout">
			<p class="dx-mw-44"><?php esc_html_e( 'בשורה התחתונה: מה שאנחנו חוסכים לך בתמחור, בניהול ובטעויות — לרוב גדול ממה שאנחנו עולים.', 'diromax' ); ?></p>
		</div>

		<div class="dx-box">
			<div class="dx-eyebrow dx-mb-18">
				<span class="dx-dot dx-dot--teal" aria-hidden="true"></span>
				<span class="dx-eyebrow__t"><?php esc_html_e( 'מסלול לבעלי נכס בלי תקציב', 'diromax' ); ?></span>
			</div>
			<h3 class="dx-mw-28"><?php esc_html_e( 'אין לך תקציב להשבחה? זה לא סוף הדרך.', 'diromax' ); ?></h3>
			<p class="dx-box__intro dx-mw-66"><?php esc_html_e( 'לא כל בעל נכס יכול להוציא כסף מהבית על תכנון ועל ביצוע, וזה לגיטימי לחלוטין. במקרים כאלה אנחנו לא סוגרים את הדלת — אנחנו בונים מסלול שמתאים למצב שלך, כך שההשבחה תמומן מתוך הפרויקט ולא מהכיס.', 'diromax' ); ?></p>
			<ul class="dx-box__grid">
				<?php foreach ( dx_budget_tracks() as $dx_track ) : ?>
					<li class="dx-box__c">
						<h4><?php echo esc_html( $dx_track['h'] ); ?></h4>
						<p><?php echo esc_html( $dx_track['p'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="dx-box__rule dx-mw-60"><?php esc_html_e( 'הכלל אצלנו פשוט: חוסר תקציב לא צריך להיות הסיבה שהנכס שלך נשאר בלי מיצוי.', 'diromax' ); ?></p>
		</div>
	</div>
</section>

<?php /* ============ DIFFERENTIATORS ============ */ ?>
<section class="dx-section dx-section--blue" aria-labelledby="dx-diff">
	<div class="dx-wrap dx-pad-lg">
		<div class="dx-eyebrow">
			<span class="dx-dot" aria-hidden="true"></span>
			<span class="dx-eyebrow__t dx-eyebrow__t--onblue"><?php esc_html_e( 'הבידול שלנו', 'diromax' ); ?></span>
		</div>
		<h2 class="dx-h2--wide dx-mw-30 dx-mb-56" id="dx-diff"><?php echo wp_kses( __( 'אנחנו לא מייעצים ונעלמים.<br>אנחנו לוקחים את הנכס עד הסוף.', 'diromax' ), array( 'br' => array() ) ); ?></h2>
		<ul class="dx-blue-cards">
			<?php foreach ( dx_differentiators() as $dx_item ) : ?>
				<li class="dx-blue-card">
					<span class="dx-blue-card__l" aria-hidden="true"><?php echo esc_html( $dx_item['l'] ); ?></span>
					<h3><?php echo esc_html( $dx_item['h'] ); ?></h3>
					<p><?php echo esc_html( $dx_item['p'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>

<?php /* ============ 06 · WHO IT IS FOR ============ */ ?>
<section class="dx-section" aria-labelledby="dx-s06">
	<div class="dx-wrap dx-pad-lg">
		<div class="dx-split">
			<div>
				<?php dx_kicker( '06', __( 'למי זה מתאים', 'diromax' ) ); ?>
				<h2 class="dx-h2--who" id="dx-s06"><?php esc_html_e( 'אם אתה מחזיק אחד מאלה — יש על מה לדבר.', 'diromax' ); ?></h2>
			</div>
			<ul class="dx-list">
				<?php foreach ( dx_audience() as $dx_item ) : ?>
					<li class="dx-list__i">
						<h3><?php echo esc_html( $dx_item['h'] ); ?></h3>
						<p><?php echo esc_html( $dx_item['p'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</section>

<?php /* ============ 07 · BEFORE / AFTER ============ */ ?>
<?php if ( dx_show_projects() ) : ?>
	<?php
	$dx_projects = get_posts(
		array(
			'post_type'              => 'dx_project',
			'numberposts'            => 4,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);
	?>
	<section class="dx-section dx-section--alt" aria-labelledby="dx-s07">
		<div class="dx-wrap dx-pad-lg">
			<?php dx_kicker( '07', __( 'פרויקטים', 'diromax' ) ); ?>
			<h2 class="dx-h2 dx-mb-52" id="dx-s07"><?php esc_html_e( 'לפני ואחרי.', 'diromax' ); ?></h2>
			<ul class="dx-projects">
				<?php
				if ( $dx_projects ) {
					foreach ( $dx_projects as $dx_project ) {
						dx_project_card( $dx_project );
					}
				} else {
					dx_project_card();
					dx_project_card();
				}
				?>
			</ul>
		</div>
	</section>
<?php endif; ?>

<?php /* ============ 08 · FAQ TEASER ============ */ ?>
<section class="dx-section" aria-labelledby="dx-s08">
	<div class="dx-wrap dx-pad-lg">
		<div class="dx-split">
			<div>
				<?php dx_kicker( '08', __( 'שאלות נפוצות', 'diromax' ) ); ?>
				<h2 class="dx-h2--sm dx-mb-22" id="dx-s08"><?php esc_html_e( 'מה שבעלי נכסים שואלים אותנו קודם.', 'diromax' ); ?></h2>
				<a class="dx-btn dx-btn--outline dx-btn--md" href="<?php echo esc_url( dx_view_url( 'faq' ) ); ?>"><?php esc_html_e( 'לכל השאלות והתשובות', 'diromax' ); ?></a>
			</div>
			<div class="dx-faq">
				<?php dx_accordion( dx_faq_home() ); ?>
			</div>
		</div>
	</div>
</section>

<?php /* ============ CTA + LEAD FORM ============ */ ?>
<section class="dx-section dx-section--orange" aria-labelledby="dx-cta">
	<div class="dx-wrap dx-pad-cta">
		<div class="dx-cta__grid">
			<div>
				<h2 class="dx-h2--cta dx-mb-22" id="dx-cta"><?php echo wp_kses( __( 'נתחיל מבדיקה אחת:<br>מה מותר לבנות אצלך.', 'diromax' ), array( 'br' => array() ) ); ?></h2>
				<p class="dx-intro--lg dx-mw-44 dx-mb-28"><?php esc_html_e( 'השאר פרטים ונחזור אליך עם תמונת מצב ראשונית על הנכס — בלי עלות ובלי התחייבות.', 'diromax' ); ?></p>
				<div class="dx-cta__contacts">
					<div>
						<span class="dx-cta__k"><?php esc_html_e( 'טלפון', 'diromax' ); ?></span>
						<a class="dx-cta__v" href="tel:<?php echo esc_attr( dx_phone_tel() ); ?>"><?php echo esc_html( dx_phone() ); ?></a>
					</div>
					<div>
						<span class="dx-cta__k"><?php esc_html_e( 'וואטסאפ', 'diromax' ); ?></span>
						<a class="dx-cta__v" href="<?php echo esc_url( dx_wa_url() ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( dx_phone() ); ?>
							<span class="dx-sr-only"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax' ); ?></span>
						</a>
					</div>
				</div>
			</div>
			<?php get_template_part( 'template-parts/lead-form', null, array( 'variant' => 'light' ) ); ?>
		</div>
	</div>
</section>
