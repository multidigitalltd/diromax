<?php
/**
 * Encrypted private file store.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Stores documents encrypted at rest (risk level 3, spec section 9).
 *
 * - libsodium secretstream (XChaCha20-Poly1305), chunked, so large files never
 *   sit in memory whole and any tampering or truncation fails authentication.
 * - Each file has its own key, derived from the master key and the file's
 *   random name; key_version allows a future master-key rotation.
 * - The master key is DX_PORTAL_KEY in wp-config.php (base64 of 32 bytes),
 *   kept apart from the database. Without it uploads are refused.
 * - Files sit outside the public uploads URL space, under random names, behind
 *   deny rules; they are only ever served through DocumentService.
 *
 * Direct file functions are used on purpose: WP_Filesystem cannot stream.
 * phpcs:disable WordPress.WP.AlternativeFunctions -- Chunked streaming of encrypted files (standard 9.4.13).
 */
final class DocumentStore {

	/** Maximum plaintext size accepted, bytes. */
	public const MAX_BYTES = 20971520;

	/** Current master-key version. */
	public const KEY_VERSION = 1;

	/** Plaintext chunk size. */
	private const CHUNK = 65536;

	/** Shape of a stored file name. */
	private const NAME_PATTERN = '/^[a-f0-9]{40}\.bin$/';

	/**
	 * Is a valid master key configured?
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return null !== $this->master_key();
	}

	/**
	 * New random stored name.
	 *
	 * @return string
	 */
	public function new_name(): string {
		return bin2hex( random_bytes( 20 ) ) . '.bin';
	}

	/**
	 * Encrypt a plaintext file into the store.
	 *
	 * @param string $source      Path of the plaintext (an upload's temp file).
	 * @param string $stored_name Name from new_name().
	 * @return array{size:int, sha256:string} Plaintext size and hash.
	 * @throws \RuntimeException When the key, the directory or the files fail.
	 */
	public function put( string $source, string $stored_name ): array {
		$target = $this->path( $stored_name );
		$in     = fopen( $source, 'rb' );
		$out    = fopen( $target, 'xb' );

		if ( false === $in || false === $out ) {
			throw new \RuntimeException( 'document_store.open_failed' );
		}

		$hash = hash_init( 'sha256' );
		$size = 0;

		try {
			[ $state, $header ] = sodium_crypto_secretstream_xchacha20poly1305_init_push( $this->file_key( $stored_name, self::KEY_VERSION ) );
			fwrite( $out, $header );

			do {
				$chunk = (string) fread( $in, self::CHUNK );
				$last  = feof( $in );
				$size += strlen( $chunk );
				hash_update( $hash, $chunk );

				$tag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
				fwrite( $out, sodium_crypto_secretstream_xchacha20poly1305_push( $state, $chunk, '', $tag ) );
			} while ( ! $last );
		} finally {
			fclose( $in );
			fclose( $out );
		}

		return array(
			'size'   => $size,
			'sha256' => hash_final( $hash ),
		);
	}

	/**
	 * Decrypt a stored file, handing plaintext chunks to the writer.
	 *
	 * @param string                 $stored_name Stored name.
	 * @param int                    $key_version Key version recorded with the file.
	 * @param callable(string): void $write       Receives each plaintext chunk.
	 * @return bool False when the file is missing, tampered with or truncated.
	 */
	public function read( string $stored_name, int $key_version, callable $write ): bool {
		$path = $this->path( $stored_name );
		$in   = is_readable( $path ) ? fopen( $path, 'rb' ) : false;

		if ( false === $in ) {
			return false;
		}

		try {
			$header = (string) fread( $in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
			$state  = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $this->file_key( $stored_name, $key_version ) );
			$size   = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

			while ( ! feof( $in ) ) {
				$cipher = (string) fread( $in, $size );

				if ( '' === $cipher ) {
					break;
				}

				$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $cipher );

				if ( false === $result ) {
					return false;
				}

				$write( $result[0] );

				if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $result[1] ) {
					return true;
				}
			}

			// The stream ended without its final tag: truncated.
			return false;
		} catch ( \SodiumException ) {
			return false;
		} finally {
			fclose( $in );
		}
	}

	/**
	 * Delete a stored file.
	 *
	 * @param string $stored_name Stored name.
	 */
	public function delete( string $stored_name ): void {
		$path = $this->path( $stored_name );

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * The store directory, created and locked down on first use.
	 *
	 * Outside the web root when DX_PORTAL_PRIVATE_DIR is defined; otherwise a
	 * folder under uploads with deny rules for Apache and IIS (Nginx needs the
	 * location rule from the README).
	 *
	 * @return string
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public function directory(): string {
		$default = defined( 'DX_PORTAL_PRIVATE_DIR' ) ? (string) constant( 'DX_PORTAL_PRIVATE_DIR' ) : wp_upload_dir( null, false )['basedir'] . '/dx-private';
		$dir     = untrailingslashit( (string) apply_filters( 'dx_portal_private_dir', $default ) );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'document_store.no_directory' );
		}

		$guards = array(
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence.\n",
		);

		foreach ( $guards as $file => $contents ) {
			if ( ! file_exists( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $contents );
			}
		}

		return $dir;
	}

	/**
	 * Absolute path of a stored file. Only generated names are accepted, so
	 * no input can walk out of the directory (standard 4.3.7).
	 *
	 * @param string $stored_name Stored name.
	 * @return string
	 * @throws \InvalidArgumentException On a name that was not generated by new_name().
	 */
	private function path( string $stored_name ): string {
		if ( 1 !== preg_match( self::NAME_PATTERN, $stored_name ) ) {
			throw new \InvalidArgumentException( 'document_store.bad_name' );
		}

		return $this->directory() . '/' . $stored_name;
	}

	/**
	 * Per-file key: keyed BLAKE2b of the file name under the master key.
	 *
	 * @param string $stored_name Stored name.
	 * @param int    $version     Key version.
	 * @return string 32 bytes.
	 * @throws \RuntimeException When no master key is configured.
	 */
	private function file_key( string $stored_name, int $version ): string {
		$master = $this->master_key( $version );

		if ( null === $master ) {
			throw new \RuntimeException( 'document_store.no_key' );
		}

		return sodium_crypto_generichash( 'dx-portal-file|' . $stored_name, $master, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES );
	}

	/**
	 * The master key for a version, decoded and length-checked.
	 *
	 * Version 1 is DX_PORTAL_KEY. A later rotation adds DX_PORTAL_KEY_V2 and
	 * bumps KEY_VERSION; old files keep decrypting with their recorded version.
	 *
	 * @param int $version Key version.
	 * @return string|null
	 */
	private function master_key( int $version = self::KEY_VERSION ): ?string {
		$name = 1 === $version ? 'DX_PORTAL_KEY' : 'DX_PORTAL_KEY_V' . $version;

		if ( ! defined( $name ) ) {
			return null;
		}

		$key = base64_decode( (string) constant( $name ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the key from wp-config; no code is involved.

		return is_string( $key ) && SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES === strlen( $key ) ? $key : null;
	}
}
