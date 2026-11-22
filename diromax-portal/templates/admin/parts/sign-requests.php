<?php
/**
 * Admin part: send a document to be signed through an emailed link, by a
 * client or by anyone outside the system (spec 16.6), and the requests sent.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\SignatureService;

defined( 'ABSPATH' ) || exit;

$dxa_sign      = new Form( $view['flash'], 'signrequest' );
$dxa_documents = (array) $view['documents'];
$dxa_titles    = array();
$dxa_states    = array(
	'pending'   => __( 'ממתין לחתימה', 'diromax-portal' ),
	'signed'    => __( 'נחתם', 'diromax-portal' ),
	'cancelled' => __( 'בוטל', 'diromax-portal' ),
);

foreach ( $dxa_documents as $dxa_document ) {
	$dxa_titles[ $dxa_document->id ] = $dxa_document->title;
}
?>
<?php if ( array() !== $dxa_documents ) : ?>
<details class="dxa-box" id="form-signrequest"<?php echo $dxa_sign->failed() ? ' open' : ''; ?>>
	<summary><h2><?php esc_html_e( 'שליחת מסמך לחתימה במייל', 'diromax-portal' ); ?></h2></summary>
	<p class="description">
		<?php
		/* translators: %d: days. */
		echo esc_html( sprintf( __( 'לכל מי שצריך לחתום, גם מחוץ למערכת (למשל שכנים). החותם מקבל קישור אישי לאותו מסמך בלבד, תקף ל-%d יום. לפני החתימה הוא מקבל קוד למייל, וממלא שם מלא, תעודת זהות וציור חתימה. לקוחות שמחוברים לאזור האישי יכולים לחתום שם גם בלי קישור.', 'diromax-portal' ), SignatureService::LINK_DAYS ) );
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dx_portal_request_signature">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_request_signature_' . $case->id ); ?>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $dxa_sign->id( 'document_id' ) ); ?>"><?php esc_html_e( 'המסמך', 'diromax-portal' ); ?></label>
			<select <?php echo $dxa_sign->attrs( 'document_id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
				<?php foreach ( $dxa_documents as $dxa_document ) : ?>
					<option value="<?php echo esc_attr( (string) $dxa_document->id ); ?>"<?php selected( $dxa_sign->old( 'document_id' ), (string) $dxa_document->id ); ?>><?php echo esc_html( $dxa_document->title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $dxa_sign->id( 'signer_name' ) ); ?>"><?php esc_html_e( 'שם החותם', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $dxa_sign->attrs( 'signer_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $dxa_sign->old( 'signer_name' ) ); ?>" maxlength="100" required>
			<?php $dxa_sign->print_error( 'signer_name' ); ?>
		</p>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $dxa_sign->id( 'signer_email' ) ); ?>"><?php esc_html_e( 'המייל של החותם', 'diromax-portal' ); ?></label>
			<input type="email" dir="ltr" <?php echo $dxa_sign->attrs( 'signer_email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $dxa_sign->old( 'signer_email' ) ); ?>" maxlength="190" required>
			<?php $dxa_sign->print_error( 'signer_email' ); ?>
		</p>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'שליחה לחתימה', 'diromax-portal' ); ?></button></p>
	</form>

	<?php if ( array() !== $view['signing'] ) : ?>
		<ul class="dxa-tasks">
			<?php foreach ( $view['signing'] as $dxa_request ) : ?>
				<?php $dxa_expired = 'pending' === $dxa_request['status'] && $dxa_request['expires_at'] <= (string) $view['now']; ?>
				<li class="dxa-task<?php echo 'pending' === $dxa_request['status'] && ! $dxa_expired ? '' : ' is-done'; ?>">
					<span class="dxa-task__t"><?php echo esc_html( $dxa_request['signer_name'] . ' · ' . ( $dxa_titles[ $dxa_request['document_id'] ] ?? '' ) ); ?></span>
					<span class="dxa-task__m">
						<span dir="ltr"><?php echo esc_html( $dxa_request['signer_email'] ); ?></span>
						·
						<?php echo esc_html( $dxa_expired ? __( 'פג תוקף', 'diromax-portal' ) : ( $dxa_states[ $dxa_request['status'] ] ?? '' ) ); ?>
						·
						<?php echo esc_html( Labels::datetime( $dxa_request['closed_at'] ?? $dxa_request['created_at'] ) ); ?>
					</span>
					<?php if ( 'pending' === $dxa_request['status'] && ! $dxa_expired ) : ?>
						<span class="dxa-task__a">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="dx_portal_cancel_signature">
								<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
								<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $dxa_request['id'] ); ?>">
								<?php wp_nonce_field( 'dx_portal_cancel_signature_' . $case->id ); ?>
								<button type="submit" class="button-link button-link-delete">
									<?php esc_html_e( 'ביטול הבקשה', 'diromax-portal' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( ': ' . $dxa_request['signer_name'] ); ?></span>
								</button>
							</form>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</details>
<?php endif; ?>
