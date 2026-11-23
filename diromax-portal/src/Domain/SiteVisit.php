<?php
/**
 * Site visits and findings.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The rules of a site visit and its findings (spec 23): what a visit and a
 * finding hold, and when a finding is overdue. A finding is open until the
 * manager marks it fixed (decision 41); its supplier and due date are noted
 * for follow-up, not sent anywhere (decision 42).
 *
 * Pure: no WordPress, no database. Dates are Y-m-d.
 *
 * @phpstan-type VisitFields array{visit_on:string, attendees:string, summary:string, visible:bool}
 * @phpstan-type FindingFields array{title:string, location:string, severity:string, supplier:string, due_on:string|null, visible:bool}
 */
final class SiteVisit {

	/** How bad a finding is, mildest first. */
	public const SEVERITIES = array( 'low', 'medium', 'high' );

	/** Longest texts, in characters. */
	private const MAX_ATTENDEES = 300;
	private const MAX_SUMMARY   = 3000;
	private const MAX_TITLE     = 160;
	private const MAX_LOCATION  = 120;
	private const MAX_SUPPLIER  = 120;
	public const MAX_NOTE       = 300;

	/**
	 * Check a visit: a date not after today, who was there, a summary.
	 *
	 * @param array<string, string> $input visit_on, attendees, summary, visible.
	 * @param string                $today Today.
	 * @return array{fields:VisitFields|null, errors:array<string, string>}
	 */
	public static function visit( array $input, string $today ): array {
		$errors    = array();
		$date      = trim( $input['visit_on'] ?? '' );
		$attendees = Text::line( $input['attendees'] ?? '' );
		$summary   = Text::block( $input['summary'] ?? '' );

		if ( '' === $date ) {
			$errors['visit_on'] = 'required';
		} elseif ( ! self::is_date( $date ) ) {
			$errors['visit_on'] = 'date';
		} elseif ( $date > $today ) {
			$errors['visit_on'] = 'future';
		}

		if ( Text::length( $attendees ) > self::MAX_ATTENDEES ) {
			$errors['attendees'] = 'too_long';
		}

		if ( '' === $summary ) {
			$errors['summary'] = 'required';
		} elseif ( Text::length( $summary ) > self::MAX_SUMMARY ) {
			$errors['summary'] = 'too_long';
		}

		if ( array() !== $errors ) {
			return array(
				'fields' => null,
				'errors' => $errors,
			);
		}

		return array(
			'fields' => array(
				'visit_on'  => $date,
				'attendees' => $attendees,
				'summary'   => $summary,
				'visible'   => '1' === ( $input['visible'] ?? '' ),
			),
			'errors' => array(),
		);
	}

	/**
	 * Check a finding: what, where, how bad, who fixes it and by when.
	 *
	 * @param array<string, string> $input title, location, severity, supplier, due_on, visible.
	 * @return array{fields:FindingFields|null, errors:array<string, string>}
	 */
	public static function finding( array $input ): array {
		$errors   = array();
		$title    = Text::line( $input['title'] ?? '' );
		$location = Text::line( $input['location'] ?? '' );
		$severity = (string) ( $input['severity'] ?? '' );
		$supplier = Text::line( $input['supplier'] ?? '' );
		$due      = trim( $input['due_on'] ?? '' );

		if ( '' === $title ) {
			$errors['title'] = 'required';
		} elseif ( Text::length( $title ) > self::MAX_TITLE ) {
			$errors['title'] = 'too_long';
		}

		if ( Text::length( $location ) > self::MAX_LOCATION ) {
			$errors['location'] = 'too_long';
		}

		if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
			$errors['severity'] = 'invalid';
		}

		if ( Text::length( $supplier ) > self::MAX_SUPPLIER ) {
			$errors['supplier'] = 'too_long';
		}

		if ( '' !== $due && ! self::is_date( $due ) ) {
			$errors['due_on'] = 'date';
		}

		if ( array() !== $errors ) {
			return array(
				'fields' => null,
				'errors' => $errors,
			);
		}

		return array(
			'fields' => array(
				'title'    => $title,
				'location' => $location,
				'severity' => $severity,
				'supplier' => $supplier,
				'due_on'   => '' === $due ? null : $due,
				'visible'  => '1' === ( $input['visible'] ?? '' ),
			),
			'errors' => array(),
		);
	}

	/**
	 * Is an open finding past its due date?
	 *
	 * @param array{status:string, due_on:string|null} $finding Finding.
	 * @param string                                   $today   Today.
	 * @return bool
	 */
	public static function is_overdue( array $finding, string $today ): bool {
		return 'open' === $finding['status'] && null !== $finding['due_on'] && $finding['due_on'] < $today;
	}

	/**
	 * Open, overdue and fixed findings.
	 *
	 * @param array<int, array{status:string, due_on:string|null}> $findings Findings.
	 * @param string                                               $today    Today.
	 * @return array{open:int, overdue:int, fixed:int}
	 */
	public static function counts( array $findings, string $today ): array {
		$counts = array(
			'open'    => 0,
			'overdue' => 0,
			'fixed'   => 0,
		);

		foreach ( $findings as $finding ) {
			++$counts[ 'open' === $finding['status'] ? 'open' : 'fixed' ];
			$counts['overdue'] += self::is_overdue( $finding, $today ) ? 1 : 0;
		}

		return $counts;
	}

	/**
	 * A real Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
