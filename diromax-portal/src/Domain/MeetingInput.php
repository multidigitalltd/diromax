<?php
/**
 * Meeting form validation.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the meeting form against allowlists (spec 20.1) and turns the
 * local date and time into UTC. Pure: the time zone, "now" and the case's
 * members come in as arguments.
 *
 * @phpstan-type MeetingFields array{title:string, kind:string, starts_at:string, minutes:int, location:string, note:string, participants:int[], notify:bool}
 */
final class MeetingInput {

	/** Kinds of meeting. */
	public const KINDS = array( 'office', 'site', 'call', 'video' );

	/** Lengths offered, minutes. */
	public const LENGTHS = array( 30, 45, 60, 90, 120, 180, 240 );

	/** Longest title. */
	private const MAX_TITLE = 120;

	/** Longest location. */
	private const MAX_LOCATION = 200;

	/** Longest note. */
	private const MAX_NOTE = 1000;

	/**
	 * Validate.
	 *
	 * @param array<string, string> $input   Form fields: title, kind, date (Y-m-d), time (H:i), minutes, location, note, notify.
	 * @param int[]                 $chosen  Participants ticked.
	 * @param int[]                 $members Members of the case who may be invited.
	 * @param int                   $now     Unix time.
	 * @param \DateTimeZone         $zone    The site's time zone, in which date and time are typed.
	 * @return array{fields:MeetingFields|null, errors:array<string, string>} Fields when valid, else errors by field.
	 */
	public static function validate( array $input, array $chosen, array $members, int $now, \DateTimeZone $zone ): array {
		$errors = array();
		$title  = Text::line( $input['title'] ?? '' );
		$kind   = (string) ( $input['kind'] ?? '' );
		$length = (int) ( $input['minutes'] ?? 0 );
		$place  = Text::line( $input['location'] ?? '' );
		$note   = Text::block( $input['note'] ?? '' );

		if ( '' === $title ) {
			$errors['title'] = 'required';
		} elseif ( Text::length( $title ) > self::MAX_TITLE ) {
			$errors['title'] = 'too_long';
		}

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$errors['kind'] = 'invalid';
		}

		if ( ! in_array( $length, self::LENGTHS, true ) ) {
			$errors['minutes'] = 'invalid';
		}

		if ( Text::length( $place ) > self::MAX_LOCATION ) {
			$errors['location'] = 'too_long';
		}

		if ( Text::length( $note ) > self::MAX_NOTE ) {
			$errors['note'] = 'too_long';
		}

		$start = self::start( (string) ( $input['date'] ?? '' ), (string) ( $input['time'] ?? '' ), $zone );

		if ( null === $start ) {
			$errors['date'] = 'invalid';
		} elseif ( $start->getTimestamp() <= $now ) {
			$errors['date'] = 'past';
		}

		$people = array_values( array_unique( array_map( 'intval', $chosen ) ) );

		if ( array() !== array_diff( $people, $members ) ) {
			$errors['participants'] = 'invalid';
		}

		if ( array() !== $errors || null === $start ) {
			return array(
				'fields' => null,
				'errors' => $errors,
			);
		}

		$fields = array(
			'title'        => $title,
			'kind'         => $kind,
			'starts_at'    => $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'minutes'      => $length,
			'location'     => $place,
			'note'         => $note,
			'participants' => $people,
			'notify'       => '1' === ( $input['notify'] ?? '' ),
		);

		return array(
			'fields' => $fields,
			'errors' => array(),
		);
	}

	/**
	 * The start, from a local date and time.
	 *
	 * @param string        $date Y-m-d.
	 * @param string        $time H:i.
	 * @param \DateTimeZone $zone Time zone.
	 * @return \DateTimeImmutable|null Null when either is not a real date or time.
	 */
	private static function start( string $date, string $time, \DateTimeZone $zone ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return null;
		}

		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $zone );

		return false !== $start && $start->format( 'Y-m-d H:i' ) === $date . ' ' . $time ? $start : null;
	}
}
