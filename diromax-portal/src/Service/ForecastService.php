<?php
/**
 * The office's payments forecast.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Domain\Forecast;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Open payment requests of every case that is not closed, by month, the late
 * ones, and each case's budget against what is paid and committed (spec
 * 20.2). Administrators only (decision 30), on screen and as CSV.
 */
final class ForecastService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * The screen.
	 *
	 * @param int $viewer User.
	 * @return array<string, mixed>|WP_Error
	 */
	public function view( int $viewer ): array|WP_Error {
		if ( ! $this->app->policy->can_manage_settings( $viewer ) ) {
			return Errors::forbidden();
		}

		$today    = $this->app->clock->today();
		$requests = $this->app->dashboard->open_requests();
		$buckets  = array_map( fn( array $bucket ): array => $bucket + array( 'label' => self::label( $bucket['key'] ) ), Forecast::buckets( $requests, $today ) );

		return array(
			'today'    => $today,
			'buckets'  => $buckets,
			'total'    => array(
				'count'     => array_sum( array_column( $buckets, 'count' ) ),
				'diromax'   => array_sum( array_column( $buckets, 'diromax' ) ),
				'suppliers' => array_sum( array_column( $buckets, 'suppliers' ) ),
				'total'     => array_sum( array_column( $buckets, 'total' ) ),
			),
			'overdue'  => array_slice( Forecast::overdue( $requests, $today ), 0, 200 ),
			'variance' => Forecast::variance( $this->case_money() ),
		);
	}

	/**
	 * The open requests as CSV, audited.
	 *
	 * @param int $viewer User.
	 * @return string|WP_Error
	 */
	public function csv( int $viewer ): string|WP_Error {
		if ( ! $this->app->policy->can_manage_settings( $viewer ) ) {
			return Errors::forbidden();
		}

		$today    = $this->app->clock->today();
		$requests = $this->app->dashboard->open_requests();
		$handle   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory buffer for fputcsv.

		if ( false === $handle ) {
			return Errors::storage();
		}

		$header = array(
			__( 'מספר תיק', 'diromax-portal' ),
			__( 'תיק', 'diromax-portal' ),
			__( 'תיאור', 'diromax-portal' ),
			__( 'למי משלמים', 'diromax-portal' ),
			__( 'לתשלום עד', 'diromax-portal' ),
			__( 'ימי איחור', 'diromax-portal' ),
			__( 'תקופה', 'diromax-portal' ),
			__( 'סכום', 'diromax-portal' ),
		);
		fputcsv( $handle, $header, ',', '"', '' );

		foreach ( $requests as $request ) {
			$late = null !== $request['due_date'] && $request['due_date'] < $today
				? (string) intdiv( (int) strtotime( $today . ' UTC' ) - (int) strtotime( $request['due_date'] . ' UTC' ), DAY_IN_SECONDS )
				: '';
			$row  = array(
				$request['number'],
				$request['case_title'],
				$request['description'],
				$request['to_diromax'] ? __( 'דירומקס', 'diromax-portal' ) : ( '' !== $request['payee'] ? $request['payee'] : __( 'ספק', 'diromax-portal' ) ),
				(string) $request['due_date'],
				$late,
				self::label( self::bucket_of( $request['due_date'], $today ) ),
				CsvExport::amount( $request['total'] ),
			);
			fputcsv( $handle, array_map( array( CsvExport::class, 'cell' ), $row ), ',', '"', '' );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the buffer above.

		$this->app->audit->record( 'export.forecast', null, 'forecast', null, array( 'rows' => count( $requests ) ), $this->app->clock->mysql() );

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * A bucket's name.
	 *
	 * @param string $key overdue, Y-m, later or undated.
	 * @return string
	 */
	public static function label( string $key ): string {
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})$/', $key, $match ) ) {
			$months = array( __( 'ינואר', 'diromax-portal' ), __( 'פברואר', 'diromax-portal' ), __( 'מרץ', 'diromax-portal' ), __( 'אפריל', 'diromax-portal' ), __( 'מאי', 'diromax-portal' ), __( 'יוני', 'diromax-portal' ), __( 'יולי', 'diromax-portal' ), __( 'אוגוסט', 'diromax-portal' ), __( 'ספטמבר', 'diromax-portal' ), __( 'אוקטובר', 'diromax-portal' ), __( 'נובמבר', 'diromax-portal' ), __( 'דצמבר', 'diromax-portal' ) );

			return ( $months[ (int) $match[2] - 1 ] ?? $key ) . ' ' . $match[1];
		}

		return match ( $key ) {
			'overdue' => __( 'באיחור', 'diromax-portal' ),
			'later'   => __( 'מאוחר יותר', 'diromax-portal' ),
			default   => __( 'בלי תאריך יעד', 'diromax-portal' ),
		};
	}

	/**
	 * The bucket of one due date, as Forecast::buckets() would put it.
	 *
	 * @param string|null $due   Y-m-d.
	 * @param string      $today Y-m-d.
	 * @return string
	 */
	private static function bucket_of( ?string $due, string $today ): string {
		$request = array(
			'id'          => 0,
			'case_id'     => 0,
			'number'      => '',
			'case_title'  => '',
			'description' => '',
			'total'       => 1,
			'due_date'    => $due,
			'to_diromax'  => true,
			'payee'       => '',
		);

		foreach ( Forecast::buckets( array( $request ), $today ) as $bucket ) {
			if ( 1 === $bucket['count'] ) {
				return $bucket['key'];
			}
		}

		return 'undated';
	}

	/**
	 * Budget and spending of every case that is not closed, from the summary
	 * each case keeps (no ledger query per case).
	 *
	 * @return array<int, array{case_id:int, number:string, title:string, budget:int, cost:int, committed:int}>
	 */
	private function case_money(): array {
		$rows = array();

		foreach ( $this->app->cases->active_for( null, 500 ) as $client_case ) {
			$rows[] = array(
				'case_id'   => $client_case->id,
				'number'    => $client_case->number,
				'title'     => $client_case->title,
				'budget'    => (int) ( $client_case->summary['budget'] ?? 0 ),
				'cost'      => (int) ( $client_case->summary['cost_to_date'] ?? 0 ),
				'committed' => (int) ( $client_case->summary['committed'] ?? 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Money for the screen.
	 *
	 * @param int $agorot Agorot.
	 * @return string
	 */
	public static function money( int $agorot ): string {
		return Money::format( $agorot );
	}
}
