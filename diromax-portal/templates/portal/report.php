<?php
/**
 * Portal: the case report, on its own page for printing or saving as PDF
 * (spec 16.5). Only what the viewer may see in the portal.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$case    = $view['case'];
$manager = $view['manager'];
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: case address. */ __( 'דוח תיק · %s', 'diromax-portal' ), $case['title'] ) . ' · ' . get_bloginfo( 'name' ) ); ?></title>
	<?php wp_print_styles( array( 'dx-portal' ) ); ?>
</head>
<body class="dxr">
	<main class="dxr-page">
		<p class="dxr-tools">
			<a class="dxp-btn" href="<?php echo esc_url( (string) $view['back'] ); ?>"><span aria-hidden="true">&rarr;</span> <?php esc_html_e( 'חזרה לתיק', 'diromax-portal' ); ?></a>
			<button class="dxp-btn dxp-btn--orange" type="button" data-dxp-print hidden><?php esc_html_e( 'שמירה כ-PDF / הדפסה', 'diromax-portal' ); ?></button>
		</p>

		<header class="dxr-head">
			<p class="dxr-brand">DIROMAX</p>
			<h1><?php echo esc_html( (string) $case['title'] ); ?></h1>
			<p class="dxr-meta">
				<?php
				echo esc_html(
					implode(
						' · ',
						array_filter(
							array(
								/* translators: %s: case number. */
								sprintf( __( 'תיק %s', 'diromax-portal' ), $case['number'] ),
								(string) $case['scope'],
								(string) $view['summary'],
								/* translators: %s: name. */
								sprintf( __( 'מנהל פרויקט: %s', 'diromax-portal' ), $manager['name'] ),
								/* translators: %s: date and time. */
								sprintf( __( 'הופק: %s', 'diromax-portal' ), $view['printed'] ),
							)
						)
					)
				);
				?>
			</p>
		</header>

		<section aria-labelledby="dxr-stages">
			<h2 id="dxr-stages"><?php esc_html_e( 'שלבי התהליך', 'diromax-portal' ); ?></h2>
			<table class="dxr-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'שלב', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'מצב', 'diromax-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'תאריך', 'diromax-portal' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $view['stages'] as $stage ) : ?>
						<tr>
							<td><?php echo esc_html( $stage['number'] . ' · ' . $stage['name'] ); ?></td>
							<td><?php echo esc_html( (string) $stage['label'] ); ?></td>
							<td><?php echo esc_html( (string) $stage['when'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<section aria-labelledby="dxr-money">
			<h2 id="dxr-money"><?php esc_html_e( 'המצב הכספי', 'diromax-portal' ); ?></h2>
			<dl class="dxr-kpis">
				<?php foreach ( $view['kpis'] as $tile ) : ?>
					<div>
						<dt><?php echo esc_html( (string) $tile['label'] ); ?></dt>
						<dd><span class="dxp-num"><?php echo esc_html( (string) $tile['value'] ); ?></span><?php echo '' !== $tile['note'] ? '<small>' . esc_html( (string) $tile['note'] ) . '</small>' : ''; ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</section>

		<section aria-labelledby="dxr-ledger">
			<h2 id="dxr-ledger"><?php esc_html_e( 'תנועות', 'diromax-portal' ); ?></h2>
			<?php if ( array() === $view['entries'] ) : ?>
				<p><?php esc_html_e( 'עדיין אין תנועות.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<table class="dxr-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'תאריך', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תיאור', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'קטגוריה', 'diromax-portal' ); ?></th>
						<th scope="col" class="dxr-num"><?php esc_html_e( 'סכום כולל מע״מ', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['entries'] as $entry ) : ?>
							<tr<?php echo $entry['voided'] ? ' class="is-void"' : ''; ?>>
								<td><span class="dxp-num"><?php echo esc_html( (string) $entry['date'] ); ?></span></td>
								<td><?php echo esc_html( (string) $entry['tag'] ); ?></td>
								<td>
									<?php echo esc_html( (string) $entry['description'] ); ?>
									<?php if ( $entry['voided'] ) : ?>
										<small>
											<?php
											/* translators: %s: reason. */
											echo esc_html( sprintf( __( 'בוטלה: %s', 'diromax-portal' ), $entry['void_reason'] ) );
											?>
										</small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $entry['category'] ); ?></td>
								<td class="dxr-num"><span class="dxp-num"><?php echo esc_html( (string) $entry['amount'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<?php if ( array() !== $view['decisions'] ) : ?>
			<section aria-labelledby="dxr-decisions">
				<h2 id="dxr-decisions"><?php esc_html_e( 'החלטות', 'diromax-portal' ); ?></h2>
				<table class="dxr-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'החלטה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מצב', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מי ומתי', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['decisions'] as $decision ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $decision['title'] . ( '' === $decision['amount'] ? '' : ' · ' . $decision['amount'] ) ); ?></td>
								<td><?php echo esc_html( (string) $decision['label'] ); ?></td>
								<td><?php echo esc_html( (string) $decision['answered'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>

		<?php if ( array() !== $view['signed'] ) : ?>
			<section aria-labelledby="dxr-signed">
				<h2 id="dxr-signed"><?php esc_html_e( 'חתימות', 'diromax-portal' ); ?></h2>
				<table class="dxr-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'על מה', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מי חתם', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מתי ואיך', 'diromax-portal' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $view['signed'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['kind'] . ': ' . $row['what'] ); ?><br><small class="dxr-hash" dir="ltr">SHA-256 <?php echo esc_html( substr( $row['sha256'], 0, 16 ) ); ?>…</small></td>
								<td><?php echo esc_html( $row['signer'] ); ?></td>
								<td><?php echo esc_html( $row['when'] . ' · ' . $row['how'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>

		<footer class="dxr-foot">
			<p><?php esc_html_e( 'הדוח מציג את מה שגלוי באזור האישי ביום ההפקה. סכומים כוללים מע״מ.', 'diromax-portal' ); ?></p>
		</footer>
	</main>
	<?php wp_print_scripts( array( 'dx-portal' ) ); ?>
</body>
</html>
