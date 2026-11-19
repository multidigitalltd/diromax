<?php
/**
 * Admin: one case — head, stages, tabs, active tab.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/** Case being viewed. @var \Diromax\Portal\Data\CaseRecord $case */
$case    = $view['case'];
$manager = $view['manager'];
$meta    = array();

if ( $manager instanceof WP_User ) {
	/* translators: %s: manager name. */
	$meta[] = sprintf( __( 'מנהל פרויקט: %s', 'diromax-portal' ), $manager->display_name );
}
foreach ( array( $case->property_type, $case->scope ) as $detail ) {
	if ( '' !== $detail ) {
		$meta[] = $detail;
	}
}

$header     = array(
	'current' => 'cases',
	'back'    => array( __( 'כל התיקים', 'diromax-portal' ), add_query_arg( 'page', Admin::PAGE_CASES, admin_url( 'admin.php' ) ) ),
	'eyebrow' => $case->number . ' · ' . ( $view['statuses'][ $case->status->value ] ?? '' ),
	'title'   => $case->title,
	'meta'    => $meta,
	'actions' => array(
		array(
			'label'   => __( 'צפייה כלקוח', 'diromax-portal' ),
			'url'     => (string) $view['portal'],
			'new_tab' => true,
		),
	),
);
$stages     = $view['steps'];
$stages_url = Admin::case_url( $case->id, 'details' ) . '#dxa-stages';
?>
<div class="wrap dxa">
	<?php require __DIR__ . '/parts/header.php'; ?>
	<?php require __DIR__ . '/parts/flash.php'; ?>
	<?php require __DIR__ . '/parts/stepper.php'; ?>

	<nav class="nav-tab-wrapper dxa-tabs" aria-label="<?php esc_attr_e( 'לשוניות התיק', 'diromax-portal' ); ?>">
		<?php foreach ( $view['tabs'] as $key => $label ) : ?>
			<?php $count = (int) ( $view['counts'][ $key ] ?? 0 ); ?>
			<a href="<?php echo esc_url( Admin::case_url( $case->id, $key ) ); ?>" class="nav-tab<?php echo $key === $view['tab'] ? ' nav-tab-active' : ''; ?>"<?php echo $key === $view['tab'] ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
				<?php if ( $count > 0 ) : ?>
					<span class="dxa-tabs__n"><?php echo esc_html( (string) $count ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="dxa-panel">
		<?php require __DIR__ . '/tabs/' . $view['tab'] . '.php'; ?>
	</div>
</div>
