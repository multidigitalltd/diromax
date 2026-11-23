<?php
/**
 * Tests for the meeting form (spec 20.1).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\MeetingInput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\MeetingInput
 */
final class MeetingInputTest extends TestCase {

	/** 2026-11-23 10:00 UTC (12:00 in Israel). */
	private const NOW = 1795428000;

	/**
	 * A valid form with overrides.
	 *
	 * @param array<string, string> $overrides Fields.
	 * @return array<string, string>
	 */
	private static function form( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'    => '  סיור בשטח ',
				'kind'     => 'site',
				'date'     => '2026-11-24',
				'time'     => '09:30',
				'minutes'  => '90',
				'location' => 'רח׳ הזית 14',
				'note'     => "להביא את התוכניות\nולבוא בנעליים סגורות",
				'notify'   => '1',
			),
			$overrides
		);
	}

	/**
	 * Fields when valid, else errors.
	 *
	 * @param array<string, string>  $input   Form.
	 * @param array<int, int|string> $chosen  Ticked.
	 * @param int[]                  $members Members.
	 * @param int                    $now     Now.
	 * @param \DateTimeZone          $zone    Zone.
	 * @return array<string, mixed>
	 */
	private static function check( array $input, array $chosen, array $members, int $now, \DateTimeZone $zone ): array {
		$result = MeetingInput::validate( $input, array_map( 'intval', $chosen ), $members, $now, $zone );

		return $result['fields'] ?? $result['errors'];
	}

	public function test_a_valid_meeting_is_converted_to_utc(): void {
		$fields = self::check( self::form(), array( 7, '9', 7 ), array( 7, 9, 11 ), self::NOW, new \DateTimeZone( 'Asia/Jerusalem' ) );

		$this->assertSame(
			array(
				'title'        => 'סיור בשטח',
				'kind'         => 'site',
				'starts_at'    => '2026-11-24 07:30:00',
				'minutes'      => 90,
				'location'     => 'רח׳ הזית 14',
				'note'         => "להביא את התוכניות\nולבוא בנעליים סגורות",
				'participants' => array( 7, 9 ),
				'notify'       => true,
			),
			$fields
		);
	}

	public function test_summer_time_is_taken_from_the_date(): void {
		$fields = self::check( self::form( array( 'date' => '2027-07-01' ) ), array(), array(), self::NOW, new \DateTimeZone( 'Asia/Jerusalem' ) );

		$this->assertSame( '2027-07-01 06:30:00', $fields['starts_at'] ?? '' );
	}

	public function test_bad_input_is_reported_by_field(): void {
		$errors = self::check(
			self::form(
				array(
					'title'    => '',
					'kind'     => 'party',
					'minutes'  => '61',
					'date'     => '2026-02-30',
					'location' => str_repeat( 'א', 201 ),
				)
			),
			array( 7, 99 ),
			array( 7 ),
			self::NOW,
			new \DateTimeZone( 'Asia/Jerusalem' )
		);

		$this->assertSame(
			array(
				'title'        => 'required',
				'kind'         => 'invalid',
				'minutes'      => 'invalid',
				'location'     => 'too_long',
				'date'         => 'invalid',
				'participants' => 'invalid',
			),
			$errors,
			'A person outside the case cannot be invited.'
		);
	}

	public function test_a_meeting_in_the_past_or_a_bad_time_is_refused(): void {
		$zone = new \DateTimeZone( 'Asia/Jerusalem' );

		$this->assertSame(
			array( 'date' => 'past' ),
			self::check(
				self::form(
					array(
						'date' => '2026-11-23',
						'time' => '11:59',
					)
				),
				array(),
				array(),
				self::NOW,
				$zone
			)
		);
		$this->assertSame( array( 'date' => 'invalid' ), self::check( self::form( array( 'time' => '24:00' ) ), array(), array(), self::NOW, $zone ) );
		$this->assertSame( array( 'date' => 'invalid' ), self::check( self::form( array( 'time' => '9:30' ) ), array(), array(), self::NOW, $zone ) );
		$this->assertArrayHasKey(
			'starts_at',
			self::check(
				self::form(
					array(
						'date' => '2026-11-23',
						'time' => '12:01',
					)
				),
				array(),
				array(),
				self::NOW,
				$zone
			)
		);
	}
}
