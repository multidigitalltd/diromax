<?php
/**
 * The conversation of a case, written on the site.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Admin\Admin;
use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\Logger;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Support\Ids;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Messages between the clients of a case and its project manager (spec
 * 18.1), in one thread with the clients' email replies (spec 17.3).
 *
 * An owner or a partner writes in the portal; the project manager gets the
 * whole message by email, answering to the client. The manager replies from
 * the case; every client of the case who receives case email gets the whole
 * reply, and answering that email comes back into the case. A viewer reads
 * the thread but does not write.
 *
 * @phpstan-import-type MessageRow from \Diromax\Portal\Data\MessageRepository
 */
final class MessageService {

	/** Longest message, characters. */
	public const MAX_LENGTH = 5000;

	/** Messages a client may write per hour. */
	private const PER_HOUR = 20;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * A client writes to the project manager from the portal.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $user_id     Client.
	 * @param string     $body        Text.
	 * @return int|WP_Error Message ID.
	 */
	public function post( CaseRecord $client_case, int $user_id, string $body ): int|WP_Error {
		if ( ! $this->app->policy->can_view_case( $user_id, $client_case ) || $this->app->policy->can_use_admin( $user_id ) ) {
			return Errors::not_found();
		}

		if ( ! self::may_write( $this->app->members->role( $client_case->id, $user_id ) ) || CaseStatus::Closed === $client_case->status ) {
			return Errors::forbidden();
		}

		$body = self::text( $body );

		if ( $body instanceof WP_Error ) {
			return $body;
		}

		if ( ! $this->app->limiter->attempt( 'portal_message', (string) $user_id, self::PER_HOUR, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'dx_rate_limited', __( 'נשלחו הרבה הודעות בשעה האחרונה. אפשר להמשיך בעוד שעה.', 'diromax-portal' ) );
		}

		$user = get_userdata( $user_id );
		$name = $user ? (string) $user->display_name : '';
		$id   = $this->save( $client_case, $user_id, $name, $user ? (string) $user->user_email : '', $body, 'in' );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$this->app->audit->record( 'message.posted', $client_case->id, 'message', $id, array(), $this->app->clock->mysql() );

		if ( $user && $this->tell_manager( $client_case, $name, (string) $user->user_email, $body ) ) {
			$this->app->messages->set_stage( $id, 'forwarded' );
		}

		return $id;
	}

	/**
	 * The project manager answers in the case; the clients get it by email.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $actor       Staff member.
	 * @param string     $body        Text.
	 * @return int|WP_Error Message ID.
	 */
	public function reply( CaseRecord $client_case, int $actor, string $body ): int|WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		$body = self::text( $body );

		if ( $body instanceof WP_Error ) {
			return $body;
		}

		$staff = get_userdata( $actor );
		$name  = $staff ? (string) $staff->display_name : '';
		$id    = $this->save( $client_case, $actor, $name, $staff ? (string) $staff->user_email : '', $body, 'out' );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$sent = 0;

		foreach ( $this->app->members->for_case( $client_case->id ) as $member ) {
			if ( ! $member->notify || NotifyMode::Off === $member->mode() || '' === $member->email ) {
				continue;
			}

			$message = $this->app->renderer->staff_reply( $client_case, $member->name, $name, $body, Router::case_url( $client_case, 'messages' ) );
			$sent   += $this->app->notifier->send_to_member( $client_case, $member->user_id, $member->email, 'reply', $message, '' ) ? 1 : 0;
		}

		$this->app->messages->set_stage( $id, 'forwarded' );
		$this->app->audit->record( 'message.replied', $client_case->id, 'message', $id, array( 'emailed' => $sent ), $this->app->clock->mysql() );

		return $id;
	}

	/**
	 * The thread for a screen, oldest first. Files a client sent by email
	 * link to their documents; on the client's screen only shown ones do.
	 * Staff screens also show the address each client message came from.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $viewer      Viewer.
	 * @param bool       $staff       Built for a staff screen.
	 * @return array<int, array{id:int, mine:bool, staff:bool, author:string, address:string, when:string, iso:string, subject:string, body:string, email:bool, files:array<int, array{name:string, url:string, reason:string}>}>
	 */
	public function thread( CaseRecord $client_case, int $viewer, bool $staff ): array {
		$list = array();

		foreach ( $this->app->messages->thread( $client_case->id ) as $message ) {
			$list[] = array(
				'id'      => $message['id'],
				'mine'    => $message['user_id'] === $viewer,
				'staff'   => 'out' === $message['direction'],
				'author'  => $message['sender_name'],
				'address' => $staff && 'in' === $message['direction'] ? $message['sender_email'] : '',
				'when'    => Labels::datetime( $message['received_at'] ),
				'iso'     => str_replace( ' ', 'T', $message['received_at'] ) . 'Z',
				'subject' => $message['subject'],
				'body'    => $message['body'],
				'email'   => 'email' === $message['channel'],
				'files'   => $this->files( $client_case, $message, $staff ),
			);
		}

		return $list;
	}

	/**
	 * May someone in this role write? Owners and partners; viewers read.
	 *
	 * @param \Diromax\Portal\Domain\MemberRole|null $role Role in the case.
	 * @return bool
	 */
	public static function may_write( ?\Diromax\Portal\Domain\MemberRole $role ): bool {
		return DecisionService::may_answer( $role );
	}

	/**
	 * Store a message written on the site.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $user_id     Author.
	 * @param string     $name        Author's name.
	 * @param string     $email       Author's address.
	 * @param string     $body        Checked text.
	 * @param string     $direction   in (client) or out (staff).
	 * @return int|WP_Error
	 */
	private function save( CaseRecord $client_case, int $user_id, string $name, string $email, string $body, string $direction ): int|WP_Error {
		$id = $this->app->messages->insert(
			array(
				'case_id'      => $client_case->id,
				'user_id'      => $user_id,
				'provider_id'  => 'dx-' . Ids::public_id(),
				'sender_name'  => $name,
				'sender_email' => $email,
				'subject'      => '',
				'body'         => $body,
				'direction'    => $direction,
				'channel'      => 'portal',
				'stage'        => 'saved',
			),
			$this->app->clock->mysql()
		);

		if ( 0 === $id ) {
			Logger::error( 'message.store_failed', array( 'case' => $client_case->id ) );
			return Errors::storage();
		}

		return $id;
	}

	/**
	 * Email the whole message to the project manager, answering to the client.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $name        Client's name.
	 * @param string     $email       Client's address.
	 * @param string     $body        Text.
	 * @return bool Sent.
	 */
	private function tell_manager( CaseRecord $client_case, string $name, string $email, string $body ): bool {
		$manager = get_userdata( $client_case->manager_id );

		if ( ! $manager ) {
			return false;
		}

		$message = $this->app->renderer->inbound(
			$client_case,
			array(
				'name'    => $name,
				'email'   => $email,
				'subject' => '',
				'text'    => $body,
				'files'   => array(),
				'reason'  => '',
				'url'     => Admin::case_url( $client_case->id, 'messages' ),
				'channel' => 'portal',
			)
		);
		$ok      = Mailer::send(
			(string) $manager->user_email,
			$message,
			null,
			array(
				/* translators: %s: client name. */
				'from_name'  => sprintf( __( '%s · דרך דירומקס', 'diromax-portal' ), '' !== $name ? $name : $email ),
				'reply_to'   => $email,
				'reply_name' => $name,
			)
		);

		$this->app->mail_log->add(
			array(
				'case_id'   => $client_case->id,
				'user_id'   => (int) $manager->ID,
				'recipient' => (string) $manager->user_email,
				'template'  => 'message',
				'subject'   => $message->subject,
				'batch_key' => '',
				'status'    => $ok ? 'sent' : 'failed',
				'error'     => $ok ? '' : 'wp_mail_failed',
			),
			$this->app->clock->mysql()
		);

		return $ok;
	}

	/**
	 * Files of a message, with a link where the viewer may open them.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param array      $message     Message.
	 * @param bool       $staff       For a staff screen.
	 * @phpstan-param MessageRow $message
	 * @return array<int, array{name:string, url:string, reason:string}>
	 */
	private function files( CaseRecord $client_case, array $message, bool $staff ): array {
		$files = array();

		foreach ( $message['files'] as $file ) {
			$document = null === $file['document_id'] ? null : $this->app->documents->find( $file['document_id'] );
			$url      = '';

			if ( null !== $document && $document->case_id === $client_case->id && ( $staff || $document->visible ) ) {
				$url = $staff ? Admin::document_url( $document->id ) : Router::document_url( $document );
			}

			$files[] = array(
				'name'   => $file['name'],
				'url'    => $url,
				'reason' => $file['reason'],
			);
		}

		return $files;
	}

	/**
	 * Checked message text.
	 *
	 * @param string $body Raw text.
	 * @return string|WP_Error
	 */
	private static function text( string $body ): string|WP_Error {
		$body = Text::block( $body );

		if ( '' === $body ) {
			return Errors::invalid( array( 'body' => 'required' ) );
		}

		if ( Text::length( $body ) > self::MAX_LENGTH ) {
			return Errors::invalid( array( 'body' => 'too_long' ) );
		}

		return $body;
	}
}
