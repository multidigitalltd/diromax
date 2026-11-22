<?php
/**
 * Portal part: ledger table, budget against actuals, how the balance is made.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$entries = $view['entries'];
?>
<section class="dxp-panel" aria-labelledby="dxp-fin-title">
	<h2 id="dxp-fin-title" class="dxp-sr"><?php esc_html_e( 'כספים', 'diromax-portal' ); ?></h2>

	<?php if ( array() === $entries ) : ?>
		<p class="dxp-lead"><?php esc_html_e( 'עדיין אין תנועות כספיות בתיק. כל הוצאה או תשלום יופיעו כאן ברגע שיירשמו.', 'diromax-portal' ); ?></p>
	<?php else : ?>
		<div class="dxp-tools">
			<div class="dxp-filters" data-dxp-filters hidden>
				<p class="dxp-field">
					<label for="dxp-f-type"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></label>
					<select id="dxp-f-type" data-dxp-filter="type">
						<option value=""><?php esc_html_e( 'הכל', 'diromax-portal' ); ?></option>
						<?php foreach ( $view['filters']['types'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( (string) $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="dxp-field">
					<label for="dxp-f-cat"><?php esc_html_e( 'סעיף', 'diromax-portal' ); ?></label>
					<select id="dxp-f-cat" data-dxp-filter="category">
						<option value=""><?php esc_html_e( 'כל הסעיפים', 'diromax-portal' ); ?></option>
						<?php foreach ( $view['filters']['categories'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( (string) $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="dxp-status" role="status" data-dxp-count></p>
			</div>
			<p class="dxp-tools__btns">
				<button class="dxp-btn" type="button" data-dxp-print hidden><?php esc_html_e( 'הדפסה / PDF', 'diromax-portal' ); ?></button>
				<a class="dxp-btn" href="<?php echo esc_url( (string) $view['export'] ); ?>" download><?php esc_html_e( 'הורדה ל-Excel (CSV)', 'diromax-portal' ); ?></a>
				<a class="dxp-btn" href="<?php echo esc_url( (string) $view['report'] ); ?>"><?php esc_html_e( 'דוח מלא לשמירה כ-PDF', 'diromax-portal' ); ?></a>
			</p>
		</div>

		<div class="dxp-table" role="region" aria-labelledby="dxp-ledger-cap" tabindex="0">
			<table>
				<caption id="dxp-ledger-cap"><?php esc_html_e( 'כל התנועות בתיק', 'diromax-portal' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'תאריך', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תיאור', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סעיף', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'שולם מ', 'diromax-portal' ); ?></th>
						<th scope="col" class="dxp-amt"><?php esc_html_e( 'סכום', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מסמך', 'diromax-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $row ) : ?>
						<tr data-type="<?php echo esc_attr( (string) $row['type'] ); ?>" data-category="<?php echo esc_attr( (string) $row['category_id'] ); ?>"<?php echo $row['voided'] ? ' class="dxp-void"' : ''; ?>>
							<td data-l="<?php esc_attr_e( 'תאריך', 'diromax-portal' ); ?>"><span class="dxp-num"><?php echo esc_html( (string) $row['date'] ); ?></span></td>
							<td><span class="dxp-tag dxp-tag--<?php echo esc_attr( (string) $row['tone'] ); ?>"><?php echo esc_html( (string) $row['tag'] ); ?></span></td>
							<td class="dxp-desc">
								<b><?php echo esc_html( (string) $row['description'] ); ?></b>
								<?php if ( '' !== $row['sub'] ) : ?>
									<small><?php echo esc_html( (string) $row['sub'] ); ?></small>
								<?php endif; ?>
								<?php if ( $row['voided'] ) : ?>
									<small class="dxp-void__why">
										<?php
										/* translators: %s: reason. */
										echo esc_html( sprintf( __( 'בוטל: %s', 'diromax-portal' ), $row['void_reason'] ) );
										?>
									</small>
								<?php endif; ?>
							</td>
							<td data-l="<?php esc_attr_e( 'סעיף', 'diromax-portal' ); ?>"><?php echo esc_html( (string) $row['category'] ); ?></td>
							<td data-l="<?php esc_attr_e( 'שולם מ', 'diromax-portal' ); ?>"><?php echo esc_html( (string) $row['funding'] ); ?></td>
							<td class="dxp-amt<?php echo $row['positive'] ? ' dxp-pos' : ''; ?>">
								<span class="dxp-num"><?php echo esc_html( (string) $row['amount'] ); ?></span>
								<?php if ( '' !== $row['vat'] ) : ?>
									<small><?php echo esc_html( (string) $row['vat'] ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $row['doc_url'] ) : ?>
									<a class="dxp-doc" href="<?php echo esc_url( (string) $row['doc_url'] ); ?>"><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 1h6l3 3v11H4z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg><?php echo esc_html( (string) $row['doc_label'] ); ?><span class="dxp-sr"> — <?php echo esc_html( (string) $row['description'] ); ?></span></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr><td colspan="7"><div class="dxp-totals">
						<span><?php esc_html_e( 'הוצאות ששולמו:', 'diromax-portal' ); ?> <span class="dxp-num"><?php echo esc_html( (string) $view['totals']['cost'] ); ?></span></span>
						<span><?php esc_html_e( 'התחייבויות:', 'diromax-portal' ); ?> <span class="dxp-num"><?php echo esc_html( (string) $view['totals']['committed'] ); ?></span></span>
						<span><?php esc_html_e( 'תשלומים שהתקבלו:', 'diromax-portal' ); ?> <span class="dxp-num"><?php echo esc_html( (string) $view['totals']['payments'] ); ?></span></span>
					</div></td></tr>
				</tfoot>
			</table>
		</div>
	<?php endif; ?>

	<div class="dxp-split">
		<?php if ( array() !== $view['categories'] ) : ?>
		<div>
			<h2><?php esc_html_e( 'תקציב מול ביצוע', 'diromax-portal' ); ?></h2>
			<ul class="dxp-bva">
				<?php foreach ( $view['categories'] as $line ) : ?>
					<li>
						<div class="dxp-bva__row">
							<b><?php echo esc_html( (string) $line['label'] ); ?></b>
							<span>
								<?php
								/* translators: 1: paid, 2: planned. */
								echo esc_html( sprintf( __( '%1$s מתוך %2$s', 'diromax-portal' ), $line['paid'], $line['planned'] ) );
								if ( '' !== $line['committed'] ) {
									/* translators: %s: committed amount. */
									echo esc_html( sprintf( __( ' · התחייבות %s', 'diromax-portal' ), $line['committed'] ) );
								}
								?>
							</span>
						</div>
						<div class="dxp-bar" aria-hidden="true"><i class="dxp-bar__comm" style="width:<?php echo esc_attr( (string) $line['committed_pct'] ); ?>%"></i><i class="dxp-bar__paid" style="width:<?php echo esc_attr( (string) $line['paid_pct'] ); ?>%"></i></div>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="dxp-legend"><span><i class="dxp-legend__paid"></i><?php esc_html_e( 'שולם', 'diromax-portal' ); ?></span><span><i class="dxp-legend__comm"></i><?php esc_html_e( 'התחייבות', 'diromax-portal' ); ?></span></p>
		</div>
		<?php endif; ?>

		<?php if ( null !== $view['balance'] ) : ?>
		<div>
			<h2><?php esc_html_e( 'איך מחושבת היתרה', 'diromax-portal' ); ?></h2>
			<ul class="dxp-bva">
				<li><div class="dxp-bva__row"><b><?php esc_html_e( 'תשלומים שהועברו לחשבון הפרויקט', 'diromax-portal' ); ?></b><span class="dxp-num"><?php echo esc_html( (string) $view['balance']['payments'] ); ?></span></div></li>
				<li><div class="dxp-bva__row"><b><?php esc_html_e( 'הוצאות ששולמו מחשבון הפרויקט', 'diromax-portal' ); ?></b><span class="dxp-num"><?php echo esc_html( (string) $view['balance']['spent'] ); ?></span></div></li>
				<li><div class="dxp-bva__row"><b><?php esc_html_e( 'יתרה בחשבון הפרויקט', 'diromax-portal' ); ?></b><span class="dxp-num"><?php echo esc_html( (string) $view['balance']['balance'] ); ?></span></div></li>
			</ul>
			<p class="dxp-legend"><?php esc_html_e( 'הוצאות ששולמו ישירות לספק, והוצאות שייפרעו מהתמורה, מופיעות בעלות הפרויקט אבל לא משנות את היתרה.', 'diromax-portal' ); ?></p>
		</div>
		<?php endif; ?>
	</div>
</section>
