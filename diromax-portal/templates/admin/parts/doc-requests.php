<?php
/**
 * Admin part: documents asked of the client (spec 16.4), with the form to
 * ask for one. Part of the documents tab.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$dxa_ask      = new Form( $view['flash'], 'docrequest' );
$dxa_requests = (array) $view['requests'];
$dxa_labels   = array(
	'open'      => __( 'ממתין ללקוח', 'diromax-portal' ),
	'done'      => __( 'הלקוח העלה', 'diromax-portal' ),
	'cancelled' => __( 'בוטל', 'diromax-portal' ),
);
?>
<details class="dxa-box" id="form-docrequest"<?php echo $dxa_ask->failed() ? ' open' : ''; ?>>
	<summary><h2>
		<?php esc_html_e( 'בקשת מסמך מהלקוח', 'diromax-portal' ); ?>
		<?php $dxa_open = count( array_filter( $dxa_requests, static fn( array $r ): bool => 'open' === $r['status'] ) ); ?>
		<?php if ( $dxa_open > 0 ) : ?>
			<span class="dxa-count">
				<?php
				/* translators: %d: open requests. */
				echo esc_html( sprintf( _n( '%d פתוחה', '%d פתוחות', $dxa_open, 'diromax-portal' ), $dxa_open ) );
				?>
			</span>
		<?php endif; ?>
	</h2></summary>
	<p class="description"><?php esc_html_e( 'הלקוח מקבל מייל, והבקשה מופיעה אצלו באזור האישי עם כפתור העלאה. כשהוא מעלה, תקבל מייל והבקשה נסגרת.', 'diromax-portal' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dx_portal_request_document">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_request_document_' . $case->id ); ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $dxa_ask->id( 'title' ) ); ?>"><?php esc_html_e( 'איזה מסמך צריך', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $dxa_ask->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $dxa_ask->old( 'title' ) ); ?>" maxlength="200" required placeholder="<?php esc_attr_e( 'לדוגמה: נסח טאבו עדכני', 'diromax-portal' ); ?>">
			<?php $dxa_ask->print_error( 'title' ); ?>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $dxa_ask->id( 'note' ) ); ?>"><?php esc_html_e( 'הסבר ללקוח (רשות)', 'diromax-portal' ); ?></label>
			<textarea <?php echo $dxa_ask->attrs( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> rows="3" maxlength="500"><?php echo esc_textarea( $dxa_ask->old( 'note' ) ); ?></textarea>
			<?php $dxa_ask->print_error( 'note' ); ?>
		</p>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'שליחת הבקשה', 'diromax-portal' ); ?></button></p>
	</form>
	<?php if ( array() !== $dxa_requests ) : ?>
		<ul class="dxa-tasks">
			<?php foreach ( $dxa_requests as $dxa_request ) : ?>
				<li class="dxa-task<?php echo 'open' === $dxa_request['status'] ? '' : ' is-done'; ?>">
					<span class="dxa-task__t"><?php echo esc_html( $dxa_request['title'] ); ?></span>
					<span class="dxa-task__m">
						<?php echo esc_html( ( $dxa_labels[ $dxa_request['status'] ] ?? '' ) . ' · ' . Labels::datetime( $dxa_request['closed_at'] ?? $dxa_request['created_at'] ) ); ?>
					</span>
					<?php if ( 'open' === $dxa_request['status'] ) : ?>
						<span class="dxa-task__a">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="dx_portal_cancel_doc_request">
								<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
								<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $dxa_request['id'] ); ?>">
								<?php wp_nonce_field( 'dx_portal_cancel_doc_request_' . $case->id ); ?>
								<button type="submit" class="button-link button-link-delete">
									<?php esc_html_e( 'ביטול הבקשה', 'diromax-portal' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( ': ' . $dxa_request['title'] ); ?></span>
								</button>
							</form>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</details>
