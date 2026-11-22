<?php
/**
 * Cases list table.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Plugin;
use Diromax\Portal\Security\Capabilities;
use Diromax\Portal\Service\Labels;

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
		$actions = array();

		if ( current_user_can( Capabilities::DELETE_CASES ) ) {
			// The delete form, with what it removes, is on the case's details tab (spec 19.1).
			$actions['delete'] = sprintf(
				'<a class="dxa-delete-link" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( Admin::case_url( $item->id, 'details' ) . '#dxa-delete' ),
				esc_html__( 'מחיקה', 'diromax-portal' ),
				esc_html( $item->title )
			);
		}

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong><br><span class="dxa-muted">%3$s</span>%4$s',
			esc_url( Admin::case_url( $item->id ) ),
			esc_html( $item->title ),
			esc_html( $item->number ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Stage.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_stage( $item ): string {
		return CaseCells::stage( $item );
	}

	/**
	 * Budget use from the cached summary.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_budget( $item ): string {
		return CaseCells::budget( $item );
	}

	/**
	 * Open requests and overdue count.
	 *
	 * @param CaseRecord $item Case.
	 * @return string
	 */
	protected function column_requests( $item ): string {
		return CaseCells::requests( $item );
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
		return CaseCells::status( $item );
	}
}
