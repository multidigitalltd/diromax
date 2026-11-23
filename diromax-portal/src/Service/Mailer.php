<?php
/**
 * Mail transport.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Domain\Text;
use Diromax\Portal\Support\MailTrace;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through wp_mail(), like every other email of the site, so the
 * site's transport (Postmark, see Postmark) carries the portal's mail too.
 */
final class Mailer {

	/**
	 * Send an HTML email with a plain-text alternative.
	 *
	 * @param string                                                                                     $to      Recipient address.
	 * @param MailMessage                                                                                $message Message.
	 * @param \WP_User|null                                                                              $manager Project manager: sender name and Reply-To.
	 * @param array{reply_to?:string, reply_name?:string, from_name?:string, attachments?:array<string>} $options Reply-To address and name instead of the manager's (replies into the case), sender name, files to attach.
	 * @return bool
	 */
	public static function send( string $to, MailMessage $message, ?\WP_User $manager, array $options = array() ): bool {
		$to = sanitize_email( $to );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$from_name = $manager
			? sprintf( /* translators: %s: project manager name. */ __( 'דירומקס · %s', 'diromax-portal' ), Text::line( (string) $manager->display_name ) )
			: __( 'דירומקס', 'diromax-portal' );

		if ( isset( $options['from_name'] ) && '' !== Text::line( $options['from_name'] ) ) {
			$from_name = Text::line( $options['from_name'] );
		}

		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply_to = (string) ( $options['reply_to'] ?? '' );
		$address  = '' !== $reply_to && is_email( $reply_to ) ? $reply_to : ( $manager && is_email( $manager->user_email ) ? (string) $manager->user_email : '' );

		if ( '' !== $address ) {
			// Text::line strips line breaks, so no header can be injected (standard 4.2.11).
			$name      = Text::line( (string) ( $options['reply_name'] ?? ( $manager ? $manager->display_name : __( 'דירומקס', 'diromax-portal' ) ) ) );
			$headers[] = 'Reply-To: ' . str_replace( array( '"', '<', '>', ',' ), '', $name ) . ' <' . sanitize_email( $address ) . '>';
		}

		$set_name = static fn(): string => $from_name;
		$set_alt  = static function ( $phpmailer ) use ( $message ): void {
			$phpmailer->AltBody = $message->text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		};

		add_filter( 'wp_mail_from_name', $set_name );
		add_action( 'phpmailer_init', $set_alt );
		MailTrace::$text = $message->text;
		MailTrace::reset();

		try {
			return wp_mail( $to, Text::line( $message->subject ), $message->html, $headers, $options['attachments'] ?? array() );
		} finally {
			remove_filter( 'wp_mail_from_name', $set_name );
			remove_action( 'phpmailer_init', $set_alt );
			MailTrace::$text = '';
		}
	}
}
