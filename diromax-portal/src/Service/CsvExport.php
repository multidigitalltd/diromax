<?php
/**
 * Ledger export to CSV.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Data\LedgerRow;
use Diromax\Portal\Domain\BudgetTree;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The finance table as a spreadsheet (spec 15.7).
 *
 * Staff get every entry with visibility and internal notes; a client gets
 * exactly what the portal shows. UTF-8 with a BOM so Excel reads Hebrew, and
 * every cell that a spreadsheet would read as a formula is defused.
 */
final class CsvExport {

	/** Characters that start a formula in Excel, LibreOffice and Sheets. */
	private const FORMULA_START = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Build the file.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $viewer      Requesting user.
	 * @param bool       $staff       Staff export (everything) or client export.
	 * @return string|WP_Error CSV text.
	 */
	public function build( CaseRecord $client_case, int $viewer, bool $staff ): string|WP_Error {
		$allowed = $staff ? $this->app->policy->can_manage_case( $viewer, $client_case ) : $this->app->policy->can_view_case( $viewer, $client_case );

		if ( ! $allowed ) {
			return Errors::not_found();
		}

		$rows = $this->app->ledger->for_case( $client_case->id, ! $staff );
		$tree = $this->app->budget->tree( $client_case->id );

		$this->app->audit->record(
			'export.csv',
			$client_case->id,
			'case',
			$client_case->id,
			array(
				'staff' => $staff,
				'rows'  => count( $rows ),
			),
			$this->app->clock->mysql()
		);

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory buffer for fputcsv.

		if ( false === $handle ) {
			return Errors::storage();
		}

		fputcsv( $handle, self::header( $staff ), ',', '"', '' );

		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( array( self::class, 'cell' ), self::line( $row, $tree, $staff ) ), ',', '"', '' );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the buffer above.

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Send a built file to the browser as a download.
	 *
	 * @param string $csv      File content.
	 * @param string $filename File name.
	 */
	public static function output( string $csv, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );

		// Not HTML: a spreadsheet file whose cells were defused by cell().
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, see above.
	}

	/**
	 * File name for a case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $today       Y-m-d.
	 * @return string
	 */
	public static function filename( CaseRecord $client_case, string $today ): string {
		return 'diromax-' . sanitize_file_name( $client_case->number ) . '-' . $today . '.csv';
	}

	/**
	 * Defuse a cell a spreadsheet would run as a formula.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	public static function cell( string $value ): string {
		return '' !== $value && in_array( $value[0], self::FORMULA_START, true ) ? "'" . $value : $value;
	}

	/**
	 * An amount as a plain decimal number: 12300.50, no separators, no symbol.
	 *
	 * @param int $agorot Agorot.
	 * @return string
	 */
	public static function amount( int $agorot ): string {
		return ( $agorot < 0 ? '-' : '' ) . intdiv( abs( $agorot ), 100 ) . '.' . str_pad( (string) ( abs( $agorot ) % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Column titles.
	 *
	 * @param bool $staff Staff export.
	 * @return string[]
	 */
	private static function header( bool $staff ): array {
		$columns = array(
			__( 'תאריך', 'diromax-portal' ),
			__( 'סוג', 'diromax-portal' ),
			__( 'סטטוס', 'diromax-portal' ),
			__( 'תיאור', 'diromax-portal' ),
			__( 'ספק / נמען', 'diromax-portal' ),
			__( 'סעיף בתקציב', 'diromax-portal' ),
			__( 'סעיף משנה', 'diromax-portal' ),
			__( 'מקור מימון', 'diromax-portal' ),
			__( 'לפני מע״מ', 'diromax-portal' ),
			__( 'מע״מ', 'diromax-portal' ),
			__( 'סה״כ', 'diromax-portal' ),
			__( 'לתשלום עד', 'diromax-portal' ),
			__( 'אמצעי תשלום', 'diromax-portal' ),
			__( 'אסמכתא', 'diromax-portal' ),
			__( 'בוטל', 'diromax-portal' ),
			__( 'סיבת ביטול', 'diromax-portal' ),
		);

		return $staff ? array_merge( $columns, array( __( 'גלוי ללקוח', 'diromax-portal' ), __( 'הערה פנימית', 'diromax-portal' ) ) ) : $columns;
	}

	/**
	 * One entry as cells.
	 *
	 * @param LedgerRow  $row   Row.
	 * @param BudgetTree $tree  The case's budget.
	 * @param bool       $staff Staff export.
	 * @return string[]
	 */
	private static function line( LedgerRow $row, BudgetTree $tree, bool $staff ): array {
		$entry = $row->entry;
		$yes   = __( 'כן', 'diromax-portal' );
		$cells = array(
			$entry->entry_date,
			Labels::entry_tag( $entry ),
			self::status( $entry->type, $entry->status ),
			$entry->description,
			EntryType::Request === $entry->type ? Labels::pay_to( $entry->pay_to, $entry->payee ) : $entry->payee,
			...self::budget_cells( $tree, $entry->category ),
			null === $entry->funding ? '' : ( Labels::funding()[ $entry->funding->value ] ?? '' ),
			self::amount( $entry->net ),
			self::amount( $entry->vat ),
			self::amount( $entry->total ),
			(string) $entry->due_date,
			Labels::methods()[ $row->method ] ?? '',
			$row->reference,
			$entry->voided ? $yes : '',
			$row->void_reason,
		);

		return $staff ? array_merge( $cells, array( $entry->visible ? $yes : __( 'לא', 'diromax-portal' ), $row->internal_note ) ) : $cells;
	}

	/**
	 * Status text.
	 *
	 * @param EntryType   $type   Type.
	 * @param EntryStatus $status Status.
	 * @return string
	 */
	private static function status( EntryType $type, EntryStatus $status ): string {
		return match ( true ) {
			EntryStatus::Open === $status      => __( 'פתוחה', 'diromax-portal' ),
			EntryStatus::Committed === $status => __( 'טרם שולם', 'diromax-portal' ),
			EntryType::Request === $type       => __( 'שולמה', 'diromax-portal' ),
			default                            => __( 'שולם', 'diromax-portal' ),
		};
	}

	/**
	 * Section and sub-section of an entry, as two columns.
	 *
	 * @param BudgetTree $tree     The case's budget.
	 * @param string     $category The entry's key.
	 * @return array{0:string, 1:string}
	 */
	private static function budget_cells( BudgetTree $tree, string $category ): array {
		if ( '' === $category ) {
			return array( '', '' );
		}

		$line = $tree->find( $category );

		if ( null === $line || '' === $line['parent'] ) {
			return array( Labels::budget_line( $tree, $category ), '' );
		}

		return array( Labels::budget_line( $tree, $line['parent'] ), $line['label'] );
	}
}
