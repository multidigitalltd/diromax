<?php
/**
 * Admin part: audit entries as a readable list.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$entries = $view['recent'] ?? $view['audit'] ?? array();
$actions = Labels::audit_actions();

if ( array() === $entries ) {
	echo '<p>' . esc_html__( 'אין עדיין פעילות.', 'diromax-portal' ) . '</p>';
	return;
}
?>
<ul class="dxa-list">
	<?php foreach ( $entries as $entry ) : ?>
		<li>
			<strong><?php echo esc_html( $actions[ $entry['action'] ] ?? $entry['action'] ); ?></strong>
			<span class="dxa-muted"><?php echo esc_html( Labels::datetime( $entry['created_at'] ) . ' · ' . ( '' === $entry['user'] ? __( 'מערכת', 'diromax-portal' ) : $entry['user'] ) ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>
