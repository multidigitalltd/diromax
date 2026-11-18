<?php
/**
 * Admin screens.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Service\Labels;
use Diromax\Portal\Service\Stages;
use Diromax\Portal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds view models for the admin templates. No writes happen here.
 */
final class Pages {

	/** Case screen tabs. */
	public const TABS = array( 'overview', 'details', 'members', 'budget', 'finance', 'decisions', 'updates', 'documents', 'log' );

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Cases list, or one case when ?case= is present.
	 */
	public function cases(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$case_id = isset( $_GET['case'] ) ? absint( $_GET['case'] ) : 0;

		if ( $case_id > 0 ) {
			$this->case_screen( $case_id );
			return;
		}

		$table = new CasesTable( $this->app );
		$table->prepare_items();
		$view = array( 'flash' => Flash::take() );

		include DX_PORTAL_DIR . 'templates/admin/cases.php';
	}

	/**
	 * New case form.
	 */
	public function new_case(): void {
		$user = get_current_user_id();

		if ( ! $this->app->policy->can_use_admin( $user ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$view = array(
			'flash'    => Flash::take(),
			'managers' => $this->app->policy->can_manage_all( $user ) ? $this->app->case_service->managers() : array(),
			'user'     => $user,
		);

		include DX_PORTAL_DIR . 'templates/admin/case-new.php';
	}

	/**
	 * Settings screen.
	 */
	public function settings(): void {
		if ( ! $this->app->policy->can_manage_settings( get_current_user_id() ) ) {
			wp_die( esc_html__( 'אין הרשאה.', 'diromax-portal' ), '', array( 'response' => 403 ) );
		}

		$view = array(
			'flash'      => Flash::take(),
			'settings'   => Settings::all(),
			'key_ready'  => $this->app->store->is_configured(),
			'categories' => implode(
				"\n",
				array_map(
					static fn( string $key, string $label ): string => $key . '=' . $label,
					array_keys( Settings::categories() ),
					Settings::categories()
				)
			),
		);

		include DX_PORTAL_DIR . 'templates/admin/settings.php';
	}

	/**
	 * One case.
	 *
	 * @param int $case_id Case.
	 */
	private function case_screen( int $case_id ): void {
		$user        = get_current_user_id();
		$client_case = $this->app->cases->find( $case_id );

		if ( null === $client_case || ! $this->app->policy->can_manage_case( $user, $client_case ) ) {
			wp_die( esc_html__( 'התיק לא נמצא, או שאין לך הרשאה אליו.', 'diromax-portal' ), '', array( 'response' => 404 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'overview';

		$view = array_merge(
			array(
				'case'      => $client_case,
				'tab'       => $tab,
				'tabs'      => self::tab_labels(),
				'flash'     => Flash::take(),
				'portal'    => Router::case_url( $client_case ),
				'manager'   => get_userdata( $client_case->manager_id ),
				'statuses'  => Labels::case_statuses(),
				'can_all'   => $this->app->policy->can_manage_all( $user ),
				'can_purge' => $this->app->policy->can_delete_case( $user, $client_case ),
			),
			$this->tab_data( $client_case, $tab )
		);

		include DX_PORTAL_DIR . 'templates/admin/case.php';
	}

	/**
	 * Data for the active tab only.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $tab  Tab.
	 * @return array<string, mixed>
	 */
	private function tab_data( CaseRecord $client_case, string $tab ): array {
		return match ( $tab ) {
			'overview'  => array(
				'summary' => $this->app->case_service->summary( $client_case, false ),
				'pending' => $this->app->notifier->pending( $client_case ),
				'members' => $this->app->members->for_case( $client_case->id ),
				'recent'  => array_slice( $this->app->audit->for_case( $client_case->id, 8 ), 0, 8 ),
			),
			'details'   => array(
				'stages'   => Stages::names(),
				'managers' => $this->app->case_service->managers(),
			),
			'members'   => array(
				'members' => $this->app->members->for_case( $client_case->id ),
				'roles'   => Labels::roles(),
				'modes'   => Labels::notify_modes(),
			),
			'budget'    => array(
				'planned'    => $this->app->budget->planned( $client_case->id ),
				'categories' => Settings::categories(),
			),
			'finance'   => array(
				'rows'       => $this->app->ledger->for_case( $client_case->id, false ),
				'categories' => Settings::categories(),
				'payees'     => $this->app->ledger->payees(),
				'documents'  => $this->documents_by_id( $client_case ),
				'today'      => $this->app->clock->today(),
			),
			'decisions' => $this->decisions_data( $client_case ),
			'updates'   => array(
				'updates' => $this->app->updates->for_case( $client_case->id, false ),
				'stages'  => Stages::names(),
			),
			'documents' => array(
				'documents' => $this->app->documents->for_case( $client_case->id, false ),
				'kinds'     => Labels::document_kinds(),
				'key_ready' => $this->app->store->is_configured(),
				'acks'      => $this->app->acknowledgements->for_case( $client_case->id ),
			),
			'log'       => array(
				'audit' => $this->app->audit->for_case( $client_case->id, 300 ),
				'mail'  => $this->app->mail_log->for_case( $client_case->id, 200 ),
			),
			default     => array(),
		};
	}

	/**
	 * Data of the decisions tab.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<string, mixed>
	 */
	private function decisions_data( CaseRecord $client_case ): array {
		$decisions = $this->app->decisions->for_case( $client_case->id );
		$names     = array();

		foreach ( array_unique( array_filter( array_map( static fn( $d ): ?int => $d->answered_by, $decisions ) ) ) as $user_id ) {
			$user              = get_userdata( (int) $user_id );
			$names[ $user_id ] = $user ? (string) $user->display_name : '';
		}

		return array(
			'decisions' => $decisions,
			'names'     => $names,
			'documents' => $this->app->documents->for_case( $client_case->id, true ),
		);
	}

	/**
	 * Documents of the case keyed by ID.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, \Diromax\Portal\Data\DocumentRecord>
	 */
	private function documents_by_id( CaseRecord $client_case ): array {
		$list = array();

		foreach ( $this->app->documents->for_case( $client_case->id, false ) as $document ) {
			$list[ $document->id ] = $document;
		}

		return $list;
	}

	/**
	 * Tab labels.
	 *
	 * @return array<string, string>
	 */
	private static function tab_labels(): array {
		return array(
			'overview'  => __( 'סקירה', 'diromax-portal' ),
			'details'   => __( 'פרטי התיק', 'diromax-portal' ),
			'members'   => __( 'לקוחות', 'diromax-portal' ),
			'budget'    => __( 'תקציב', 'diromax-portal' ),
			'finance'   => __( 'כספים', 'diromax-portal' ),
			'decisions' => __( 'החלטות', 'diromax-portal' ),
			'updates'   => __( 'עדכונים', 'diromax-portal' ),
			'documents' => __( 'הסכם ומסמכים', 'diromax-portal' ),
			'log'       => __( 'יומן', 'diromax-portal' ),
		);
	}

	/**
	 * Money for templates.
	 *
	 * @param int $agorot Agorot.
	 * @return string
	 */
	public static function money( int $agorot ): string {
		return Money::format( $agorot );
	}
}
