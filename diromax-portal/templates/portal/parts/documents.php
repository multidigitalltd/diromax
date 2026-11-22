<?php
/**
 * Portal part: the agreement card, documents by kind, signing (spec 16.6),
 * documents the project manager asked for, and the client's own uploads
 * (spec 16.4).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$agreement = $view['agreement'];
$groups    = $view['documents'];
$signing   = (array) $view['signing'];
$requests  = (array) $view['requests'];
$me        = wp_get_current_user();
$terms     = array_filter(
	array(
		__( 'נחתם', 'diromax-portal' )      => $agreement['signed'],
		__( 'היקף', 'diromax-portal' )      => $agreement['scope'],
		__( 'מסלול', 'diromax-portal' )     => $agreement['track'],
		__( 'שכר ניהול', 'diromax-portal' ) => $agreement['fee'],
	)
);

/**
 * The signing state of a document, or its sign form.
 *
 * @param int    $document_id Document.
 * @param string $title       Document title.
 */
$sign_block = static function ( int $document_id, string $title ) use ( $signing, $view, $me ): void {
	$state = $signing[ $document_id ] ?? null;

	if ( null === $state ) {
		return;
	}

	if ( '' !== $state['signed'] ) {
		/* translators: %s: date and time. */
		printf( '<p class="dxp-ok">%s</p>', esc_html( sprintf( __( 'חתמת על המסמך: %s', 'diromax-portal' ), $state['signed'] ) ) );
		return;
	}

	if ( ! $state['can_sign'] ) {
		return;
	}

	$pad = array(
		'id'          => 'dxp-sign-' . $document_id,
		'name_field'  => 'sign_name',
		'image_field' => 'sign_image',
		'name'        => (string) $me->display_name,
		'errors'      => array(),
	);
	?>
	<details class="dxp-sign" id="sign-<?php echo esc_attr( (string) $document_id ); ?>">
		<summary class="dxp-btn dxp-btn--ink">
			<?php esc_html_e( 'חתימה על המסמך', 'diromax-portal' ); ?>
			<span class="dxp-sr"><?php echo esc_html( ': ' . $title ); ?></span>
		</summary>
		<form method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>" class="dxp-sign__form" data-dxp-sign>
			<input type="hidden" name="dx_step" value="sign">
			<input type="hidden" name="document" value="<?php echo esc_attr( (string) $document_id ); ?>">
			<?php wp_nonce_field( 'dx_portal_sign_doc_' . $document_id, '_dx_nonce', false ); ?>
			<p class="dxp-dec__note"><?php esc_html_e( 'קודם קוראים את המסמך (הקישור למעלה). החתימה נשמרת עם התאריך, השעה וטביעת אצבע של הקובץ, ומנהל הפרויקט מקבל הודעה.', 'diromax-portal' ); ?></p>
			<?php require __DIR__ . '/signature-pad.php'; ?>
			<p class="dxp-check">
				<input type="checkbox" id="<?php echo esc_attr( 'dxp-sign-ok-' . $document_id ); ?>" name="confirm" value="1" required>
				<label for="<?php echo esc_attr( 'dxp-sign-ok-' . $document_id ); ?>"><?php esc_html_e( 'קראתי את המסמך ואני חותם/ת עליו', 'diromax-portal' ); ?></label>
			</p>
			<p><button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'חתימה', 'diromax-portal' ); ?></button></p>
		</form>
	</details>
	<?php
};
?>
<section class="dxp-panel" aria-labelledby="dxp-docs-title">
	<h2 id="dxp-docs-title" class="dxp-sr"><?php esc_html_e( 'הסכם ומסמכים', 'diromax-portal' ); ?></h2>

	<?php if ( array() !== $requests && $view['can_upload'] ) : ?>
		<div class="dxp-asks" id="requests">
			<h3><?php esc_html_e( 'מסמכים שביקשנו ממך', 'diromax-portal' ); ?></h3>
			<ul>
				<?php foreach ( $requests as $request ) : ?>
					<li class="dxp-ask">
						<p class="dxp-ask__t"><?php echo esc_html( (string) $request['title'] ); ?></p>
						<?php if ( '' !== $request['note'] ) : ?>
							<p class="dxp-ask__n"><?php echo esc_html( (string) $request['note'] ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>" enctype="multipart/form-data" class="dxp-upload">
							<input type="hidden" name="dx_step" value="upload">
							<input type="hidden" name="request" value="<?php echo esc_attr( (string) $request['id'] ); ?>">
							<?php wp_nonce_field( 'dx_portal_upload_' . $view['case_id'], '_dx_nonce', false ); ?>
							<p class="dxp-field">
								<label for="<?php echo esc_attr( 'dxp-ask-file-' . $request['id'] ); ?>"><?php esc_html_e( 'הקובץ (PDF, תמונה, Word או Excel, עד 20MB)', 'diromax-portal' ); ?></label>
								<input type="file" id="<?php echo esc_attr( 'dxp-ask-file-' . $request['id'] ); ?>" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" required>
							</p>
							<p><button type="submit" class="dxp-btn dxp-btn--orange">
								<?php esc_html_e( 'העלאה', 'diromax-portal' ); ?>
								<span class="dxp-sr"><?php echo esc_html( ': ' . $request['title'] ); ?></span>
							</button></p>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="dxp-split dxp-split--top">
		<div class="dxp-agr">
			<div class="dxp-agr__h">
				<h3><?php esc_html_e( 'הסכם ניהול השבחה', 'diromax-portal' ); ?></h3>
				<?php if ( '' !== $agreement['url'] ) : ?>
					<a class="dxp-btn dxp-btn--orange" href="<?php echo esc_url( (string) $agreement['url'] ); ?>"><?php esc_html_e( 'הורדת ההסכם', 'diromax-portal' ); ?> <small>(<?php echo esc_html( (string) $agreement['size'] ); ?>)</small></a>
				<?php endif; ?>
			</div>
			<?php if ( array() === $terms ) : ?>
				<p class="dxp-agr__empty"><?php esc_html_e( 'פרטי ההסכם יופיעו כאן אחרי החתימה.', 'diromax-portal' ); ?></p>
			<?php else : ?>
				<dl>
					<?php foreach ( $terms as $label => $value ) : ?>
						<dt><?php echo esc_html( (string) $label ); ?></dt>
						<dd><?php echo esc_html( (string) $value ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php if ( 0 !== (int) $agreement['id'] ) : ?>
				<?php $sign_block( (int) $agreement['id'], __( 'הסכם ניהול השבחה', 'diromax-portal' ) ); ?>
			<?php endif; ?>
			<?php if ( '' !== $agreement['confirmed'] && '' === ( $signing[ (int) $agreement['id'] ]['signed'] ?? '' ) ) : ?>
				<p class="dxp-dec__note">
					<?php
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'אישרת קריאה של ההסכם: %s', 'diromax-portal' ), $agreement['confirmed'] ) );
					?>
				</p>
			<?php endif; ?>
		</div>

		<div class="dxp-docs">
			<?php if ( array() === $groups ) : ?>
				<p class="dxp-lead"><?php esc_html_e( 'עדיין אין מסמכים בתיק.', 'diromax-portal' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $groups as $label => $documents ) : ?>
				<h3><?php echo esc_html( (string) $label ); ?></h3>
				<ul>
					<?php foreach ( $documents as $document ) : ?>
						<li>
							<a href="<?php echo esc_url( (string) $document['url'] ); ?>"><?php echo esc_html( (string) $document['title'] ); ?></a>
							<small><?php echo esc_html( (string) $document['meta'] ); ?> · <span class="dxp-num"><?php echo esc_html( (string) $document['date'] ); ?></span></small>
							<?php if ( (int) $document['id'] !== (int) $agreement['id'] ) : ?>
								<?php $sign_block( (int) $document['id'], (string) $document['title'] ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>

			<?php if ( $view['can_upload'] ) : ?>
				<details class="dxp-sign" id="upload">
					<summary class="dxp-btn dxp-btn--line"><?php esc_html_e( 'העלאת מסמך לתיק', 'diromax-portal' ); ?></summary>
					<form method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>" enctype="multipart/form-data" class="dxp-upload">
						<input type="hidden" name="dx_step" value="upload">
						<?php wp_nonce_field( 'dx_portal_upload_' . $view['case_id'], '_dx_nonce', false ); ?>
						<p class="dxp-field">
							<label for="dxp-upload-title"><?php esc_html_e( 'מה המסמך', 'diromax-portal' ); ?></label>
							<input type="text" id="dxp-upload-title" name="title" maxlength="200" required>
						</p>
						<p class="dxp-field">
							<label for="dxp-upload-file"><?php esc_html_e( 'הקובץ (PDF, תמונה, Word או Excel, עד 20MB)', 'diromax-portal' ); ?></label>
							<input type="file" id="dxp-upload-file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" required>
						</p>
						<p class="dxp-dec__note"><?php esc_html_e( 'הקובץ נשמר מוצפן, ומנהל הפרויקט מקבל הודעה.', 'diromax-portal' ); ?></p>
						<p><button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'העלאה', 'diromax-portal' ); ?></button></p>
					</form>
				</details>
			<?php endif; ?>
		</div>
	</div>
</section>
