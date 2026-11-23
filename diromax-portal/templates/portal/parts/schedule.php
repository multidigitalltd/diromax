<?php
/**
 * Portal part: the project's timeline (spec 22.6).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$schedule = (array) $view['schedule'];
$layout   = (array) $schedule['layout'];
$rows     = (array) $schedule['rows'];
?>
<section class="dxp-panel" aria-labelledby="dxp-sched-title">
	<h2 id="dxp-sched-title"><?php esc_html_e( 'לוח הזמנים של הפרויקט', 'diromax-portal' ); ?></h2>
	<p class="dxp-lead"><?php esc_html_e( 'מתי כל שלב מתוכנן, ומה קרה בפועל. כשמועד משתנה, מופיע כאן גם המועד המקורי וההסבר.', 'diromax-portal' ); ?></p>

	<?php
	$dx_gantt_prefix = 'dxp';
	require __DIR__ . '/gantt.php';
	?>

	<ol class="dxp-sched">
		<?php foreach ( $rows as $row ) : ?>
			<li class="dxp-sched__stage dxp-sched__stage--<?php echo esc_attr( (string) $row['status'] ); ?>">
				<p class="dxp-sched__head">
					<b><?php echo esc_html( $row['number'] . '. ' . $row['label'] ); ?></b>
					<span class="dxp-sched__state"><?php echo esc_html( (string) $row['status_label'] ); ?></span>
				</p>
				<p class="dxp-sched__dates">
					<?php
					if ( null === $row['start'] && null === $row['end'] ) {
						esc_html_e( 'מתוכנן: טרם נקבע מועד', 'diromax-portal' );
					} elseif ( null === $row['start'] ) {
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'מתוכנן להסתיים ב-%s', 'diromax-portal' ), $row['end_label'] ) );
					} else {
						/* translators: 1: start date, 2: end date. */
						echo esc_html( sprintf( __( 'מתוכנן: %1$s עד %2$s', 'diromax-portal' ), $row['start_label'], '' !== $row['end_label'] ? $row['end_label'] : '—' ) );
					}
					?>
					<?php if ( '' !== $row['actual'] ) : ?>
						<br><?php echo esc_html( (string) $row['actual'] ); ?>
					<?php endif; ?>
				</p>
				<?php if ( $row['moved'] ) : ?>
					<p class="dxp-sched__moved">
						<?php
						/* translators: %s: date first given. */
						echo esc_html( sprintf( __( 'המועד עודכן. במקור: %s.', 'diromax-portal' ), $row['first_label'] ) );
						?>
						<?php if ( '' !== $row['note'] ) : ?>
							<?php echo esc_html( (string) $row['note'] ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( array() !== $row['milestones'] ) : ?>
					<ul class="dxp-sched__marks">
						<?php foreach ( $row['milestones'] as $milestone ) : ?>
							<li class="<?php echo $milestone['done'] ? 'dxp-sched__mark--done' : ''; ?>">
								<b><?php echo esc_html( (string) $milestone['label'] ); ?></b> ·
								<?php
								echo esc_html(
									$milestone['done']
										/* translators: %s: date. */
										? sprintf( __( 'הושגה ב-%s', 'diromax-portal' ), $milestone['done_label'] )
										/* translators: %s: date. */
										: sprintf( __( 'מתוכננת ל-%s', 'diromax-portal' ), $milestone['end_label'] )
								);
								?>
								<?php if ( $milestone['moved'] && ! $milestone['done'] ) : ?>
									<span class="dxp-sched__moved">
										<?php
										/* translators: 1: date first given, 2: note. */
										echo esc_html( trim( sprintf( __( '(במקור %1$s) %2$s', 'diromax-portal' ), $milestone['first_label'], $milestone['note'] ) ) );
										?>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
