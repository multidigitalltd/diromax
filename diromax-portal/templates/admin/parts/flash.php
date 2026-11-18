<?php
/**
 * Admin part: the message from the last save.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$flash = $view['flash'] ?? null;

if ( ! is_array( $flash ) || '' === $flash['message'] ) {
	return;
}
?>
<div class="notice notice-<?php echo 'error' === $flash['type'] ? 'error' : 'success'; ?> is-dismissible" role="<?php echo 'error' === $flash['type'] ? 'alert' : 'status'; ?>">
	<p><?php echo esc_html( (string) $flash['message'] ); ?></p>
</div>
