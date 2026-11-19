<?php
/**
 * Admin: new case.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Admin\Form;

defined( 'ABSPATH' ) || exit;

$form = new Form( $view['flash'], 'case' );
?>
<div class="wrap dxa">
	<h1><?php esc_html_e( 'תיק חדש', 'diromax-portal' ); ?></h1>
	<?php require __DIR__ . '/parts/flash.php'; ?>
	<p class="dxa-intro"><?php esc_html_e( 'הפרטים האלה מופיעים ללקוח בראש התיק ובכרטיס ההסכם. אפשר לשנות אותם בכל שלב.', 'diromax-portal' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="form-case">
		<input type="hidden" name="action" value="dx_portal_create_case">
		<input type="hidden" name="case_id" value="0">
		<?php wp_nonce_field( 'dx_portal_create_case_0' ); ?>
		<?php if ( null !== $view['lead'] ) : ?>
			<input type="hidden" name="lead_id" value="<?php echo esc_attr( (string) $view['lead']['lead_id'] ); ?>">
			<div class="notice notice-info inline">
				<p>
					<?php
					/* translators: 1: name, 2: phone. */
					echo esc_html( sprintf( __( 'נפתח מליד: %1$s, %2$s. אחרי השמירה מוסיפים אותו כלקוח בלשונית "לקוחות" (צריך את כתובת המייל שלו).', 'diromax-portal' ), $view['lead']['name'], $view['lead']['phone'] ) );
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php require __DIR__ . '/parts/case-fields.php'; ?>
		<?php submit_button( __( 'פתיחת התיק', 'diromax-portal' ) ); ?>
	</form>
</div>
