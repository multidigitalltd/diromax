<?php
/**
 * Admin part: the brand bar, the plugin's navigation, and the page head.
 *
 * Expects $header: title, and optionally eyebrow, meta (strings), actions
 * (label, url, primary, new_tab) and current (dashboard, cases, new,
 * settings).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $header
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

$dxa_page  = static fn( string $page ): string => add_query_arg( 'page', $page, admin_url( 'admin.php' ) );
$dxa_items = array(
	'dashboard' => array( __( 'לוח בקרה', 'diromax-portal' ), $dxa_page( Admin::PAGE_DASHBOARD ) ),
	'cases'     => array( __( 'כל התיקים', 'diromax-portal' ), $dxa_page( Admin::PAGE_CASES ) ),
	'new'       => array( __( 'תיק חדש', 'diromax-portal' ), $dxa_page( Admin::PAGE_NEW ) ),
);

$dxa_leads = get_post_type_object( 'dx_lead' );

if ( null !== $dxa_leads && current_user_can( $dxa_leads->cap->edit_posts ) ) {
	$dxa_items['leads'] = array( __( 'לידים', 'diromax-portal' ), admin_url( 'edit.php?post_type=dx_lead' ) );
}

if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
	$dxa_items['settings'] = array( __( 'הגדרות', 'diromax-portal' ), $dxa_page( Admin::PAGE_SETTINGS ) );
}

$dxa_current = (string) ( $header['current'] ?? '' );
?>
<div class="dxa-top">
	<a class="dxa-top__brand" href="<?php echo esc_url( $dxa_page( Admin::PAGE_DASHBOARD ) ); ?>">
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
