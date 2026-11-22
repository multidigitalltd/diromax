<?php
/**
 * Admin: signature certificate of a document or a decision, on its own page
 * for printing. Staff only; every opening is audited.
 *
 * @package Diromax\Portal
 *
 * @var \Diromax\Portal\Data\CaseRecord                                                  $client_case
 * @var string                                                                           $title
 * @var string                                                                           $type
 * @var string                                                                           $current
 * @var string                                                                           $back
 * @var array<int, array{row:array<string, mixed>, valid:bool, id_number:string, image:string}> $rows
 */

declare( strict_types = 1 );

use Diromax\Portal\Domain\IsraeliId;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: what was signed. */ __( 'אישור חתימה · %s', 'diromax-portal' ), $title ) ); ?></title>
	<style>
		body { margin: 0; padding: 24px; background: #fff; color: #15171c; font: 15px/1.55 'Heebo', system-ui, sans-serif; }
		main { max-width: 860px; margin: 0 auto; }
		h1 { margin: 0 0 4px; font-size: 26px; }
		.meta { margin: 0 0 20px; color: #55585f; }
		.sig { margin: 0 0 18px; padding: 14px 16px; border: 2px solid #15171c; break-inside: avoid; }
		.sig.is-old { border-style: dashed; }
		.sig h2 { margin: 0 0 8px; font-size: 18px; }
		dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0; }
		dt { font-weight: 700; }
		dd { margin: 0; overflow-wrap: anywhere; }
		.hash { font-family: ui-monospace, monospace; font-size: 13px; direction: ltr; text-align: left; }
		img { display: block; max-width: 320px; height: auto; margin-top: 8px; border-bottom: 1px solid #cfcdc8; }
		.warn { color: #a1510c; font-weight: 700; }
		.tools { margin: 0 0 20px; }
		@media print { .tools { display: none; } body { padding: 0; } }
	</style>
</head>
<body>
	<main>
		<p class="tools"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'חזרה לתיק', 'diromax-portal' ); ?></a></p>
		<h1><?php esc_html_e( 'אישור חתימה', 'diromax-portal' ); ?></h1>
		<p class="meta">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: document or decision, 2: title, 3: case address, 4: case number. */
					__( '%1$s: %2$s · תיק %3$s (%4$s)', 'diromax-portal' ),
					'decision' === $type ? __( 'החלטה', 'diromax-portal' ) : __( 'מסמך', 'diromax-portal' ),
					$title,
					$client_case->title,
					$client_case->number
				)
			);
			?>
		</p>
		<p class="meta"><?php esc_html_e( 'טביעת האצבע (SHA-256) הנוכחית של מה שנחתם:', 'diromax-portal' ); ?> <span class="hash"><?php echo esc_html( $current ); ?></span></p>

		<?php if ( array() === $rows ) : ?>
			<p><?php esc_html_e( 'עדיין אין חתימות.', 'diromax-portal' ); ?></p>
		<?php endif; ?>

		<?php foreach ( $rows as $index => $item ) : ?>
			<?php $row = $item['row']; ?>
			<section class="sig<?php echo $item['valid'] ? '' : ' is-old'; ?>" aria-labelledby="sig-<?php echo esc_attr( (string) $index ); ?>">
				<h2 id="sig-<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html( (string) $row['signer_name'] ); ?></h2>
				<?php if ( ! $item['valid'] ) : ?>
					<p class="warn"><?php esc_html_e( 'החתימה הזו על גרסה אחרת של התוכן, ולכן אינה בתוקף לגרסה הנוכחית.', 'diromax-portal' ); ?></p>
				<?php endif; ?>
				<dl>
					<dt><?php esc_html_e( 'מייל', 'diromax-portal' ); ?></dt>
					<dd dir="ltr"><?php echo esc_html( (string) $row['signer_email'] ); ?></dd>
					<?php if ( '' !== $item['id_number'] ) : ?>
						<dt><?php esc_html_e( 'תעודת זהות', 'diromax-portal' ); ?></dt>
						<dd dir="ltr"><?php echo esc_html( $item['id_number'] ); ?> <span class="meta">(<?php echo esc_html( IsraeliId::masked( $item['id_number'] ) ); ?>)</span></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'שיטה', 'diromax-portal' ); ?></dt>
					<dd><?php echo esc_html( 'link' === $row['method'] ? __( 'קישור במייל, עם קוד חד-פעמי לאותה כתובת', 'diromax-portal' ) : __( 'אזור אישי, משתמש מחובר', 'diromax-portal' ) ); ?></dd>
					<dt><?php esc_html_e( 'נחתם', 'diromax-portal' ); ?></dt>
					<dd><?php echo esc_html( Labels::datetime( (string) $row['signed_at'] ) ); ?></dd>
					<?php if ( null !== $row['verified_at'] ) : ?>
						<dt><?php esc_html_e( 'הקוד אומת', 'diromax-portal' ); ?></dt>
						<dd><?php echo esc_html( Labels::datetime( (string) $row['verified_at'] ) ); ?></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'כתובת IP', 'diromax-portal' ); ?></dt>
					<dd dir="ltr"><?php echo esc_html( (string) $row['ip'] ); ?></dd>
					<dt><?php esc_html_e( 'דפדפן', 'diromax-portal' ); ?></dt>
					<dd dir="ltr"><?php echo esc_html( (string) $row['user_agent'] ); ?></dd>
					<dt><?php esc_html_e( 'SHA-256 של מה שנחתם', 'diromax-portal' ); ?></dt>
					<dd class="hash"><?php echo esc_html( (string) $row['sha256'] ); ?></dd>
				</dl>
				<?php if ( '' !== $item['image'] ) : ?>
					<img src="<?php echo esc_attr( 'data:image/png;base64,' . base64_encode( $item['image'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline image of the drawn signature. ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: name. */ __( 'החתימה של %s', 'diromax-portal' ), (string) $row['signer_name'] ) ); ?>">
				<?php else : ?>
					<p class="meta"><?php esc_html_e( 'בלי ציור: השם המלא שהוקלד הוא החתימה.', 'diromax-portal' ); ?></p>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
	</main>
</body>
</html>
