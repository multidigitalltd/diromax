<?php
/**
 * Portal part: agreement card and documents by kind.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$agreement = $view['agreement'];
$groups    = $view['documents'];
$terms     = array_filter(
	array(
		__( 'נחתם', 'diromax-portal' )      => $agreement['signed'],
		__( 'היקף', 'diromax-portal' )      => $agreement['scope'],
		__( 'מסלול', 'diromax-portal' )     => $agreement['track'],
		__( 'שכר ניהול', 'diromax-portal' ) => $agreement['fee'],
	)
);
?>
<section class="dxp-panel" aria-labelledby="dxp-docs-title">
	<h2 id="dxp-docs-title" class="dxp-sr"><?php esc_html_e( 'הסכם ומסמכים', 'diromax-portal' ); ?></h2>
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
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</div>
	</div>
</section>
