<?php
/**
 * Postmark as the site's mail transport.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\Logger;
use Diromax\Portal\Support\MailConfig;
use Diromax\Portal\Support\MailTrace;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every email of the site goes out through Postmark's API (decision 17):
 * the portal's, the lead form's and WordPress's own. wp_mail() still builds
 * the message, so every plugin keeps working; this transport replaces the
 * sending only.
 *
 * Configuration in wp-config.php, never in the database (see MailConfig):
 *   DX_POSTMARK_TOKEN   Server API token (required).
 *   DX_POSTMARK_FROM    Sender address on a domain verified in Postmark (optional;
 *                       default: the site's usual From address). When set, every
 *                       email goes out from it, keeping the sender's name.
 *   DX_POSTMARK_STREAM  Message stream (optional; default "outbound").
 *
 * Without a token nothing changes: WordPress sends as before.
 */
final class Postmark {

	/** API endpoint. */
	private const ENDPOINT = 'https://api.postmarkapp.com/email';

	/** Postmark's limit on attachments per message, bytes. */
	private const MAX_ATTACHMENTS = 10485760;

	/** Headers wp_mail() handles itself; the rest pass through. */
	private const OWN_HEADERS = array( 'from', 'reply-to', 'cc', 'bcc', 'content-type', 'mime-version', 'to', 'subject' );

	/**
	 * Is a server token configured?
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return '' !== self::token();
	}

	/**
	 * Register the transport.
	 */
	public function register(): void {
		// Late: a filter that already answered (e.g. tests capturing mail) wins.
		add_filter( 'pre_wp_mail', array( $this, 'send' ), 20, 2 );
	}

	/**
	 * Send through Postmark instead of PHPMailer.
	 *
	 * @param null|bool            $short Answer of an earlier filter.
	 * @param array<string, mixed> $atts  wp_mail() arguments.
	 * @return null|bool Null leaves the sending to WordPress.
	 */
	public function send( $short, array $atts ): ?bool {
		if ( null !== $short || ! self::configured() ) {
			return null === $short ? null : (bool) $short;
		}

		$fixed   = '' !== MailConfig::get( 'from' );
		$payload = self::payload( $atts, self::sender(), self::stream(), MailTrace::$text, $fixed );

		if ( $payload instanceof WP_Error ) {
			return $this->failed( $payload, $atts );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'                  => 'application/json',
					'Content-Type'            => 'application/json',
					'X-Postmark-Server-Token' => self::token(),
				),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failed( $response, $atts );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code   = is_array( $body ) ? (int) ( $body['ErrorCode'] ?? -1 ) : -1;

		if ( 200 !== $status || 0 !== $code ) {
			$message = is_array( $body ) ? (string) ( $body['Message'] ?? '' ) : '';
			return $this->failed( new WP_Error( 'dx_postmark', 'Postmark ' . $status . '/' . $code . ': ' . $message ), $atts );
		}

		MailTrace::sent( is_array( $body ) ? (string) ( $body['MessageID'] ?? '' ) : '' );

		return true;
	}

	/**
	 * The API request for a wp_mail() call.
	 *
	 * @param array<string, mixed> $atts   wp_mail() arguments: to, subject, message, headers, attachments.
	 * @param string               $from   Default sender, "Name <address>".
	 * @param string               $stream Message stream.
	 * @param string               $text   Plain-text version, when the caller gave one.
	 * @param bool                 $fixed  The sender is fixed (DX_POSTMARK_FROM): a From header keeps only its name, and its address becomes the Reply-To.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function payload( array $atts, string $from, string $stream, string $text, bool $fixed = false ): array|WP_Error {
		$headers = self::headers( $atts['headers'] ?? array() );
		$type    = $headers['content-type'][0] ?? (string) apply_filters( 'wp_mail_content_type', 'text/plain' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$html    = str_contains( strtolower( $type ), 'text/html' );
		$body    = (string) ( $atts['message'] ?? '' );
		$to      = self::addresses( $atts['to'] ?? array() );

		if ( '' === $to ) {
			return new WP_Error( 'dx_postmark_to', 'No recipient.' );
		}

		$sender = $headers['from'][0] ?? $from;

		if ( $fixed && isset( $headers['from'][0] ) ) {
			// Postmark sends only from verified addresses: keep the name, reply to the original address.
			$name                  = trim( (string) preg_replace( '/<[^>]*>/', '', $headers['from'][0] ), " \"'" );
			$sender                = ( '' === $name ? '' : str_replace( '"', '', $name ) . ' ' ) . substr( $from, (int) strpos( $from, '<' ) );
			$headers['reply-to'] ??= array( $headers['from'][0] );
		}

		$payload = array(
			'From'          => $sender,
			'To'            => $to,
			'Subject'       => (string) ( $atts['subject'] ?? '' ),
			'MessageStream' => $stream,
			'TrackOpens'    => false,
			'TrackLinks'    => 'None',
		);

		if ( $html ) {
			$payload['HtmlBody'] = $body;
			$payload['TextBody'] = '' !== $text ? $text : self::text_of( $body );
		} else {
			$payload['TextBody'] = $body;
		}

		foreach ( array(
			'reply-to' => 'ReplyTo',
			'cc'       => 'Cc',
			'bcc'      => 'Bcc',
		) as $name => $key ) {
			if ( isset( $headers[ $name ] ) ) {
				$payload[ $key ] = implode( ', ', $headers[ $name ] );
			}
		}

		$extra = array();

		foreach ( $headers as $name => $values ) {
			if ( ! in_array( $name, self::OWN_HEADERS, true ) ) {
				foreach ( $values as $value ) {
					$extra[] = array(
						'Name'  => $name,
						'Value' => $value,
					);
				}
			}
		}

		if ( array() !== $extra ) {
			$payload['Headers'] = $extra;
		}

		$attachments = self::attachments( $atts['attachments'] ?? array() );

		if ( $attachments instanceof WP_Error ) {
			return $attachments;
		}

		if ( array() !== $attachments ) {
			$payload['Attachments'] = $attachments;
		}

		return $payload;
	}

	/**
	 * Headers by lower-case name, each with its values.
	 *
	 * @param mixed $raw String or list, as wp_mail() takes them.
	 * @return array<string, string[]>
	 */
	private static function headers( $raw ): array {
		$lines   = is_array( $raw ) ? $raw : explode( "\n", str_replace( "\r\n", "\n", (string) $raw ) );
		$headers = array();

		foreach ( $lines as $line ) {
			$parts = explode( ':', (string) $line, 2 );

			if ( 2 !== count( $parts ) || '' === trim( $parts[0] ) ) {
				continue;
			}

			// No line breaks survive into a header value (standard 4.2.11).
			$headers[ strtolower( trim( $parts[0] ) ) ][] = trim( str_replace( array( "\r", "\n" ), '', $parts[1] ) );
		}

		return $headers;
	}

	/**
	 * Recipients as Postmark takes them: comma-separated.
	 *
	 * @param mixed $to String or list.
	 * @return string
	 */
	private static function addresses( $to ): string {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );

		return implode( ', ', array_filter( array_map( static fn( $address ): string => trim( str_replace( array( "\r", "\n" ), '', (string) $address ) ), $list ) ) );
	}

	/**
	 * Files as Postmark attachments.
	 *
	 * @param mixed $files Paths, as wp_mail() takes them.
	 * @return array<int, array{Name:string, Content:string, ContentType:string}>|WP_Error
	 */
	private static function attachments( $files ): array|WP_Error {
		$list  = is_array( $files ) ? $files : array_filter( explode( "\n", str_replace( "\r\n", "\n", (string) $files ) ) );
		$out   = array();
		$total = 0;

		foreach ( $list as $name => $path ) {
			$path = (string) $path;

			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$total += (int) filesize( $path );

			if ( $total > self::MAX_ATTACHMENTS ) {
				return new WP_Error( 'dx_postmark_size', 'Attachments over 10 MB.' );
			}

			$label = is_string( $name ) ? $name : wp_basename( $path );
			$type  = wp_check_filetype( $label );
			$out[] = array(
				'Name'        => $label,
				'Content'     => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The API takes attachments as base64 of a local file.
				'ContentType' => is_string( $type['type'] ) && '' !== $type['type'] ? $type['type'] : 'application/octet-stream',
			);
		}

		return $out;
	}

	/**
	 * A plain-text version of an HTML body, for messages that brought none.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function text_of( string $html ): string {
		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<br\s*/?>|</p>|</div>|</tr>|</h[1-6]>#i', "\n", $html );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Report a failure the way WordPress does, and log it.
	 *
	 * @param WP_Error             $error What went wrong.
	 * @param array<string, mixed> $atts  wp_mail() arguments.
	 * @return bool Always false.
	 */
	private function failed( WP_Error $error, array $atts ): bool {
		Logger::error( 'mail.postmark_failed', array( 'reason' => $error->get_error_message() ) );
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error->get_error_message(), $atts ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		return false;
	}

	/**
	 * The default sender: DX_POSTMARK_FROM, else the site's usual From.
	 *
	 * @return string "Name <address>".
	 */
	private static function sender(): string {
		$configured = sanitize_email( MailConfig::get( 'from' ) );
		$host       = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$address    = '' !== $configured ? $configured : (string) apply_filters( 'wp_mail_from', 'wordpress@' . preg_replace( '/^www\./', '', $host ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$name       = (string) apply_filters( 'wp_mail_from_name', 'WordPress' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

		return str_replace( array( '"', "\r", "\n" ), '', $name ) . ' <' . $address . '>';
	}

	/**
	 * The message stream.
	 *
	 * @return string
	 */
	private static function stream(): string {
		$stream = sanitize_key( MailConfig::get( 'stream' ) );

		return '' === $stream ? 'outbound' : $stream;
	}

	/**
	 * The server token.
	 *
	 * @return string
	 */
	private static function token(): string {
		return MailConfig::get( 'token' );
	}
}
