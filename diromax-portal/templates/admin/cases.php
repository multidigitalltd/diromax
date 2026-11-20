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
<?php
$header = array(
	'current' => 'cases',
	'eyebrow' => __( 'תיקי לקוחות', 'diromax-portal' ),
	'title'   => __( 'כל התיקים', 'diromax-portal' ),
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
	<?php require __DIR__ . '/parts/flash.php'; ?>
	<?php $table->views(); ?>
	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_CASES ); ?>">
		<?php $table->search_box( __( 'חיפוש לפי כתובת או מספר תיק', 'diromax-portal' ), 'dxa-search' ); ?>
		<?php $table->display(); ?>
	</form>
</div>
