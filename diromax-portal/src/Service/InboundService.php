<?php
/**
 * Client replies by email, received through Postmark.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Support\Ids;
use Diromax\Portal\Support\MailConfig;
use Diromax\Portal\Support\TempFile;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A client answers a case email, and the answer lands in two places
 * (decision 18): it is kept in the case, with its files as encrypted
 * documents, and it is forwarded in full to the project manager.
 *
 * Case emails carry a Reply-To of the form local+HASH@inbound-domain. HASH
 * is the case's public ID and an HMAC of it, so a reply can be tied to its
 * case and the address cannot be guessed for another case.
 *
 * A reply is kept only when all of these hold: the HMAC matches, the case
 * is not closed, the sender is a member of the case, and Postmark's checks
 * show the sender's domain really sent it (DKIM signed by the author's
 * domain, or SPF passed for the same domain), and it is not spam. Anything
 * else is forwarded to the manager only, with a warning, and not kept. Spam
 * is dropped.
 *
 * Configuration in wp-config.php:
 *   DX_POSTMARK_INBOUND  The server's inbound address, e.g.
 *                        abc123@inbound.postmarkapp.com or reply@reply.example.co.il.
 *
 * @phpstan-type Letter array{provider_id:string, sender:array{email:string, name:string}, subject:string, reply:string, full:string, files:array<int, array{name:string, bytes:string}>}
 * @phpstan-type Member array{user_id:int, name:string, may_add_files:bool}
 * @phpstan-type Outcome array<int, array{name:string, document_id:int|null, reason:string}>
 */
final class InboundService {

	/** Messages handled per case (or without a case) per hour. */
	private const PER_HOUR = 40;

	/** Longest text kept, characters. */
	private const MAX_TEXT = 20000;

	/** Postmark's limit on attachments of one outgoing message, bytes. */
	private const MAX_FORWARD = 10485760;

	/** How long a message ID is remembered against repeats, seconds. */
	private const SEEN_FOR = 7 * DAY_IN_SECONDS;

	/** Characters of the HMAC in the address. */
	private const MAC_LENGTH = 16;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Are replies received? Needs Postmark and an inbound address.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return Postmark::configured() && '' !== self::inbound();
	}

	/**
	 * The Reply-To address for a case's emails, '' when replies are not received.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return string
	 */
	public static function reply_address( CaseRecord $client_case ): string {
		if ( ! self::enabled() ) {
			return '';
		}

		[ $local, $domain ] = explode( '@', self::inbound(), 2 );
		$local              = explode( '+', $local, 2 )[0];

		return $local . '+' . self::mailbox_hash( $client_case->public_id ) . '@' . $domain;
	}

	/**
	 * The part after "+" for a case.
	 *
	 * @param string $public_id Case public ID.
	 * @return string
	 */
	public static function mailbox_hash( string $public_id ): string {
		return strtolower( $public_id ) . substr( hash_hmac( 'sha256', 'reply|' . strtolower( $public_id ), wp_salt( 'auth' ) ), 0, self::MAC_LENGTH );
	}

	/**
	 * Handle one inbound email from Postmark.
	 *
	 * @param array<string, mixed> $mail Postmark's inbound JSON.
	 * @return string|WP_Error stored, forwarded, duplicate or ignored; WP_Error to be tried again.
	 */
	public function receive( array $mail ): string|WP_Error {
		$provider_id = Text::line( self::str( $mail, 'MessageID' ) );

		if ( '' === $provider_id || mb_strlen( $provider_id ) > 64 ) {
			return 'ignored';
		}

		$seen = 'dx_portal_in_' . substr( hash( 'sha256', $provider_id ), 0, 40 );

		if ( false !== get_transient( $seen ) || $this->app->messages->exists( $provider_id ) ) {
			return 'duplicate';
		}

		$result = $this->handle( $provider_id, $mail );

		// Remembered only once handled: a failure is tried again by Postmark.
		if ( ! $result instanceof WP_Error ) {
			set_transient( $seen, 1, self::SEEN_FOR );
		}

		return $result;
	}

	/**
	 * Sort, keep and forward one new message.
	 *
	 * @param string               $provider_id Postmark's message ID.
	 * @param array<string, mixed> $mail        Inbound JSON.
	 * @return string|WP_Error
	 */
	private function handle( string $provider_id, array $mail ): string|WP_Error {
		$headers = self::headers( $mail['Headers'] ?? array() );

		if ( self::is_spam( $headers ) ) {
			Logger::warning( 'inbound.spam_dropped', array( 'id' => $provider_id ) );
			return 'ignored';
		}

		$client_case = $this->case_of( $mail );

		if ( ! $this->app->limiter->attempt( 'inbound', null === $client_case ? 'none' : (string) $client_case->id, self::PER_HOUR, HOUR_IN_SECONDS ) ) {
			Logger::warning( 'inbound.rate_limited', array( 'case' => $client_case?->id ) );
			return 'ignored';
		}

		$sender = self::sender( $mail );
		$member = null === $client_case ? null : $this->member( $client_case, $sender['email'] );
		$reason = $this->problem( $client_case, $member, $sender['email'], $headers );
		$letter = array(
			'provider_id' => $provider_id,
			'sender'      => $sender,
			'subject'     => mb_substr( Text::line( self::str( $mail, 'Subject' ) ), 0, 255 ),
			'reply'       => self::text( $mail, true ),
			'full'        => self::text( $mail, false ),
			'files'       => self::files( $mail['Attachments'] ?? array() ),
		);

		if ( '' !== $reason || null === $client_case || null === $member ) {
			$this->forward( $client_case, $letter, array(), $reason );
			Logger::warning(
				'inbound.forwarded_only',
				array(
					'case'   => $client_case?->id,
					'reason' => $reason,
				)
			);

			return 'forwarded';
		}

		$stored = $this->store( $client_case, $member, $letter );

		if ( $stored instanceof WP_Error ) {
			return $stored;
		}

		$this->forward( $client_case, $letter, $stored, '' );

		return 'stored';
	}

	/**
	 * The case a reply is for, by the hash in the address it was sent to.
	 *
	 * @param array<string, mixed> $mail Inbound JSON.
	 * @return CaseRecord|null
	 */
	private function case_of( array $mail ): ?CaseRecord {
		$hashes = array( self::str( $mail, 'MailboxHash' ) );

		foreach ( array( 'ToFull', 'CcFull', 'BccFull' ) as $field ) {
			foreach ( is_array( $mail[ $field ] ?? null ) ? $mail[ $field ] : array() as $address ) {
				if ( is_array( $address ) ) {
					$hashes[] = self::str( $address, 'MailboxHash' );
				}
			}
		}

		foreach ( array_unique( array_map( 'strtolower', $hashes ) ) as $hash ) {
			if ( ! preg_match( '/^[0-9a-f]{24}[0-9a-f]{' . self::MAC_LENGTH . '}$/', $hash ) ) {
				continue;
			}

			$public_id = substr( $hash, 0, 24 );

			if ( hash_equals( self::mailbox_hash( $public_id ), $hash ) ) {
				return $this->app->cases->find_by_public_id( $public_id );
			}
		}

		return null;
	}

	/**
	 * The case member with this address.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $email       Sender address.
	 * @return Member|null
	 */
	private function member( CaseRecord $client_case, string $email ): ?array {
		if ( '' === $email ) {
			return null;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user || ! $this->app->members->is_member( $client_case->id, (int) $user->ID ) ) {
			return null;
		}

		return array(
			'user_id'       => (int) $user->ID,
			'name'          => (string) $user->display_name,
			'may_add_files' => DecisionService::may_answer( $this->app->members->role( $client_case->id, (int) $user->ID ) ),
		);
	}

	/**
	 * Why a reply cannot be kept, '' when it can.
	 *
	 * @param CaseRecord|null         $client_case Case.
	 * @param array|null              $member      Sender as a member.
	 * @param string                  $email       Sender address.
	 * @param array<string, string[]> $headers     Headers.
	 * @phpstan-param Member|null $member
	 * @return string
	 */
	private function problem( ?CaseRecord $client_case, ?array $member, string $email, array $headers ): string {
		return match ( true ) {
			null === $client_case                      => 'no_case',
			CaseStatus::Closed === $client_case->status => 'closed',
			null === $member                           => 'not_member',
			! self::authentic( $email, $headers )      => 'unverified',
			default                                    => '',
		};
	}

	/**
	 * Keep a reply in its case, with its files.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param array      $member      Sender.
	 * @param array      $letter      The reply.
	 * @phpstan-param Member $member
	 * @phpstan-param Letter $letter
	 * @return Outcome|WP_Error What happened to each file.
	 */
	private function store( CaseRecord $client_case, array $member, array $letter ): array|WP_Error {
		$outcome = array();

		foreach ( $letter['files'] as $file ) {
			$document = $member['may_add_files']
				? $this->app->document_service->store_from_mail( $client_case, $file['name'], $file['bytes'], $member['user_id'] )
				: new WP_Error( 'dx_forbidden' );

			$outcome[] = array(
				'name'        => $file['name'],
				'document_id' => $document instanceof WP_Error ? null : $document->id,
				'reason'      => $document instanceof WP_Error ? self::file_reason( $document ) : '',
			);
		}

		$now = $this->app->clock->mysql();
		$id  = $this->app->messages->insert(
			array(
				'case_id'      => $client_case->id,
				'user_id'      => $member['user_id'],
				'provider_id'  => $letter['provider_id'],
				'sender_name'  => $member['name'],
				'sender_email' => $letter['sender']['email'],
				'subject'      => $letter['subject'],
				'body'         => '' === $letter['reply'] ? $letter['full'] : $letter['reply'],
				'files'        => $outcome,
			),
			$now
		);

		if ( 0 === $id ) {
			Logger::error( 'inbound.store_failed', array( 'case' => $client_case->id ) );
			return Errors::storage();
		}

		$this->app->audit->record(
			'message.received',
			$client_case->id,
			'message',
			$id,
			array(
				'files' => count( array_filter( array_column( $outcome, 'document_id' ) ) ),
			),
			$now
		);

		return $outcome;
	}

	/**
	 * Forward the reply in full to the project manager (or the site's
	 * administrator when there is no case), with the files that may travel.
	 *
	 * @param CaseRecord|null $client_case Case.
	 * @param array           $letter      The reply.
	 * @param array           $stored      Files kept in the case.
	 * @param string          $reason      Why it was not kept, '' when it was.
	 * @phpstan-param Letter  $letter
	 * @phpstan-param Outcome $stored
	 */
	private function forward( ?CaseRecord $client_case, array $letter, array $stored, string $reason ): void {
		$manager = null === $client_case ? false : get_userdata( $client_case->manager_id );
		$to      = $manager ? (string) $manager->user_email : (string) get_option( 'admin_email' );
		$temp    = array();
		$attach  = array();
		$total   = 0;
		$notes   = array();

		foreach ( $letter['files'] as $index => $file ) {
			$kept  = $stored[ $index ]['document_id'] ?? null;
			$ext   = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
			$size  = strlen( $file['bytes'] );
			$sends = isset( DocumentService::TYPES[ $ext ] ) && $total + $size <= self::MAX_FORWARD;

			if ( $sends ) {
				$path = TempFile::create( 'dx-portal-forward' );

				if ( '' !== $path && false !== file_put_contents( $path, $file['bytes'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A private temporary copy for the forward, deleted right after.
					$label            = isset( $attach[ $file['name'] ] ) ? ( $index + 1 ) . '-' . $file['name'] : $file['name'];
					$temp[]           = $path;
					$attach[ $label ] = $path;
					$total           += $size;
				} else {
					$sends = false;
				}
			}

			$notes[] = array(
				'name'     => $file['name'],
				'attached' => $sends,
				'kept'     => null !== $kept,
			);
		}

		$message = $this->app->renderer->inbound(
			$client_case,
			array(
				'name'    => $letter['sender']['name'],
				'email'   => $letter['sender']['email'],
				'subject' => $letter['subject'],
				'text'    => $letter['full'],
				'files'   => $notes,
				'reason'  => $reason,
				'url'     => null === $client_case ? '' : Admin::case_url( $client_case->id, 'messages' ),
			)
		);

		$sender = $letter['sender']['email'];
		$name   = '' !== $letter['sender']['name'] ? $letter['sender']['name'] : $sender;

		try {
			$ok = Mailer::send(
				$to,
				$message,
				null,
				array(
					/* translators: %s: sender name. */
					'from_name'   => sprintf( __( '%s · דרך דירומקס', 'diromax-portal' ), $name ),
					'reply_to'    => $sender,
					'reply_name'  => $name,
					'attachments' => $attach,
				)
			);
		} finally {
			foreach ( $temp as $path ) {
				wp_delete_file( $path );
			}
		}

		$this->app->mail_log->add(
			array(
				'case_id'   => $client_case?->id,
				'user_id'   => $manager ? (int) $manager->ID : null,
				'recipient' => $to,
				'template'  => 'inbound',
				'subject'   => $message->subject,
				'batch_key' => '',
				'status'    => $ok ? 'sent' : 'failed',
				'error'     => $ok ? '' : 'wp_mail_failed',
			),
			$this->app->clock->mysql()
		);
	}

	/**
	 * Did the sender's own domain send this? Postmark reports its checks in
	 * the headers it adds: SpamAssassin's DKIM_VALID_AU (a valid DKIM
	 * signature of the author's domain), and Received-SPF.
	 *
	 * @param string                  $email   Sender address (From).
	 * @param array<string, string[]> $headers Headers.
	 * @return bool
	 */
	public static function authentic( string $email, array $headers ): bool {
		$domain = strtolower( (string) substr( (string) strrchr( $email, '@' ), 1 ) );

		if ( '' === $domain ) {
			return false;
		}

		$tests = array_map( 'trim', explode( ',', strtoupper( implode( ',', $headers['x-spam-tests'] ?? array() ) ) ) );

		if ( in_array( 'DKIM_VALID_AU', $tests, true ) ) {
			return true;
		}

		foreach ( $headers['received-spf'] ?? array() as $spf ) {
			if ( preg_match( '/^pass\b.*\benvelope-from=([^;\s]+)/i', $spf, $match ) ) {
				$envelope = strtolower( (string) substr( (string) strrchr( trim( $match[1], '<>"' ), '@' ), 1 ) );

				if ( $envelope === $domain ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Did Postmark's spam check mark it as spam?
	 *
	 * @param array<string, string[]> $headers Headers.
	 * @return bool
	 */
	private static function is_spam( array $headers ): bool {
		foreach ( $headers['x-spam-status'] ?? array() as $status ) {
			if ( str_starts_with( strtolower( trim( $status ) ), 'yes' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Headers by lower-case name.
	 *
	 * @param mixed $raw Postmark's list of {Name, Value}.
	 * @return array<string, string[]>
	 */
	private static function headers( $raw ): array {
		$headers = array();

		foreach ( is_array( $raw ) ? $raw : array() as $header ) {
			if ( is_array( $header ) && is_string( $header['Name'] ?? null ) && is_string( $header['Value'] ?? null ) ) {
				$headers[ strtolower( trim( $header['Name'] ) ) ][] = Text::line( $header['Value'] );
			}
		}

		return $headers;
	}

	/**
	 * The sender, from FromFull (or From).
	 *
	 * @param array<string, mixed> $mail Inbound JSON.
	 * @return array{email:string, name:string}
	 */
	private static function sender( array $mail ): array {
		$full  = is_array( $mail['FromFull'] ?? null ) ? $mail['FromFull'] : array();
		$email = sanitize_email( self::str( $full, 'Email' ) );

		if ( '' === $email ) {
			$email = sanitize_email( self::str( $mail, 'From' ) );
		}

		return array(
			'email' => is_email( $email ) ? strtolower( $email ) : '',
			'name'  => mb_substr( Text::line( '' !== self::str( $full, 'Name' ) ? self::str( $full, 'Name' ) : self::str( $mail, 'FromName' ) ), 0, 100 ),
		);
	}

	/**
	 * The text of the email: only the new reply, or all of it.
	 *
	 * @param array<string, mixed> $mail       Inbound JSON.
	 * @param bool                 $reply_only Postmark's StrippedTextReply.
	 * @return string
	 */
	private static function text( array $mail, bool $reply_only ): string {
		$text = $reply_only ? self::str( $mail, 'StrippedTextReply' ) : self::str( $mail, 'TextBody' );

		if ( ! $reply_only && '' === trim( $text ) ) {
			$text = wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>|</p>|</div>#i', "\n", self::str( $mail, 'HtmlBody' ) ) );
			$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		}

		return mb_substr( Text::block( $text ), 0, self::MAX_TEXT );
	}

	/**
	 * Attachments, decoded.
	 *
	 * @param mixed $raw Postmark's list of {Name, Content, ContentType}.
	 * @return array<int, array{name:string, bytes:string}>
	 */
	private static function files( $raw ): array {
		$files = array();

		foreach ( is_array( $raw ) ? $raw : array() as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$bytes = base64_decode( self::str( $attachment, 'Content' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Postmark sends attachments as base64.
			$name  = sanitize_file_name( Text::line( self::str( $attachment, 'Name' ) ) );

			if ( false === $bytes || '' === $bytes ) {
				continue;
			}

			$files[] = array(
				'name'  => '' === $name ? 'file-' . Ids::public_id() : mb_substr( $name, 0, 150 ),
				'bytes' => $bytes,
			);
		}

		return $files;
	}

	/**
	 * Why a file was not kept, for the message record.
	 *
	 * @param WP_Error $error Error.
	 * @return string type, too_large, not_allowed or storage.
	 */
	private static function file_reason( WP_Error $error ): string {
		if ( 'dx_forbidden' === $error->get_error_code() ) {
			return 'not_allowed';
		}

		$fields = Errors::fields( $error );

		return match ( $fields['file'] ?? '' ) {
			'type', 'rejected' => 'type',
			'too_large'        => 'too_large',
			default            => 'storage',
		};
	}

	/**
	 * A string field of the JSON.
	 *
	 * @param array<mixed> $data  Object.
	 * @param string       $field Field.
	 * @return string
	 */
	private static function str( array $data, string $field ): string {
		return is_string( $data[ $field ] ?? null ) ? $data[ $field ] : '';
	}

	/**
	 * The configured inbound address, '' when there is none.
	 *
	 * @return string
	 */
	private static function inbound(): string {
		$address = sanitize_email( MailConfig::get( 'inbound' ) );

		return is_email( $address ) ? strtolower( $address ) : '';
	}
}
