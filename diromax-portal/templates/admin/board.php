<?php
/**
 * Admin: the stage board — the active cases under each stage in progress.
 *
 * Shared by wp-admin and the management app on the site.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Service\Stages;

defined( 'ABSPATH' ) || exit;

$header = array(
	'current' => 'board',
	'eyebrow' => __( 'תיקי לקוחות', 'diromax-portal' ),
	'title'   => __( 'לוח שלבים', 'diromax-portal' ),
	'meta'    => array(
		/* translators: %d: number of active cases. */
		sprintf( _n( '%d תיק פעיל', '%d תיקים פעילים', (int) $view['total'], 'diromax-portal' ), (int) $view['total'] ),
	),
	'actions' => array(
		array(
			'label'   => __( '+ תיק חדש', 'diromax-portal' ),
			'url'     => Admin::page_url( Admin::PAGE_NEW ),
			'primary' => true,
		),
	),
);
?>
<div class="wrap dxa">
	<?php require __DIR__ . '/parts/header.php'; ?>

	<p class="dxa-board__intro"><?php esc_html_e( 'כל תיק פעיל מופיע בשלב שהוא נמצא בו. תיק שמתקדם בכמה שלבים במקביל מופיע בכל אחד מהם. בראש כל שלב: התיקים שנמצאים בו הכי הרבה זמן.', 'diromax-portal' ); ?></p>
	<?php if ( $view['capped'] ) : ?>
		<p class="dxa-muted"><?php esc_html_e( 'מוצגים 500 התיקים שעודכנו לאחרונה.', 'diromax-portal' ); ?></p>
	<?php endif; ?>

	<div class="dxa-board">
		<?php foreach ( $view['columns'] as $dxa_number => $dxa_column ) : ?>
			<?php $dxa_count = count( $dxa_column['cards'] ); ?>
			<section class="dxa-board__col<?php echo $dxa_count > 0 ? ' has-cases' : ''; ?>" aria-labelledby="dxa-board-<?php echo esc_attr( (string) $dxa_number ); ?>">
				<h2 class="dxa-board__h" id="dxa-board-<?php echo esc_attr( (string) $dxa_number ); ?>">
					<span class="dxa-board__n"><?php echo esc_html( $dxa_column['number'] ); ?></span>
					<span class="dxa-board__t"><?php echo esc_html( $dxa_column['name'] ); ?></span>
					<span class="dxa-board__count">
						<?php
						/* translators: %d: number of cases. */
						echo esc_html( sprintf( _n( '%d תיק', '%d תיקים', $dxa_count, 'diromax-portal' ), $dxa_count ) );
						?>
					</span>
				</h2>
				<?php if ( 0 === $dxa_count ) : ?>
					<p class="dxa-board__empty"><?php esc_html_e( 'אין תיקים בשלב הזה.', 'diromax-portal' ); ?></p>
				<?php else : ?>
					<ul class="dxa-board__list">
						<?php foreach ( $dxa_column['cards'] as $dxa_card ) : ?>
							<li class="dxa-board__card">
								<a href="<?php echo esc_url( Admin::case_url( $dxa_card['case']->id ) ); ?>"><?php echo esc_html( $dxa_card['case']->title ); ?></a>
								<span class="dxa-board__meta">
									<span class="dxa-num"><?php echo esc_html( $dxa_card['case']->number ); ?></span>
									<?php if ( null !== $dxa_card['days'] ) : ?>
										·
										<?php
										echo esc_html(
											0 === $dxa_card['days']
												? __( 'התחיל היום', 'diromax-portal' )
												/* translators: %d: days in this stage. */
												: sprintf( _n( '%d יום בשלב', '%d ימים בשלב', $dxa_card['days'], 'diromax-portal' ), $dxa_card['days'] )
										);
										?>
									<?php endif; ?>
								</span>
								<?php if ( '' !== $dxa_card['also'] ) : ?>
									<span class="dxa-board__also">
										<?php
										/* translators: %s: other stage numbers, e.g. "04". */
										echo esc_html( sprintf( __( 'במקביל גם בשלב %s', 'diromax-portal' ), $dxa_card['also'] ) );
										?>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
	</div>

	<?php if ( array() !== $view['waiting'] ) : ?>
		<section class="dxa-board__waiting" aria-labelledby="dxa-board-waiting">
			<h2 id="dxa-board-waiting"><?php esc_html_e( 'תיקים פעילים בלי שלב בתהליך', 'diromax-portal' ); ?></h2>
			<ul class="dxa-list">
				<?php foreach ( $view['waiting'] as $dxa_case ) : ?>
					<li>
						<a href="<?php echo esc_url( Admin::case_url( $dxa_case->id, 'details' ) ); ?>"><?php echo esc_html( $dxa_case->title ); ?></a>
						<span class="dxa-muted">· <?php echo esc_html( $dxa_case->number . ' · ' . Stages::summary( $dxa_case->stages ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
