<?php
/**
 * Admin screen: the office's payments forecast (spec 20.2). Administrators only.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$header  = array(
	'current' => 'forecast',
	'eyebrow' => __( 'תיקי לקוחות', 'diromax-portal' ),
	'title'   => __( 'תחזית תשלומים', 'diromax-portal' ),
	'meta'    => array( __( 'דרישות התשלום הפתוחות בכל התיקים הפעילים, והתקציב של כל תיק מול מה ששולם ומה שהתחייבו.', 'diromax-portal' ) ),
);
$total   = (array) $view['total'];
$csv_url = wp_nonce_url( add_query_arg( 'action', 'dx_portal_forecast_csv', admin_url( 'admin-post.php' ) ), 'dx_portal_forecast_csv' );
?>
<div class="wrap dxa">
	<?php require __DIR__ . '/parts/header.php'; ?>

	<p class="dxa-forecast__tools">
		<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'הורדת כל הדרישות הפתוחות ל-CSV', 'diromax-portal' ); ?></a>
	</p>

	<section class="dxa-panel" aria-labelledby="dxa-forecast-months">
		<h2 id="dxa-forecast-months"><?php esc_html_e( 'לפי מועד התשלום', 'diromax-portal' ); ?></h2>
		<div class="dxa-scroll"><table class="widefat dxa-table dxa-forecast">
			<caption class="screen-reader-text"><?php esc_html_e( 'דרישות תשלום פתוחות לפי תקופה', 'diromax-portal' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'תקופה', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'דרישות', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'לדירומקס', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'לספקים', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'סה״כ', 'diromax-portal' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $view['buckets'] as $bucket ) : ?>
					<tr<?php echo 'overdue' === $bucket['key'] && $bucket['count'] > 0 ? ' class="is-late"' : ''; ?>>
						<th scope="row"><?php echo esc_html( (string) $bucket['label'] ); ?></th>
						<td><?php echo esc_html( (string) $bucket['count'] ); ?></td>
						<td dir="ltr"><?php echo esc_html( Money::format( (int) $bucket['diromax'] ) ); ?></td>
						<td dir="ltr"><?php echo esc_html( Money::format( (int) $bucket['suppliers'] ) ); ?></td>
						<td dir="ltr"><strong><?php echo esc_html( Money::format( (int) $bucket['total'] ) ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row"><?php esc_html_e( 'סה״כ פתוח', 'diromax-portal' ); ?></th>
					<td><?php echo esc_html( (string) $total['count'] ); ?></td>
					<td dir="ltr"><?php echo esc_html( Money::format( (int) $total['diromax'] ) ); ?></td>
					<td dir="ltr"><?php echo esc_html( Money::format( (int) $total['suppliers'] ) ); ?></td>
					<td dir="ltr"><strong><?php echo esc_html( Money::format( (int) $total['total'] ) ); ?></strong></td>
				</tr>
			</tfoot>
		</table></div>
		<p class="description"><?php esc_html_e( '"לדירומקס": דרישות שהלקוח משלם למשרד. "לספקים": דרישות שהלקוח משלם ישירות לספק. דרישות פנימיות שהלקוח לא רואה לא נספרות.', 'diromax-portal' ); ?></p>
	</section>

	<section class="dxa-panel" aria-labelledby="dxa-forecast-late">
		<h2 id="dxa-forecast-late"><?php esc_html_e( 'דרישות באיחור', 'diromax-portal' ); ?> <span class="dxa-count"><?php echo esc_html( (string) count( (array) $view['overdue'] ) ); ?></span></h2>
		<?php if ( array() === $view['overdue'] ) : ?>
			<p class="dxa-muted"><?php esc_html_e( 'אין דרישות תשלום באיחור.', 'diromax-portal' ); ?></p>
		<?php else : ?>
			<div class="dxa-scroll"><table class="widefat striped dxa-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'דרישות תשלום באיחור, מהאיחור הארוך ביותר', 'diromax-portal' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'דרישה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'למי', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'עד', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ימי איחור', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $view['overdue'] as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( Admin::case_url( (int) $row['case_id'], 'finance' ) ); ?>"><?php echo esc_html( (string) $row['case_title'] ); ?></a><br><span class="dxa-muted"><?php echo esc_html( (string) $row['number'] ); ?></span></td>
							<td><?php echo esc_html( (string) $row['description'] ); ?></td>
							<td><?php echo esc_html( $row['to_diromax'] ? __( 'דירומקס', 'diromax-portal' ) : ( '' !== $row['payee'] ? (string) $row['payee'] : __( 'ספק', 'diromax-portal' ) ) ); ?></td>
							<td><?php echo esc_html( Labels::date( (string) $row['due_date'] ) ); ?></td>
							<td><span class="dxa-late"><?php echo esc_html( (string) $row['days'] ); ?></span></td>
							<td dir="ltr"><?php echo esc_html( Money::format( (int) $row['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	</section>

	<section class="dxa-panel" aria-labelledby="dxa-forecast-budget">
		<h2 id="dxa-forecast-budget"><?php esc_html_e( 'תקציב מול ביצוע בתיקים', 'diromax-portal' ); ?></h2>
		<?php if ( array() === $view['variance'] ) : ?>
			<p class="dxa-muted"><?php esc_html_e( 'אין עדיין תיקים פעילים עם תקציב או הוצאות.', 'diromax-portal' ); ?></p>
		<?php else : ?>
			<div class="dxa-scroll"><table class="widefat striped dxa-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'תקציב מול שולם והתחייבויות, תיקים שחרגו קודם', 'diromax-portal' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תקציב', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'שולם', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'התחייבויות', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'נותר / חריגה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ניצול', 'diromax-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $view['variance'] as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( Admin::case_url( (int) $row['case_id'], 'budget' ) ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a><br><span class="dxa-muted"><?php echo esc_html( (string) $row['number'] ); ?></span></td>
							<td dir="ltr"><?php echo esc_html( Money::format( (int) $row['budget'] ) ); ?></td>
							<td dir="ltr"><?php echo esc_html( Money::format( (int) $row['cost'] ) ); ?></td>
							<td dir="ltr"><?php echo esc_html( Money::format( (int) $row['committed'] ) ); ?></td>
							<td>
								<?php if ( $row['over'] ) : ?>
									<span class="dxa-late">
										<?php
										/* translators: %s: amount over budget. */
										echo esc_html( sprintf( __( 'חריגה %s', 'diromax-portal' ), Money::format( - (int) $row['remaining'] ) ) );
										?>
									</span>
								<?php else : ?>
									<span dir="ltr"><?php echo esc_html( Money::format( (int) $row['remaining'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( (int) $row['budget'] > 0 ? Money::percent( (int) $row['used_bps'] ) : '—' ); ?>
								<?php if ( $row['near'] ) : ?>
									<span class="dxa-muted"><?php esc_html_e( '(קרוב לתקרה)', 'diromax-portal' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	</section>
</div>
