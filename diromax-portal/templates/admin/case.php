<?php
/**
 * Admin: one case — header, tabs, active tab.
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
?>
<div class="wrap dxa">
	<p class="dxa-back"><a href="<?php echo esc_url( add_query_arg( 'page', Admin::PAGE_CASES, admin_url( 'admin.php' ) ) ); ?>">&rarr; <?php esc_html_e( 'כל התיקים', 'diromax-portal' ); ?></a></p>
	<h1 class="wp-heading-inline"><?php echo esc_html( $case->title ); ?></h1>
	<a href="<?php echo esc_url( (string) $view['portal'] ); ?>" class="page-title-action" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'צפייה כלקוח', 'diromax-portal' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(נפתח בחלון חדש)', 'diromax-portal' ); ?></span></a>
	<p class="dxa-meta">
		<span><?php echo esc_html( $case->number ); ?></span>
		<span><?php echo esc_html( (string) ( $view['statuses'][ $case->status->value ] ?? '' ) ); ?></span>
		<?php if ( $manager instanceof WP_User ) : ?>
			<span>
				<?php
				/* translators: %s: manager name. */
				echo esc_html( sprintf( __( 'מנהל פרויקט: %s', 'diromax-portal' ), $manager->display_name ) );
				?>
			</span>
		<?php endif; ?>
	</p>
	<hr class="wp-header-end">
	<?php require __DIR__ . '/parts/flash.php'; ?>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'לשוניות התיק', 'diromax-portal' ); ?>">
		<?php foreach ( $view['tabs'] as $key => $label ) : ?>
			<a href="<?php echo esc_url( Admin::case_url( $case->id, $key ) ); ?>" class="nav-tab<?php echo $key === $view['tab'] ? ' nav-tab-active' : ''; ?>"<?php echo $key === $view['tab'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="dxa-panel">
		<?php require __DIR__ . '/tabs/' . $view['tab'] . '.php'; ?>
	</div>
</div>
