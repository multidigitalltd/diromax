<?php
/**
 * Calendar invitations.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One meeting as an iCalendar file (RFC 5545), for the email invitation and
 * the "add to calendar" link (spec 20.1). Google Calendar, Outlook and the
 * iPhone read it. The same UID across a meeting's life, with SEQUENCE going
 * up on every change, lets a calendar update or cancel the event it holds.
 *
 * Pure: no WordPress calls. Times are UTC ("Y-m-d H:i:s") and written with
 * a Z, so no time-zone block is needed.
 *
 * @phpstan-type IcsEvent array{uid:string, sequence:int, start:string, end:string, stamp:string, summary:string, location:string, description:string, url:string, organizer_name:string, organizer_email:string, attendee_name:string, attendee_email:string, cancelled:bool}
 */
final class Ics {

	/** Octets per line before folding (RFC 5545 3.1). */
	private const LINE = 75;

	/**
	 * The file.
	 *
	 * @param array<string, mixed> $event Event.
	 * @phpstan-param IcsEvent $event
	 * @return string CRLF lines.
	 */
	public static function event( array $event ): string {
		$cancelled = $event['cancelled'];
		$lines     = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Diromax//Client portal//HE',
			'CALSCALE:GREGORIAN',
			'METHOD:' . ( $cancelled ? 'CANCEL' : 'REQUEST' ),
			'BEGIN:VEVENT',
			'UID:' . self::text( $event['uid'] ),
			'SEQUENCE:' . max( 0, $event['sequence'] ),
			'DTSTAMP:' . self::time( $event['stamp'] ),
			'DTSTART:' . self::time( $event['start'] ),
			'DTEND:' . self::time( $event['end'] ),
			'SUMMARY:' . self::text( $event['summary'] ),
			'STATUS:' . ( $cancelled ? 'CANCELLED' : 'CONFIRMED' ),
			'TRANSP:OPAQUE',
		);

		if ( '' !== $event['location'] ) {
			$lines[] = 'LOCATION:' . self::text( $event['location'] );
		}

		if ( '' !== $event['description'] ) {
			$lines[] = 'DESCRIPTION:' . self::text( $event['description'] );
		}

		if ( '' !== $event['url'] ) {
			$lines[] = 'URL:' . self::text( $event['url'] );
		}

		$organizer = self::address( $event['organizer_email'] );
		$attendee  = self::address( $event['attendee_email'] );

		if ( '' !== $organizer ) {
			$lines[] = 'ORGANIZER;CN=' . self::param( $event['organizer_name'] ) . ':mailto:' . $organizer;
		}

		if ( '' !== $attendee ) {
			// No RSVP: answers would go to the manager's mailbox, not into the case.
			$lines[] = 'ATTENDEE;CN=' . self::param( $event['attendee_name'] ) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:' . $attendee;
		}

		if ( ! $cancelled ) {
			$lines = array_merge( $lines, array( 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::text( $event['summary'] ), 'TRIGGER:-PT1H', 'END:VALARM' ) );
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * A UTC time as 20261124T070000Z.
	 *
	 * @param string $utc Y-m-d H:i:s.
	 * @return string
	 */
	private static function time( string $utc ): string {
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );

		return false === $time ? '19700101T000000Z' : $time->format( 'Ymd\THis\Z' );
	}

	/**
	 * A TEXT value: backslash, semicolon, comma and newline escaped, other
	 * control characters dropped.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function text( string $value ): string {
		$value = str_replace( array( '\\', ';', ',', "\r\n", "\r", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ), $value );

		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
	}

	/**
	 * A parameter value (CN): quoted, without quotes or control characters.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function param( string $value ): string {
		return '"' . (string) preg_replace( '/["\x00-\x1F\x7F]/u', '', $value ) . '"';
	}

	/**
	 * An email address for a mailto: value; '' when it is not one.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	private static function address( string $email ): string {
		return 1 === preg_match( '/^[^\s@:;,"<>]+@[^\s@:;,"<>]+$/', $email ) ? $email : '';
	}

	/**
	 * Fold a line at 75 octets, never inside a UTF-8 character.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function fold( string $line ): string {
		$out   = '';
		$width = 0;

		foreach ( mb_str_split( $line ) as $char ) {
			$size = strlen( $char );

			if ( $width + $size > self::LINE ) {
				$out  .= "\r\n ";
				$width = 1;
			}

			$out   .= $char;
			$width += $size;
		}

		return $out;
	}
}
