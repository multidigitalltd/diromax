<?php
/**
 * Portal: one case — head, stages, request banner, money tiles, tabs.
 *
 * Tabs are plain links (?tab=…) rendered on the server, so the page works
 * fully without JavaScript; the script only adds table filters and copying.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$case       = $view['case'];
$manager    = $view['manager'];
$request    = $view['request'];
$current    = (string) $view['tab'];
$tab_labels = array(
	'finance'   => __( 'כספים', 'diromax-portal' ),
	'documents' => __( 'הסכם ומסמכים', 'diromax-portal' ),
	'updates'   => __( 'עדכונים', 'diromax-portal' ),
);
?>
<section class="dxp-head" aria-labelledby="dxp-case-title">
	<div class="dxp-wrap">
		<?php if ( $view['is_manager'] ) : ?>
			<p class="dxp-preview" role="note"><?php esc_html_e( 'תצוגה כפי שהלקוח רואה את התיק. פריטים שסומנו "לא גלוי" והערות פנימיות לא מוצגים כאן.', 'diromax-portal' ); ?></p>
		<?php endif; ?>
		<div class="dxp-head__grid">
			<div class="dxp-head__main">
				<p class="dxp-eyebrow"><span class="dxp-dot" aria-hidden="true"></span><?php esc_html_e( 'אזור אישי · תיק', 'diromax-portal' ); ?> <span class="dxp-num"><?php echo esc_html( (string) $case['number'] ); ?></span></p>
				<h1 id="dxp-case-title"><?php echo esc_html( (string) $case['title'] ); ?></h1>
				<p class="dxp-lead">
					<?php if ( '' !== $case['scope'] ) : ?>
						<strong><?php echo esc_html( (string) $case['scope'] ); ?></strong>
					<?php endif; ?>
					<?php if ( '' !== $case['track'] ) : ?>
						<?php
						/* translators: %s: track. */
						echo esc_html( sprintf( __( 'מסלול: %s', 'diromax-portal' ), $case['track'] ) );
						?>
					<?php endif; ?>
				</p>
				<?php if ( $case['closed'] ) : ?>
					<p class="dxp-ok"><?php esc_html_e( 'התיק סגור. הנתונים נשמרים כאן לעיון.', 'diromax-portal' ); ?></p>
				<?php endif; ?>
				<?php require __DIR__ . '/parts/account.php'; ?>
			</div>
			<aside class="dxp-pm" aria-label="<?php esc_attr_e( 'מנהל הפרויקט', 'diromax-portal' ); ?>">
				<p class="dxp-pm__k"><?php esc_html_e( 'מנהל הפרויקט שלך', 'diromax-portal' ); ?></p>
				<p class="dxp-pm__n"><?php echo esc_html( (string) $manager['name'] ); ?></p>
				<p class="dxp-pm__btns">
					<?php if ( '' !== $manager['tel'] ) : ?>
						<a class="dxp-btn" href="<?php echo esc_url( 'tel:' . $manager['tel'] ); ?>"><?php esc_html_e( 'שיחה', 'diromax-portal' ); ?><span class="dxp-sr"> <?php echo esc_html( (string) $manager['phone'] ); ?></span></a>
					<?php endif; ?>
					<?php if ( '' !== $manager['whatsapp'] ) : ?>
						<a class="dxp-btn" href="<?php echo esc_url( (string) $manager['whatsapp'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'וואטסאפ', 'diromax-portal' ); ?><span class="dxp-sr"> <?php esc_html_e( '(נפתח בחלון חדש)', 'diromax-portal' ); ?></span></a>
					<?php endif; ?>
					<?php if ( '' !== $manager['email'] ) : ?>
						<a class="dxp-btn" href="<?php echo esc_url( 'mailto:' . $manager['email'] ); ?>"><?php esc_html_e( 'דוא״ל', 'diromax-portal' ); ?></a>
					<?php endif; ?>
				</p>
			</aside>
		</div>

		<ol class="dxp-stages" aria-label="<?php esc_attr_e( 'שלבי התהליך', 'diromax-portal' ); ?>">
			<?php foreach ( $view['stages'] as $stage ) : ?>
				<li class="dxp-stage dxp-stage--<?php echo esc_attr( (string) $stage['status'] ); ?>"<?php echo 'active' === $stage['status'] ? ' aria-current="step"' : ''; ?>>
					<span class="dxp-stage__n"><?php echo esc_html( (string) $stage['number'] ); ?><?php echo 'done' === $stage['status'] ? ' <span aria-hidden="true">✓</span>' : ''; ?></span>
					<span class="dxp-stage__t"><?php echo esc_html( (string) $stage['name'] ); ?></span>
					<span class="dxp-stage__s"><?php echo esc_html( (string) $stage['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>

<?php if ( null !== $request ) : ?>
<section class="dxp-req<?php echo $request['overdue'] ? ' dxp-req--late' : ''; ?>" aria-labelledby="dxp-req-title">
	<div class="dxp-wrap dxp-req__in">
		<div>
			<p class="dxp-req__k" id="dxp-req-title"><?php echo $request['overdue'] ? esc_html__( 'דרישת תשלום באיחור', 'diromax-portal' ) : esc_html__( 'דרישת תשלום פתוחה', 'diromax-portal' ); ?></p>
			<p class="dxp-req__v"><span class="dxp-num"><?php echo esc_html( (string) $request['amount'] ); ?></span> <?php esc_html_e( 'עד', 'diromax-portal' ); ?> <span class="dxp-num"><?php echo esc_html( (string) $request['due'] ); ?></span> · <?php echo esc_html( (string) $request['description'] ); ?></p>
			<p class="dxp-req__to">
				<?php
				/* translators: %s: who to pay. */
				echo esc_html( sprintf( __( 'למי משלמים: %s', 'diromax-portal' ), $request['pay_to'] ) );
				?>
			</p>
			<?php if ( '' !== $request['details'] ) : ?>
				<p class="dxp-req__bank" id="dxp-pay-details"><?php echo nl2br( esc_html( (string) $request['details'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( $request['more'] > 0 ) : ?>
				<p class="dxp-req__to">
					<?php
					echo esc_html(
						1 === (int) $request['more']
							? __( 'ועוד דרישה פתוחה אחת בלשונית הכספים.', 'diromax-portal' )
							/* translators: %d: number of other open requests. */
							: sprintf( __( 'ועוד %d דרישות פתוחות בלשונית הכספים.', 'diromax-portal' ), (int) $request['more'] )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( '' !== $request['details'] ) : ?>
			<button class="dxp-btn dxp-btn--ink" type="button" data-dxp-copy="dxp-pay-details" data-dxp-copied="<?php esc_attr_e( 'הועתק', 'diromax-portal' ); ?>" hidden><?php esc_html_e( 'העתקת פרטי התשלום', 'diromax-portal' ); ?></button>
		<?php endif; ?>
	</div>
</section>
<?php endif; ?>

<div class="dxp-wrap">
	<ul class="dxp-kpis" aria-label="<?php esc_attr_e( 'המצב הכספי', 'diromax-portal' ); ?>">
		<?php foreach ( $view['kpis'] as $tile ) : ?>
			<li class="dxp-kpi<?php echo $tile['highlight'] ? ' dxp-kpi--hl' : ''; ?>">
				<p class="dxp-kpi__k"><?php echo esc_html( (string) $tile['label'] ); ?></p>
				<p class="dxp-kpi__v"><span class="dxp-num"><?php echo esc_html( (string) $tile['value'] ); ?></span></p>
				<?php if ( null !== $tile['bar'] ) : ?>
					<div class="dxp-bar" aria-hidden="true"><i class="dxp-bar__comm" style="width:<?php echo esc_attr( (string) ( $tile['bar']['forecast'] / 100 ) ); ?>%"></i><i class="dxp-bar__paid" style="width:<?php echo esc_attr( (string) ( $tile['bar']['paid'] / 100 ) ); ?>%"></i></div>
				<?php endif; ?>
				<?php if ( '' !== $tile['note'] ) : ?>
					<p class="dxp-kpi__s"><?php echo esc_html( (string) $tile['note'] ); ?></p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<nav class="dxp-tabs" aria-label="<?php esc_attr_e( 'תוכן התיק', 'diromax-portal' ); ?>">
		<?php foreach ( $tab_labels as $key => $label ) : ?>
			<a class="dxp-tab" href="<?php echo esc_url( add_query_arg( 'tab', $key ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
				<?php if ( 'updates' === $key && array() !== $view['updates'] ) : ?>
					<span class="dxp-cnt"><?php echo esc_html( (string) count( $view['updates'] ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	$panel = array(
		'finance'   => 'finance',
		'documents' => 'documents',
		'updates'   => 'updates',
	)[ $current ] ?? 'finance';
	require __DIR__ . '/parts/' . $panel . '.php';
	?>
</div>
