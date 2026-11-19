<?php
/**
 * Cloudflare Turnstile for the site's forms (Multi Digital standard 15.3).
 *
 * The keys live in wp-config.php (DX_TURNSTILE_SITE_KEY and
 * DX_TURNSTILE_SECRET_KEY), the same pair the client portal uses, so the
 * secret never reaches the browser or the database. Without both keys the
 * forms work as before, protected by the honeypot and the rate limit.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Are both keys configured?
 *
 * @return bool
 */
function dx_turnstile_enabled() {
	return defined( 'DX_TURNSTILE_SITE_KEY' ) && defined( 'DX_TURNSTILE_SECRET_KEY' )
		&& '' !== (string) DX_TURNSTILE_SITE_KEY && '' !== (string) DX_TURNSTILE_SECRET_KEY;
}

/**
 * The public site key, for the widget.
 *
 * @return string
 */
function dx_turnstile_site_key() {
	return defined( 'DX_TURNSTILE_SITE_KEY' ) ? (string) DX_TURNSTILE_SITE_KEY : '';
}

/**
 * The secret key, server side only.
 *
 * @return string
 */
function dx_turnstile_secret() {
	return defined( 'DX_TURNSTILE_SECRET_KEY' ) ? (string) DX_TURNSTILE_SECRET_KEY : '';
}

/**
 * Validate a token with Siteverify (standard 15.3.2, 15.3.5, 15.3.6).
 *
 * Success alone is not enough: the token must also have been issued for
 * this site's hostname and for this form's action. Any failure, including
 * a network error, rejects the submission and is logged.
 *
 * @param string $token  The cf-turnstile-response field.
 * @param string $action The widget's data-action.
 * @return bool
 */
function dx_turnstile_verify( $token, $action ) {
	if ( '' === $token || strlen( $token ) > 2048 ) {
		return false;
	}

	$response = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 5,
			'body'    => array(
				'secret'   => dx_turnstile_secret(),
				'response' => $token,
				'remoteip' => dx_client_ip(),
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		error_log( 'diromax: turnstile siteverify unreachable' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log line, no personal data.
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$ok   = is_array( $body )
		&& true === ( $body['success'] ?? false )
		&& ( $body['action'] ?? '' ) === $action
		&& strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === strtolower( (string) ( $body['hostname'] ?? '' ) );

	if ( ! $ok ) {
		error_log( 'diromax: turnstile rejected' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log line, no personal data.
	}

	return $ok;
}

/**
 * The widget script, only on pages with a lead form, without blocking render (15.3.7).
 */
function dx_turnstile_script() {
	if ( dx_turnstile_enabled() && dx_needs_lead_script() ) {
		wp_enqueue_script(
			'dx-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare serves the current widget; a version query is not allowed.
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'dx_turnstile_script' );
