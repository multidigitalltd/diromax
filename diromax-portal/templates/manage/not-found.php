<?php
/**
 * Management app: a case or screen that is not there, or not the user's.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$header = array(
	'title' => __( 'לא נמצא', 'diromax-portal' ),
	'meta'  => array( __( 'התיק או העמוד לא קיימים, או שאין לך הרשאה אליהם.', 'diromax-portal' ) ),
);
?>
<div class="wrap dxa">
	<?php require DX_PORTAL_DIR . 'templates/admin/parts/header.php'; ?>
	<p><a class="dxa-btn" href="<?php echo esc_url( Admin::page_url( Admin::PAGE_CASES ) ); ?>"><?php esc_html_e( 'לכל התיקים', 'diromax-portal' ); ?></a></p>
</div>
