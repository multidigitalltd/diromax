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
use Diromax\Portal\Admin\CaseCells;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\ReminderPolicy;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\Stages;

defined( 'ABSPATH' ) || exit;

$case_link       = static function ( array $row, string $tab ): void {
	printf(
		'<a href="%1$s">%2$s</a><br><span class="dxa-muted">%3$s</span>',
		esc_url( Admin::case_url( (int) $row['case_id'], $tab ) ),
		esc_html( (string) $row['case_title'] ),
		esc_html( (string) $row['number'] )
	);
};
$statuses        = Labels::decision_statuses();
$request_actions = static function ( array $row, string $today ): void {
	$case_id = (int) $row['case_id'];
	?>
	<div class="dxa-row-actions">
		<?php if ( $today !== (string) $row['last_reminder_on'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline-form">
				<input type="hidden" name="action" value="dx_portal_send_reminder">
				<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>">
				<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
				<input type="hidden" name="dx_back" value="dashboard">
				<?php wp_nonce_field( 'dx_portal_send_reminder_' . $case_id ); ?>
				<button type="submit" class="button" data-dxa-confirm="<?php esc_attr_e( 'לשלוח עכשיו ללקוחות של התיק תזכורת על הדרישה הזו?', 'diromax-portal' ); ?>"><?php esc_html_e( 'תזכורת', 'diromax-portal' ); ?></button>
			</form>
		<?php else : ?>
			<span class="dxa-muted"><?php esc_html_e( 'תזכורת נשלחה היום', 'diromax-portal' ); ?></span>
		<?php endif; ?>
		<a href="<?php echo esc_url( add_query_arg( 'settle', (int) $row['id'], Admin::case_url( $case_id, 'finance' ) ) ); ?>"><?php esc_html_e( 'סימון כשולמה', 'diromax-portal' ); ?></a>
	</div>
	<?php
};
$user_name = wp_get_current_user()->display_name;
$header    = array(
	'current' => 'dashboard',
	'eyebrow' => $view['all'] ? __( 'כל התיקים הפעילים במשרד', 'diromax-portal' ) : __( 'התיקים הפעילים שלך', 'diromax-portal' ),
	'title'   => __( 'לוח בקרה', 'diromax-portal' ),
	/* translators: %s: user's name. */
	'meta'    => array( sprintf( __( 'שלום, %s', 'diromax-portal' ), $user_name ), Labels::date( (string) $view['today'] ) ),
	'actions' => array(
		array(
			'label'   => __( '+ תיק חדש', 'diromax-portal' ),
			'url'     => Admin::page_url( Admin::PAGE_NEW ),
			'primary' => true,
		),
		array(
			'label' => __( 'כל התיקים', 'diromax-portal' ),
			'url'   => Admin::page_url( Admin::PAGE_CASES ),
		),
	),
);
?>
<div class="wrap dxa dxa-dash">
	<?php require __DIR__ . '/parts/header.php'; ?>
	<?php require __DIR__ . '/parts/flash.php'; ?>

	<?php if ( ! $view['has_cases'] ) : ?>
		<section class="dxa-welcome" aria-labelledby="dxa-welcome-title">
			<h2 id="dxa-welcome-title"><?php esc_html_e( 'ברוכים הבאים לתיקי הלקוחות', 'diromax-portal' ); ?></h2>
			<p class="dxa-welcome__lead"><?php esc_html_e( 'כאן מנהלים כל פרויקט השבחה מההסכם ועד המכירה: תקציב, הוצאות, דרישות תשלום, מסמכים ועדכונים. הלקוח רואה את התיק שלו באזור האישי באתר, ומקבל מייל מעוצב על כל עדכון.', 'diromax-portal' ); ?></p>
			<ol class="dxa-welcome__steps">
				<li><strong><?php esc_html_e( 'פותחים תיק', 'diromax-portal' ); ?></strong> <?php esc_html_e( 'כתובת הנכס, ההסכם ומנהל הפרויקט.', 'diromax-portal' ); ?></li>
				<li><strong><?php esc_html_e( 'קובעים תקציב', 'diromax-portal' ); ?></strong> <?php esc_html_e( 'לפי סעיפים: תכנון, יועצים, אגרות, ביצוע.', 'diromax-portal' ); ?></li>
				<li><strong><?php esc_html_e( 'מוסיפים את הלקוח', 'diromax-portal' ); ?></strong> <?php esc_html_e( 'הוא מקבל קישור כניסה במייל, בלי סיסמה.', 'diromax-portal' ); ?></li>
				<li><strong><?php esc_html_e( 'מזינים תנועות ועדכונים', 'diromax-portal' ); ?></strong> <?php esc_html_e( 'והלקוח רואה הכל מיד.', 'diromax-portal' ); ?></li>
			</ol>
			<div class="dxa-welcome__actions">
				<a class="dxa-btn dxa-btn--primary" href="<?php echo esc_url( Admin::page_url( Admin::PAGE_NEW ) ); ?>"><?php esc_html_e( 'פתיחת תיק ראשון', 'diromax-portal' ); ?></a>
				<?php require __DIR__ . '/parts/demo-button.php'; ?>
			</div>
			<p class="dxa-muted"><?php esc_html_e( 'התיק לדוגמה מגיע מלא: תקציב, תנועות, דרישת תשלום באיחור, עדכונים והחלטה שממתינה ללקוח. אין בו לקוחות, ולכן לא יוצא ממנו שום מייל.', 'diromax-portal' ); ?></p>
		</section>
	<?php else : ?>
		<?php $dash_totals = $view['totals']; ?>
		<ul class="dxa-kpis" aria-label="<?php esc_attr_e( 'סיכום התיקים הפעילים', 'diromax-portal' ); ?>">
			<li>
				<span class="dxa-kpis__k"><?php esc_html_e( 'תיקים פעילים', 'diromax-portal' ); ?></span>
				<span class="dxa-kpis__v"><?php echo esc_html( (string) $dash_totals['cases'] ); ?></span>
			</li>
			<li>
				<span class="dxa-kpis__k"><?php esc_html_e( 'תקציב בניהול', 'diromax-portal' ); ?></span>
				<span class="dxa-kpis__v" dir="ltr"><?php echo esc_html( Money::format( $dash_totals['budget'] ) ); ?></span>
			</li>
			<li>
				<span class="dxa-kpis__k"><?php esc_html_e( 'שולם עד היום', 'diromax-portal' ); ?></span>
				<span class="dxa-kpis__v" dir="ltr"><?php echo esc_html( Money::format( $dash_totals['cost'] ) ); ?></span>
				<?php if ( $dash_totals['budget'] > 0 ) : ?>
					<span class="dxa-kpis__s">
						<?php
						/* translators: %s: share of the budget. */
						echo esc_html( sprintf( __( '%s מהתקציב', 'diromax-portal' ), Money::percent( Money::share_bps( $dash_totals['cost'], $dash_totals['budget'] ) ) ) );
						?>
					</span>
					<span class="dxa-bar" aria-hidden="true"><i style="width:<?php echo esc_attr( (string) min( 100, intdiv( Money::share_bps( $dash_totals['cost'], $dash_totals['budget'] ), 100 ) ) ); ?>%"></i></span>
				<?php endif; ?>
			</li>
			<li>
				<span class="dxa-kpis__k"><?php esc_html_e( 'התחייבויות פתוחות', 'diromax-portal' ); ?></span>
				<span class="dxa-kpis__v" dir="ltr"><?php echo esc_html( Money::format( $dash_totals['committed'] ) ); ?></span>
			</li>
			<li>
				<span class="dxa-kpis__k"><?php esc_html_e( 'דרישות תשלום פתוחות', 'diromax-portal' ); ?></span>
				<span class="dxa-kpis__v" dir="ltr"><?php echo esc_html( Money::format( $dash_totals['open'] ) ); ?></span>
				<span class="dxa-kpis__s">
					<?php
					/* translators: %d: number of open requests. */
					echo esc_html( sprintf( _n( '%d דרישה', '%d דרישות', $dash_totals['open_count'], 'diromax-portal' ), $dash_totals['open_count'] ) );
					?>
				</span>
			</li>
			<li class="<?php echo $dash_totals['overdue_count'] > 0 ? 'dxa-kpis--hot' : ''; ?>">
				<span class="dxa-kpis__k"><?php esc_html_e( 'באיחור', 'diromax-portal' ); ?></span>
				<span class="dxa-kpis__v" dir="ltr"><?php echo esc_html( Money::format( $dash_totals['overdue'] ) ); ?></span>
				<span class="dxa-kpis__s">
					<?php
					echo esc_html(
						0 === $dash_totals['overdue_count']
							? __( 'אין איחורים', 'diromax-portal' )
							/* translators: %d: number of overdue requests. */
							: sprintf( _n( '%d דרישה', '%d דרישות', $dash_totals['overdue_count'], 'diromax-portal' ), $dash_totals['overdue_count'] )
					);
					?>
				</span>
			</li>
		</ul>
	<?php endif; ?>

	<div class="dxa-dash-grid">
	<div class="dxa-dash-main">
	<h2 class="dxa-section-title"><?php esc_html_e( 'דורש טיפול', 'diromax-portal' ); ?></h2>
	<?php $attention = count( $view['overdue'] ) + count( $view['upcoming'] ) + count( $view['decisions'] ) + count( $view['actions'] ) + count( $view['stale'] ) + count( $view['quiet'] ) + count( $view['failed'] ) + count( $view['bounced'] ) + count( $view['late'] ) + count( $view['tasks'] ) + count( $view['awaiting'] ); ?>
	<?php if ( 0 === $attention ) : ?>
		<p class="dxa-allclear" role="status"><span aria-hidden="true">✓</span> <?php esc_html_e( 'אין כרגע שום דבר שמחכה לטיפול: אין דרישות באיחור או בשבוע הקרוב, אין החלטות פתוחות, וכל הלקוחות קיבלו עדכון לאחרונה.', 'diromax-portal' ); ?></p>
	<?php endif; ?>
	<?php if ( $attention > 0 ) : ?>
	<div class="dxa-cards">
		<section class="dxa-card<?php echo array() === $view['tasks'] ? ' dxa-card--empty' : ''; ?>" aria-labelledby="dxa-dash-tasks">
			<h2 id="dxa-dash-tasks"><?php esc_html_e( 'המשימות שלי', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['tasks'] ) ); ?></span></h2>
			<?php if ( array() === $view['tasks'] ) : ?>
				<p><?php esc_html_e( 'אין משימות פתוחות שהוטלו עליך.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<div class="dxa-scroll"><table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'משימה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'עד', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['tasks'] as $row ) : ?>
							<?php $task_late = null !== $row['due_date'] && $row['due_date'] < $view['today']; ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( Admin::case_url( (int) $row['case_id'], 'tasks' ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a><br>
									<span class="dxa-muted"><?php echo esc_html( $row['case_title'] . ' · ' . $row['number'] ); ?></span>
								</td>
								<td>
									<?php if ( null === $row['due_date'] ) : ?>
										<span class="dxa-muted"><?php esc_html_e( 'בלי תאריך', 'diromax-portal' ); ?></span>
									<?php elseif ( $task_late ) : ?>
										<span class="dxa-late"><?php echo esc_html( Labels::date( $row['due_date'] ) . ' · ' . __( 'באיחור', 'diromax-portal' ) ); ?></span>
									<?php else : ?>
										<?php echo esc_html( $row['due_date'] === $view['today'] ? __( 'היום', 'diromax-portal' ) : Labels::date( $row['due_date'] ) ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<?php if ( array() !== $view['drafts'] ) : ?>
			<section class="dxa-card dxa-card--hot" aria-labelledby="dxa-dash-drafts">
				<h2 id="dxa-dash-drafts"><?php esc_html_e( 'דרישות תשלום לאישור', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['drafts'] ) ); ?></span></h2>
				<ul class="dxa-list">
					<?php foreach ( $view['drafts'] as $row ) : ?>
						<li>
							<a href="<?php echo esc_url( Admin::case_url( $row['payment']['case_id'], 'plan' ) . '#form-approve' . $row['payment']['id'] ); ?>"><?php echo esc_html( $row['payment']['label'] ); ?></a><br>
							<span class="dxa-muted"><?php echo esc_html( $row['case_title'] . ' · ' . Money::format( $row['payment']['draft_amount'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( array() !== $view['meetings'] ) : ?>
			<section class="dxa-card" aria-labelledby="dxa-dash-meetings">
				<h2 id="dxa-dash-meetings"><?php esc_html_e( 'פגישות בשבוע הקרוב', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['meetings'] ) ); ?></span></h2>
				<ul class="dxa-list">
					<?php foreach ( $view['meetings'] as $row ) : ?>
						<li>
							<a href="<?php echo esc_url( Admin::case_url( (int) $row['meeting']['case_id'], 'meetings' ) ); ?>"><?php echo esc_html( $row['meeting']['title'] ); ?></a><br>
							<span class="dxa-muted"><?php echo esc_html( Labels::meeting_when( $row['meeting']['starts_at'], $row['meeting']['minutes'] ) . ' · ' . $row['case_title'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( array() !== $view['awaiting'] ) : ?>
			<section class="dxa-card dxa-card--hot" aria-labelledby="dxa-dash-awaiting">
				<h2 id="dxa-dash-awaiting"><?php esc_html_e( 'הודעות שממתינות לתשובה', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['awaiting'] ) ); ?></span></h2>
				<ul>
					<?php foreach ( $view['awaiting'] as $row ) : ?>
						<li>
							<a href="<?php echo esc_url( Admin::case_url( (int) $row['case_id'], 'messages' ) ); ?>"><?php echo esc_html( (string) $row['case_title'] ); ?></a>
							· <?php echo esc_html( (string) $row['sender_name'] ); ?>
							· <span class="dxa-muted"><?php echo esc_html( Labels::datetime( (string) $row['received_at'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'תיקים שההודעה האחרונה בהם היא של הלקוח.', 'diromax-portal' ); ?></p>
			</section>
		<?php endif; ?>

		<section class="dxa-card dxa-card--hot<?php echo array() === $view['overdue'] ? ' dxa-card--empty' : ''; ?>" aria-labelledby="dxa-dash-overdue">
			<h2 id="dxa-dash-overdue"><?php esc_html_e( 'דרישות תשלום באיחור', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) $view['totals']['overdue_count'] ); ?></span></h2>
			<?php if ( array() === $view['overdue'] ) : ?>
				<p><?php esc_html_e( 'אין דרישות באיחור.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<div class="dxa-scroll"><table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'דרישה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'באיחור', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תזכורות', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'פעולות', 'diromax-portal' ); ?></th>
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
								<td><?php $request_actions( $row, (string) $view['today'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<section class="dxa-card<?php echo array() === $view['upcoming'] ? ' dxa-card--empty' : ''; ?>" aria-labelledby="dxa-dash-upcoming">
			<h2 id="dxa-dash-upcoming"><?php esc_html_e( 'לתשלום ב-7 הימים הקרובים', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['upcoming'] ) ); ?></span></h2>
			<?php if ( array() === $view['upcoming'] ) : ?>
				<p><?php esc_html_e( 'אין דרישות שמועדן מתקרב.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<div class="dxa-scroll"><table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'דרישה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'עד', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'פעולות', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['upcoming'] as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'finance' ); ?></td>
								<td><?php echo esc_html( (string) $row['description'] ); ?></td>
								<td dir="ltr" class="dxa-num"><?php echo esc_html( Money::format( (int) $row['total'] ) ); ?></td>
								<td dir="ltr"><?php echo esc_html( Labels::date( $row['due_date'] ) ); ?></td>
								<td><?php $request_actions( $row, (string) $view['today'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<section class="dxa-card<?php echo array() === $view['late'] ? ' dxa-card--empty' : ' dxa-card--hot'; ?>" aria-labelledby="dxa-dash-late">
			<h2 id="dxa-dash-late"><?php esc_html_e( 'שלבים שעברו את היעד', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['late'] ) ); ?></span></h2>
			<?php if ( array() === $view['late'] ) : ?>
				<p><?php esc_html_e( 'כל השלבים עומדים ביעד, או שלא נקבע להם יעד.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<div class="dxa-scroll"><table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'שלב', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'היעד היה', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( array_slice( $view['late'], 0, 10 ) as $row ) : ?>
							<tr>
								<td><?php $case_link( $row, 'details' ); ?></td>
								<td><?php echo esc_html( $row['stage'] ); ?></td>
								<td>
									<?php
									/* translators: 1: due date, 2: days late. */
									echo esc_html( sprintf( _n( '%1$s · יום %2$d באיחור', '%1$s · %2$d ימים באיחור', $row['days'], 'diromax-portal' ), Labels::date( $row['due'] ), $row['days'] ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<section class="dxa-card<?php echo array() === $view['decisions'] ? ' dxa-card--empty' : ''; ?>" aria-labelledby="dxa-dash-decisions">
			<h2 id="dxa-dash-decisions"><?php esc_html_e( 'החלטות שממתינות ללקוח', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['decisions'] ) ); ?></span></h2>
			<?php if ( array() === $view['decisions'] ) : ?>
				<p><?php esc_html_e( 'אין החלטות פתוחות.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<div class="dxa-scroll"><table class="widefat striped">
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
				</table></div>
			<?php endif; ?>
		</section>

		<section class="dxa-card<?php echo array() === $view['actions'] ? ' dxa-card--empty' : ''; ?>" aria-labelledby="dxa-dash-actions">
			<h2 id="dxa-dash-actions"><?php esc_html_e( 'תשובות ואישורים מהשבוע האחרון', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['actions'] ) ); ?></span></h2>
			<?php if ( array() === $view['actions'] ) : ?>
				<p><?php esc_html_e( 'לקוחות לא ענו על החלטות ולא אישרו הסכמים השבוע.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<div class="dxa-scroll"><table class="widefat striped">
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
				</table></div>
			<?php endif; ?>
		</section>

		<section class="dxa-card<?php echo array() === $view['stale'] ? ' dxa-card--empty' : ''; ?>" aria-labelledby="dxa-dash-stale">
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
				<div class="dxa-scroll"><table class="widefat striped">
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
				</table></div>
			<?php endif; ?>
		</section>

		<section class="dxa-card<?php echo array() === $view['quiet'] ? ' dxa-card--empty' : ''; ?>" aria-labelledby="dxa-dash-quiet">
			<h2 id="dxa-dash-quiet"><?php esc_html_e( 'לקוחות שהוזמנו ועוד לא נכנסו', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['quiet'] ) ); ?></span></h2>
			<?php if ( array() === $view['quiet'] ) : ?>
				<p><?php esc_html_e( 'כל מי שהוזמן לפני יותר מ-3 ימים כבר נכנס.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<div class="dxa-scroll"><table class="widefat striped">
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
				</table></div>
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

		<?php if ( array() !== $view['bounced'] ) : ?>
			<section class="dxa-card dxa-card--hot" aria-labelledby="dxa-dash-bounced">
				<h2 id="dxa-dash-bounced"><?php esc_html_e( 'מיילים שחזרו', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( $view['bounced'] ) ); ?></span></h2>
				<ul>
					<?php foreach ( $view['bounced'] as $row ) : ?>
						<li>
							<a href="<?php echo esc_url( Admin::case_url( (int) $row['case_id'], 'log' ) ); ?>"><?php echo esc_html( (string) $row['case_title'] ); ?></a>
							· <span dir="ltr"><?php echo esc_html( (string) $row['recipient'] ); ?></span>
							· <?php echo esc_html( 'complaint' === $row['status'] ? __( 'סומן כספאם', 'diromax-portal' ) : __( 'המייל חזר', 'diromax-portal' ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'ב-30 הימים האחרונים. בדרך כלל כתובת שגויה: בודקים מול הלקוח ומעדכנים בלשונית „לקוחות”.', 'diromax-portal' ); ?></p>
			</section>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	</div>

	<aside class="dxa-dash-side" aria-label="<?php esc_attr_e( 'תיקים ופעילות', 'diromax-portal' ); ?>">
		<section class="dxa-card dxa-card--plain" aria-labelledby="dxa-dash-cases">
			<h2 id="dxa-dash-cases"><?php esc_html_e( 'התיקים הפעילים', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) $view['totals']['cases'] ); ?></span></h2>
			<?php if ( array() === $view['cases'] ) : ?>
				<p><?php esc_html_e( 'אין תיקים פעילים.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<ul class="dxa-cases">
					<?php foreach ( array_slice( $view['cases'], 0, 8 ) as $active ) : ?>
						<?php
						$budget = (int) ( $active->summary['budget'] ?? 0 );
						$share  = $budget > 0 ? Money::share_bps( (int) ( $active->summary['cost_to_date'] ?? 0 ), $budget ) : 0;
						?>
						<li class="dxa-cases__i">
							<a class="dxa-cases__t" href="<?php echo esc_url( Admin::case_url( $active->id ) ); ?>"><?php echo esc_html( $active->title ); ?></a>
							<span class="dxa-cases__m">
								<?php
								/* translators: 1: case number, 2: where the case stands, e.g. "03 · stage name". */
								echo esc_html( sprintf( __( '%1$s · %2$s', 'diromax-portal' ), $active->number, Stages::summary( $active->stages ) ) );
								?>
							</span>
							<?php echo CaseCells::strip( $active ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in CaseCells. ?>
							<?php if ( $budget > 0 ) : ?>
								<span class="dxa-cases__b">
									<span class="dxa-bar" aria-hidden="true"><i style="width:<?php echo esc_attr( (string) min( 100, intdiv( $share, 100 ) ) ); ?>%"></i></span>
									<?php
									/* translators: 1: share of the budget, 2: budget. */
									echo esc_html( sprintf( __( '%1$s מתוך %2$s', 'diromax-portal' ), Money::percent( $share ), Money::format( $budget ) ) );
									?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="dxa-card__more"><a href="<?php echo esc_url( Admin::page_url( Admin::PAGE_CASES ) ); ?>"><?php esc_html_e( 'לכל התיקים', 'diromax-portal' ); ?></a></p>
			<?php endif; ?>
		</section>

		<section class="dxa-card dxa-card--plain" aria-labelledby="dxa-dash-activity">
			<h2 id="dxa-dash-activity"><?php esc_html_e( 'פעילות אחרונה', 'diromax-portal' ); ?></h2>
			<?php if ( array() === $view['recent'] ) : ?>
				<p><?php esc_html_e( 'אין עדיין פעילות.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<?php $audit_labels = Labels::audit_actions(); ?>
				<ul class="dxa-feed">
					<?php foreach ( $view['recent'] as $entry ) : ?>
						<li>
							<strong><?php echo esc_html( $audit_labels[ $entry['action'] ] ?? $entry['action'] ); ?></strong>
							<a href="<?php echo esc_url( Admin::case_url( $entry['case_id'] ) ); ?>"><?php echo esc_html( $entry['case_title'] ); ?></a>
							<span class="dxa-muted"><?php echo esc_html( Labels::datetime( $entry['created_at'] ) . ' · ' . ( '' === $entry['user'] ? __( 'מערכת', 'diromax-portal' ) : $entry['user'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php if ( $view['has_cases'] ) : ?>
			<section class="dxa-card dxa-card--plain dxa-card--quiet" aria-labelledby="dxa-dash-demo">
				<h2 id="dxa-dash-demo"><?php esc_html_e( 'תיק לדוגמה', 'diromax-portal' ); ?></h2>
				<p><?php esc_html_e( 'תיק מלא לניסיון: תקציב, תנועות, דרישות תשלום, עדכונים והחלטה. בלי לקוחות ובלי מיילים.', 'diromax-portal' ); ?></p>
				<?php require __DIR__ . '/parts/demo-button.php'; ?>
			</section>
		<?php endif; ?>
	</aside>
	</div>
</div>
