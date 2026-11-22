<?php
/**
 * Document record.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Metadata of one encrypted document. The file itself lives in the private store.
 */
final readonly class DocumentRecord {

	/** Document kinds and their order on screen. */
	public const KINDS = array( 'agreement', 'addendum', 'plan', 'permit', 'contract', 'quote', 'invoice', 'receipt', 'photo', 'client', 'other' );

	/**
	 * Constructor.
	 *
	 * @param int      $id            ID.
	 * @param string   $public_id     Public identifier for download URLs.
	 * @param int      $case_id       Case.
	 * @param int|null $update_id     Update it belongs to.
	 * @param string   $kind          One of KINDS.
	 * @param string   $title         Title shown on screen.
	 * @param string   $original_name Uploaded file name (metadata only, never a path).
	 * @param string   $stored_name   Random name in the private store.
	 * @param string   $mime          Verified MIME type.
	 * @param int      $size          Plaintext size, bytes.
	 * @param string   $sha256        Plaintext SHA-256.
	 * @param int      $key_version   Encryption key version.
	 * @param bool     $visible       Shown to the client.
	 * @param string   $created_at    UTC.
	 * @param int      $uploaded_by   Who uploaded it: staff, or a client for kind "client".
	 * @param bool     $sign_required The clients are asked to sign it.
	 * @param int|null $request_id    The document request it answers.
	 */
	public function __construct(
		public int $id,
		public string $public_id,
		public int $case_id,
		public ?int $update_id,
		public string $kind,
		public string $title,
		public string $original_name,
		public string $stored_name,
		public string $mime,
		public int $size,
		public string $sha256,
		public int $key_version,
		public bool $visible,
		public string $created_at,
		public int $uploaded_by = 0,
		public bool $sign_required = false,
		public ?int $request_id = null,
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			public_id: (string) $row['public_id'],
			case_id: (int) $row['case_id'],
			update_id: null === $row['update_id'] ? null : (int) $row['update_id'],
			kind: (string) $row['kind'],
			title: (string) $row['title'],
			original_name: (string) $row['original_name'],
			stored_name: (string) $row['stored_name'],
			mime: (string) $row['mime'],
			size: (int) $row['size'],
			sha256: (string) $row['sha256'],
			key_version: (int) $row['key_version'],
			visible: '1' === (string) $row['visible'],
			created_at: (string) $row['created_at'],
			uploaded_by: (int) ( $row['uploaded_by'] ?? 0 ),
			sign_required: '1' === (string) ( $row['sign_required'] ?? '0' ),
			request_id: null === ( $row['request_id'] ?? null ) ? null : (int) $row['request_id'],
		);
	}
}
