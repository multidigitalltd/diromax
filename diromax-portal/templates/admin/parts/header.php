<?php
/**
 * Admin part: the brand bar, the plugin's navigation, and the page head.
 *
 * Shared by the wp-admin screens and the management app on the site
 * (Admin::is_front()); every link goes to the interface the user is in.
 *
 * Expects $header: title, and optionally eyebrow, meta (strings), back
 * (label, url), actions (label, url, primary, new_tab) and current
 * (dashboard, cases, new, settings).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $header
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

$dxa_front = Admin::is_front();
$dxa_items = array(
	'dashboard' => array( __( 'לוח בקרה', 'diromax-portal' ), Admin::page_url( Admin::PAGE_DASHBOARD ) ),
	'cases'     => array( __( 'תיקים', 'diromax-portal' ), Admin::page_url( Admin::PAGE_CASES ) ),
	'new'       => array( __( 'תיק חדש', 'diromax-portal' ), Admin::page_url( Admin::PAGE_NEW ) ),
);

$dxa_leads = get_post_type_object( 'dx_lead' );

if ( null !== $dxa_leads && current_user_can( $dxa_leads->cap->edit_posts ) ) {
	$dxa_items['leads'] = array( __( 'לידים', 'diromax-portal' ), admin_url( 'edit.php?post_type=dx_lead' ) );
}

if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
	$dxa_items['settings'] = array( __( 'הגדרות', 'diromax-portal' ), Admin::page_url( Admin::PAGE_SETTINGS ) );
}

$dxa_current = (string) ( $header['current'] ?? '' );
$dxa_search  = Admin::page_url( Admin::PAGE_CASES );
$dxa_hidden  = array();
parse_str( (string) wp_parse_url( $dxa_search, PHP_URL_QUERY ), $dxa_hidden );
$dxa_hidden = array_filter( $dxa_hidden, 'is_string' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search term, echoed escaped.
$dxa_term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$dxa_user = wp_get_current_user();
?>
<div class="dxa-top">
	<a class="dxa-top__brand" href="<?php echo esc_url( Admin::page_url( Admin::PAGE_DASHBOARD ) ); ?>">
		<span class="dxa-top__logo">DIROMAX</span>
		<span class="dxa-top__app"><?php esc_html_e( 'תיקי לקוחות', 'diromax-portal' ); ?></span>
	</a>
	<nav class="dxa-top__nav" aria-label="<?php esc_attr_e( 'תיקי לקוחות', 'diromax-portal' ); ?>">
		<ul>
			<?php foreach ( $dxa_items as $dxa_key => [ $dxa_label, $dxa_url ] ) : ?>
				<li><a href="<?php echo esc_url( $dxa_url ); ?>"<?php echo $dxa_key === $dxa_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $dxa_label ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<div class="dxa-top__tools">
		<form class="dxa-top__search" role="search" method="get" action="<?php echo esc_url( explode( '?', $dxa_search )[0] ); ?>">
			<?php foreach ( $dxa_hidden as $dxa_name => $dxa_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $dxa_name ); ?>" value="<?php echo esc_attr( $dxa_value ); ?>">
			<?php endforeach; ?>
			<label class="screen-reader-text" for="dxa-top-search"><?php esc_html_e( 'חיפוש תיק לפי כתובת או מספר', 'diromax-portal' ); ?></label>
			<input type="search" id="dxa-top-search" name="s" value="<?php echo esc_attr( $dxa_term ); ?>" placeholder="<?php esc_attr_e( 'חיפוש תיק… ( / )', 'diromax-portal' ); ?>" maxlength="100" aria-keyshortcuts="/">
		</form>
		<?php if ( $dxa_front ) : ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a class="dxa-top__link" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'לוח וורדפרס', 'diromax-portal' ); ?></a>
			<?php endif; ?>
			<form class="dxa-top__user" method="post" action="<?php echo esc_url( Router::logout_url() ); ?>">
				<span><?php echo esc_html( $dxa_user->display_name ); ?></span>
				<?php wp_nonce_field( 'dx_portal_logout', '_dx_nonce', false ); ?>
				<button type="submit" class="dxa-top__link"><?php esc_html_e( 'יציאה', 'diromax-portal' ); ?></button>
			</form>
		<?php else : ?>
			<a class="dxa-top__link" href="<?php echo esc_url( Router::manage_url() ); ?>"><?php esc_html_e( 'מסך מלא באתר', 'diromax-portal' ); ?></a>
		<?php endif; ?>
	</div>
</div>

<div class="dxa-head">
	<div class="dxa-head__text">
		<?php if ( ! empty( $header['back'] ) ) : ?>
			<p class="dxa-back"><a href="<?php echo esc_url( (string) $header['back'][1] ); ?>"><span aria-hidden="true">&rarr;</span> <?php echo esc_html( (string) $header['back'][0] ); ?></a></p>
		<?php endif; ?>
		<?php if ( ! empty( $header['eyebrow'] ) ) : ?>
			<p class="dxa-eyebrow"><span class="dxa-dot" aria-hidden="true"></span><?php echo esc_html( (string) $header['eyebrow'] ); ?></p>
		<?php endif; ?>
		<h1 class="dxa-title"><?php echo esc_html( (string) $header['title'] ); ?></h1>
		<?php if ( ! empty( $header['meta'] ) ) : ?>
			<p class="dxa-head__meta">
				<?php foreach ( (array) $header['meta'] as $dxa_meta ) : ?>
					<span><?php echo esc_html( (string) $dxa_meta ); ?></span>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $header['actions'] ) ) : ?>
		<div class="dxa-head__actions">
			<?php foreach ( (array) $header['actions'] as $dxa_action ) : ?>
				<a class="dxa-btn<?php echo ! empty( $dxa_action['primary'] ) ? ' dxa-btn--primary' : ''; ?>" href="<?php echo esc_url( (string) $dxa_action['url'] ); ?>"<?php echo ! empty( $dxa_action['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
					<?php echo esc_html( (string) $dxa_action['label'] ); ?>
					<?php if ( ! empty( $dxa_action['new_tab'] ) ) : ?>
						<span class="screen-reader-text"><?php esc_html_e( '(נפתח בחלון חדש)', 'diromax-portal' ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<hr class="wp-header-end">
<?php if ( $dxa_front ) : ?>
	<?php foreach ( (array) ( $view['notices'] ?? array() ) as [ $dxa_type, $dxa_message ] ) : ?>
		<div class="notice notice-<?php echo esc_attr( (string) $dxa_type ); ?>"><p><?php echo esc_html( (string) $dxa_message ); ?></p></div>
	<?php endforeach; ?>
<?php endif; ?>
