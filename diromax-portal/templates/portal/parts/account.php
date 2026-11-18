<?php
/**
 * Portal part: signed-in user and sign-out button.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$account = $view['user'] ?? null;

if ( ! $account instanceof WP_User || 0 === $account->ID ) {
	return;
}
?>
<form class="dxp-account" method="post" action="<?php echo esc_url( (string) $view['logout'] ); ?>">
	<span>
		<?php
		/* translators: %s: user's name. */
		echo esc_html( sprintf( __( 'חשבון: %s', 'diromax-portal' ), $account->display_name ) );
		?>
	</span>
	<a href="<?php echo esc_url( \Diromax\Portal\Portal\Router::account_url() ); ?>"><?php esc_html_e( 'החשבון שלי', 'diromax-portal' ); ?></a>
	<?php wp_nonce_field( 'dx_portal_logout', '_dx_nonce', false ); ?>
	<button type="submit" class="dxp-linkbtn"><?php esc_html_e( 'יציאה', 'diromax-portal' ); ?></button>
</form>
