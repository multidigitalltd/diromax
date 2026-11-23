<?php
/**
 * Case record.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Data;

use Diromax\Portal\Domain\CaseStatus;

defined( 'ABSPATH' ) || exit;

/**
 * One client case, as stored.
 */
final readonly class CaseRecord {

	/**
	 * Constructor.
	 *
	 * @param int                                                                                      $id            ID.
	 * @param string                                                                                   $public_id     Public identifier for URLs.
	 * @param string                                                                                   $number        Human case number, e.g. 2026-014.
	 * @param string                                                                                   $title         Property address.
	 * @param string                                                                                   $property_type Property type.
	 * @param string                                                                                   $track         Track, e.g. funding from the project.
	 * @param string                                                                                   $scope         What is being done at the property.
	 * @param string                                                                                   $fee_terms     Management fee, as in the agreement.
	 * @param string|null                                                                              $signed_on     Agreement date, Y-m-d.
	 * @param int                                                                                      $manager_id    Assigned project manager.
	 * @param CaseStatus                                                                               $status        Status.
	 * @param int                                                                                      $stage         Current stage, 1–7.
	 * @param array<int, array{status:string, started:string|null, done:string|null, due:string|null}> $stages Stage states keyed 1–7.
	 * @param array<string, mixed>|null                                                                $summary       Cached manager summary.
	 * @param string                                                                                   $created_at    UTC.
	 * @param string|null                                                                              $closed_at     UTC.
	 * @param int|null                                                                                 $lead_id       Lead (theme dx_lead post) the case was opened from.
	 * @param int                                                                                      $plan_base     Base of the payment plan's shares, agorot; 0 for the budget's total.
	 */
	public function __construct(
		public int $id,
		public string $public_id,
		public string $number,
		public string $title,
		public string $property_type,
		public string $track,
		public string $scope,
		public string $fee_terms,
		public ?string $signed_on,
		public int $manager_id,
		public CaseStatus $status,
		public int $stage,
		public array $stages,
		public ?array $summary,
		public string $created_at,
		public ?string $closed_at,
		public ?int $lead_id = null,
		public int $plan_base = 0,
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$stages  = json_decode( (string) ( $row['stages'] ?? '' ), true );
		$summary = json_decode( (string) ( $row['summary'] ?? '' ), true );

		return new self(
			id: (int) $row['id'],
			public_id: (string) $row['public_id'],
			number: (string) $row['number'],
			title: (string) $row['title'],
			property_type: (string) $row['property_type'],
			track: (string) $row['track'],
			scope: (string) $row['scope'],
			fee_terms: (string) $row['fee_terms'],
			signed_on: $row['signed_on'] ?? null,
			manager_id: (int) $row['manager_id'],
			status: CaseStatus::tryFrom( (string) $row['status'] ) ?? CaseStatus::Active,
			stage: (int) $row['stage'],
			stages: is_array( $stages ) ? self::normalize_stages( $stages ) : self::default_stages(),
			summary: is_array( $summary ) ? $summary : null,
			created_at: (string) $row['created_at'],
			closed_at: $row['closed_at'] ?? null,
			lead_id: isset( $row['lead_id'] ) ? (int) $row['lead_id'] : null,
			plan_base: (int) ( $row['plan_base'] ?? 0 ),
		);
	}

	/**
	 * Seven pending stages.
	 *
	 * @return array<int, array{status:string, started:string|null, done:string|null, due:string|null}>
	 */
	public static function default_stages(): array {
		$stages = array();

		for ( $i = 1; $i <= 7; $i++ ) {
			$stages[ $i ] = array(
				'status'  => 'pending',
				'started' => null,
				'done'    => null,
				'due'     => null,
			);
		}

		return $stages;
	}

	/**
	 * Coerce decoded JSON into the stage shape, dropping anything unexpected.
	 *
	 * @param array<mixed> $decoded Decoded JSON.
	 * @return array<int, array{status:string, started:string|null, done:string|null, due:string|null}>
	 */
	private static function normalize_stages( array $decoded ): array {
		$stages = self::default_stages();

		foreach ( $stages as $number => $stage ) {
			$value = $decoded[ $number ] ?? null;

			if ( is_array( $value ) ) {
				$stages[ $number ] = array(
					'status'  => is_string( $value['status'] ?? null ) ? $value['status'] : 'pending',
					'started' => is_string( $value['started'] ?? null ) ? $value['started'] : null,
					'done'    => is_string( $value['done'] ?? null ) ? $value['done'] : null,
					'due'     => is_string( $value['due'] ?? null ) ? $value['due'] : null,
				);
			}
		}

		return $stages;
	}
}
