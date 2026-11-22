<?php
/**
 * Portal part: the conversation with the project manager (spec 18.1),
 * oldest first, with the form at the end.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Service\MessageService;

defined( 'ABSPATH' ) || exit;

$dx_thread  = (array) $view['messages'];
$dx_manager = is_array( $view['manager'] ?? null ) ? (string) ( $view['manager']['name'] ?? '' ) : '';
?>
<section class="dxp-panel" aria-labelledby="dxp-msg-title">
	<h2 id="dxp-msg-title" class="dxp-sr"><?php esc_html_e( 'הודעות', 'diromax-portal' ); ?></h2>
	<?php if ( array() === $dx_thread ) : ?>
		<p class="dxp-lead"><?php esc_html_e( 'כאן כותבים למנהל הפרויקט, ורואים את התשובות שלו. גם תשובה שלך למייל מהאזור האישי מופיעה כאן.', 'diromax-portal' ); ?></p>
	<?php else : ?>
		<ol class="dxp-chat">
			<?php foreach ( $dx_thread as $dx_message ) : ?>
				<li class="dxp-chat__msg<?php echo $dx_message['staff'] ? ' is-staff' : ''; ?><?php echo $dx_message['mine'] ? ' is-mine' : ''; ?>">
					<article aria-label="<?php echo esc_attr( sprintf( /* translators: 1: author, 2: date. */ __( 'הודעה מ%1$s, %2$s', 'diromax-portal' ), $dx_message['author'], $dx_message['when'] ) ); ?>">
						<p class="dxp-chat__who">
							<strong><?php echo esc_html( $dx_message['mine'] ? __( 'את/ה', 'diromax-portal' ) : $dx_message['author'] ); ?></strong>
							<?php if ( $dx_message['staff'] ) : ?>
								<span><?php esc_html_e( 'מנהל הפרויקט', 'diromax-portal' ); ?></span>
							<?php endif; ?>
							<time datetime="<?php echo esc_attr( (string) $dx_message['iso'] ); ?>" class="dxp-num"><?php echo esc_html( (string) $dx_message['when'] ); ?></time>
							<?php if ( $dx_message['email'] ) : ?>
								<span><?php esc_html_e( '(במייל)', 'diromax-portal' ); ?></span>
							<?php endif; ?>
						</p>
						<div class="dxp-chat__body" dir="auto"><?php echo nl2br( esc_html( (string) $dx_message['body'] ) ); ?></div>
						<?php if ( array() !== $dx_message['files'] ) : ?>
							<ul class="dxp-chat__files">
								<?php foreach ( $dx_message['files'] as $dx_file ) : ?>
									<li>
										<?php if ( '' !== $dx_file['url'] ) : ?>
											<a href="<?php echo esc_url( (string) $dx_file['url'] ); ?>" dir="auto"><?php echo esc_html( (string) $dx_file['name'] ); ?></a>
										<?php else : ?>
											<span dir="auto"><?php echo esc_html( (string) $dx_file['name'] ); ?></span>
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

	<?php if ( $view['can_write'] && ! $view['is_manager'] ) : ?>
		<form class="dxp-form dxp-chat__form" method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>">
			<input type="hidden" name="dx_step" value="message">
			<?php wp_nonce_field( 'dx_portal_message_' . (int) $view['case_id'], '_dx_nonce', false ); ?>
			<p class="dxp-field">
				<label for="dxp-msg-body">
					<?php
					echo esc_html(
						'' !== $dx_manager
							/* translators: %s: project manager name. */
							? sprintf( __( 'הודעה ל%s', 'diromax-portal' ), $dx_manager )
							: __( 'הודעה למנהל הפרויקט', 'diromax-portal' )
					);
					?>
				</label>
				<textarea id="dxp-msg-body" name="body" rows="5" maxlength="<?php echo esc_attr( (string) MessageService::MAX_LENGTH ); ?>" required dir="auto" aria-describedby="dxp-msg-help"></textarea>
				<span class="dxp-hint" id="dxp-msg-help"><?php esc_html_e( 'מנהל הפרויקט יקבל את ההודעה במייל, והתשובה תגיע אליך במייל וגם לכאן. מסמכים מעלים בלשונית „הסכם ומסמכים”.', 'diromax-portal' ); ?></span>
			</p>
			<button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'שליחה', 'diromax-portal' ); ?></button>
		</form>
	<?php endif; ?>
</section>
