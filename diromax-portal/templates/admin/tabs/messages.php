<?php
/**
 * Admin tab: the conversation with the clients of the case (spec 17.3,
 * 18.1): their messages from the portal and their email replies, the
 * team's answers, and the form to answer.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Service\MessageService;

defined( 'ABSPATH' ) || exit;

$form        = new Form( $view['flash'], 'message' );
$dx_messages = (array) $view['messages'];
$dx_reasons  = array(
	'type'        => __( 'סוג קובץ שלא מתקבל', 'diromax-portal' ),
	'too_large'   => __( 'גדול מדי', 'diromax-portal' ),
	'not_allowed' => __( 'השולח צופה בתיק ולא מעלה מסמכים', 'diromax-portal' ),
	'storage'     => __( 'השמירה נכשלה', 'diromax-portal' ),
);
?>
<section aria-labelledby="dxa-messages-title">
	<h2 id="dxa-messages-title"><?php esc_html_e( 'שיחה עם הלקוחות', 'diromax-portal' ); ?></h2>
	<p class="description">
		<?php
		if ( $view['enabled'] ) {
			esc_html_e( 'הודעות שהלקוחות כותבים באזור האישי, ותשובות שלהם למיילים מהאזור האישי, נשמרות כאן וגם מגיעות אליך במייל. קבצים שצורפו נשמרים במסמכי התיק.', 'diromax-portal' );
		} else {
			esc_html_e( 'הודעות שהלקוחות כותבים באזור האישי נשמרות כאן וגם מגיעות אליך במייל. קבלת תשובות במייל לתוך התיק עדיין לא הוגדרה באתר.', 'diromax-portal' );
		}
		?>
	</p>

	<?php if ( array() === $dx_messages ) : ?>
		<p><?php esc_html_e( 'עדיין אין הודעות בתיק.', 'diromax-portal' ); ?></p>
	<?php else : ?>
		<ol class="dxa-messages">
			<?php foreach ( $dx_messages as $dx_message ) : ?>
				<li class="dxa-message<?php echo $dx_message['staff'] ? ' is-staff' : ''; ?>">
					<article aria-labelledby="dxa-message-<?php echo esc_attr( (string) $dx_message['id'] ); ?>">
						<h3 id="dxa-message-<?php echo esc_attr( (string) $dx_message['id'] ); ?>">
							<?php echo esc_html( (string) $dx_message['author'] ); ?>
							<?php if ( $dx_message['staff'] ) : ?>
								<span class="dxa-badge"><?php esc_html_e( 'צוות', 'diromax-portal' ); ?></span>
							<?php endif; ?>
						</h3>
						<p class="dxa-muted">
							<?php if ( '' !== $dx_message['address'] ) : ?>
								<span dir="ltr"><?php echo esc_html( (string) $dx_message['address'] ); ?></span> ·
							<?php endif; ?>
							<?php echo esc_html( (string) $dx_message['when'] ); ?>
							<?php if ( $dx_message['email'] ) : ?>
								· <?php esc_html_e( 'תשובה במייל', 'diromax-portal' ); ?>
							<?php endif; ?>
							<?php if ( '' !== $dx_message['subject'] ) : ?>
								· <?php echo esc_html( (string) $dx_message['subject'] ); ?>
							<?php endif; ?>
						</p>
						<div class="dxa-message__body" dir="auto"><?php echo nl2br( esc_html( (string) $dx_message['body'] ) ); ?></div>
						<?php if ( array() !== $dx_message['files'] ) : ?>
							<ul class="dxa-message__files">
								<?php foreach ( $dx_message['files'] as $dx_file ) : ?>
									<li>
										<?php if ( '' !== $dx_file['url'] ) : ?>
											<a href="<?php echo esc_url( (string) $dx_file['url'] ); ?>" dir="auto"><?php echo esc_html( (string) $dx_file['name'] ); ?></a>
										<?php else : ?>
											<span dir="auto"><?php echo esc_html( (string) $dx_file['name'] ); ?></span>
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

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-box" id="form-message">
		<input type="hidden" name="action" value="dx_portal_reply_message">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_reply_message_' . $case->id ); ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'body' ) ); ?>"><?php esc_html_e( 'תשובה ללקוחות התיק', 'diromax-portal' ); ?></label>
			<textarea <?php echo $form->attrs( 'body' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="5" maxlength="<?php echo esc_attr( (string) MessageService::MAX_LENGTH ); ?>" required dir="auto"><?php echo esc_textarea( $form->old( 'body' ) ); ?></textarea>
			<?php $form->print_error( 'body' ); ?>
		</p>
		<p class="description"><?php esc_html_e( 'נשלחת במייל, במלואה, לכל לקוח בתיק שמקבל מיילים, ונשמרת כאן ובאזור האישי. הלקוח יכול להשיב למייל.', 'diromax-portal' ); ?></p>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'שליחה ללקוחות', 'diromax-portal' ); ?></button></p>
	</form>
</section>
