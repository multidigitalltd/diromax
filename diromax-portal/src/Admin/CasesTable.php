<?php
/**
 * Cases list table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Plugin;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\Stages;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The list of cases: one query for the page, money from the cached summary.
 */
final class CasesTable extends \WP_List_Table {

	/** Rows per page. */
	private const PER_PAGE = 25;

	/**
	 * Manager names keyed by user ID, loaded once per page.
	 *
	 * @var array<int, string>
	 */
	private array $managers = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {
		parent::__construct(
			array(
				'singular' => 'dx_case',
				'plural'   => 'dx_cases',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'title'    => __( 'תיק', 'diromax-portal' ),
			'stage'    => __( 'שלב', 'diromax-portal' ),
			'budget'   => __( 'ניצול תקציב', 'diromax-portal' ),
			'requests' => __( 'לתשלום', 'diromax-portal' ),
			'manager'  => __( 'מנהל פרויקט', 'diromax-portal' ),
			'status'   => __( 'סטטוס', 'diromax-portal' ),
		);
	}

	/**
	 * Load the page of cases. A manager without the all-cases capability sees only theirs.
	 */
	public function prepare_items(): void {
		$user = get_current_user_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		$result = $this->app->cases->search(
			$this->app->policy->can_manage_all( $user ) ? null : $user,
			$status,
			mb_substr( $search, 0, 100 ),
			$this->get_pagenum(),
			self::PER_PAGE
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->managers        = $this->app->case_service->managers();

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Status filter links.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$views   = array();
		$base    = add_query_arg( 'page', Admin::PAGE_CASES, admin_url( 'admin.php' ) );

		foreach ( array( '' => __( 'הכל', 'diromax-portal' ) ) + Labels::case_statuses() as $key => $label ) {
			$views[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( '' === $key ? $base : add_query_arg( 'status', $key, $base ) ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}

		return $views;
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'עדיין אין תיקים. אפשר לפתוח את הראשון מ"תיק חדש".', 'diromax-portal' );
	}

	/**
	 * Title column with the number and the open link.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_title( $item ): string {
		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong><br><span class="dxa-muted">%3$s</span>',
			esc_url( Admin::case_url( $item->id ) ),
			esc_html( $item->title ),
			esc_html( $item->number )
		);
	}

	/**
	 * Stage.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_stage( $item ): string {
		return esc_html( str_pad( (string) $item->stage, 2, '0', STR_PAD_LEFT ) . ' · ' . Stages::name( $item->stage ) );
	}

	/**
	 * Budget use from the cached summary.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_budget( $item ): string {
		$budget = (int) ( $item->summary['budget'] ?? 0 );
		$cost   = (int) ( $item->summary['cost_to_date'] ?? 0 );

		if ( $budget <= 0 ) {
			return '<span class="dxa-muted">' . esc_html__( 'אין תקציב', 'diromax-portal' ) . '</span>';
		}

		$share = Money::share_bps( $cost, $budget );

		return sprintf(
			'<span class="dxa-meter" aria-hidden="true"><i style="width:%1$s%%"></i></span> %2$s <span class="dxa-muted">(%3$s)</span>',
			esc_attr( (string) min( 100, intdiv( $share, 100 ) ) ),
			esc_html( Money::percent( $share ) ),
			esc_html( Money::format( $cost ) )
		);
	}

	/**
	 * Open requests and overdue count.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_requests( $item ): string {
		$open    = (int) ( $item->summary['open_requests'] ?? 0 );
		$overdue = (int) ( $item->summary['overdue_count'] ?? 0 );

		if ( 0 === $open ) {
			return '—';
		}

		$late = $overdue > 0
			/* translators: %d: overdue requests. */
			? ' <span class="dxa-badge dxa-badge--late">' . esc_html( sprintf( _n( '%d באיחור', '%d באיחור', $overdue, 'diromax-portal' ), $overdue ) ) . '</span>'
			: '';

		return esc_html( Money::format( $open ) ) . $late;
	}

	/**
	 * Manager.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_manager( $item ): string {
		return esc_html( $this->managers[ $item->manager_id ] ?? '—' );
	}

	/**
	 * Status.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_status( $item ): string {
		return esc_html( Labels::case_statuses()[ $item->status->value ] ?? '' );
	}
}
