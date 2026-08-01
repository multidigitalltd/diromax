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
					<span class="dx-eyebrow__t dx-eyebrow__t--nowrap"><?php dx_the_text( 'improve.hero_eyebrow' ); ?></span>
				</div>

				<h1 class="dx-h1 dx-mb-26" id="dx-hero-title"><?php dx_the_lines( 'improve.hero_title' ); ?></h1>

				<p class="dx-lead dx-mw-56 dx-mb-20"><?php dx_the_text( 'improve.hero_lead' ); ?></p>
				<p class="dx-lead dx-lead--strong dx-mb-38"><?php dx_the_text( 'improve.hero_lead2' ); ?></p>

				<div class="dx-btnrow dx-mb-34">
					<a class="dx-btn dx-btn--orange dx-btn--lg" href="<?php echo esc_url( dx_view_url( 'contact' ) ); ?>"><?php dx_the_text( 'improve.hero_btn' ); ?></a>
					<a class="dx-btn dx-btn--outline dx-btn--lg" href="#process"><?php dx_the_text( 'improve.hero_btn2' ); ?></a>
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
							'alt'           => dx_opt( 'hero_alt', (string) dx_content( 'improve.hero_img_alt' ) ),
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
<section class="dx-section dx-section--blue" aria-label="<?php echo esc_attr( dx_content( 'improve.band_eyebrow' ) ); ?>">
	<div class="dx-wrap dx-pad-band">
		<div class="dx-band__grid">
			<div class="dx-band__main">
				<div class="dx-eyebrow dx-eyebrow--tight">
					<span class="dx-dot" aria-hidden="true"></span>
					<span class="dx-eyebrow__t dx-eyebrow__t--onblue"><?php dx_the_text( 'improve.band_eyebrow' ); ?></span>
				</div>
				<p class="dx-band__quote dx-mw-19"><?php dx_the_lines( 'improve.band_quote' ); ?></p>
				<div class="dx-rule dx-rule--wide" aria-hidden="true"></div>
				<p class="dx-band__sub dx-mw-34"><?php dx_the_text( 'improve.band_sub' ); ?></p>
			</div>
			<div class="dx-band__side">
				<p class="dx-band__note"><?php dx_the_text( 'improve.band_note' ); ?></p>
				<a class="dx-btn dx-btn--onblue dx-btn--link" href="#process"><?php dx_the_text( 'improve.band_link' ); ?> <span aria-hidden="true">&#8592;</span></a>
			</div>
		</div>
	</div>
</section>

<?php /* ============ 01 · WHAT OWNERS DO NOT KNOW ============ */ ?>
<section class="dx-section" aria-labelledby="dx-s01">
	<div class="dx-wrap dx-pad-lg">
		<?php dx_kicker( '01', dx_content( 'improve.s1_kicker' ) ); ?>
		<h2 class="dx-h2 dx-mw-24 dx-mb-52" id="dx-s01"><?php dx_the_lines( 'improve.s1_title' ); ?></h2>
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
		<?php dx_kicker( '02', dx_content( 'improve.s2_kicker' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-52" id="dx-s02"><?php dx_the_lines( 'improve.s2_title' ); ?></h2>
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
		<?php dx_kicker( '03', dx_content( 'improve.s3_kicker' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-16" id="dx-s03"><?php dx_the_lines( 'improve.s3_title' ); ?></h2>
		<p class="dx-intro dx-mw-60 dx-mb-52"><?php dx_the_text( 'improve.s3_intro' ); ?></p>
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
		<?php dx_kicker( '04', dx_content( 'improve.s4_kicker' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-52" id="dx-s04"><?php dx_the_lines( 'improve.s4_title' ); ?></h2>
		<?php
		/*
		 * Scrollable region: focusable and labelled so keyboard users can reach the
		 * overflow. Its label is its own — reusing the section heading would give
		 * two landmarks the same accessible name.
		 */
		?>
		<div class="dx-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'טבלת השוואה — ניתן לגלול לצדדים', 'diromax' ); ?>">
			<table class="dx-table">
				<caption class="dx-sr-only"><?php dx_the_text( 'improve.s4_caption' ); ?></caption>
				<thead>
					<tr>
						<th class="dx-th-stage" scope="col"><?php dx_the_text( 'improve.s4_col_stage' ); ?></th>
						<th class="dx-th-alone" scope="col"><?php dx_the_text( 'improve.s4_col_alone' ); ?></th>
						<th class="dx-th-us" scope="col"><?php dx_the_text( 'improve.s4_col_us' ); ?></th>
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
		<?php dx_kicker( '05', dx_content( 'improve.s5_kicker' ) ); ?>
		<h2 class="dx-h2 dx-mw-26 dx-mb-20" id="dx-s05"><?php dx_the_lines( 'improve.s5_title' ); ?></h2>
		<p class="dx-intro dx-mw-64 dx-mb-52"><?php dx_the_text( 'improve.s5_intro' ); ?></p>

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
			<p class="dx-mw-44"><?php dx_the_text( 'improve.callout' ); ?></p>
		</div>

		<div class="dx-box">
			<div class="dx-eyebrow dx-mb-18">
				<span class="dx-dot dx-dot--teal" aria-hidden="true"></span>
				<span class="dx-eyebrow__t"><?php dx_the_text( 'improve.box_eyebrow' ); ?></span>
			</div>
			<h3 class="dx-mw-28"><?php dx_the_lines( 'improve.box_title' ); ?></h3>
			<p class="dx-box__intro dx-mw-66"><?php dx_the_text( 'improve.box_intro' ); ?></p>
			<ul class="dx-box__grid">
				<?php foreach ( dx_budget_tracks() as $dx_track ) : ?>
					<li class="dx-box__c">
						<h4><?php echo esc_html( $dx_track['h'] ); ?></h4>
						<p><?php echo esc_html( $dx_track['p'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="dx-box__rule dx-mw-60"><?php dx_the_text( 'improve.box_rule' ); ?></p>
		</div>
	</div>
</section>

<?php /* ============ DIFFERENTIATORS ============ */ ?>
<section class="dx-section dx-section--blue" aria-labelledby="dx-diff">
	<div class="dx-wrap dx-pad-lg">
		<div class="dx-eyebrow">
			<span class="dx-dot" aria-hidden="true"></span>
			<span class="dx-eyebrow__t dx-eyebrow__t--onblue"><?php dx_the_text( 'improve.diff_eyebrow' ); ?></span>
		</div>
		<h2 class="dx-h2--wide dx-mw-30 dx-mb-56" id="dx-diff"><?php dx_the_lines( 'improve.diff_title' ); ?></h2>
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
				<?php dx_kicker( '06', dx_content( 'improve.s6_kicker' ) ); ?>
				<h2 class="dx-h2--who" id="dx-s06"><?php dx_the_lines( 'improve.s6_title' ); ?></h2>
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
			<?php dx_kicker( '07', dx_content( 'improve.s7_kicker' ) ); ?>
			<h2 class="dx-h2 dx-mb-52" id="dx-s07"><?php dx_the_lines( 'improve.s7_title' ); ?></h2>
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
				<?php dx_kicker( '08', dx_content( 'improve.s8_kicker' ) ); ?>
				<h2 class="dx-h2--sm dx-mb-22" id="dx-s08"><?php dx_the_lines( 'improve.s8_title' ); ?></h2>
				<a class="dx-btn dx-btn--outline dx-btn--md" href="<?php echo esc_url( dx_view_url( 'faq' ) ); ?>"><?php dx_the_text( 'improve.s8_btn' ); ?></a>
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
				<h2 class="dx-h2--cta dx-mb-22" id="dx-cta"><?php dx_the_lines( 'improve.cta_title' ); ?></h2>
				<p class="dx-intro--lg dx-mw-44 dx-mb-28"><?php dx_the_text( 'improve.cta_intro' ); ?></p>
				<div class="dx-cta__contacts">
					<div>
						<span class="dx-cta__k"><?php dx_the_text( 'improve.cta_phone' ); ?></span>
						<a class="dx-cta__v" href="tel:<?php echo esc_attr( dx_phone_tel() ); ?>"><?php echo esc_html( dx_phone() ); ?></a>
					</div>
					<div>
						<span class="dx-cta__k"><?php dx_the_text( 'improve.cta_wa' ); ?></span>
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
