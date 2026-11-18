<?php
/**
 * Login tokens table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * One-time sign-in tokens. Only a keyed hash is stored, never the token.
 */
final class TokenRepository {

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Store a token hash.
	 *
	 * @param int    $user_id    User.
	 * @param string $purpose    login or invite.
	 * @param string $hash       Keyed SHA-256 of the token, hex.
	 * @param string $expires_at UTC DATETIME.
	 * @param string $now        UTC DATETIME.
	 */
	public function add( int $user_id, string $purpose, string $hash, string $expires_at, string $now ): void {
		$this->db->insert(
			Schema::table( 'login_tokens' ),
			array(
				'user_id'    => $user_id,
				'purpose'    => $purpose,
				'token_hash' => $hash,
				'expires_at' => $expires_at,
				'created_at' => $now,
			)
		);
	}

	/**
	 * Unused, unexpired token by hash.
	 *
	 * @param string $hash Keyed hash.
	 * @param string $now  UTC DATETIME.
	 * @return array{id:int, user_id:int, purpose:string}|null
	 */
	public function find_valid( string $hash, string $now ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT id, user_id, purpose FROM %i WHERE token_hash = %s AND used_at IS NULL AND expires_at > %s',
				Schema::table( 'login_tokens' ),
				$hash,
				$now
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'      => (int) $row['id'],
			'user_id' => (int) $row['user_id'],
			'purpose' => (string) $row['purpose'],
		);
	}

	/**
	 * Mark a token used. Atomic: of two concurrent uses only one succeeds.
	 *
	 * @param int    $id  Token row.
	 * @param string $now UTC DATETIME.
	 * @return bool
	 */
	public function consume( int $id, string $now ): bool {
		// The IS NULL condition makes the token single-use even under concurrency.
		return 1 === Db::write( $this->db, 'UPDATE %i SET used_at = %s WHERE id = %d AND used_at IS NULL', Schema::table( 'login_tokens' ), $now, $id );
	}

	/**
	 * Delete tokens that expired before the cut-off.
	 *
	 * @param string $before UTC DATETIME.
	 * @return int Rows deleted.
	 */
	public function purge( string $before ): int {
		return max( 0, Db::write( $this->db, 'DELETE FROM %i WHERE expires_at < %s LIMIT 5000', Schema::table( 'login_tokens' ), $before ) );
	}
}
