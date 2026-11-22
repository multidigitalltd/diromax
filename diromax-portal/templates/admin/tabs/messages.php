<?php
/**
 * Admin tab: clients' email replies kept in the case (spec 17.3).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$dx_messages = (array) $view['messages'];
$dx_reasons  = array(
	'type'        => __( 'סוג קובץ שלא מתקבל', 'diromax-portal' ),
	'too_large'   => __( 'גדול מדי', 'diromax-portal' ),
	'not_allowed' => __( 'השולח צופה בתיק ולא מעלה מסמכים', 'diromax-portal' ),
	'storage'     => __( 'השמירה נכשלה', 'diromax-portal' ),
);
$dx_download = static fn( int $document_id ): string => wp_nonce_url(
	add_query_arg(
		array(
			'action'      => 'dx_portal_download',
			'document_id' => $document_id,
		),
		admin_url( 'admin-post.php' )
	),
	'dx_portal_download_' . $document_id
);
?>
<section aria-labelledby="dxa-messages-title">
	<h2 id="dxa-messages-title"><?php esc_html_e( 'הודעות מהלקוחות', 'diromax-portal' ); ?></h2>
	<p class="description">
		<?php
		if ( $view['enabled'] ) {
			esc_html_e( 'תשובות של לקוחות התיק למיילים מהאזור האישי נשמרות כאן, וגם מועברות אליך במייל. קבצים שצורפו נשמרים במסמכי התיק.', 'diromax-portal' );
		} else {
			esc_html_e( 'קבלת תשובות במייל עדיין לא הוגדרה באתר. עד אז, תשובות של לקוחות מגיעות ישירות למייל של מנהל הפרויקט.', 'diromax-portal' );
		}
		?>
	</p>

	<?php if ( array() === $dx_messages ) : ?>
		<p><?php esc_html_e( 'עדיין לא התקבלו הודעות בתיק.', 'diromax-portal' ); ?></p>
	<?php else : ?>
		<ol class="dxa-messages">
			<?php foreach ( $dx_messages as $dx_message ) : ?>
				<li class="dxa-message">
					<article aria-labelledby="dxa-message-<?php echo esc_attr( (string) $dx_message['id'] ); ?>">
						<h3 id="dxa-message-<?php echo esc_attr( (string) $dx_message['id'] ); ?>">
							<?php echo esc_html( '' !== $dx_message['subject'] ? $dx_message['subject'] : __( '(בלי נושא)', 'diromax-portal' ) ); ?>
						</h3>
						<p class="dxa-muted">
							<?php echo esc_html( $dx_message['sender_name'] ); ?>
							· <span dir="ltr"><?php echo esc_html( $dx_message['sender_email'] ); ?></span>
							· <?php echo esc_html( Labels::datetime( $dx_message['received_at'] ) ); ?>
						</p>
						<div class="dxa-message__body" dir="auto"><?php echo nl2br( esc_html( $dx_message['body'] ) ); ?></div>
						<?php if ( array() !== $dx_message['files'] ) : ?>
							<ul class="dxa-message__files">
								<?php foreach ( $dx_message['files'] as $dx_file ) : ?>
									<li>
										<?php if ( null !== $dx_file['document_id'] ) : ?>
											<a href="<?php echo esc_url( $dx_download( $dx_file['document_id'] ) ); ?>" dir="auto"><?php echo esc_html( $dx_file['name'] ); ?></a>
										<?php else : ?>
											<span dir="auto"><?php echo esc_html( $dx_file['name'] ); ?></span>
											<span class="dxa-muted">· <?php echo esc_html( sprintf( /* translators: %s: reason. */ __( 'לא נשמר: %s', 'diromax-portal' ), $dx_reasons[ $dx_file['reason'] ] ?? $dx_reasons['storage'] ) ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</article>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</section>
