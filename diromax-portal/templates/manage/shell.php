<?php
/**
 * Management app: the page around a screen, without the theme.
 *
 * Hebrew, right to left, whatever the site's admin language. Only the app's
 * own stylesheet and script are printed.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 * @var string               $template_file
 * @var string               $document_title
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $document_title ); ?></title>
	<?php wp_print_styles( array( 'dx-portal-admin', 'dx-portal-manage' ) ); ?>
</head>
<body class="dxa-screen dxa-front">
	<a class="screen-reader-text dxa-skip" href="#dxa-main"><?php esc_html_e( 'דילוג לתוכן', 'diromax-portal' ); ?></a>
	<main id="dxa-main">
		<?php require $template_file; ?>
	</main>
	<?php wp_print_scripts( array( 'dx-portal-admin' ) ); ?>
</body>
</html>
