<?php
/**
 * Admin: manager dashboard (spec 15.8).
 *
 * Every row links to the case tab where the item is handled.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\ReminderPolicy;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$case_link = static function ( array $row, string $tab ): void {
	printf(
		'<a href="%1$s">%2$s</a><br><span class="dxa-muted">%3$s</span>',
		esc_url( Admin::case_url( (int) $row['case_id'], $tab ) ),
		esc_html( (string) $row['case_title'] ),
		esc_html( (string) $row['number'] )
	);
};
$statuses  = Labels::decision_statuses();
?>
<div class="wrap dxa dxa-dash">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'לוח בקרה', 'diromax-portal' ); ?></h1>
	<a href="<?php echo esc_url( add_query_arg( 'page', Admin::PAGE_CASES, admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'כל התיקים', 'diromax-portal' ); ?></a>
	<hr class="wp-header-end">
	<p class="description">
		<?php echo $view['all'] ? esc_html__( 'כל התיקים הפעילים במשרד.', 'diromax-portal' ) : esc_html__( 'התיקים הפעילים שלך.', 'diromax-portal' ); ?>
	</p>

	<div class="dxa-cards">
		<section class="dxa-card dxa-card--hot" aria-labelledby="dxa-dash-overdue">
			<h2 id="dxa-dash-overdue"><?php esc_html_e( 'דרישות תשלום באיחור', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['overdue'] ) ); ?></span></h2>
			<?php if ( array() === $view['overdue'] ) : ?>
				<p><?php esc_html_e( 'אין דרישות באיחור.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'דרישה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'באיחור', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תזכורות', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['overdue'] as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'finance' ); ?></td>
								<td><?php echo esc_html( (string) $row['description'] ); ?></td>
								<td dir="ltr" class="dxa-num"><?php echo esc_html( Money::format( (int) $row['total'] ) ); ?></td>
								<td>
									<?php
									$late = -ReminderPolicy::days_between( (string) $view['today'], (string) $row['due_date'] );
									/* translators: %d: days. */
									echo esc_html( 1 === $late ? __( 'יום אחד', 'diromax-portal' ) : sprintf( __( '%d ימים', 'diromax-portal' ), $late ) );
									?>
								</td>
								<td><?php echo esc_html( (string) $row['reminders_sent'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="dxa-card" aria-labelledby="dxa-dash-upcoming">
			<h2 id="dxa-dash-upcoming"><?php esc_html_e( 'לתשלום ב-7 הימים הקרובים', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['upcoming'] ) ); ?></span></h2>
			<?php if ( array() === $view['upcoming'] ) : ?>
				<p><?php esc_html_e( 'אין דרישות שמועדן מתקרב.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'דרישה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'עד', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['upcoming'] as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'finance' ); ?></td>
								<td><?php echo esc_html( (string) $row['description'] ); ?></td>
								<td dir="ltr" class="dxa-num"><?php echo esc_html( Money::format( (int) $row['total'] ) ); ?></td>
								<td dir="ltr"><?php echo esc_html( Labels::date( $row['due_date'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="dxa-card" aria-labelledby="dxa-dash-decisions">
			<h2 id="dxa-dash-decisions"><?php esc_html_e( 'החלטות שממתינות ללקוח', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['decisions'] ) ); ?></span></h2>
			<?php if ( array() === $view['decisions'] ) : ?>
				<p><?php esc_html_e( 'אין החלטות פתוחות.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'החלטה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'נשלחה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תשובה עד', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['decisions'] as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'decisions' ); ?></td>
								<td><?php echo esc_html( (string) $row['title'] ); ?></td>
								<td><?php echo esc_html( Labels::datetime( $row['created_at'] ) ); ?></td>
								<td dir="ltr"><?php echo esc_html( null === $row['due_date'] ? '—' : Labels::date( $row['due_date'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="dxa-card" aria-labelledby="dxa-dash-actions">
			<h2 id="dxa-dash-actions"><?php esc_html_e( 'תשובות ואישורים מהשבוע האחרון', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['actions'] ) ); ?></span></h2>
			<?php if ( array() === $view['actions'] ) : ?>
				<p><?php esc_html_e( 'לקוחות לא ענו על החלטות ולא אישרו הסכמים השבוע.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מי ומתי', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['actions'] as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'decision' === $row['kind'] ? 'decisions' : 'documents' ); ?></td>
								<td>
									<?php
									echo esc_html(
										'decision' === $row['kind']
											/* translators: 1: answer, 2: decision title. */
											? sprintf( __( '%1$s: %2$s', 'diromax-portal' ), $statuses[ (string) $row['status'] ] ?? '', $row['title'] )
											/* translators: %s: document title. */
											: sprintf( __( 'אישור קריאה: %s', 'diromax-portal' ), $row['title'] )
									);
									?>
								</td>
								<td><?php echo esc_html( (string) $row['display_name'] . ' · ' . Labels::datetime( $row['acted_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="dxa-card" aria-labelledby="dxa-dash-stale">
			<h2 id="dxa-dash-stale">
				<?php
				/* translators: %d: days. */
				echo esc_html( sprintf( __( 'תיקים בלי עדכון ללקוח %d ימים', 'diromax-portal' ), (int) $view['stale_days'] ) );
				?>
				<span class="dxa-count"><?php echo esc_html( (string) count( $view['stale'] ) ); ?></span>
			</h2>
			<?php if ( array() === $view['stale'] ) : ?>
				<p><?php esc_html_e( 'כל התיקים הפעילים קיבלו עדכון לאחרונה.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'עדכון אחרון', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['stale'] as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'updates' ); ?></td>
								<td><?php echo esc_html( null === $row['last_update'] ? __( 'עוד לא היה', 'diromax-portal' ) : Labels::datetime( $row['last_update'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="dxa-card" aria-labelledby="dxa-dash-quiet">
			<h2 id="dxa-dash-quiet"><?php esc_html_e( 'לקוחות שהוזמנו ועוד לא נכנסו', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['quiet'] ) ); ?></span></h2>
			<?php if ( array() === $view['quiet'] ) : ?>
				<p><?php esc_html_e( 'כל מי שהוזמן לפני יותר מ-3 ימים כבר נכנס.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'לקוח', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'הוזמן', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['quiet'] as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'members' ); ?></td>
								<td><?php echo esc_html( (string) $row['display_name'] ); ?></td>
								<td><?php echo esc_html( Labels::datetime( $row['invited_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<?php if ( array() !== $view['failed'] ) : ?>
			<section class="dxa-card dxa-card--hot" aria-labelledby="dxa-dash-failed">
				<h2 id="dxa-dash-failed"><?php esc_html_e( 'מיילים שלא נשלחו', 'diromax-portal' ); ?></h2>
				<ul>
					<?php foreach ( $view['failed'] as $row ) : ?>
						<li>
							<a href="<?php echo esc_url( Admin::case_url( (int) $row['case_id'], 'log' ) ); ?>"><?php echo esc_html( (string) $row['case_title'] ); ?></a>
							<?php
							/* translators: %d: number of failed notifications. */
							echo esc_html( sprintf( __( '(%d)', 'diromax-portal' ), (int) $row['failed'] ) );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'בדרך כלל בעיה בהגדרות המייל של האתר. היומן של כל תיק מראה מה נכשל.', 'diromax-portal' ); ?></p>
			</section>
		<?php endif; ?>
	</div>
</div>
