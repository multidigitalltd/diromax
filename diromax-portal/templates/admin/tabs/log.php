<?php
/**
 * Admin tab: audit log and mail log.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;
?>
<div class="dxa-cols">
	<section>
		<h2><?php esc_html_e( 'יומן שינויים וגישה', 'diromax-portal' ); ?></h2>
		<?php require __DIR__ . '/../parts/audit-list.php'; ?>
	</section>
	<section>
		<h2><?php esc_html_e( 'מיילים שנשלחו', 'diromax-portal' ); ?></h2>
		<?php if ( array() === $view['mail'] ) : ?>
			<p><?php esc_html_e( 'עדיין לא נשלחו מיילים מהתיק.', 'diromax-portal' ); ?></p>
		<?php else : ?>
			<ul class="dxa-list">
				<?php foreach ( $view['mail'] as $mail ) : ?>
					<li>
						<strong><?php echo esc_html( $mail['subject'] ); ?></strong>
						<span class="dxa-muted"><?php echo esc_html( Labels::datetime( $mail['created_at'] ) ); ?> · <span dir="ltr"><?php echo esc_html( $mail['recipient'] ); ?></span></span>
						<?php if ( 'sent' !== $mail['status'] ) : ?>
							<span class="dxa-badge dxa-badge--late">
								<?php
								echo esc_html(
									match ( $mail['status'] ) {
										'bounced'   => __( 'המייל חזר', 'diromax-portal' ),
										'complaint' => __( 'סומן כספאם', 'diromax-portal' ),
										default     => __( 'השליחה נכשלה', 'diromax-portal' ),
									}
								);
								?>
							</span>
							<?php if ( in_array( $mail['status'], array( 'bounced', 'complaint' ), true ) && '' !== $mail['error'] ) : ?>
								<span class="dxa-muted" dir="ltr"><?php echo esc_html( $mail['error'] ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>
