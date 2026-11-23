<?php
/**
 * Case timelines.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\StageProgress;
use Diromax\Portal\Domain\StageStatus;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Domain\Timeline;
use Diromax\Portal\Plugin;
use Diromax\Portal\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A case's timeline (spec 22): each stage's planned start and end (the end is
 * the stage's due date) and milestones, drawn against when work really
 * started and finished (decision 35). The client sees it once the manager
 * publishes it; from then on a moved date shows the date first shown and
 * the manager's note (decision 36). The client hears of a change only when
 * the manager asks (decision 37). A new plan can be worked out from the
 * office's typical stage lengths (decision 38).
 *
 * @phpstan-import-type ScheduleRow from \Diromax\Portal\Data\ScheduleRepository
 */
final class ScheduleService {

	/** Longest note. */
	private const MAX_NOTE = 300;

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Save the stages' planned dates, whether the client sees the timeline,
	 * and why dates moved.
	 *
	 * @param CaseRecord                                    $client_case Case.
	 * @param array<int, string>                            $starts      Planned start by stage, Y-m-d or ''.
	 * @param array<int, string>                            $ends        Planned end by stage, Y-m-d or ''.
	 * @param array{note:string, visible:bool, notify:bool} $options Note on moved dates, show to the client, email the client.
	 * @param int                                           $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function save( CaseRecord $client_case, array $starts, array $ends, array $options, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$checked = self::check_dates( $starts, $ends );

		if ( array() !== $checked['errors'] ) {
			return Errors::invalid( $checked['errors'] );
		}

		$note = mb_substr( Text::line( $options['note'] ), 0, self::MAX_NOTE );

		return $this->apply( $client_case, $checked['starts'], $checked['ends'], $note, $options['visible'], $options['notify'], $actor );
	}

	/**
	 * Work out the stages' planned dates from the office's typical stage
	 * lengths, from a first day. Stages without a length keep their dates.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $start       First day, Y-m-d.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function compute( CaseRecord $client_case, string $start, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		if ( ! Timeline::is_date( $start ) ) {
			return Errors::invalid( array( 'start_on' => 'date' ) );
		}

		$plan = Timeline::from_weeks( $start, Settings::all()['schedule_weeks'] );

		if ( array() === $plan ) {
			return Errors::invalid( array( 'start_on' => 'no_weeks' ) );
		}

		$client_case = $this->app->cases->find( $client_case->id ) ?? $client_case;

		return $this->apply(
			$client_case,
			array_map( static fn( array $dates ): string => $dates['start'], $plan ) + $this->starts( $client_case ),
			array_map( static fn( array $dates ): string => $dates['end'], $plan ) + array_map( static fn( array $state ): ?string => $state['due'], $client_case->stages ),
			'',
			$client_case->schedule_visible,
			false,
			$actor
		);
	}

	/**
	 * Add a milestone.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       label, stage, date.
	 * @param int                   $actor       Acting user.
	 * @return int|WP_Error
	 */
	public function add_milestone( CaseRecord $client_case, array $input, int $actor ): int|WP_Error {
		$fields = $this->milestone_fields( $client_case, $input, $actor );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		if ( $this->app->schedule->count_milestones( $client_case->id ) >= \Diromax\Portal\Data\ScheduleRepository::MAX_MILESTONES ) {
			return Errors::invalid( array( 'label' => 'too_many' ) );
		}

		// Added to a timeline the client already sees: this is its first date.
		$fields['first_ends_on'] = $client_case->schedule_visible ? $fields['ends_on'] : null;
		$id                      = $this->app->schedule->insert( $client_case->id, 'milestone', $fields, $this->app->clock->mysql() );

		if ( 0 === $id ) {
			return Errors::storage();
		}

		$this->app->audit->record( 'schedule.milestone_added', $client_case->id, 'schedule', $id, array( 'label' => $fields['label'] ), $this->app->clock->mysql() );

		return $id;
	}

	/**
	 * Change a milestone. A new date on a published timeline keeps the date
	 * first shown, with the note.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param int                   $id          Milestone.
	 * @param array<string, string> $input       label, stage, date, note.
	 * @param int                   $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function update_milestone( CaseRecord $client_case, int $id, array $input, int $actor ): bool|WP_Error {
		$fields = $this->milestone_fields( $client_case, $input, $actor );

		if ( $fields instanceof WP_Error ) {
			return $fields;
		}

		$row = $this->milestone( $client_case, $id );

		if ( null === $row ) {
			return Errors::not_found();
		}

		$moved                   = $row['ends_on'] !== $fields['ends_on'];
		$fields['first_ends_on'] = $row['first_ends_on'] ?? ( $client_case->schedule_visible ? $fields['ends_on'] : null );
		$fields['note']          = self::note_for( $fields['ends_on'], $fields['first_ends_on'], $moved, mb_substr( Text::line( $input['note'] ?? '' ), 0, self::MAX_NOTE ), $row['note'] );

		if ( ! $this->app->schedule->update( $id, $fields, $this->app->clock->mysql() ) ) {
			return Errors::storage();
		}

		$this->app->audit->record(
			'schedule.milestone_updated',
			$client_case->id,
			'schedule',
			$id,
			array(
				'label' => $fields['label'],
				'date'  => $fields['ends_on'],
			),
			$this->app->clock->mysql()
		);

		return true;
	}

	/**
	 * Mark a milestone reached today, or not reached.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Milestone.
	 * @param bool       $done        Reached.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function set_done( CaseRecord $client_case, int $id, bool $done, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$row = $this->milestone( $client_case, $id );

		if ( null === $row ) {
			return Errors::not_found();
		}

		$this->app->schedule->update( $id, array( 'done_on' => $done ? $this->app->clock->today() : null ), $this->app->clock->mysql() );
		$this->app->audit->record( $done ? 'schedule.milestone_done' : 'schedule.milestone_reopened', $client_case->id, 'schedule', $id, array( 'label' => $row['label'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * Delete a milestone.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Milestone.
	 * @param int        $actor       Acting user.
	 * @return true|WP_Error
	 */
	public function delete_milestone( CaseRecord $client_case, int $id, int $actor ): bool|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$row = $this->milestone( $client_case, $id );

		if ( null === $row ) {
			return Errors::not_found();
		}

		$this->app->schedule->delete_milestone( $id );
		$this->app->audit->record( 'schedule.milestone_deleted', $client_case->id, 'schedule', $id, array( 'label' => $row['label'] ), $this->app->clock->mysql() );

		return true;
	}

	/**
	 * The timeline for the staff tab, with late flags.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<string, mixed>
	 */
	public function for_staff( CaseRecord $client_case ): array {
		return $this->build( $client_case, true ) + array(
			'visible' => $client_case->schedule_visible,
			'weeks'   => array_sum( Settings::all()['schedule_weeks'] ) > 0,
		);
	}

	/**
	 * The timeline as the client sees it: null until the manager publishes
	 * it, or while nothing is dated. Stages marked not relevant are left out.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<string, mixed>|null
	 */
	public function for_client( CaseRecord $client_case ): ?array {
		if ( ! $client_case->schedule_visible ) {
			return null;
		}

		$view = $this->build( $client_case, false );

		return null === $view['layout'] ? null : $view;
	}

	/**
	 * Stages and milestones whose date moved since it was first shown, for
	 * the client's email.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, array{label:string, date:string, first:string, note:string}>
	 */
	public function moved( CaseRecord $client_case ): array {
		$view  = $this->for_client( $client_case );
		$moved = array();

		foreach ( null === $view ? array() : $view['rows'] as $row ) {
			foreach ( array_merge( array( $row ), $row['milestones'] ) as $item ) {
				if ( $item['moved'] ) {
					$moved[] = array(
						'label' => (string) $item['label'],
						'date'  => (string) $item['end_label'],
						'first' => (string) $item['first_label'],
						'note'  => (string) $item['note'],
					);
				}
			}
		}

		return $moved;
	}

	/**
	 * Save planned dates and publication, keep first-shown dates and notes,
	 * and tell the client when asked.
	 *
	 * @param CaseRecord              $client_case Case.
	 * @param array<int, string|null> $starts      Planned start by stage.
	 * @param array<int, string|null> $ends        Planned end by stage.
	 * @param string                  $note        Why dates moved.
	 * @param bool                    $visible     Show to the client.
	 * @param bool                    $notify      Email the client.
	 * @param int                     $actor       Acting user.
	 * @return true|WP_Error
	 */
	private function apply( CaseRecord $client_case, array $starts, array $ends, string $note, bool $visible, bool $notify, int $actor ): bool|WP_Error {
		$was_visible = $client_case->schedule_visible;
		$before      = array_map( static fn( array $state ): ?string => $state['due'], $client_case->stages );
		$saved       = $this->app->case_service->set_dues( $client_case, $ends, $actor );

		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$rows  = $this->stage_rows( $client_case->id );
		$now   = $this->app->clock->mysql();
		$moved = array();

		for ( $stage = 1; $stage <= StageProgress::COUNT; $stage++ ) {
			$row   = $rows[ $stage ] ?? null;
			$end   = $saved->stages[ $stage ]['due'];
			$first = $row['first_ends_on'] ?? null;
			// The first date the client is shown: set when the timeline is published, or when a date is added to a published one.
			$first  = $first ?? ( $visible ? $end : null );
			$fields = array(
				// A stage given with no start clears it; a stage not given keeps its own.
				'starts_on'     => array_key_exists( $stage, $starts ) ? $starts[ $stage ] : ( $row['starts_on'] ?? null ),
				'first_ends_on' => $first,
				'note'          => self::note_for( $end, $first, $was_visible && $before[ $stage ] !== $end, $note, $row['note'] ?? '' ),
			);

			if ( $was_visible && $before[ $stage ] !== $end ) {
				$moved[] = $stage;
			}

			if ( null === $row ) {
				if ( null !== $fields['starts_on'] || null !== $first || '' !== $fields['note'] ) {
					$this->app->schedule->insert( $client_case->id, 'stage', $fields + array( 'stage' => $stage ), $now );
				}
			} elseif ( array( $row['starts_on'], $row['first_ends_on'], $row['note'] ) !== array_values( $fields ) ) {
				$this->app->schedule->update( $row['id'], $fields, $now );
			}
		}

		if ( $visible ) {
			$this->publish_milestones( $client_case->id, $now );
		}

		if ( $visible !== $was_visible ) {
			$this->app->cases->update( $client_case->id, array( 'schedule_visible' => $visible ? 1 : 0 ), $now );
		}

		$this->app->audit->record(
			'schedule.updated',
			$client_case->id,
			'case',
			$client_case->id,
			array_filter(
				array(
					'moved'   => $moved,
					'visible' => $visible !== $was_visible ? $visible : null,
					'notify'  => $notify && $visible,
				)
			),
			$now
		);

		if ( $notify && $visible ) {
			$this->app->notifier->enqueue( $client_case->id, 'schedule_changed', $client_case->id, false );
		}

		return true;
	}

	/**
	 * Milestones on a published timeline: their date now is their first.
	 *
	 * @param int    $case_id Case.
	 * @param string $now     UTC DATETIME.
	 */
	private function publish_milestones( int $case_id, string $now ): void {
		foreach ( $this->app->schedule->for_case( $case_id ) as $row ) {
			if ( 'milestone' === $row['kind'] && null === $row['first_ends_on'] && null !== $row['ends_on'] ) {
				$this->app->schedule->update( $row['id'], array( 'first_ends_on' => $row['ends_on'] ), $now );
			}
		}
	}

	/**
	 * The note an item keeps: none once it is back on its first date; the new
	 * note when its date just moved; else the one it had.
	 *
	 * @param string|null $end   Date now.
	 * @param string|null $first Date first shown.
	 * @param bool        $moved Moved in this change.
	 * @param string      $note  Note given with this change.
	 * @param string      $kept  Note it had.
	 * @return string
	 */
	private static function note_for( ?string $end, ?string $first, bool $moved, string $note, string $kept ): string {
		if ( null === $first || $end === $first ) {
			return '';
		}

		return $moved && '' !== $note ? $note : $kept;
	}

	/**
	 * Check typed dates.
	 *
	 * @param array<int, string> $starts Start by stage.
	 * @param array<int, string> $ends   End by stage.
	 * @return array{starts:array<int, string|null>, ends:array<int, string|null>, errors:array<string, string>}
	 */
	private static function check_dates( array $starts, array $ends ): array {
		$clean = array(
			'starts' => array(),
			'ends'   => array(),
			'errors' => array(),
		);

		for ( $stage = 1; $stage <= StageProgress::COUNT; $stage++ ) {
			$start = trim( $starts[ $stage ] ?? '' );
			$end   = trim( $ends[ $stage ] ?? '' );

			foreach ( array(
				'start' => $start,
				'end'   => $end,
			) as $field => $date ) {
				if ( '' !== $date && ! Timeline::is_date( $date ) ) {
					$clean['errors'][ $field . '-' . $stage ] = 'date';
				}
			}

			if ( '' !== $start && '' !== $end && ! isset( $clean['errors'][ 'start-' . $stage ] ) && ! isset( $clean['errors'][ 'end-' . $stage ] ) && $end < $start ) {
				$clean['errors'][ 'end-' . $stage ] = 'before_start';
			}

			$clean['starts'][ $stage ] = '' === $start ? null : $start;
			$clean['ends'][ $stage ]   = '' === $end ? null : $end;
		}

		return $clean;
	}

	/**
	 * Checked fields of a milestone form.
	 *
	 * @param CaseRecord            $client_case Case.
	 * @param array<string, string> $input       label, stage, date.
	 * @param int                   $actor       Acting user.
	 * @return array{label:string, stage:int, ends_on:string}|WP_Error
	 */
	private function milestone_fields( CaseRecord $client_case, array $input, int $actor ): array|WP_Error {
		$refused = $this->refused( $client_case, $actor );

		if ( null !== $refused ) {
			return $refused;
		}

		$label  = mb_substr( Text::line( $input['label'] ?? '' ), 0, 121 );
		$stage  = (int) ( $input['stage'] ?? 0 );
		$date   = trim( $input['date'] ?? '' );
		$errors = array();

		if ( '' === $label ) {
			$errors['label'] = 'required';
		} elseif ( Text::length( $label ) > 120 ) {
			$errors['label'] = 'too_long';
		}

		if ( $stage < 1 || $stage > StageProgress::COUNT ) {
			$errors['stage'] = 'invalid';
		}

		if ( '' === $date ) {
			$errors['date'] = 'required';
		} elseif ( ! Timeline::is_date( $date ) ) {
			$errors['date'] = 'date';
		}

		if ( array() !== $errors ) {
			return Errors::invalid( $errors );
		}

		return array(
			'label'   => $label,
			'stage'   => $stage,
			'ends_on' => $date,
		);
	}

	/**
	 * Why the actor may not change this case's timeline now, if they may not.
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
	 * A milestone of this case.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param int        $id          Row.
	 * @return ScheduleRow|null
	 */
	private function milestone( CaseRecord $client_case, int $id ): ?array {
		$row = $this->app->schedule->find( $id );

		return null !== $row && 'milestone' === $row['kind'] && $row['case_id'] === $client_case->id ? $row : null;
	}

	/**
	 * The stage rows of a case by stage number.
	 *
	 * @param int $case_id Case.
	 * @return array<int, ScheduleRow>
	 */
	private function stage_rows( int $case_id ): array {
		return self::by_stage( $this->app->schedule->for_case( $case_id ) );
	}

	/**
	 * The stage rows among a case's rows, by stage number.
	 *
	 * @param array<int, array> $all Rows.
	 * @phpstan-param array<int, ScheduleRow> $all
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return array<int, ScheduleRow>
	 */
	private static function by_stage( array $all ): array {
		$rows = array();

		foreach ( $all as $row ) {
			if ( 'stage' === $row['kind'] ) {
				$rows[ $row['stage'] ] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Planned starts by stage.
	 *
	 * @param CaseRecord $client_case Case.
	 * @return array<int, string|null>
	 */
	private function starts( CaseRecord $client_case ): array {
		$rows   = $this->stage_rows( $client_case->id );
		$starts = array();

		for ( $stage = 1; $stage <= StageProgress::COUNT; $stage++ ) {
			$starts[ $stage ] = $rows[ $stage ]['starts_on'] ?? null;
		}

		return $starts;
	}

	/**
	 * Rows and geometry of the timeline.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param bool       $staff       For staff: every stage, with late flags.
	 * @return array{layout:array<string, mixed>|null, rows:array<int, array<string, mixed>>}
	 */
	private function build( CaseRecord $client_case, bool $staff ): array {
		$today      = $this->app->clock->today();
		$all        = $this->app->schedule->for_case( $client_case->id );
		$rows       = array();
		$items      = array();
		$milestones = array();
		$published  = $client_case->schedule_visible;

		foreach ( $all as $row ) {
			if ( 'milestone' === $row['kind'] ) {
				$milestones[ $row['stage'] ][] = $row;
			}
		}

		$stage_rows = self::by_stage( $all );

		foreach ( $client_case->stages as $number => $state ) {
			if ( ! $staff && StageStatus::Skipped->value === $state['status'] ) {
				continue;
			}

			$row   = $stage_rows[ $number ] ?? null;
			$start = $row['starts_on'] ?? null;
			$end   = $state['due'];
			$first = $row['first_ends_on'] ?? null;
			$key   = 'stage-' . $number;

			$items[ $key ] = array(
				'start'        => $start,
				'end'          => $end,
				'actual_start' => $state['started'],
				'actual_end'   => $state['done'],
			);
			$entries       = array();

			foreach ( $milestones[ $number ] ?? array() as $milestone ) {
				$items[ 'm-' . $milestone['id'] ] = array(
					'start'        => null,
					'end'          => $milestone['ends_on'],
					'actual_start' => null,
					'actual_end'   => null,
				);
				$entries[]                        = $this->milestone_view( $milestone, $published, $staff, $today );
			}

			$rows[] = array(
				'key'          => $key,
				'number'       => $number,
				'label'        => Stages::name( $number ),
				'status'       => $state['status'],
				'status_label' => Labels::stage_statuses()[ $state['status'] ] ?? '',
				'start'        => $start,
				'end'          => $end,
				'start_label'  => Labels::date( $start ),
				'end_label'    => Labels::date( $end ),
				'actual'       => self::actual( $state ),
				'first_label'  => Labels::date( $first ),
				'moved'        => $published && null !== $first && null !== $end && $end !== $first,
				'note'         => $row['note'] ?? '',
				'late'         => $staff && StageProgress::is_late( $state, $today ),
				'milestones'   => $entries,
			);
		}

		return array(
			'layout' => Timeline::layout( $items, $today ),
			'rows'   => $rows,
		);
	}

	/**
	 * A milestone for a screen.
	 *
	 * @param array  $milestone Row.
	 * @param bool   $published The timeline is shown to the client.
	 * @param bool   $staff     For staff.
	 * @param string $today     Y-m-d.
	 * @phpstan-param ScheduleRow $milestone
	 * @return array<string, mixed>
	 */
	private function milestone_view( array $milestone, bool $published, bool $staff, string $today ): array {
		return array(
			'id'          => $milestone['id'],
			'key'         => 'm-' . $milestone['id'],
			'label'       => $milestone['label'],
			'stage'       => $milestone['stage'],
			'date'        => $milestone['ends_on'],
			'end_label'   => Labels::date( $milestone['ends_on'] ),
			'first_label' => Labels::date( $milestone['first_ends_on'] ),
			'moved'       => $published && null !== $milestone['first_ends_on'] && $milestone['ends_on'] !== $milestone['first_ends_on'],
			'note'        => $milestone['note'],
			'done'        => null !== $milestone['done_on'],
			'done_label'  => Labels::date( $milestone['done_on'] ),
			'late'        => $staff && null === $milestone['done_on'] && null !== $milestone['ends_on'] && $milestone['ends_on'] < $today,
		);
	}

	/**
	 * When work on a stage really happened, in words.
	 *
	 * @param array{status:string, started:string|null, done:string|null, due:string|null} $state Stage.
	 * @return string
	 */
	private static function actual( array $state ): string {
		return match ( true ) {
			null !== $state['started'] && null !== $state['done'] => sprintf(
				/* translators: 1: start date, 2: end date. */
				__( 'בפועל: %1$s עד %2$s', 'diromax-portal' ),
				Labels::date( $state['started'] ),
				Labels::date( $state['done'] )
			),
			/* translators: %s: date. */
			null !== $state['started'] => sprintf( __( 'בפועל: התחיל ב-%s', 'diromax-portal' ), Labels::date( $state['started'] ) ),
			/* translators: %s: date. */
			null !== $state['done']    => sprintf( __( 'בפועל: הסתיים ב-%s', 'diromax-portal' ), Labels::date( $state['done'] ) ),
			default                    => '',
		};
	}
}
