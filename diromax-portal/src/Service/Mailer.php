<?php
/**
 * Mail transport.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Domain\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through wp_mail(), like every other email of the site, so an SMTP
 * plugin configured on the site carries the portal's mail too.
 */
final class Mailer {

	/**
	 * Send an HTML email with a plain-text alternative.
	 *
	 * @param string        $to      Recipient address.
	 * @param MailMessage   $message Message.
	 * @param \WP_User|null $manager Project manager: sender name and Reply-To.
	 * @return bool
	 */
	public static function send( string $to, MailMessage $message, ?\WP_User $manager ): bool {
		$to = sanitize_email( $to );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$from_name = $manager
			? sprintf( /* translators: %s: project manager name. */ __( 'דירומקס · %s', 'diromax-portal' ), Text::line( (string) $manager->display_name ) )
			: __( 'דירומקס', 'diromax-portal' );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( $manager && is_email( $manager->user_email ) ) {
			// Text::line strips line breaks, so no header can be injected (standard 4.2.11).
			$headers[] = 'Reply-To: ' . Text::line( (string) $manager->display_name ) . ' <' . sanitize_email( $manager->user_email ) . '>';
		}

		$set_name = static fn(): string => $from_name;
		$set_alt  = static function ( $phpmailer ) use ( $message ): void {
			$phpmailer->AltBody = $message->text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		};

		add_filter( 'wp_mail_from_name', $set_name );
		add_action( 'phpmailer_init', $set_alt );

		try {
			return wp_mail( $to, Text::line( $message->subject ), $message->html, $headers );
		} finally {
			remove_filter( 'wp_mail_from_name', $set_name );
			remove_action( 'phpmailer_init', $set_alt );
		}
	}
}
