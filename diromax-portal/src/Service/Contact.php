<?php
/**
 * Contact details.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Project manager and office contact details for emails and the portal.
 */
final class Contact {

	/** User meta holding a manager's phone for clients. */
	public const PHONE_META = 'dx_portal_phone';

	/**
	 * A manager's card: name, email, phone and WhatsApp link.
	 *
	 * Falls back to the office phone from the theme's contact settings;
	 * "office" says so, for the label next to the number.
	 *
	 * @param int $user_id Manager.
	 * @return array{name:string, email:string, phone:string, tel:string, whatsapp:string, office:bool}
	 */
	public static function manager( int $user_id ): array {
		$user   = get_userdata( $user_id );
		$phone  = $user ? trim( (string) get_user_meta( $user_id, self::PHONE_META, true ) ) : '';
		$office = '' === $phone;

		if ( $office ) {
			$phone = trim( (string) get_theme_mod( 'dx_phone', '' ) );
		}

		$digits = (string) preg_replace( '/\D/', '', $phone );

		return array(
			'name'     => $user ? (string) $user->display_name : __( 'צוות דירומקס', 'diromax-portal' ),
			'email'    => $user ? (string) $user->user_email : '',
			'phone'    => $phone,
			'tel'      => $digits,
			'whatsapp' => '' === $digits ? '' : 'https://wa.me/' . ( str_starts_with( $digits, '0' ) ? '972' . substr( $digits, 1 ) : $digits ),
			'office'   => $office && '' !== $phone,
		);
	}

	/**
	 * Site logo URL for emails: the custom logo, else the theme's bundled logo.
	 *
	 * @return string
	 */
	public static function logo_url(): string {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$url     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : false;

		if ( is_string( $url ) ) {
			return $url;
		}

		return file_exists( get_template_directory() . '/assets/img/logo.jpg' ) ? get_template_directory_uri() . '/assets/img/logo.jpg' : '';
	}
}
