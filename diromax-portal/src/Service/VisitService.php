<?php
/**
 * Site visits and findings.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\SiteVisit;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Site visits and the findings noted on them (spec 23): a visit with its
 * date, who was there and a summary (decision 39); findings with a place,
 * how bad, a photo, who fixes them and by when. The manager picks what the
 * client sees, shown by default (decision 40), and marks a finding fixed,
 * with an "after" photo when there is one (decision 41). The supplier and
 * due date are for follow-up: an overdue finding is flagged for the staff
 * (decision 42).
 *
 * @phpstan-import-type VisitRow from \Diromax\Portal\Data\VisitRepository
 * @phpstan-import-type FindingRow from \Diromax\Portal\Data\VisitRepository
 */
final class VisitService {

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Record a visit; tell the client when it is shown and the manager asks.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       visit_on, attendees, summary, visible, notify.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error
	 */
	public function add_visit( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$checked = SiteVisit::visit( $input, $this->app->clock->today() );

		if ( null === $checked['fields'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$id = $this->app->visits->insert_visit( $client_case->id, $checked['fields'], $actor, $this->app->clock->mysql() );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'visit.added', $client_case->id, 'visit', $id, array( 'date' => $checked['fields']['visit_on'] ), $this->app->clock->mysql() );
		$this->announce( $client_case, $id, $checked['fields']['visible'], $input );

		return $id;
	}

	/**
	 * Change a visit. Hiding it hides its findings' photos from the client.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $id          Visit.
	 * @param array<string, string> $input       visit_on, attendees, summary, visible, notify.
	 * @param int                   $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function update_visit( CaseRecord $client_case, int $id, array $input, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$visit = $this->visit( $client_case, $id );

		if ( null === $visit ) {
			return Errors::not_found();
		}

		$checked = SiteVisit::visit( $input, $this->app->clock->today() );

		if ( null === $checked['fields'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		if ( ! $this->app->visits->update_visit( $id, $checked['fields'], $this->app->clock->mysql() ) ) {
			return Errors::storage();
		}

		$this->sync_photos( $client_case, $id );
		$this->app->audit->record( 'visit.updated', $client_case->id, 'visit', $id, array( 'date' => $checked['fields']['visit_on'] ), $this->app->clock->mysql() );
		// Shown after the save and the box ticked: the manager may send the report once its findings are in.
		$this->announce( $client_case, $id, $checked['fields']['visible'], $input );

		return true;
	}

	/**
	 * Delete a visit and its findings. Their photos stay in the journal, as
	 * they were shown or hidden.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Visit.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function delete_visit( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$visit = $this->visit( $client_case, $id );

		if ( null === $visit ) {
			return Errors::not_found();
		}

		$this->app->visits->delete_visit( $id );
		$this->app->audit->record( 'visit.deleted', $client_case->id, 'visit', $id, array( 'date' => $visit['visit_on'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Note a finding on a visit, with a photo when one is given.
	 *
	 * @param CaseRecord                $client_case Case.
	 * @param int                       $visit_id    Visit.
	 * @param array<string, string>     $input       title, location, severity, supplier, due_on, visible.
	 * @param array<string, mixed>|null $file        Photo ($_FILES item) or null.
	 * @param int                       $actor       Acting user.
	 * @return int|WP_Error
	 */
	public function add_finding( CaseRecord $client_case, int $visit_id, array $input, ?array $file, int $actor ): int|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$visit = $this->visit( $client_case, $visit_id );

		if ( null === $visit ) {
			return Errors::not_found();
		}

		$checked = SiteVisit::finding( $input );

		if ( null === $checked['fields'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$fields = $checked['fields'];
		$photo  = null === $file ? null : ( new PhotoService( $this->app ) )->attach( $client_case, $file, $fields['title'], $fields['visible'] && $visit['visible'], $actor );

		if ( $photo instanceof WP_Error ) {
			return $photo;
		}

		$id = $this->app->visits->insert_finding( $client_case->id, $visit_id, $fields + array( 'photo_id' => $photo ), $this->app->clock->mysql() );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'finding.added', $client_case->id, 'finding', $id, array( 'title' => $fields['title'] ), $this->app->clock->mysql() );

		return $id;
	}

	/**
	 * Change a finding's details.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $id          Finding.
	 * @param array<string, string> $input       title, location, severity, supplier, due_on, visible.
	 * @param int                   $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function update_finding( CaseRecord $client_case, int $id, array $input, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$finding = $this->finding( $client_case, $id );

		if ( null === $finding ) {
			return Errors::not_found();
		}

		$checked = SiteVisit::finding( $input );

		if ( null === $checked['fields'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		if ( ! $this->app->visits->update_finding( $id, $checked['fields'], $this->app->clock->mysql() ) ) {
			return Errors::storage();
		}

		$this->sync_photos( $client_case, (int) $finding['visit_id'] );
		$this->app->audit->record( 'finding.updated', $client_case->id, 'finding', $id, array( 'title' => $checked['fields']['title'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Mark a finding fixed today, with a note and an "after" photo when given.
	 *
	 * @param CaseRecord                $client_case Case.
	 * @param int                       $id          Finding.
	 * @param string                    $note        How it was fixed.
	 * @param array<string, mixed>|null $file        Photo after the fix, or null.
	 * @param int                       $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function fix( CaseRecord $client_case, int $id, string $note, ?array $file, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$finding = $this->finding( $client_case, $id );

		if ( null === $finding || 'open' !== $finding['status'] ) {
			return Errors::not_found();
		}

		$note = Text::line( $note );

		if ( Text::length( $note ) > SiteVisit::MAX_NOTE ) {
			return Errors::invalid( array( 'fix_note' => 'too_long' ) );
		}

		$visit = $this->visit( $client_case, (int) $finding['visit_id'] );
		/* translators: %s: finding. */
		$after = null === $file ? null : ( new PhotoService( $this->app ) )->attach( $client_case, $file, sprintf( __( 'אחרי התיקון: %s', 'diromax-portal' ), $finding['title'] ), $finding['visible'] && (bool) ( $visit['visible'] ?? false ), $actor );

		if ( $after instanceof WP_Error ) {
			return $after;
		}

		$this->app->visits->update_finding(
			$id,
			array(
				'status'         => 'fixed',
				'fixed_on'       => $this->app->clock->today(),
				'fix_note'       => $note,
				'after_photo_id' => $after ?? $finding['after_photo_id'],
			),
			$this->app->clock->mysql()
		);
		$this->app->audit->record( 'finding.fixed', $client_case->id, 'finding', $id, array( 'title' => $finding['title'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Open a fixed finding again.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Finding.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function reopen( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$finding = $this->finding( $client_case, $id );

		if ( null === $finding || 'fixed' !== $finding['status'] ) {
			return Errors::not_found();
		}

		$this->app->visits->update_finding(
			$id,
			array(
				'status'   => 'open',
				'fixed_on' => null,
			),
			$this->app->clock->mysql()
		);
		$this->app->audit->record( 'finding.reopened', $client_case->id, 'finding', $id, array( 'title' => $finding['title'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Delete a finding. Its photos stay in the journal, as they were.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Finding.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function delete_finding( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$finding = $this->finding( $client_case, $id );

		if ( null === $finding ) {
			return Errors::not_found();
		}

		$this->app->visits->delete_finding( $id );
		$this->app->audit->record( 'finding.deleted', $client_case->id, 'finding', $id, array( 'title' => $finding['title'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Visits and findings for the staff tab.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array{visits:array<int, array<string, mixed>>, totals:array{open:int, overdue:int, fixed:int}}
	 */
	public function for_staff( CaseRecord $client_case ): array {
		return $this->build( $client_case, false );
	}

	/**
	 * What the client sees: the visits shown, with their findings shown.
	 * Empty when there is nothing to show.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array{visits:array<int, array<string, mixed>>, totals:array{open:int, overdue:int, fixed:int}}|null
	 */
	public function for_client( CaseRecord $client_case ): ?array {
		$view = $this->build( $client_case, true );

		return array() === $view['visits'] ? null : $view;
	}

	/**
	 * Names of the severities.
	 *
	 * @return array<string, string>
	 */
	public static function severities(): array {
		return array(
			'low'    => __( 'קל', 'diromax-portal' ),
			'medium' => __( 'בינוני', 'diromax-portal' ),
			'high'   => __( 'חמור', 'diromax-portal' ),
		);
	}

	/**
	 * Visits with their findings and photos, in three queries.
	 *
	 * @param CaseRecord $client_case  Case.
	 * @param bool       $visible_only For the client.
	 * @return array{visits:array<int, array<string, mixed>>, totals:array{open:int, overdue:int, fixed:int}}
	 */
	private function build( CaseRecord $client_case, bool $visible_only ): array {
		$today    = $this->app->clock->today();
		$visits   = $this->app->visits->visits_for( $client_case->id, $visible_only );
		$findings = $this->app->visits->findings_for( $client_case->id, $visible_only );
		$photos   = $this->app->photos->find_many( $client_case->id, array_filter( array_merge( array_column( $findings, 'photo_id' ), array_column( $findings, 'after_photo_id' ) ) ) );
		$by_visit = array();

		foreach ( $findings as $finding ) {
			$by_visit[ (int) $finding['visit_id'] ][] = $this->finding_view( $finding, $photos, $visible_only, $today );
		}

		return array(
			'visits' => array_map(
				static fn( array $visit ): array => array(
					'id'        => $visit['id'],
					'date'      => Labels::date( $visit['visit_on'] ),
					'visit_on'  => $visit['visit_on'],
					'attendees' => $visit['attendees'],
					'summary'   => $visit['summary'],
					'visible'   => $visit['visible'],
					'findings'  => $by_visit[ $visit['id'] ] ?? array(),
				),
				$visits
			),
			'totals' => SiteVisit::counts( $findings, $today ),
		);
	}

	/**
	 * A finding for a screen.
	 *
	 * @param array             $finding      Finding.
	 * @param array<int, array> $photos       Photos by ID.
	 * @param bool              $visible_only For the client: only photos shown to the client.
	 * @param string            $today        Y-m-d.
	 * @phpstan-param FindingRow $finding
	 * @phpstan-param array<int, array{public_id:string, visible:bool}> $photos
	 * @return array<string, mixed>
	 */
	private function finding_view( array $finding, array $photos, bool $visible_only, string $today ): array {
		$photo = static function ( ?int $id ) use ( $photos, $visible_only ): ?array {
			$row = null === $id ? null : ( $photos[ $id ] ?? null );

			if ( null === $row || ( $visible_only && ! $row['visible'] ) ) {
				return null;
			}

			return array(
				'thumb' => Router::photo_url( $row['public_id'], true ),
				'full'  => Router::photo_url( $row['public_id'], false ),
			);
		};

		return array(
			'id'             => $finding['id'],
			'visit_id'       => $finding['visit_id'],
			'title'          => $finding['title'],
			'location'       => $finding['location'],
			'severity'       => $finding['severity'],
			'severity_label' => self::severities()[ $finding['severity'] ] ?? '',
			'supplier'       => $finding['supplier'],
			'due_on'         => $finding['due_on'],
			'due_label'      => Labels::date( $finding['due_on'] ),
			'status'         => $finding['status'],
			'fixed_label'    => Labels::date( $finding['fixed_on'] ),
			'fix_note'       => $finding['fix_note'],
			'visible'        => $finding['visible'],
			'overdue'        => ! $visible_only && SiteVisit::is_overdue( $finding, $today ),
			'photo'          => $photo( $finding['photo_id'] ),
			'after'          => $photo( $finding['after_photo_id'] ),
		);
	}

	/**
	 * The photos of a visit's findings follow what the client sees: shown
	 * only when both the finding and its visit are.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $visit_id    Visit.
	 */
	private function sync_photos( CaseRecord $client_case, int $visit_id ): void {
		$visit = $this->visit( $client_case, $visit_id );

		// Every finding of the visit, not the screen's capped list: a photo left out would stay shown.
		foreach ( $this->app->visits->photos_of_visit( $visit_id ) as $finding ) {
			foreach ( array( $finding['photo_id'], $finding['after_photo_id'] ) as $photo_id ) {
				if ( null !== $photo_id ) {
					$this->app->photos->set_visible( $photo_id, $finding['visible'] && (bool) ( $visit['visible'] ?? false ) );
				}
			}
		}
	}

	/**
	 * Tell the client of a visit newly shown, when the manager asks.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $id          Visit.
	 * @param bool                  $shown       Shown to the client now.
	 * @param array<string, string> $input       Form, with notify.
	 */
	private function announce( CaseRecord $client_case, int $id, bool $shown, array $input ): void {
		if ( $shown && '1' === ( $input['notify'] ?? '' ) ) {
			$this->app->notifier->enqueue( $client_case->id, 'visit_published', $id, false );
		}
	}

	/**
	 * Why the actor may not change this case's visits now, if they may not.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $actor       Acting user.
	 * @return WP_Error|null
	 */
	private function refused( CaseRecord $client_case, int $actor ): ?WP_Error {
		if ( ! $this->app->policy->can_manage_case( $actor, $client_case ) ) {
			return Errors::forbidden();
		}

		return CaseStatus::Closed === $client_case->status ? Errors::closed() : null;
	}

	/**
	 * A visit of this case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Visit.
	 * @return VisitRow|null
	 */
	private function visit( CaseRecord $client_case, int $id ): ?array {
		$visit = $this->app->visits->find_visit( $id );

		return null !== $visit && $visit['case_id'] === $client_case->id ? $visit : null;
	}

	/**
	 * A finding of this case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Finding.
	 * @return FindingRow|null
	 */
	private function finding( CaseRecord $client_case, int $id ): ?array {
		$finding = $this->app->visits->find_finding( $id );

		return null !== $finding && $finding['case_id'] === $client_case->id ? $finding : null;
	}
}
