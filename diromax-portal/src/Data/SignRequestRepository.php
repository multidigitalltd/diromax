<?php
/**
 * Signature requests table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * A request to sign one document through an emailed link (spec 16.6). The
 * link token and the email code are stored only as hashes.
 *
 * @phpstan-type SignRequest array{id:int, case_id:int, document_id:int, signer_name:string, signer_email:string, user_id:int|null, token_hash:string, status:string, code_hash:string, code_expires_at:string|null, code_attempts:int, codes_sent:int, codes_window_at:string|null, verified_at:string|null, expires_at:string, created_by:int, created_at:string, closed_at:string|null}
 */
final class SignRequestRepository {

	/** Columns read back. */
	private const COLUMNS = 'id, case_id, document_id, signer_name, signer_email, user_id, token_hash, status, code_hash, code_expires_at, code_attempts, codes_sent, codes_window_at, verified_at, expires_at, created_by, created_at, closed_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Add a request.
	 *
	 * @param array{case_id:int, document_id:int, signer_name:string, signer_email:string, user_id:int|null, token_hash:string, expires_at:string, created_by:int, created_at:string} $row Fields.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( array $row ): int {
		return false === $this->db->insert( Schema::table( 'sign_requests' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * One request.
	 *
	 * @param int $id Request.
	 * @return SignRequest|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'sign_requests' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * The request a link token belongs to.
	 *
	 * @param string $token_hash SHA-256 of the token.
	 * @return SignRequest|null
	 */
	public function find_by_token( string $token_hash ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE token_hash = %s', Schema::table( 'sign_requests' ), $token_hash ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Requests of a case, newest first.
	 *
	 * @param int $case_id Case.
	 * @return SignRequest[]
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE case_id = %d ORDER BY id DESC LIMIT 500', Schema::table( 'sign_requests' ), $case_id ),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Store a new email code (as a hash) and count it in the sending window.
	 *
	 * @param int    $id         Request.
	 * @param string $code_hash  Hash of the code.
	 * @param string $expires_at UTC DATETIME.
	 * @param int    $codes_sent Codes sent in the current window, this one included.
	 * @param string $window_at  UTC DATETIME the window started.
	 * @return bool
	 */
	public function set_code( int $id, string $code_hash, string $expires_at, int $codes_sent, string $window_at ): bool {
		return false !== $this->db->update(
			Schema::table( 'sign_requests' ),
			array(
				'code_hash'       => $code_hash,
				'code_expires_at' => $expires_at,
				'code_attempts'   => 0,
				'codes_sent'      => $codes_sent,
				'codes_window_at' => $window_at,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Count a wrong code; at the limit, the code is dropped.
	 *
	 * @param int $id    Request.
	 * @param int $limit Wrong attempts allowed.
	 * @return int Attempts so far.
	 */
	public function wrong_code( int $id, int $limit ): int {
		Db::write( $this->db, 'UPDATE %i SET code_attempts = code_attempts + 1 WHERE id = %d', Schema::table( 'sign_requests' ), $id );
		Db::write( $this->db, "UPDATE %i SET code_hash = '', code_expires_at = NULL WHERE id = %d AND code_attempts >= %d", Schema::table( 'sign_requests' ), $id, $limit );

		return (int) ( $this->find( $id )['code_attempts'] ?? $limit );
	}

	/**
	 * Close a pending request: signed or cancelled. Only one close wins.
	 *
	 * @param int    $id          Request.
	 * @param string $status      signed or cancelled.
	 * @param string $now         UTC DATETIME.
	 * @param bool   $verified    The email code was verified now.
	 * @return bool Whether this call closed it.
	 */
	public function close( int $id, string $status, string $now, bool $verified = false ): bool {
		$changed = $verified
			? Db::write( $this->db, "UPDATE %i SET status = %s, closed_at = %s, verified_at = %s, code_hash = '' WHERE id = %d AND status = 'pending'", Schema::table( 'sign_requests' ), $status, $now, $now, $id )
			: Db::write( $this->db, "UPDATE %i SET status = %s, closed_at = %s, code_hash = '' WHERE id = %d AND status = 'pending'", Schema::table( 'sign_requests' ), $status, $now, $id );

		return 1 === $changed;
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return SignRequest
	 */
	private static function map( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'case_id'         => (int) $row['case_id'],
			'document_id'     => (int) $row['document_id'],
			'signer_name'     => (string) $row['signer_name'],
			'signer_email'    => (string) $row['signer_email'],
			'user_id'         => null === $row['user_id'] ? null : (int) $row['user_id'],
			'token_hash'      => (string) $row['token_hash'],
			'status'          => (string) $row['status'],
			'code_hash'       => (string) $row['code_hash'],
			'code_expires_at' => null === $row['code_expires_at'] ? null : (string) $row['code_expires_at'],
			'code_attempts'   => (int) $row['code_attempts'],
			'codes_sent'      => (int) $row['codes_sent'],
			'codes_window_at' => null === $row['codes_window_at'] ? null : (string) $row['codes_window_at'],
			'verified_at'     => null === $row['verified_at'] ? null : (string) $row['verified_at'],
			'expires_at'      => (string) $row['expires_at'],
			'created_by'      => (int) $row['created_by'],
			'created_at'      => (string) $row['created_at'],
			'closed_at'       => null === $row['closed_at'] ? null : (string) $row['closed_at'],
		);
	}
}
