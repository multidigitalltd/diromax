<?php
/**
 * Portal part: quotes to compare and choose from (spec 24.4).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$comparisons = (array) $view['quotes'];

/**
 * What a quote says: supplier, price, tags, duration, note.
 *
 * @param array<string, mixed> $offer  Quote.
 * @param bool                 $chosen It is the client's choice.
 */
$dx_offer_body = static function ( array $offer, bool $chosen ): void {
	?>
	<span class="dxp-offer__head">
		<b><?php echo esc_html( (string) $offer['supplier'] ); ?></b>
		<span class="dxp-num"><?php echo esc_html( (string) $offer['amount'] ); ?></span>
	</span>
	<span class="dxp-offer__tags">
		<?php if ( $offer['recommended'] ) : ?>
			<span class="dxp-offer__tag dxp-offer__tag--rec"><?php esc_html_e( 'ההמלצה של מנהל הפרויקט', 'diromax-portal' ); ?></span>
		<?php endif; ?>
		<?php if ( $offer['lowest'] ) : ?>
			<span class="dxp-offer__tag"><?php esc_html_e( 'הזולה ביותר', 'diromax-portal' ); ?></span>
		<?php endif; ?>
		<?php if ( $chosen ) : ?>
			<span class="dxp-offer__tag dxp-offer__tag--chosen"><?php esc_html_e( 'נבחרה', 'diromax-portal' ); ?></span>
		<?php endif; ?>
	</span>
	<?php if ( '' !== $offer['duration'] ) : ?>
		<span class="dxp-offer__line">
			<?php
			/* translators: %s: duration. */
			echo esc_html( sprintf( __( 'משך: %s', 'diromax-portal' ), $offer['duration'] ) );
			?>
		</span>
	<?php endif; ?>
	<?php if ( '' !== $offer['note'] ) : ?>
		<span class="dxp-offer__line"><?php echo esc_html( (string) $offer['note'] ); ?></span>
	<?php endif; ?>
	<?php
};

/**
 * The link to the supplier's own quote, if there is one.
 *
 * @param array<string, mixed> $offer Quote.
 */
$dx_offer_doc = static function ( array $offer ): void {
	if ( '' === $offer['document'] ) {
		return;
	}
	?>
	<a class="dxp-offer__doc" href="<?php echo esc_url( (string) $offer['document'] ); ?>">
		<?php esc_html_e( 'ההצעה המקורית', 'diromax-portal' ); ?>
		<span class="dxp-sr"><?php echo esc_html( ': ' . $offer['supplier'] ); ?></span>
	</a>
	<?php
};
?>
<section class="dxp-panel" aria-labelledby="dxp-quotes-title">
	<h2 id="dxp-quotes-title"><?php esc_html_e( 'הצעות מחיר', 'diromax-portal' ); ?></h2>
	<p class="dxp-lead"><?php esc_html_e( 'ההצעות שקיבלנו לכל עבודה, זו לצד זו. בוחרים הצעה אחת, ומנהל הפרויקט מקבל את הבחירה מיד.', 'diromax-portal' ); ?></p>
	<?php foreach ( $comparisons as $comparison ) : ?>
		<?php
		$field    = 'dxp-quote-' . (int) $comparison['id'];
		$choosing = 'open' === $comparison['status'] && $view['can_answer'] && ! $view['case']['closed'];
		?>
		<article class="dxp-quote" aria-labelledby="<?php echo esc_attr( $field ); ?>-title">
			<h3 id="<?php echo esc_attr( $field ); ?>-title"><?php echo esc_html( (string) $comparison['title'] ); ?></h3>
			<p class="dxp-quote__meta">
				<?php
				echo esc_html(
					implode(
						' · ',
						array_filter(
							array(
								(string) $comparison['line'],
								'open' === $comparison['status'] && '' !== $comparison['due_label'] ? sprintf( /* translators: %s: date. */ __( 'לבחור עד %s', 'diromax-portal' ), $comparison['due_label'] ) : '',
								'chosen' === $comparison['status'] ? sprintf( /* translators: 1: who, 2: when. */ __( 'נבחרה על ידי %1$s, %2$s', 'diromax-portal' ), $comparison['chosen_by'], $comparison['chosen_date'] ) : '',
							)
						)
					)
				);
				?>
			</p>
			<?php if ( '' !== $comparison['note'] ) : ?>
				<p><?php echo nl2br( esc_html( (string) $comparison['note'] ) ); ?></p>
			<?php endif; ?>

			<?php if ( $choosing ) : ?>
				<form class="dxp-quote__form" method="post" action="<?php echo esc_url( (string) $view['self'] ); ?>">
					<input type="hidden" name="dx_step" value="choose">
					<input type="hidden" name="comparison" value="<?php echo esc_attr( (string) $comparison['id'] ); ?>">
					<?php wp_nonce_field( 'dx_portal_choose_' . (int) $comparison['id'], '_dx_nonce', false ); ?>
					<fieldset class="dxp-offers">
						<legend class="dxp-sr"><?php esc_html_e( 'בוחרים הצעה אחת', 'diromax-portal' ); ?></legend>
						<?php foreach ( (array) $comparison['offers'] as $offer ) : ?>
							<?php $offer_id = $field . '-' . (int) $offer['id']; ?>
							<div class="dxp-offer<?php echo $offer['recommended'] ? ' dxp-offer--recommended' : ''; ?>">
								<input type="radio" class="dxp-offer__pick" id="<?php echo esc_attr( $offer_id ); ?>" name="offer" value="<?php echo esc_attr( (string) $offer['id'] ); ?>" required>
								<label for="<?php echo esc_attr( $offer_id ); ?>" class="dxp-offer__body"><?php $dx_offer_body( $offer, false ); ?></label>
								<?php $dx_offer_doc( $offer ); ?>
							</div>
						<?php endforeach; ?>
					</fieldset>
					<p class="dxp-field">
						<label for="<?php echo esc_attr( $field ); ?>-note"><?php esc_html_e( 'הערה למנהל הפרויקט (רשות)', 'diromax-portal' ); ?></label>
						<textarea id="<?php echo esc_attr( $field ); ?>-note" name="note" rows="2" maxlength="500"></textarea>
					</p>
					<p class="dxp-check">
						<input type="checkbox" id="<?php echo esc_attr( $field ); ?>-ok" name="confirm" value="1" required>
						<label for="<?php echo esc_attr( $field ); ?>-ok"><?php esc_html_e( 'בדקתי את ההצעות, ואני בוחר/ת בהצעה שסימנתי.', 'diromax-portal' ); ?></label>
					</p>
					<p><button type="submit" class="dxp-btn dxp-btn--orange"><?php esc_html_e( 'בחירת ההצעה', 'diromax-portal' ); ?></button></p>
				</form>
			<?php else : ?>
				<ul class="dxp-offers">
					<?php foreach ( (array) $comparison['offers'] as $offer ) : ?>
						<?php $chosen = (int) $offer['id'] === (int) $comparison['chosen']; ?>
						<li class="dxp-offer<?php echo $chosen ? ' dxp-offer--chosen' : ''; ?><?php echo $offer['recommended'] ? ' dxp-offer--recommended' : ''; ?>">
							<div class="dxp-offer__body"><?php $dx_offer_body( $offer, $chosen ); ?></div>
							<?php $dx_offer_doc( $offer ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( 'open' === $comparison['status'] ) : ?>
					<p class="dxp-quote__meta"><?php esc_html_e( 'הבחירה בהצעה שמורה לבעלי התיק ולשותפים.', 'diromax-portal' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
