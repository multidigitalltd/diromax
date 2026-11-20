<?php
/**
 * Admin tab: overview — money, pending emails, members, recent activity.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Domain\Money;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

/** Summary with every entry, hidden ones included. @var \Diromax\Portal\Domain\CaseSummary $summary */
$summary = $view['summary'];
$pending = $view['pending'];
$tiles   = array(
	array( __( 'תקציב מאושר', 'diromax-portal' ), Money::format( $summary->budget ), '' ),
	array( __( 'עלות עד היום', 'diromax-portal' ), Money::format( $summary->cost_to_date ), $summary->budget > 0 ? Money::percent( $summary->utilization_bps() ) : '' ),
	array( __( 'התחייבויות', 'diromax-portal' ), Money::format( $summary->committed ), '' ),
	array( __( 'יתרה בחשבון הפרויקט', 'diromax-portal' ), $summary->has_project_account ? Money::format( $summary->project_balance ) : '—', '' ),
	array( __( 'לפירעון מהתמורה', 'diromax-portal' ), Money::format( $summary->deferred ), '' ),
	/* translators: %d: overdue requests. */
	array( __( 'דרישות פתוחות', 'diromax-portal' ), Money::format( $summary->open_requests ), $summary->overdue_count > 0 ? sprintf( __( '%d באיחור', 'diromax-portal' ), $summary->overdue_count ) : '' ),
);
?>
<?php
$setup = $view['setup'];
$done  = count( array_filter( array_column( $setup, 'done' ) ) );
?>
<?php if ( $done < count( $setup ) ) : ?>
	<section class="dxa-next" aria-labelledby="dxa-next-title">
		<h2 id="dxa-next-title">
			<?php esc_html_e( 'הקמת התיק', 'diromax-portal' ); ?>
			<span class="dxa-muted">
				<?php
				/* translators: 1: steps done, 2: all steps. */
				echo esc_html( sprintf( __( '%1$d מתוך %2$d', 'diromax-portal' ), $done, count( $setup ) ) );
				?>
			</span>
		</h2>
		<div class="dxa-next__bar" style="--dxa-steps:<?php echo esc_attr( (string) count( $setup ) ); ?>" aria-hidden="true">
			<?php foreach ( $setup as $step ) : ?>
				<i class="<?php echo $step['done'] ? 'is-done' : ''; ?>"></i>
			<?php endforeach; ?>
		</div>
		<ol class="dxa-checklist">
			<?php foreach ( $setup as $index => $step ) : ?>
				<li class="<?php echo $step['done'] ? 'is-done' : 'is-todo'; ?>">
					<span class="dxa-checklist__mark" aria-hidden="true"><?php echo $step['done'] ? '✓' : esc_html( (string) ( $index + 1 ) ); ?></span>
					<span class="dxa-checklist__t"><?php echo esc_html( $step['label'] ); ?></span>
					<span class="screen-reader-text"><?php echo $step['done'] ? esc_html__( '(הושלם)', 'diromax-portal' ) : esc_html__( '(עוד לא)', 'diromax-portal' ); ?></span>
					<?php if ( ! $step['done'] ) : ?>
						<a class="dxa-checklist__go" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['action'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
<?php endif; ?>

<?php if ( $pending['count'] > 0 ) : ?>
	<div class="dxa-pending" role="status">
		<p>
			<?php
			$send_time = (string) wp_date( 'H:i', (int) $pending['send_at'] );
			echo esc_html(
				1 === $pending['count']
					/* translators: %s: send time. */
					? sprintf( __( 'עדכון אחד ממתין לשליחה ללקוחות. יישלח ב-%s.', 'diromax-portal' ), $send_time )
					/* translators: 1: number of updates, 2: send time. */
					: sprintf( __( '%1$d עדכונים ממתינים לשליחה ללקוחות. יישלחו ב-%2$s.', 'diromax-portal' ), $pending['count'], $send_time )
			);
			?>
		</p>
		<div class="dxa-pending__actions">
			<a class="button" href="
			<?php
			echo esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'dx_portal_preview',
							'case_id' => $case->id,
						),
						admin_url( 'admin-post.php' )
					),
					'dx_portal_preview_' . $case->id
				)
			);
			?>
									" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'תצוגה מקדימה של המייל', 'diromax-portal' ); ?></a>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dx_portal_send_now">
				<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
				<?php wp_nonce_field( 'dx_portal_send_now_' . $case->id ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'שליחה עכשיו', 'diromax-portal' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dx_portal_cancel_pending">
				<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
				<?php wp_nonce_field( 'dx_portal_cancel_pending_' . $case->id ); ?>
				<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'ביטול השליחה', 'diromax-portal' ); ?></button>
			</form>
		</div>
	</div>
<?php endif; ?>

<ul class="dxa-tiles">
	<?php foreach ( $tiles as [ $label, $value, $note ] ) : ?>
		<li>
			<span class="dxa-tiles__k"><?php echo esc_html( $label ); ?></span>
			<span class="dxa-tiles__v" dir="ltr"><?php echo esc_html( $value ); ?></span>
			<?php if ( '' !== $note ) : ?>
				<span class="dxa-tiles__s"><?php echo esc_html( $note ); ?></span>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>

<div class="dxa-cols">
	<section>
		<h2><?php esc_html_e( 'לקוחות בתיק', 'diromax-portal' ); ?></h2>
		<?php if ( array() === $view['members'] ) : ?>
			<p><?php esc_html_e( 'עדיין אין לקוחות בתיק. מוסיפים אותם בלשונית "לקוחות".', 'diromax-portal' ); ?></p>
		<?php else : ?>
			<ul class="dxa-list">
				<?php foreach ( $view['members'] as $member ) : ?>
					<li>
						<strong><?php echo esc_html( $member->name ); ?></strong>
						<span class="dxa-muted">
							<?php
							echo esc_html(
								null === $member->last_seen_at
									? __( 'עוד לא נכנס לאזור האישי', 'diromax-portal' )
									/* translators: %s: date and time. */
									: sprintf( __( 'נכנס לאחרונה: %s', 'diromax-portal' ), Labels::datetime( $member->last_seen_at ) )
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
	<section>
		<h2><?php esc_html_e( 'פעילות אחרונה', 'diromax-portal' ); ?></h2>
		<?php require __DIR__ . '/../parts/audit-list.php'; ?>
	</section>
</div>
