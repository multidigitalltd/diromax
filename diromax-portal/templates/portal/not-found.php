<?php
/**
 * Portal: not found (also for cases the user may not see).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Portal\Router;

defined( 'ABSPATH' ) || exit;
?>
<section class="dxp-auth" aria-labelledby="dxp-404-title">
	<div class="dxp-wrap dxp-auth__in">
		<p class="dxp-eyebrow"><span class="dxp-dot" aria-hidden="true"></span><?php esc_html_e( 'אזור אישי', 'diromax-portal' ); ?></p>
		<h1 id="dxp-404-title"><?php esc_html_e( 'הדף לא נמצא', 'diromax-portal' ); ?></h1>
		<p class="dxp-lead"><?php esc_html_e( 'ייתכן שהקישור ישן, או שהתיק לא משויך לחשבון הזה.', 'diromax-portal' ); ?></p>
		<p><a class="dxp-btn dxp-btn--orange" href="<?php echo esc_url( Router::portal_url() ); ?>"><?php esc_html_e( 'לתיקים שלי', 'diromax-portal' ); ?></a></p>
	</div>
</section>
