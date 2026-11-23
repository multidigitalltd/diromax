<?php
/**
 * Tests for calendar invitations (spec 20.1).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\Ics;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\Ics
 */
final class IcsTest extends TestCase {

	/**
	 * An event with overrides.
	 *
	 * @param array<string, mixed> $overrides Fields.
	 * @return array{uid:string, sequence:int, start:string, end:string, stamp:string, summary:string, location:string, description:string, url:string, organizer_name:string, organizer_email:string, attendee_name:string, attendee_email:string, cancelled:bool}
	 */
	private static function event( array $overrides = array() ): array {
		return array_merge(
			array(
				'uid'             => 'meeting-abc@example.org',
				'sequence'        => 2,
				'start'           => '2026-11-24 07:00:00',
				'end'             => '2026-11-24 08:30:00',
				'stamp'           => '2026-11-23 10:00:00',
				'summary'         => 'סיור בשטח, רח׳ הזית 14',
				'location'        => 'הזית 14; קריית אונו',
				'description'     => "שורה ראשונה\nשורה שנייה, עם פסיק",
				'url'             => 'https://example.org/portal/case/x/',
				'organizer_name'  => 'דניאל "לוי"',
				'organizer_email' => 'daniel@example.org',
				'attendee_name'   => 'משפחת כהן',
				'attendee_email'  => 'cohen@example.org',
				'cancelled'       => false,
			),
			$overrides
		);
	}

	public function test_an_invitation_has_the_fields_calendars_need(): void {
		$ics      = Ics::event( self::event() );
		$unfolded = str_replace( "\r\n ", '', $ics );

		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "METHOD:REQUEST\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20261124T070000Z\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20261124T083000Z\r\n", $ics );
		$this->assertStringContainsString( "SEQUENCE:2\r\n", $ics );
		$this->assertStringContainsString( 'LOCATION:הזית 14\\; קריית אונו', $unfolded );
		$this->assertStringContainsString( 'DESCRIPTION:שורה ראשונה\\nשורה שנייה\\, עם פסיק', $unfolded );
		$this->assertStringContainsString( 'ORGANIZER;CN="דניאל לוי":mailto:daniel@example.org', $unfolded, 'Quotes cannot break the parameter.' );
		$this->assertStringContainsString( 'RSVP=FALSE:mailto:cohen@example.org', $unfolded );
		$this->assertStringContainsString( 'BEGIN:VALARM', $ics );
	}

	public function test_a_cancellation_keeps_the_uid_and_cancels(): void {
		$ics = Ics::event(
			self::event(
				array(
					'cancelled' => true,
					'sequence'  => 3,
				)
			)
		);

		$this->assertStringContainsString( "METHOD:CANCEL\r\n", $ics );
		$this->assertStringContainsString( "STATUS:CANCELLED\r\n", $ics );
		$this->assertStringContainsString( "UID:meeting-abc@example.org\r\n", $ics );
		$this->assertStringContainsString( "SEQUENCE:3\r\n", $ics );
		$this->assertStringNotContainsString( 'VALARM', $ics );
	}

	public function test_long_lines_fold_at_75_octets_without_splitting_a_letter(): void {
		$ics = Ics::event( self::event( array( 'description' => str_repeat( 'תיאור ארוך מאוד ', 20 ) ) ) );

		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ) );
			$this->assertTrue( mb_check_encoding( $line, 'UTF-8' ), 'Each line is whole UTF-8.' );
		}

		$this->assertStringContainsString( str_repeat( 'תיאור ארוך מאוד ', 20 ), str_replace( "\r\n ", '', $ics ) );
	}

	public function test_bad_addresses_and_control_characters_are_left_out(): void {
		$ics = Ics::event(
			self::event(
				array(
					'summary'        => "פגישה\x07 עם BEL",
					'attendee_email' => "evil@example.org\r\nATTENDEE:mailto:x@y",
				)
			)
		);

		$this->assertStringContainsString( 'SUMMARY:פגישה עם BEL', $ics );
		$this->assertStringNotContainsString( 'x@y', $ics );
		$this->assertStringNotContainsString( 'ATTENDEE', $ics, 'No attendee rather than a broken one.' );
	}
}
