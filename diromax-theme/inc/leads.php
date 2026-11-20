<?php
/**
 * Lead capture.
 *
 * Stores every submission as a private post and mails it out. No form plugin.
 *
 * Security model:
 * - Nonce on every submission, refreshed over REST so a full-page cache cannot
 *   serve a stale one.
 * - Honeypot field for the cheap bots, per-IP rate limit for the rest.
 * - Every field sanitised on the way in, escaped on the way out.
 * - The visitor's IP is never stored — only a salted hash, and only for the
 *   lifetime of the rate-limit window.
 *
 * @package Diromax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maximum submissions allowed from one address per window. */
const DX_LEAD_MAX_PER_WINDOW = 5;

/** Rate-limit window, in seconds. */
const DX_LEAD_WINDOW = HOUR_IN_SECONDS;

/**
 * The leads post type. Not public, not creatable by hand — it only ever
 * receives what the form writes.
 *
 * @return void
 */
function dx_register_lead_cpt() {
	register_post_type(
		'dx_lead',
		array(
			'labels'          => array(
				'name'          => __( 'לידים', 'diromax' ),
				'singular_name' => __( 'ליד', 'diromax' ),
				'menu_name'     => __( 'לידים', 'diromax' ),
				'search_items'  => __( 'חיפוש לידים', 'diromax' ),
				'not_found'     => __( 'אין לידים עדיין.', 'diromax' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'menu_icon'       => 'dashicons-phone',
			'supports'        => array( 'title', 'editor' ),
			// A capability of its own (standard 15.2.3): see dx_leads_grant_caps().
			'capability_type' => array( 'dx_lead', 'dx_leads' ),
			'map_meta_cap'    => true,
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		)
	);
}
add_action( 'init', 'dx_register_lead_cpt' );

/**
 * A fresh nonce for a page that may have been served from cache.
 *
 * Public by design: a nonce is not a secret, it is a same-origin marker. The
 * response is marked no-store so no cache layer can hold on to it.
 *
 * @return void
 */
function dx_register_nonce_route() {
	register_rest_route(
		'diromax/v1',
		'/nonce',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dx_rest_nonce',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'dx_register_nonce_route' );

/**
 * All-In-One Security can refuse REST requests from visitors who are not
 * signed in. The lead form's nonce route is public by design (it only hands
 * out a fresh form nonce), so it stays reachable when that option is on.
 *
 * @param mixed $routes Whitelisted REST namespaces.
 * @return array<int, string>
 */
function dx_aios_allow_nonce_route( $routes ) {
	$routes   = is_array( $routes ) ? array_values( array_filter( $routes, 'is_string' ) ) : array();
	$routes[] = 'diromax';  // /wp-json/diromax/v1/nonce.
	$routes[] = '/diromax'; // ?rest_route=/diromax/v1/nonce, without pretty permalinks.

	return array_values( array_unique( $routes ) );
}
add_filter( 'aios_whitelisted_rest_routes', 'dx_aios_allow_nonce_route' );

/**
 * Rest nonce.
 *
 * @return WP_REST_Response
 */
function dx_rest_nonce() {
	$response = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'dx_lead' ) ) );
	$response->header( 'Cache-Control', 'no-store, max-age=0' );

	return $response;
}

/**
 * Client address, resolved once. Filterable so a proxy setup can override it.
 *
 * Cloudflare's "Restore original visitor IP" already rewrites REMOTE_ADDR, so
 * the forwarded headers are deliberately not trusted here — they are spoofable
 * whenever the site is reachable directly.
 *
 * @return string
 */
function dx_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return (string) apply_filters( 'dx_client_ip', $ip );
}

/**
 * Rate-limit key: a salted hash, so no address is written to the database.
 *
 * @return string
 */
function dx_lead_rate_key() {
	return 'dx_lead_rl_' . substr( wp_hash( dx_client_ip(), 'nonce' ), 0, 24 );
}

/**
 * Has this address already used up its window?
 *
 * @return bool
 */
function dx_lead_rate_exceeded() {
	return ( (int) get_transient( dx_lead_rate_key() ) >= DX_LEAD_MAX_PER_WINDOW );
}

/**
 * Count one submission against the window.
 *
 * @return void
 */
function dx_lead_rate_bump() {
	$key   = dx_lead_rate_key();
	$count = (int) get_transient( $key );

	set_transient( $key, $count + 1, DX_LEAD_WINDOW );
}

/**
 * Validate and store one submission.
 *
 * @return true|WP_Error True on success, WP_Error with a user-facing message otherwise.
 */
function dx_process_lead() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'dx_lead' ) ) {
		return new WP_Error(
			'dx_nonce',
			__( 'פג תוקף הטופס. רענן את העמוד ונסה שוב.', 'diromax' ),
			array( 'status' => 400 )
		);
	}

	// Honeypot: bots fill hidden fields, people do not. Answer as if it worked.
	if ( ! empty( $_POST['dx_hp'] ) ) {
		return true;
	}

	if ( dx_turnstile_enabled() && ! dx_turnstile_verify( isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '', 'dx_lead' ) ) {
		return new WP_Error(
			'dx_captcha',
			__( 'לא הצלחנו לאמת שהפנייה נשלחה מדפדפן. אפשר לנסות שוב או להתקשר אלינו.', 'diromax' ),
			array( 'status' => 400 )
		);
	}

	if ( dx_lead_rate_exceeded() ) {
		return new WP_Error(
			'dx_rate',
			__( 'נשלחו כבר כמה פניות מהמכשיר הזה. נסה שוב מאוחר יותר או התקשר אלינו.', 'diromax' ),
			array( 'status' => 429 )
		);
	}

	$name  = sanitize_text_field( wp_unslash( $_POST['dx_name'] ?? '' ) );
	$phone = sanitize_text_field( wp_unslash( $_POST['dx_phone'] ?? '' ) );
	$name  = trim( $name );
	$phone = trim( $phone );

	if ( '' === $name || '' === $phone ) {
		return new WP_Error(
			'dx_required',
			__( 'צריך שם וטלפון כדי שנוכל לחזור אליך.', 'diromax' ),
			array( 'status' => 422 )
		);
	}

	if ( mb_strlen( $name ) > 80 ) {
		$name = mb_substr( $name, 0, 80 );
	}

	$digits = dx_phone_digits( $phone, false );
	if ( strlen( $digits ) < 9 || strlen( $digits ) > 15 ) {
		return new WP_Error(
			'dx_phone',
			__( 'מספר הטלפון לא נראה תקין. אפשר לבדוק אותו שוב?', 'diromax' ),
			array( 'status' => 422 )
		);
	}

	$fields = array(
		'name'    => array( __( 'שם', 'diromax' ), $name ),
		'phone'   => array( __( 'טלפון', 'diromax' ), $phone ),
		'address' => array( __( 'כתובת הנכס', 'diromax' ), sanitize_text_field( wp_unslash( $_POST['dx_address'] ?? '' ) ) ),
		'type'    => array( __( 'סוג הנכס', 'diromax' ), dx_lead_choice( $_POST['dx_type'] ?? '', dx_property_types() ) ),
		'subject' => array( __( 'נושא', 'diromax' ), dx_lead_choice( $_POST['dx_subject'] ?? '', dx_contact_subjects() ) ),
		'message' => array( __( 'פרטים', 'diromax' ), sanitize_textarea_field( wp_unslash( $_POST['dx_message'] ?? '' ) ) ),
		'source'  => array( __( 'מאיזה עמוד', 'diromax' ), dx_lead_source( $_POST['dx_source'] ?? '' ) ),
	);

	if ( mb_strlen( $fields['message'][1] ) > 2000 ) {
		$fields['message'][1] = mb_substr( $fields['message'][1], 0, 2000 );
	}

	$lines = array();
	foreach ( $fields as $field ) {
		if ( '' !== $field[1] ) {
			$lines[] = $field[0] . ': ' . $field[1];
		}
	}
	$body = implode( "\n", $lines );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'dx_lead',
			'post_status'  => 'publish',
			'post_title'   => $name . ' — ' . $phone,
			'post_content' => $body,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return new WP_Error(
			'dx_store',
			__( 'לא הצלחנו לשמור את הפנייה. אפשר להתקשר אלינו ישירות.', 'diromax' ),
			array( 'status' => 500 )
		);
	}

	foreach ( $fields as $key => $field ) {
		if ( '' !== $field[1] ) {
			update_post_meta( $post_id, 'dx_' . $key, $field[1] );
		}
	}

	dx_lead_store_crm( $post_id, dx_lead_utm( $_POST['dx_utm'] ?? '' ) );
	dx_lead_rate_bump();
	dx_lead_notify( $post_id );

	/**
	 * Fires once a lead is stored. Hook a CRM here.
	 *
	 * @param int   $post_id Stored lead ID.
	 * @param array $fields  Label/value pairs.
	 */
	do_action( 'dx_lead_stored', $post_id, $fields );

	return true;
}

/**
 * Accept a select value only if it is one of the options the form offered.
 *
 * @param mixed    $value   Raw submitted value.
 * @param string[] $allowed Allowed options.
 * @return string
 */
function dx_lead_choice( $value, array $allowed ) {
	$value = sanitize_text_field( wp_unslash( $value ) );

	return in_array( $value, $allowed, true ) ? $value : '';
}

/**
 * Keep the source URL only when it points at this site.
 *
 * @param mixed $value Raw submitted URL.
 * @return string
 */
function dx_lead_source( $value ) {
	$url = esc_url_raw( wp_unslash( $value ) );

	if ( '' === $url ) {
		return '';
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );
	$home = wp_parse_url( home_url(), PHP_URL_HOST );

	return ( $host && $home && strtolower( $host ) === strtolower( $home ) ) ? $url : '';
}

/**
 * AJAX endpoint used by the enhanced form.
 *
 * @return void
 */
function dx_handle_lead_ajax() {
	$result = dx_process_lead();

	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();

		wp_send_json_error(
			array( 'message' => $result->get_error_message() ),
			isset( $data['status'] ) ? (int) $data['status'] : 400
		);
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_dx_lead', 'dx_handle_lead_ajax' );
add_action( 'wp_ajax_nopriv_dx_lead', 'dx_handle_lead_ajax' );

/**
 * No-JavaScript path: a normal POST that redirects back to the form.
 *
 * @return void
 */
function dx_handle_lead_post() {
	$result = dx_process_lead();
	$back   = dx_lead_source( $_POST['dx_source'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- dx_process_lead() checked the nonce; this only picks the same-site page to return to.

	if ( '' === $back ) {
		$back = home_url( '/' );
	}

	if ( is_wp_error( $result ) ) {
		$back = add_query_arg( 'dx_lead', rawurlencode( (string) $result->get_error_code() ), $back );
	} else {
		$back = add_query_arg( 'dx_lead', 'sent', $back );
	}

	wp_safe_redirect( $back . '#dx-form' );
	exit;
}
add_action( 'admin_post_nopriv_dx_lead', 'dx_handle_lead_post' );
add_action( 'admin_post_dx_lead', 'dx_handle_lead_post' );

/**
 * Message to show after a no-JavaScript round trip, if any.
 *
 * @return array{status:string,message:string}|null
 */
function dx_lead_notice() {
	// Read-only display flag from a redirect this site issued; nothing is mutated here.
	$flag = isset( $_GET['dx_lead'] ) ? sanitize_key( wp_unslash( $_GET['dx_lead'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $flag ) {
		return null;
	}

	$messages = array(
		'sent'        => array( 'success', __( 'קיבלנו. נחזור אליך.', 'diromax' ) ),
		'dx_nonce'    => array( 'error', __( 'פג תוקף הטופס. רענן את העמוד ונסה שוב.', 'diromax' ) ),
		'dx_rate'     => array( 'error', __( 'נשלחו כבר כמה פניות מהמכשיר הזה. נסה שוב מאוחר יותר או התקשר אלינו.', 'diromax' ) ),
		'dx_required' => array( 'error', __( 'צריך שם וטלפון כדי שנוכל לחזור אליך.', 'diromax' ) ),
		'dx_phone'    => array( 'error', __( 'מספר הטלפון לא נראה תקין. אפשר לבדוק אותו שוב?', 'diromax' ) ),
		'dx_store'    => array( 'error', __( 'לא הצלחנו לשמור את הפנייה. אפשר להתקשר אלינו ישירות.', 'diromax' ) ),
		'dx_captcha'  => array( 'error', __( 'לא הצלחנו לאמת שהפנייה נשלחה מדפדפן. אפשר לנסות שוב או להתקשר אלינו.', 'diromax' ) ),
	);

	if ( ! isset( $messages[ $flag ] ) ) {
		return null;
	}

	return array(
		'status'  => $messages[ $flag ][0],
		'message' => $messages[ $flag ][1],
	);
}

/**
 * Phone column on the leads list, so the office can scan without opening rows.
 *
 * @param array<string,string> $columns Existing columns.
 * @return array<string,string>
 */
function dx_lead_columns( $columns ) {
	$date = $columns['date'] ?? '';
	unset( $columns['date'] );

	$columns['dx_phone']   = __( 'טלפון', 'diromax' );
	$columns['dx_subject'] = __( 'נושא', 'diromax' );

	if ( $date ) {
		$columns['date'] = $date;
	}

	return $columns;
}
add_filter( 'manage_dx_lead_posts_columns', 'dx_lead_columns' );

/**
 * Lead column content.
 *
 * @param string $column  Column key.
 * @param int    $post_id Lead ID.
 * @return void
 */
function dx_lead_column_content( $column, $post_id ) {
	// Column keys deliberately match the meta keys they render.
	if ( 'dx_phone' === $column || 'dx_subject' === $column ) {
		echo esc_html( (string) get_post_meta( $post_id, $column, true ) );
	}
}
add_action( 'manage_dx_lead_posts_custom_column', 'dx_lead_column_content', 10, 2 );
