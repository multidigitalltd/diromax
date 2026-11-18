<?php
/**
 * Progress updates table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Progress updates written by the project manager.
 */
final class UpdateRepository {

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Insert an update.
	 *
	 * @param int      $case_id Case.
	 * @param string   $title   Title.
	 * @param string   $body    Body.
	 * @param int|null $stage   Stage the case moved to, if any.
	 * @param bool     $visible Shown to the client.
	 * @param int      $user_id Author.
	 * @param string   $now     UTC DATETIME.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $case_id, string $title, string $body, ?int $stage, bool $visible, int $user_id, string $now ): int {
		$ok = $this->db->insert(
			Schema::table( 'updates' ),
			array(
				'case_id'    => $case_id,
				'title'      => $title,
				'body'       => $body,
				'stage'      => $stage,
				'visible'    => $visible ? 1 : 0,
				'created_by' => $user_id,
				'created_at' => $now,
			)
		);

		return false === $ok ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Update by ID.
	 *
	 * @param int $id Update.
	 * @return array{id:int, case_id:int, title:string, body:string, stage:int|null, visible:bool, created_at:string}|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT id, case_id, title, body, stage, visible, created_at FROM %i WHERE id = %d', Schema::table( 'updates' ), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * Updates of a case, newest first.
	 *
	 * @param int  $case_id      Case.
	 * @param bool $visible_only Only those shown to the client.
	 * @return array<int, array{id:int, case_id:int, title:string, body:string, stage:int|null, visible:bool, created_at:string}>
	 */
	public function for_case( int $case_id, bool $visible_only ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT id, case_id, title, body, stage, visible, created_at FROM %i WHERE case_id = %d AND visible >= %d ORDER BY created_at DESC, id DESC LIMIT 500',
				Schema::table( 'updates' ),
				$case_id,
				$visible_only ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'map' ), (array) $rows );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return array{id:int, case_id:int, title:string, body:string, stage:int|null, visible:bool, created_at:string}
	 */
	private static function map( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'case_id'    => (int) $row['case_id'],
			'title'      => (string) $row['title'],
			'body'       => (string) $row['body'],
			'stage'      => null === $row['stage'] ? null : (int) $row['stage'],
			'visible'    => '1' === (string) $row['visible'],
			'created_at' => (string) $row['created_at'],
		);
	}
}
