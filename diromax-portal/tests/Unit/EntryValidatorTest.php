<?php
/**
 * Tests for EntryValidator and NotificationSchedule.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\EntryDraft;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\EntryValidator;
use Diromax\Portal\Domain\Funding;
use Diromax\Portal\Domain\NotificationSchedule;
use Diromax\Portal\Domain\PayTo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\EntryValidator
 * @covers \Diromax\Portal\Domain\EntryDraft
 * @covers \Diromax\Portal\Domain\NotificationSchedule
 */
final class EntryValidatorTest extends TestCase {

	private EntryValidator $validator;

	protected function setUp(): void {
		$this->validator = new EntryValidator( array( 'fees', 'architecture' ), 1800, '2026-11-18' );
	}

	/**
	 * @param array<string, string> $overrides Fields to change.
	 * @return array<string, string>
	 */
	private static function expense( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'        => 'expense',
				'status'      => 'paid',
				'entry_date'  => '2026-11-12',
				'amount'      => '12,300',
				'vat_mode'    => 'included',
				'category'    => 'fees',
				'payee'       => 'הוועדה המקומית',
				'description' => 'אגרת בקשה להיתר',
				'funding'     => 'project',
				'visible'     => '1',
				'notify'      => '1',
			),
			$overrides
		);
	}

	public function test_valid_expense_becomes_a_draft_with_vat_split(): void {
		$draft = $this->validator->validate( self::expense() );

		$this->assertInstanceOf( EntryDraft::class, $draft );
		$this->assertSame( EntryType::Expense, $draft->type );
		$this->assertSame( EntryStatus::Paid, $draft->status );
		$this->assertSame( Funding::Project, $draft->funding );
		$this->assertSame( 1230000, $draft->total );
		$this->assertSame( 1042373, $draft->net );
		$this->assertSame( 1800, $draft->vat_rate );
		$this->assertTrue( $draft->visible );
		$this->assertTrue( $draft->notify );
	}

	public function test_exempt_expense_stores_zero_rate(): void {
		$draft = $this->validator->validate( self::expense( array( 'vat_mode' => 'exempt' ) ) );

		$this->assertInstanceOf( EntryDraft::class, $draft );
		$this->assertSame( 0, $draft->vat_rate );
		$this->assertSame( 0, $draft->vat );
	}

	public function test_unchecked_boxes_are_false(): void {
		$draft = $this->validator->validate(
			self::expense(
				array(
					'visible' => '',
					'notify'  => 'yes',
				)
			)
		);

		$this->assertInstanceOf( EntryDraft::class, $draft );
		$this->assertFalse( $draft->visible );
		$this->assertFalse( $draft->notify );
	}

	public function test_unknown_type_is_rejected_before_anything_else(): void {
		$this->assertSame( array( 'type' => 'invalid' ), $this->validator->validate( array( 'type' => 'transfer' ) ) );
	}

	public function test_missing_required_expense_fields(): void {
		$errors = $this->validator->validate(
			array(
				'type'    => 'expense',
				'status'  => 'open',
				'funding' => 'cash',
			)
		);

		$this->assertSame(
			array(
				'entry_date'  => 'required',
				'amount'      => 'required',
				'description' => 'required',
				'status'      => 'invalid',
				'category'    => 'required',
				'payee'       => 'required',
				'funding'     => 'invalid',
			),
			$errors
		);
	}

	/**
	 * @dataProvider invalid_fields
	 *
	 * @param array<string, string> $overrides Field values.
	 */
	public function test_invalid_field_values( array $overrides, string $field, string $code ): void {
		$errors = $this->validator->validate( self::expense( $overrides ) );

		$this->assertIsArray( $errors );
		$this->assertSame( $code, $errors[ $field ] ?? null );
	}

	/**
	 * @return array<string, array{array<string, string>, string, string}>
	 */
	public static function invalid_fields(): array {
		return array(
			'zero amount'           => array( array( 'amount' => '0' ), 'amount', 'invalid' ),
			'negative amount'       => array( array( 'amount' => '-1' ), 'amount', 'invalid' ),
			'not a date'            => array( array( 'entry_date' => '12/11/2026' ), 'entry_date', 'invalid' ),
			'impossible date'       => array( array( 'entry_date' => '2026-02-30' ), 'entry_date', 'invalid' ),
			'before 2000'           => array( array( 'entry_date' => '1999-12-31' ), 'entry_date', 'invalid' ),
			'more than a year out'  => array( array( 'entry_date' => '2027-11-19' ), 'entry_date', 'invalid' ),
			'unknown category'      => array( array( 'category' => 'yacht' ), 'category', 'invalid' ),
			'description too long'  => array( array( 'description' => str_repeat( 'א', 201 ) ), 'description', 'too_long' ),
			'payee too long'        => array( array( 'payee' => str_repeat( 'x', 121 ) ), 'payee', 'too_long' ),
			'reference too long'    => array( array( 'reference' => str_repeat( '1', 61 ) ), 'reference', 'too_long' ),
			'note too long'         => array( array( 'internal_note' => str_repeat( 'n', 1001 ) ), 'internal_note', 'too_long' ),
			'blank after normalize' => array( array( 'description' => " \t\u{202E} " ), 'description', 'required' ),
		);
	}

	public function test_payment_ignores_expense_only_fields(): void {
		$draft = $this->validator->validate(
			array(
				'type'        => 'payment',
				'entry_date'  => '2026-07-20',
				'amount'      => '60000',
				'description' => 'העברה בנקאית',
				'method'      => 'transfer',
				'reference'   => '448210',
				'funding'     => 'project',
				'category'    => 'fees',
				'vat_mode'    => 'excluded',
			)
		);

		$this->assertInstanceOf( EntryDraft::class, $draft );
		$this->assertSame( EntryStatus::Paid, $draft->status );
		$this->assertNull( $draft->funding );
		$this->assertSame( '', $draft->category );
		$this->assertSame( 6000000, $draft->total );
		$this->assertSame( 0, $draft->vat );
		$this->assertSame( '448210', $draft->reference );
	}

	public function test_payment_method_must_be_known(): void {
		$errors = $this->validator->validate(
			array(
				'type'        => 'payment',
				'entry_date'  => '2026-07-20',
				'amount'      => '1',
				'description' => 'x',
				'method'      => 'bitcoin',
			)
		);

		$this->assertSame( array( 'method' => 'invalid' ), $errors );
	}

	public function test_refund_is_paid(): void {
		$draft = $this->validator->validate(
			array(
				'type'        => 'refund',
				'entry_date'  => '2026-07-20',
				'amount'      => '100',
				'description' => 'החזר',
			)
		);

		$this->assertInstanceOf( EntryDraft::class, $draft );
		$this->assertSame( EntryStatus::Paid, $draft->status );
	}

	public function test_request_to_supplier_needs_payee_and_due_date(): void {
		$errors = $this->validator->validate(
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-05',
				'amount'      => '40000',
				'description' => 'מקדמה',
				'pay_to'      => 'supplier',
			)
		);

		$this->assertSame(
			array(
				'due_date' => 'required',
				'payee'    => 'required',
			),
			$errors
		);
	}

	public function test_request_due_date_cannot_precede_entry_date(): void {
		$errors = $this->validator->validate(
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-05',
				'due_date'    => '2026-11-01',
				'amount'      => '40000',
				'description' => 'מקדמה',
				'pay_to'      => 'diromax',
			)
		);

		$this->assertSame( array( 'due_date' => 'before_entry' ), $errors );
	}

	public function test_request_needs_a_known_addressee(): void {
		$errors = $this->validator->validate(
			array(
				'type'        => 'request',
				'entry_date'  => '2026-11-05',
				'due_date'    => '2026-12-01',
				'amount'      => '40000',
				'description' => 'מקדמה',
				'pay_to'      => 'someone',
			)
		);

		$this->assertSame( array( 'pay_to' => 'invalid' ), $errors );
	}

	public function test_valid_request_to_diromax_is_open(): void {
		$draft = $this->validator->validate(
			array(
				'type'            => 'request',
				'entry_date'      => '2026-11-05',
				'due_date'        => '2026-12-01',
				'amount'          => '40,000',
				'description'     => 'מקדמה לשלב הביצוע',
				'pay_to'          => 'diromax',
				'category'        => 'architecture',
				'payment_details' => "בנק 00\nחשבון 000000",
			)
		);

		$this->assertInstanceOf( EntryDraft::class, $draft );
		$this->assertSame( EntryStatus::Open, $draft->status );
		$this->assertSame( PayTo::Diromax, $draft->pay_to );
		$this->assertSame( '2026-12-01', $draft->due_date );
		$this->assertSame( "בנק 00\nחשבון 000000", $draft->payment_details );
		$this->assertSame( 'architecture', $draft->category );
	}

	public function test_request_with_invalid_entry_date_reports_only_the_date(): void {
		$errors = $this->validator->validate(
			array(
				'type'        => 'request',
				'entry_date'  => 'x',
				'due_date'    => '2026-12-01',
				'amount'      => '1',
				'description' => 'd',
				'pay_to'      => 'diromax',
			)
		);

		$this->assertSame( array( 'entry_date' => 'invalid' ), $errors );
	}

	public function test_is_date(): void {
		$this->assertTrue( EntryValidator::is_date( '2024-02-29' ) );
		$this->assertFalse( EntryValidator::is_date( '2026-02-29' ) );
		$this->assertFalse( EntryValidator::is_date( '2026-1-01' ) );
	}

	public function test_schedule_waits_for_the_quiet_window(): void {
		$now = 10000;

		$this->assertFalse( NotificationSchedule::is_due( array(), true, $now, 600, 1800 ) );
		$this->assertTrue( NotificationSchedule::is_due( array( $now ), true, $now, 600, 1800 ) );
		$this->assertFalse( NotificationSchedule::is_due( array( $now - 300, $now - 100 ), false, $now, 600, 1800 ) );
		$this->assertTrue( NotificationSchedule::is_due( array( $now - 700, $now - 650 ), false, $now, 600, 1800 ) );
	}

	public function test_schedule_never_waits_past_the_maximum(): void {
		$now = 10000;

		$this->assertTrue( NotificationSchedule::is_due( array( $now - 1800, $now - 60 ), false, $now, 600, 1800 ) );
	}

	public function test_retry_backoff_and_exhaustion(): void {
		$this->assertSame( 1300, NotificationSchedule::next_attempt( 1, 1000 ) );
		$this->assertSame( 2800, NotificationSchedule::next_attempt( 2, 1000 ) );
		$this->assertSame( 8200, NotificationSchedule::next_attempt( 3, 1000 ) );
		$this->assertNull( NotificationSchedule::next_attempt( 4, 1000 ) );
	}
}
