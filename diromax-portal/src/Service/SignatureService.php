<?php
/**
 * Digital signatures.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\DocumentRecord;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Domain\SignatureInput;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Security\RateLimiter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Simple electronic signatures of the system (spec 16.6, decision 14).
 *
 * - A signed-in owner or partner signs an agreement, a document marked for
 *   signature, or the approval of a decision: full name and a drawing (or
 *   the statement that they cannot draw). No code.
 * - Anyone else, including people outside the system, signs one document
 *   through an emailed link: a 6-digit code sent to the same address, full
 *   name, ID number and a drawing.
 *
 * Each signature keeps the SHA-256 of what was signed. A document whose
 * content no longer matches is not signed.
 */
final class SignatureService {

	/** Days a signing link is valid. */
	public const LINK_DAYS = 14;

	/** Seconds an email code is valid. */
	public const CODE_TTL = 900;

	/** Wrong codes before a new one is needed. */
	public const CODE_ATTEMPTS = 5;

	/** Codes sent per request in one window. */
	public const CODES_PER_WINDOW = 3;

	/** The code-sending window, seconds. */
	private const CODE_WINDOW = 900;

	/** Shape of a link token: 32 random bytes, URL-safe base64. */
	public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Is the document one the clients sign in the portal?
	 *
	 * @param DocumentRecord $document Document.
	 * @return bool
	 */
	public static function is_signable( DocumentRecord $document ): bool {
		return $document->visible && ( 'agreement' === $document->kind || $document->sign_required );
	}

	/**
	 * A signed-in client signs a document in the portal.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $document_id Document.
	 * @param int                   $user_id     Client.
	 * @param array<string, string> $input       name, image, no_draw, confirm.
	 * @return true|WP_Error
	 */
	public function sign_document( CaseRecord $client_case, int $document_id, int $user_id, array $input ): bool|WP_Error {
		$document = $this->app->documents->find( $document_id );

		if ( null === $document || $document->case_id !== $client_case->id || ! self::is_signable( $document ) || ! $this->may_sign( $client_case, $user_id ) ) {
			return Errors::not_found();
		}

		if ( $this->signed_by( $client_case->id, 'document', $document->id, $user_id, $document->sha256 ) ) {
			return true;
		}

		$checked = SignatureInput::check( $input, false );

		if ( $checked['errors'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$user = get_userdata( $user_id );
		$id   = $this->record( $client_case, 'document', $document->id, $document->sha256, $checked, $user_id, $user ? (string) $user->user_email : '', 'portal', null, null );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$this->tell_manager( $client_case, $document->title, $checked['name'], 'documents' );

		return true;
	}

	/**
	 * Check a portal signature before it is used (decision approvals).
	 *
	 * @param array<string, string> $input name, image, no_draw, confirm.
	 * @return array{name:string, image:string, id_number:string, errors:array<string, string>}
	 */
	public function check_portal( array $input ): array {
		return SignatureInput::check( $input, false );
	}

	/**
	 * Store a signature.
	 *
	 * @param CaseRecord                                                                       $client_case  Case.
	 * @param string                                                                           $subject_type document or decision.
	 * @param int                                                                              $subject_id   Subject.
	 * @param string                                                                           $sha256       Fingerprint of what was signed.
	 * @param array{name:string, image:string, id_number:string, errors:array<string, string>} $checked      Checked input.
	 * @param int|null                                                                         $user_id      Signer's account, if any.
	 * @param string                                                                           $email        Signer's address.
	 * @param string                                                                           $method       portal or link.
	 * @param int|null                                                                         $request_id   Signing request, for links.
	 * @param string|null                                                                      $verified_at  When the email code was verified.
	 * @return int|WP_Error Signature ID.
	 */
	public function record( CaseRecord $client_case, string $subject_type, int $subject_id, string $sha256, array $checked, ?int $user_id, string $email, string $method, ?int $request_id, ?string $verified_at ): int|WP_Error {
		$context = self::context( $client_case->id, $subject_type, $subject_id );
		$now     = $this->app->clock->mysql();

		try {
			$image = '' === $checked['image'] ? null : $this->app->store->seal( $checked['image'], 'image|' . $context );
			$id_no = '' === $checked['id_number'] ? null : $this->app->store->seal( $checked['id_number'], 'id|' . $context );
		} catch ( \RuntimeException $error ) {
			Logger::error( 'signature.seal_failed', array( 'reason' => $error->getMessage() ) );
			return Errors::storage();
		}

		$id = $this->app->signatures->insert(
			array(
				'case_id'       => $client_case->id,
				'subject_type'  => $subject_type,
				'subject_id'    => $subject_id,
				'request_id'    => $request_id,
				'user_id'       => $user_id,
				'signer_name'   => $checked['name'],
				'signer_email'  => mb_substr( $email, 0, 190 ),
				'id_number_enc' => $id_no,
				'image_enc'     => $image,
				'sha256'        => $sha256,
				'method'        => $method,
				'ip'            => mb_substr( RateLimiter::client_ip(), 0, 45 ),
				'user_agent'    => self::user_agent(),
				'verified_at'   => $verified_at,
				'signed_at'     => $now,
			)
		);

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record(
			'signature.signed',
			$client_case->id,
			$subject_type,
			$subject_id,
			array(
				'signature' => $id,
				'method'    => $method,
				'sha256'    => $sha256,
			),
			$now
		);

		return $id;
	}

	/**
	 * Send a document to be signed through an emailed link.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $document_id Document.
	 * @param array<string, string> $input       signer_name, signer_email.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error Request ID.
	 */
	public function request( CaseRecord $client_case, int $document_id, array $input, int $actor ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$document = $this->app->documents->find( $document_id );

		if ( null === $document || $document->case_id !== $client_case->id ) {
			return Errors::not_found();
		}

		$name   = Text::line( $input['signer_name'] ?? '' );
		$email  = sanitize_email( $input['signer_email'] ?? '' );
		$errors = array();

		if ( Text::length( $name ) < 2 || Text::length( $name ) > 100 ) {
			$errors['signer_name'] = '' === $name ? 'required' : 'invalid';
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['signer_email'] = 'invalid';
		}

		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		$token   = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes.
		$account = get_user_by( 'email', $email );
		$member  = $account && $this->app->members->is_member( $client_case->id, $account->ID ) ? $account->ID : null;
		$now     = $this->app->clock->mysql();
		$id      = $this->app->sign_requests->insert(
			array(
				'case_id'      => $client_case->id,
				'document_id'  => $document->id,
				'signer_name'  => $name,
				'signer_email' => $email,
				'user_id'      => $member,
				'token_hash'   => self::hash( $token ),
				'expires_at'   => $this->app->clock->mysql_in( self::LINK_DAYS * DAY_IN_SECONDS ),
				'created_by'   => $actor,
				'created_at'   => $now,
			)
		);

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'signature.requested', $client_case->id, 'document', $document->id, array( 'request' => $id ), $now );

		$manager = get_userdata( $client_case->manager_id );
		$message = $this->app->renderer->sign_request( $name, $client_case, $document->title, Router::sign_url( $token ), $manager ? (string) $manager->display_name : '' );

		$this->log_mail( $client_case->id, $member, $email, 'sign_request', $message->subject, Mailer::send( $email, $message, $manager ? $manager : null ) );

		return $id;
	}

	/**
	 * Cancel a pending signing request; its link stops working.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Request.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function cancel( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$request = $this->app->sign_requests->find( $id );

		if ( null === $request || $request['case_id'] !== $client_case->id ) {
			return Errors::not_found();
		}

		$now = $this->app->clock->mysql();

		if ( ! $this->app->sign_requests->close( $id, 'cancelled', $now ) ) {
			return new WP_Error( 'dx_not_open', __( 'הבקשה כבר נחתמה או בוטלה.', 'diromax-portal' ) );
		}

		$this->app->audit->record( 'signature.cancelled', $client_case->id, 'document', $request['document_id'], array( 'request' => $id ), $now );

		return true;
	}

	/**
	 * The open request, document and case behind a link, or null when the
	 * link is malformed, unknown, used, cancelled or expired.
	 *
	 * @param string $token Token from the URL.
	 * @return array{request: array<string, mixed>, document: DocumentRecord, case: CaseRecord}|null
	 */
	public function by_token( string $token ): ?array {
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}

		$request = $this->app->sign_requests->find_by_token( self::hash( $token ) );

		if ( null === $request || 'pending' !== $request['status'] || $request['expires_at'] <= $this->app->clock->mysql() ) {
			return null;
		}

		$document    = $this->app->documents->find( $request['document_id'] );
		$client_case = $this->app->cases->find( $request['case_id'] );

		if ( null === $document || null === $client_case || $document->case_id !== $client_case->id ) {
			return null;
		}

		return array(
			'request'  => $request,
			'document' => $document,
			'case'     => $client_case,
		);
	}

	/**
	 * Email a 6-digit code to the address the link was sent to.
	 *
	 * @param string $token Link token.
	 * @return true|WP_Error
	 */
	public function send_code( string $token ): bool|WP_Error {
		$link = $this->by_token( $token );

		if ( null === $link ) {
			return Errors::not_found();
		}

		$request = $link['request'];
		$now     = $this->app->clock->mysql();
		$window  = (string) ( $request['codes_window_at'] ?? '' );
		$fresh   = '' === $window || $window <= $this->app->clock->mysql_in( -self::CODE_WINDOW );
		$sent    = $fresh ? 0 : (int) $request['codes_sent'];

		if ( $sent >= self::CODES_PER_WINDOW ) {
			return new WP_Error( 'dx_rate_limited', __( 'נשלחו כבר כמה קודים ברבע השעה האחרונה. אפשר לבקש קוד חדש בעוד כמה דקות.', 'diromax-portal' ) );
		}

		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		$this->app->sign_requests->set_code( (int) $request['id'], self::code_hash( $code, (string) $request['token_hash'] ), $this->app->clock->mysql_in( self::CODE_TTL ), $sent + 1, $fresh ? $now : $window );
		$this->app->audit->record( 'signature.code_sent', $link['case']->id, 'document', $link['document']->id, array( 'request' => $request['id'] ), $now );

		$message = $this->app->renderer->sign_code( (string) $request['signer_name'], $code );

		$this->log_mail( $link['case']->id, null === $request['user_id'] ? null : (int) $request['user_id'], (string) $request['signer_email'], 'sign_code', $message->subject, Mailer::send( (string) $request['signer_email'], $message, null ) );

		return true;
	}

	/**
	 * Sign through a link: the code, then the signer's details.
	 *
	 * @param string                $token Link token.
	 * @param array<string, string> $input code, name, id_number, image, no_draw, confirm.
	 * @return true|WP_Error
	 */
	public function sign_with_link( string $token, array $input ): bool|WP_Error {
		$link = $this->by_token( $token );

		if ( null === $link ) {
			return Errors::not_found();
		}

		$request = $link['request'];
		$code    = preg_replace( '/\D/', '', (string) ( $input['code'] ?? '' ) );

		if ( '' === $request['code_hash'] || null === $request['code_expires_at'] || $request['code_expires_at'] <= $this->app->clock->mysql() ) {
			return Errors::invalid( array( 'code' => 'expired' ) );
		}

		if ( ! hash_equals( (string) $request['code_hash'], self::code_hash( (string) $code, (string) $request['token_hash'] ) ) ) {
			$attempts = $this->app->sign_requests->wrong_code( (int) $request['id'], self::CODE_ATTEMPTS );

			return Errors::invalid( array( 'code' => $attempts >= self::CODE_ATTEMPTS ? 'expired' : 'wrong' ) );
		}

		$checked = SignatureInput::check( $input, true );

		if ( $checked['errors'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$now = $this->app->clock->mysql();

		if ( ! $this->app->sign_requests->close( (int) $request['id'], 'signed', $now, true ) ) {
			return Errors::not_found();
		}

		$document = $link['document'];
		$user_id  = null === $request['user_id'] ? null : (int) $request['user_id'];
		$id       = $this->record( $link['case'], 'document', $document->id, $document->sha256, $checked, $user_id, (string) $request['signer_email'], 'link', (int) $request['id'], $now );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$copy = $this->app->renderer->signed_copy( $checked['name'], $document->title, $document->sha256, Labels::datetime( $now ) );
		$this->log_mail( $link['case']->id, $user_id, (string) $request['signer_email'], 'signed_copy', $copy->subject, Mailer::send( (string) $request['signer_email'], $copy, null ) );
		$this->tell_manager( $link['case'], $document->title, $checked['name'], 'documents' );

		return true;
	}

	/**
	 * Has the user signed this subject, as it is now?
	 *
	 * @param int    $case_id      Case.
	 * @param string $subject_type document or decision.
	 * @param int    $subject_id   Subject.
	 * @param int    $user_id      User.
	 * @param string $sha256       Current fingerprint.
	 * @return bool
	 */
	public function signed_by( int $case_id, string $subject_type, int $subject_id, int $user_id, string $sha256 ): bool {
		foreach ( $this->app->signatures->for_subject( $case_id, $subject_type, $subject_id ) as $signature ) {
			if ( $signature['user_id'] === $user_id && hash_equals( $signature['sha256'], $sha256 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Valid signatures of a document: those of its current content.
	 *
	 * @param DocumentRecord $document Document.
	 * @return array<int, array<string, mixed>>
	 */
	public function of_document( DocumentRecord $document ): array {
		return array_values(
			array_filter(
				$this->app->signatures->for_subject( $document->case_id, 'document', $document->id ),
				static fn( array $signature ): bool => hash_equals( $signature['sha256'], $document->sha256 )
			)
		);
	}

	/**
	 * Documents the member still has to sign.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $user_id     Member.
	 * @return DocumentRecord[]
	 */
	public function waiting_for( CaseRecord $client_case, int $user_id ): array {
		if ( ! $this->may_sign( $client_case, $user_id ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$this->app->documents->for_case( $client_case->id, true ),
				fn( DocumentRecord $document ): bool => self::is_signable( $document ) && ! $this->signed_by( $client_case->id, 'document', $document->id, $user_id, $document->sha256 )
			)
		);
	}

	/**
	 * May this user sign in this case's portal: an owner or partner, not staff?
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $user_id     User.
	 * @return bool
	 */
	public function may_sign( CaseRecord $client_case, int $user_id ): bool {
		return $this->app->policy->can_view_case( $user_id, $client_case )
			&& ! $this->app->policy->can_use_admin( $user_id )
			&& DecisionService::may_answer( $this->app->members->role( $client_case->id, $user_id ) );
	}

	/**
	 * The drawn signature, decrypted, or null.
	 *
	 * @param array<string, mixed> $signature Signature row.
	 * @return string|null PNG bytes.
	 */
	public function image( array $signature ): ?string {
		$sealed = (string) ( $signature['image_enc'] ?? '' );

		return '' === $sealed ? null : $this->app->store->unseal( $sealed, 'image|' . self::context( (int) $signature['case_id'], (string) $signature['subject_type'], (int) $signature['subject_id'] ) );
	}

	/**
	 * The ID number, decrypted, or null.
	 *
	 * @param array<string, mixed> $signature Signature row.
	 * @return string|null
	 */
	public function id_number( array $signature ): ?string {
		$sealed = (string) ( $signature['id_number_enc'] ?? '' );

		return '' === $sealed ? null : $this->app->store->unseal( $sealed, 'id|' . self::context( (int) $signature['case_id'], (string) $signature['subject_type'], (int) $signature['subject_id'] ) );
	}

	/**
	 * Email the project manager that something was signed.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $title       What was signed.
	 * @param string     $signer      Who signed.
	 * @param string     $tab         Admin tab to open.
	 */
	public function tell_manager( CaseRecord $client_case, string $title, string $signer, string $tab ): void {
		$card = new MailCard(
			tone: 'teal',
			badge: __( 'נחתם', 'diromax-portal' ),
			title: $title,
			rows: array( array( __( 'מי חתם', 'diromax-portal' ), $signer ) ),
			amount: null,
			note: '',
			stage: null,
			link: null,
			subject: __( 'מסמך נחתם', 'diromax-portal' ),
		);

		$this->app->notifier->send_to_manager( $client_case, $this->app->renderer->manager_notice( $client_case, $card, Admin::case_url( $client_case->id, $tab ) ) );
	}

	/**
	 * Log an email of the signing flow.
	 *
	 * @param int      $case_id  Case.
	 * @param int|null $user_id  Recipient's account, if any.
	 * @param string   $email    Address.
	 * @param string   $template Template code.
	 * @param string   $subject  Subject.
	 * @param bool     $ok       Sent.
	 */
	private function log_mail( int $case_id, ?int $user_id, string $email, string $template, string $subject, bool $ok ): void {
		if ( ! $ok ) {
			Logger::error( 'signature.mail_failed', array( 'template' => $template ) );
		}

		$this->app->mail_log->add(
			array(
				'case_id'   => $case_id,
				'user_id'   => $user_id,
				'recipient' => $email,
				'template'  => $template,
				'subject'   => $subject,
				'batch_key' => '',
				'status'    => $ok ? 'sent' : 'failed',
				'error'     => $ok ? '' : 'wp_mail_failed',
			),
			$this->app->clock->mysql()
		);
	}

	/**
	 * What a sealed field belongs to.
	 *
	 * @param int    $case_id      Case.
	 * @param string $subject_type Subject type.
	 * @param int    $subject_id   Subject.
	 * @return string
	 */
	private static function context( int $case_id, string $subject_type, int $subject_id ): string {
		return 'signature|case:' . $case_id . '|' . $subject_type . ':' . $subject_id;
	}

	/**
	 * Hash of a link token, as stored.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Hash of an email code, bound to its request.
	 *
	 * @param string $code       Code.
	 * @param string $token_hash The request's token hash.
	 * @return string
	 */
	private static function code_hash( string $code, string $token_hash ): string {
		return hash_hmac( 'sha256', $code, $token_hash . wp_salt( 'auth' ) );
	}

	/**
	 * The signer's browser, as sent.
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return mb_substr( $agent, 0, 255 );
	}
}
