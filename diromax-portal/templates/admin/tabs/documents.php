<?php
/**
 * Admin tab: agreement and documents.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $view
 * @var \Diromax\Portal\Data\CaseRecord $case
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\Form;
use Diromax\Portal\Service\SignatureService;
use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$form = new Form( $view['flash'], 'document' );
?>
<?php if ( ! $view['key_ready'] ) : ?>
	<div class="notice notice-error inline"><p><?php esc_html_e( 'העלאת מסמכים חסומה עד שיוגדר מפתח ההצפנה DX_PORTAL_KEY.', 'diromax-portal' ); ?></p></div>
<?php else : ?>
<details class="dxa-box dxa-box--add" id="form-document"<?php echo array() === $view['documents'] || $form->failed() || ! empty( $view['adding'] ) ? ' open' : ''; ?><?php echo ! empty( $view['adding'] ) ? ' data-dxa-focus' : ''; ?>>
	<summary><h2><?php esc_html_e( 'העלאת מסמך', 'diromax-portal' ); ?></h2></summary>
	<p class="description"><?php esc_html_e( 'הקובץ נשמר מוצפן בשרת ונפתח רק דרך האזור האישי. ההסכם החתום מועלה כאן בסוג "הסכם", ותנאיו נכתבים בלשונית פרטי התיק.', 'diromax-portal' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="dx_portal_upload_document">
		<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
		<?php wp_nonce_field( 'dx_portal_upload_document_' . $case->id ); ?>
		<p class="dxa-field">
			<label for="<?php echo esc_attr( $form->id( 'kind' ) ); ?>"><?php esc_html_e( 'סוג המסמך', 'diromax-portal' ); ?></label>
			<select <?php echo $form->attrs( 'kind' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?>>
				<?php foreach ( array_diff_key( $view['kinds'], array( 'client' => '' ) ) as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $form->old( 'kind', 'agreement' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="<?php echo esc_attr( $form->id( 'title' ) ); ?>"><?php esc_html_e( 'כותרת (רשות; ברירת מחדל: שם הקובץ)', 'diromax-portal' ); ?></label>
			<input type="text" <?php echo $form->attrs( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form::attrs(). ?> value="<?php echo esc_attr( $form->old( 'title' ) ); ?>" maxlength="200">
		</p>
		<p class="dxa-field dxa-field--wide">
			<label for="dxa-document-file"><?php esc_html_e( 'קובץ: PDF, JPG, PNG, WEBP, DOCX או XLSX, עד 20MB', 'diromax-portal' ); ?></label>
			<input type="file" id="dxa-document-file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" required<?php echo '' !== $form->error( 'file' ) ? ' aria-invalid="true" aria-describedby="dxa-document-file-error"' : ''; ?>>
			<?php if ( '' !== $form->error( 'file' ) ) : ?>
				<p class="dxa-error" id="dxa-document-file-error"><?php echo esc_html( $form->error( 'file' ) ); ?></p>
			<?php endif; ?>
		</p>
		<fieldset class="dxa-field dxa-field--wide dxa-checks">
			<legend class="screen-reader-text"><?php esc_html_e( 'נראות ועדכון הלקוח', 'diromax-portal' ); ?></legend>
			<span><input type="checkbox" id="dxa-document-visible" name="visible" value="1" checked> <label for="dxa-document-visible"><?php esc_html_e( 'גלוי ללקוח', 'diromax-portal' ); ?></label></span>
			<span><input type="checkbox" id="dxa-document-notify" name="notify" value="1" checked> <label for="dxa-document-notify"><?php esc_html_e( 'לעדכן את הלקוח במייל', 'diromax-portal' ); ?></label></span>
			<span><input type="checkbox" id="dxa-document-sign" name="sign_required" value="1"<?php checked( '1', $form->old( 'sign_required' ) ); ?>> <label for="dxa-document-sign"><?php esc_html_e( 'לחתימת הלקוח (הסכם תמיד נחתם)', 'diromax-portal' ); ?></label></span>
		</fieldset>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'העלאה', 'diromax-portal' ); ?></button></p>
	</form>
</details>
<?php endif; ?>

<?php require __DIR__ . '/../parts/doc-requests.php'; ?>

<h2><?php esc_html_e( 'מסמכי התיק', 'diromax-portal' ); ?></h2>
<?php if ( array() === $view['documents'] ) : ?>
	<p><?php esc_html_e( 'עדיין אין מסמכים.', 'diromax-portal' ); ?></p>
<?php else : ?>
<table class="widefat striped dxa-table">
	<caption class="screen-reader-text"><?php esc_html_e( 'מסמכי התיק', 'diromax-portal' ); ?></caption>
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'מסמך', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'סוג', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'הועלה', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'ללקוח', 'diromax-portal' ); ?></th>
		<th scope="col"><?php esc_html_e( 'פעולות', 'diromax-portal' ); ?></th>
	</tr></thead>
	<tbody>
		<?php foreach ( $view['documents'] as $document ) : ?>
			<tr>
				<td>
					<a href="
					<?php
					echo esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'action'      => 'dx_portal_download',
									'document_id' => $document->id,
								),
								admin_url( 'admin-post.php' )
							),
							'dx_portal_download_' . $document->id
						)
					);
					?>
								"><?php echo esc_html( $document->title ); ?></a>
					<br><span class="dxa-muted" dir="ltr"><?php echo esc_html( $document->original_name . ' · ' . size_format( $document->size, 1 ) ); ?></span>
				</td>
				<td>
					<?php echo esc_html( $view['kinds'][ $document->kind ] ?? '' ); ?>
					<?php $signed = $view['signed'][ $document->id ] ?? array(); ?>
					<?php if ( array() !== $signed ) : ?>
						<br><span class="dxa-signed">
							<?php
							echo esc_html(
								implode(
									' · ',
									array_map(
										/* translators: 1: name, 2: date and time. */
										static fn( array $sig ): string => sprintf( __( 'נחתם על ידי %1$s, %2$s', 'diromax-portal' ), $sig['signer_name'], Labels::datetime( $sig['signed_at'] ) ),
										$signed
									)
								)
							);
							?>
						</span>
						<br><a href="<?php echo esc_url( Admin::certificate_url( $case->id, 'document', $document->id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'אישור חתימה', 'diromax-portal' ); ?><span class="screen-reader-text"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax-portal' ); ?></span></a>
					<?php elseif ( SignatureService::is_signable( $document ) ) : ?>
						<br><span class="dxa-muted"><?php esc_html_e( 'ממתין לחתימת הלקוח', 'diromax-portal' ); ?></span>
					<?php endif; ?>
					<?php if ( 'client' === $document->kind ) : ?>
						<br><span class="dxa-muted"><?php echo esc_html( sprintf( /* translators: %s: name. */ __( 'הועלה על ידי %s', 'diromax-portal' ), (string) ( get_userdata( $document->uploaded_by )->display_name ?? '' ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( 'agreement' === $document->kind && $document->visible && array() === $signed ) : ?>
						<?php $confirmed = $view['acks'][ $document->id ] ?? array(); ?>
						<br><span class="dxa-muted">
							<?php if ( array() === $confirmed ) : ?>
								<?php esc_html_e( 'אין אישור קריאה מהגרסה הקודמת', 'diromax-portal' ); ?>
							<?php else : ?>
								<?php
								echo esc_html(
									implode(
										' · ',
										array_map(
											/* translators: 1: name, 2: date and time. */
											static fn( array $ack ): string => sprintf( __( 'אושר על ידי %1$s, %2$s', 'diromax-portal' ), $ack['name'], Labels::datetime( $ack['created_at'] ) ),
											$confirmed
										)
									)
								);
								?>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( Labels::datetime( $document->created_at ) ); ?></td>
				<td><?php echo $document->visible ? esc_html__( 'גלוי', 'diromax-portal' ) : '<span class="dxa-muted">' . esc_html__( 'מוסתר', 'diromax-portal' ) . '</span>'; ?></td>
				<td class="dxa-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dx_portal_document_visibility">
						<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
						<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $document->id ); ?>">
						<input type="hidden" name="visible" value="<?php echo $document->visible ? '' : '1'; ?>">
						<?php wp_nonce_field( 'dx_portal_document_visibility_' . $case->id ); ?>
						<button type="submit" class="button-link"><?php echo $document->visible ? esc_html__( 'הסתרה מהלקוח', 'diromax-portal' ) : esc_html__( 'הצגה ללקוח', 'diromax-portal' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dx_portal_delete_document">
						<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case->id ); ?>">
						<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $document->id ); ?>">
						<?php wp_nonce_field( 'dx_portal_delete_document_' . $case->id ); ?>
						<button type="submit" class="button-link button-link-delete" data-dxa-confirm="<?php esc_attr_e( 'למחוק את המסמך? אי אפשר לשחזר.', 'diromax-portal' ); ?>"><?php esc_html_e( 'מחיקה', 'diromax-portal' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/../parts/sign-requests.php'; ?>
