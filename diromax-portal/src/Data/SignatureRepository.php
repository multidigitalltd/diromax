<?php
/**
 * Signatures table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Signatures (spec 16.6): who signed what, with the fingerprint of what was
 * signed. The ID number and the drawing are stored sealed (encrypted).
 *
 * @phpstan-type SignatureRow array{id:int, case_id:int, subject_type:string, subject_id:int, request_id:int|null, user_id:int|null, signer_name:string, signer_email:string, id_number_enc:string, image_enc:string, sha256:string, method:string, ip:string, user_agent:string, verified_at:string|null, signed_at:string}
 */
final class SignatureRepository {

	/** Columns read back. */
	private const COLUMNS = 'id, case_id, subject_type, subject_id, request_id, user_id, signer_name, signer_email, id_number_enc, image_enc, sha256, method, ip, user_agent, verified_at, signed_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Record a signature.
	 *
	 * @param array{case_id:int, subject_type:string, subject_id:int, request_id:int|null, user_id:int|null, signer_name:string, signer_email:string, id_number_enc:string|null, image_enc:string|null, sha256:string, method:string, ip:string, user_agent:string, verified_at:string|null, signed_at:string} $row Fields.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( array $row ): int {
		return false === $this->db->insert( Schema::table( 'signatures' ), $row ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * One signature.
	 *
	 * @param int $id Signature.
	 * @return SignatureRow|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'signatures' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Signatures of a case, oldest first.
	 *
	 * @param int $case_id Case.
	 * @return SignatureRow[]
	 */
	public function for_case( int $case_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE case_id = %d ORDER BY signed_at, id LIMIT 1000', Schema::table( 'signatures' ), $case_id ),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Signatures of one document or decision, oldest first.
	 *
	 * @param int    $case_id      Case.
	 * @param string $subject_type document or decision.
	 * @param int    $subject_id   Subject.
	 * @return SignatureRow[]
	 */
	public function for_subject( int $case_id, string $subject_type, int $subject_id ): array {
		return array_values(
			array_filter(
				$this->for_case( $case_id ),
				static fn( array $row ): bool => $row['subject_type'] === $subject_type && $row['subject_id'] === $subject_id
			)
		);
	}

	/**
	 * Signatures given with an email address, for the privacy export: what
	 * and when, without the sealed ID number or drawing.
	 *
	 * @param string $email Address.
	 * @return array<int, array<string, string|null>>
	 */
	public function by_email( string $email ): array {
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT case_id, subject_type, subject_id, signer_name, signer_email, method, signed_at FROM %i WHERE signer_email = %s ORDER BY signed_at LIMIT 1000',
				Schema::table( 'signatures' ),
				$email
			),
			ARRAY_A
		);
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return SignatureRow
	 */
	private static function map( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'case_id'       => (int) $row['case_id'],
			'subject_type'  => (string) $row['subject_type'],
			'subject_id'    => (int) $row['subject_id'],
			'request_id'    => null === $row['request_id'] ? null : (int) $row['request_id'],
			'user_id'       => null === $row['user_id'] ? null : (int) $row['user_id'],
			'signer_name'   => (string) $row['signer_name'],
			'signer_email'  => (string) $row['signer_email'],
			'id_number_enc' => (string) $row['id_number_enc'],
			'image_enc'     => (string) $row['image_enc'],
			'sha256'        => (string) $row['sha256'],
			'method'        => (string) $row['method'],
			'ip'            => (string) $row['ip'],
			'user_agent'    => (string) $row['user_agent'],
			'verified_at'   => null === $row['verified_at'] ? null : (string) $row['verified_at'],
			'signed_at'     => (string) $row['signed_at'],
		);
	}
}
