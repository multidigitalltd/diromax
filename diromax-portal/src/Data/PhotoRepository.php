<?php
/**
 * Site photos table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The photo journal of a case (spec 18.2): photos from the site, each with
 * the stage it shows, the day it was taken and a caption. The image and its
 * thumbnail are encrypted in the private store, like every document; this
 * table holds their stored names and dimensions only.
 *
 * @phpstan-type PhotoRow array{id:int, public_id:string, case_id:int, stage:int, taken_on:string, caption:string, batch_key:string, stored_name:string, thumb_name:string, mime:string, size:int, width:int, height:int, thumb_width:int, thumb_height:int, sha256:string, key_version:int, visible:bool, uploaded_by:int, created_at:string}
 */
final class PhotoRepository {

	/** Columns read back. */
	private const COLUMNS = 'id, public_id, case_id, stage, taken_on, caption, batch_key, stored_name, thumb_name, mime, size, width, height, thumb_width, thumb_height, sha256, key_version, visible, uploaded_by, created_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Add a photo.
	 *
	 * @param array{public_id:string, case_id:int, stage:int, taken_on:string, caption:string, batch_key:string, stored_name:string, thumb_name:string, mime:string, size:int, width:int, height:int, thumb_width:int, thumb_height:int, sha256:string, key_version:int, visible:bool, uploaded_by:int} $photo Fields.
	 * @param string                                                                                                                                                                                                                                                                                    $now UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( array $photo, string $now ): int {
		$photo['visible']    = $photo['visible'] ? 1 : 0;
		$photo['caption']    = mb_substr( $photo['caption'], 0, 300 );
		$photo['created_at'] = $now;

		return false === $this->db->insert( Schema::table( 'photos' ), $photo ) ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * One photo.
	 *
	 * @param int $id Photo.
	 * @return PhotoRow|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( 'photos' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * One photo by its public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return PhotoRow|null
	 */
	public function find_by_public_id( string $public_id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE public_id = %s', Schema::table( 'photos' ), $public_id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Photos of a case in journal order: by stage, then by the day taken.
	 *
	 * @param int  $case_id      Case.
	 * @param bool $visible_only Only those shown to the client.
	 * @return PhotoRow[]
	 */
	public function for_case( int $case_id, bool $visible_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE case_id = %d AND visible >= %d ORDER BY stage, taken_on, id LIMIT 2000',
				Schema::table( 'photos' ),
				$case_id,
				$visible_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Are any photos stored (for the encryption-key guard)?
	 *
	 * @return bool
	 */
	public function any(): bool {
		return null !== $this->db->get_var( $this->db->prepare( 'SELECT id FROM %i LIMIT 1', Schema::table( 'photos' ) ) );
	}

	/**
	 * Stored names of the latest photos under a key version, to test a key on.
	 *
	 * @param int $key_version Key version.
	 * @param int $limit       Maximum names, 1–10.
	 * @return string[]
	 */
	public function latest_stored( int $key_version, int $limit ): array {
		return array_map(
			'strval',
			(array) $this->db->get_col(
				$this->db->prepare(
					'SELECT stored_name FROM %i WHERE key_version = %d ORDER BY id DESC LIMIT %d',
					Schema::table( 'photos' ),
					$key_version,
					max( 1, min( 10, $limit ) )
				)
			)
		);
	}

	/**
	 * Photos uploaded together.
	 *
	 * @param string $batch_key Batch.
	 * @param bool   $visible_only Only those shown to the client.
	 * @return PhotoRow[]
	 */
	public function in_batch( string $batch_key, bool $visible_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE batch_key = %s AND visible >= %d ORDER BY id LIMIT 100',
				Schema::table( 'photos' ),
				$batch_key,
				$visible_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Photos in a case.
	 *
	 * @param int  $case_id      Case.
	 * @param bool $visible_only Only those shown to the client.
	 * @return int
	 */
	public function count_for_case( int $case_id, bool $visible_only ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE case_id = %d AND visible >= %d', Schema::table( 'photos' ), $case_id, $visible_only ? 1 : 0 )
		);
	}

	/**
	 * Stored file names (images and thumbnails) of a case, for deleting the files.
	 *
	 * @param int $case_id Case.
	 * @return string[]
	 */
	public function stored_names( int $case_id ): array {
		$rows  = $this->db->get_results(
			$this->db->prepare( 'SELECT stored_name, thumb_name FROM %i WHERE case_id = %d', Schema::table( 'photos' ), $case_id ),
			ARRAY_A
		);
		$names = array();

		foreach ( (array) $rows as $row ) {
			$names[] = (string) $row['stored_name'];
			$names[] = (string) $row['thumb_name'];
		}

		return array_values( array_filter( $names ) );
	}

	/**
	 * Show or hide a photo.
	 *
	 * @param int  $id      Photo.
	 * @param bool $visible Shown to the client.
	 */
	public function set_visible( int $id, bool $visible ): void {
		$this->db->update( Schema::table( 'photos' ), array( 'visible' => $visible ? 1 : 0 ), array( 'id' => $id ) );
	}

	/**
	 * Delete a photo row.
	 *
	 * @param int $id Photo.
	 */
	public function delete( int $id ): void {
		$this->db->delete( Schema::table( 'photos' ), array( 'id' => $id ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return PhotoRow
	 */
	private static function map( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'public_id'    => (string) $row['public_id'],
			'case_id'      => (int) $row['case_id'],
			'stage'        => (int) $row['stage'],
			'taken_on'     => (string) $row['taken_on'],
			'caption'      => (string) $row['caption'],
			'batch_key'    => (string) $row['batch_key'],
			'stored_name'  => (string) $row['stored_name'],
			'thumb_name'   => (string) $row['thumb_name'],
			'mime'         => (string) $row['mime'],
			'size'         => (int) $row['size'],
			'width'        => (int) $row['width'],
			'height'       => (int) $row['height'],
			'thumb_width'  => (int) $row['thumb_width'],
			'thumb_height' => (int) $row['thumb_height'],
			'sha256'       => (string) $row['sha256'],
			'key_version'  => (int) $row['key_version'],
			'visible'      => '1' === (string) $row['visible'],
			'uploaded_by'  => (int) $row['uploaded_by'],
			'created_at'   => (string) $row['created_at'],
		);
	}
}
