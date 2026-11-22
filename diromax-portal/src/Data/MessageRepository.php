<?php
/**
 * Messages table: client replies by email.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A client's email reply, kept in the case (spec 17.3): who sent it, the
 * subject, the reply text and the files that came with it. The files
 * themselves are case documents; this row lists them by ID, with the
 * names of files that were not kept and why.
 *
 * @phpstan-type MessageFile array{name:string, document_id:int|null, reason:string}
 * @phpstan-type MessageRow array{id:int, case_id:int, user_id:int|null, provider_id:string, sender_name:string, sender_email:string, subject:string, body:string, files:MessageFile[], stage:string, received_at:string}
 */
final class MessageRepository {

	/** Columns read back. */
	private const COLUMNS = 'id, case_id, user_id, provider_id, sender_name, sender_email, subject, body, files, stage, received_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Store a new message, before its files: stage "saving".
	 *
	 * @param array{case_id:int, user_id:int|null, provider_id:string, sender_name:string, sender_email:string, subject:string, body:string} $fields Fields.
	 * @param string                                                                                                                         $now    UTC DATETIME.
	 * @return int New ID; 0 when it failed or the message is already stored.
	 */
	public function insert( array $fields, string $now ): int {
		$ok = $this->db->insert(
			Schema::table( 'messages' ),
			array(
				'case_id'      => $fields['case_id'],
				'user_id'      => $fields['user_id'],
				'provider_id'  => mb_substr( $fields['provider_id'], 0, 64 ),
				'sender_name'  => mb_substr( $fields['sender_name'], 0, 100 ),
				'sender_email' => mb_substr( $fields['sender_email'], 0, 190 ),
				'subject'      => mb_substr( $fields['subject'], 0, 255 ),
				'body'         => $fields['body'],
				'files'        => '[]',
				'stage'        => 'saving',
				'received_at'  => $now,
			)
		);

		return false === $ok ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Record the files handled so far.
	 *
	 * @param int           $id    Message.
	 * @param MessageFile[] $files Files, in the order of the email.
	 */
	public function set_files( int $id, array $files ): void {
		$this->db->update( Schema::table( 'messages' ), array( 'files' => (string) wp_json_encode( array_values( $files ) ) ), array( 'id' => $id ) );
	}

	/**
	 * Move a message to its next stage: saved (files done) or forwarded.
	 *
	 * @param int    $id    Message.
	 * @param string $stage saved or forwarded.
	 */
	public function set_stage( int $id, string $stage ): void {
		$this->db->update( Schema::table( 'messages' ), array( 'stage' => $stage ), array( 'id' => $id ) );
	}

	/**
	 * A stored message by its provider ID.
	 *
	 * @param string $provider_id Provider message ID.
	 * @return MessageRow|null
	 */
	public function find_by_provider( string $provider_id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE provider_id = %s', Schema::table( 'messages' ), $provider_id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Messages of a case, newest first; one still saving its files is left out.
	 *
	 * @param int $case_id Case.
	 * @param int $limit   Maximum rows, 1–500.
	 * @return MessageRow[]
	 */
	public function for_case( int $case_id, int $limit = 200 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i WHERE case_id = %d AND stage <> 'saving' ORDER BY received_at DESC, id DESC LIMIT %d",
				Schema::table( 'messages' ),
				$case_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Messages in a case.
	 *
	 * @param int $case_id Case.
	 * @return int
	 */
	public function count_for_case( int $case_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM %i WHERE case_id = %d AND stage <> 'saving'", Schema::table( 'messages' ), $case_id )
		);
	}

	/**
	 * Messages a user sent (privacy export).
	 *
	 * @param int $user_id User.
	 * @return MessageRow[]
	 */
	public function for_user( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 1000', Schema::table( 'messages' ), $user_id ),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Delete the messages a user sent (privacy erasure).
	 *
	 * @param int $user_id User.
	 * @return int Rows removed.
	 */
	public function remove_user( int $user_id ): int {
		$removed = $this->db->delete( Schema::table( 'messages' ), array( 'user_id' => $user_id ) );

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return MessageRow
	 */
	private static function map( array $row ): array {
		$files = json_decode( (string) $row['files'], true );

		return array(
			'id'           => (int) $row['id'],
			'case_id'      => (int) $row['case_id'],
			'user_id'      => null === $row['user_id'] ? null : (int) $row['user_id'],
			'provider_id'  => (string) $row['provider_id'],
			'sender_name'  => (string) $row['sender_name'],
			'sender_email' => (string) $row['sender_email'],
			'subject'      => (string) $row['subject'],
			'body'         => (string) $row['body'],
			'stage'        => (string) $row['stage'],
			'files'        => array_values(
				array_map(
					static fn( $file ): array => array(
						'name'        => is_array( $file ) ? (string) ( $file['name'] ?? '' ) : '',
						'document_id' => is_array( $file ) && isset( $file['document_id'] ) ? (int) $file['document_id'] : null,
						'reason'      => is_array( $file ) ? (string) ( $file['reason'] ?? '' ) : '',
					),
					is_array( $files ) ? $files : array()
				)
			),
			'received_at'  => (string) $row['received_at'],
		);
	}
}
