<?php
/**
 * Management app: the cases list, with status filter, search and paging.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Admin\CaseCells;
use Diromax\Portal\Portal\Router;

defined( 'ABSPATH' ) || exit;

$list_url = static function ( array $args ) use ( $view ): string {
	$query = array_filter(
		array_merge(
			array(
				'status' => (string) $view['status'],
				's'      => (string) $view['search'],
			),
			$args
		),
		static fn( $value ): bool => '' !== (string) $value && 1 !== $value
	);

	return add_query_arg( $query, Router::manage_url( 'cases' ) );
};

$header = array(
	'current' => 'cases',
	'eyebrow' => __( 'תיקי לקוחות', 'diromax-portal' ),
	'title'   => __( 'כל התיקים', 'diromax-portal' ),
	'meta'    => array(
		'' === $view['search']
			/* translators: %d: number of cases. */
			? sprintf( _n( 'תיק %d', '%d תיקים', (int) $view['total'], 'diromax-portal' ), (int) $view['total'] )
			/* translators: 1: number of cases, 2: search term. */
			: sprintf( _n( 'תיק %1$d עבור "%2$s"', '%1$d תיקים עבור "%2$s"', (int) $view['total'], 'diromax-portal' ), (int) $view['total'], $view['search'] ),
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
	<?php require DX_PORTAL_DIR . 'templates/admin/parts/header.php'; ?>
	<?php require DX_PORTAL_DIR . 'templates/admin/parts/flash.php'; ?>

	<nav aria-label="<?php esc_attr_e( 'סינון לפי סטטוס', 'diromax-portal' ); ?>">
		<ul class="dxa-filters">
			<?php foreach ( array( '' => __( 'הכל', 'diromax-portal' ) ) + $view['statuses'] as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( $list_url( array( 'status' => $key ) ) ); ?>"<?php echo (string) $key === $view['status'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $label ); ?></a></li>
			<?php endforeach; ?>
			<?php if ( '' !== $view['search'] ) : ?>
				<li><a href="<?php echo esc_url( $list_url( array( 's' => '' ) ) ); ?>"><?php esc_html_e( 'ניקוי החיפוש', 'diromax-portal' ); ?></a></li>
			<?php endif; ?>
		</ul>
	</nav>

	<?php if ( array() === $view['items'] ) : ?>
		<p class="dxa-allclear">
			<?php
			echo '' === $view['search'] && '' === $view['status']
				? esc_html__( 'עדיין אין תיקים. אפשר לפתוח את הראשון מ"תיק חדש", או ליצור תיק לדוגמה בלוח הבקרה.', 'diromax-portal' )
				: esc_html__( 'לא נמצאו תיקים. אפשר לחפש לפי חלק מהכתובת או לפי מספר התיק.', 'diromax-portal' );
			?>
		</p>
	<?php else : ?>
		<div class="dxa-scroll">
			<div class="dxa-scroll"><table class="widefat striped dxa-cases-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'תיק', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'שלב', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ניצול תקציב', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'לתשלום', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מנהל פרויקט', 'diromax-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סטטוס', 'diromax-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $view['items'] as $item ) : ?>
						<tr>
							<th scope="row">
								<a class="row-title" href="<?php echo esc_url( Admin::case_url( $item->id ) ); ?>"><?php echo esc_html( $item->title ); ?></a>
								<br><span class="dxa-muted"><?php echo esc_html( $item->number ); ?></span>
							</th>
							<td><?php echo CaseCells::stage( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in CaseCells. ?></td>
							<td><?php echo CaseCells::budget( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in CaseCells. ?></td>
							<td><?php echo CaseCells::requests( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in CaseCells. ?></td>
							<td><?php echo esc_html( $view['managers'][ $item->manager_id ] ?? '—' ); ?></td>
							<td><?php echo CaseCells::status( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in CaseCells. ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		</div>

		<?php if ( $view['pages'] > 1 ) : ?>
			<nav class="dxa-pager" aria-label="<?php esc_attr_e( 'עמודים', 'diromax-portal' ); ?>">
				<?php if ( $view['page'] > 1 ) : ?>
					<a class="dxa-btn" href="<?php echo esc_url( $list_url( array( 'paged' => $view['page'] - 1 ) ) ); ?>"><?php esc_html_e( 'הקודם', 'diromax-portal' ); ?></a>
				<?php endif; ?>
				<span>
					<?php
					/* translators: 1: page, 2: pages. */
					echo esc_html( sprintf( __( 'עמוד %1$d מתוך %2$d', 'diromax-portal' ), (int) $view['page'], (int) $view['pages'] ) );
					?>
				</span>
				<?php if ( $view['page'] < $view['pages'] ) : ?>
					<a class="dxa-btn" href="<?php echo esc_url( $list_url( array( 'paged' => $view['page'] + 1 ) ) ); ?>"><?php esc_html_e( 'הבא', 'diromax-portal' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
