<?php
/**
 * Portal: the client's cases.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$cases = is_array( $view['cases'] ?? null ) ? $view['cases'] : array();
?>
<section class="dxp-head" aria-labelledby="dxp-cases-title">
	<div class="dxp-wrap">
		<p class="dxp-eyebrow"><span class="dxp-dot" aria-hidden="true"></span><?php esc_html_e( 'אזור אישי', 'diromax-portal' ); ?></p>
		<h1 id="dxp-cases-title"><?php esc_html_e( 'התיקים שלי', 'diromax-portal' ); ?></h1>
		<?php require __DIR__ . '/parts/account.php'; ?>
	</div>
</section>
<div class="dxp-wrap dxp-pad">
	<?php if ( array() === $cases ) : ?>
		<p class="dxp-lead"><?php esc_html_e( 'עדיין אין תיקים פתוחים בחשבון הזה. אם זה נראה לא נכון, כדאי לפנות למנהל הפרויקט.', 'diromax-portal' ); ?></p>
	<?php else : ?>
		<ul class="dxp-cards">
			<?php foreach ( $cases as $case ) : ?>
				<li class="dxp-card">
					<h2><a href="<?php echo esc_url( (string) $case['url'] ); ?>"><?php echo esc_html( (string) $case['title'] ); ?></a></h2>
					<?php if ( '' !== $case['scope'] ) : ?>
						<p><?php echo esc_html( (string) $case['scope'] ); ?></p>
					<?php endif; ?>
					<p class="dxp-card__meta"><span class="dxp-num"><?php echo esc_html( (string) $case['number'] ); ?></span> · <?php echo esc_html( (string) $case['stage'] ); ?> · <?php echo esc_html( (string) $case['status'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
