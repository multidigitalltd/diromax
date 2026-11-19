<?php
/**
 * Admin part: the button that creates a sample case.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dxa-inline-form">
	<input type="hidden" name="action" value="dx_portal_demo_case">
	<?php wp_nonce_field( 'dx_portal_demo_case' ); ?>
	<button type="submit" class="dxa-btn"><?php esc_html_e( 'יצירת תיק לדוגמה', 'diromax-portal' ); ?></button>
</form>
