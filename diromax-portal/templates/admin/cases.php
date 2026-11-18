<?php
/**
 * Admin: cases list.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>               $view
 * @var \Diromax\Portal\Admin\CasesTable $table
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap dxa">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'תיקי לקוחות', 'diromax-portal' ); ?></h1>
	<a href="<?php echo esc_url( add_query_arg( 'page', Admin::PAGE_NEW, admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'תיק חדש', 'diromax-portal' ); ?></a>
	<hr class="wp-header-end">
	<?php require __DIR__ . '/parts/flash.php'; ?>
	<?php $table->views(); ?>
	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_CASES ); ?>">
		<?php $table->search_box( __( 'חיפוש לפי כתובת או מספר תיק', 'diromax-portal' ), 'dxa-search' ); ?>
		<?php $table->display(); ?>
	</form>
</div>
