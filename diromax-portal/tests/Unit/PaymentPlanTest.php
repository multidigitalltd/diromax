<?php
/**
 * Tests for the payment plan rules (spec 21).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\PaymentPlan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\PaymentPlan
 */
final class PaymentPlanTest extends TestCase {

	/**
	 * Valid input with overrides.
	 *
	 * @param array<string, string> $overrides Fields.
	 * @return array<string, string>
	 */
	private static function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'label'        => ' עם קבלת ההיתר ',
				'trigger_on'   => 'stage',
				'stage'        => '4',
				'trigger_date' => '',
				'kind'         => 'percent',
				'value'        => '12.5',
				'pay_to'       => 'diromax',
				'payee'        => 'ignored',
				'days'         => '',
			),
			$overrides
		);
	}

	public function test_a_share_of_the_base_on_a_stage(): void {
		$result = PaymentPlan::validate( self::input(), 7, true );

		$this->assertSame(
			array(
				'label'        => 'עם קבלת ההיתר',
				'trigger_on'   => 'stage',
				'stage'        => 4,
				'trigger_date' => null,
				'kind'         => 'percent',
				'amount'       => 0,
				'percent'      => 1250,
				'pay_to'       => 'diromax',
				'payee'        => '',
				'days'         => 14,
			),
			$result['fields']
		);
		$this->assertSame(
			6000000,
			PaymentPlan::amount(
				$result['fields'] ?? array(
					'kind'    => '',
					'amount'  => 0,
					'percent' => 0,
				),
				48000000
			)
		);
	}

	public function test_a_fixed_amount_on_a_date_to_a_supplier(): void {
		$result = PaymentPlan::validate(
			self::input(
				array(
					'trigger_on'   => 'date',
					'trigger_date' => '2027-01-15',
					'kind'         => 'fixed',
					'value'        => '25,000',
					'pay_to'       => 'supplier',
					'payee'        => 'קבלן השלד',
					'days'         => '7',
				)
			),
			7,
			true
		);

		$this->assertSame( array( 'date', 0, '2027-01-15', 2500000, 0, 'קבלן השלד', 7 ), array( $result['fields']['trigger_on'] ?? '', $result['fields']['stage'] ?? -1, $result['fields']['trigger_date'] ?? '', $result['fields']['amount'] ?? 0, $result['fields']['percent'] ?? -1, $result['fields']['payee'] ?? '', $result['fields']['days'] ?? 0 ) );
	}

	public function test_bad_input_is_reported_by_field(): void {
		$this->assertSame(
			array(
				'label' => 'required',
				'stage' => 'invalid',
				'value' => 'percent',
			),
			PaymentPlan::validate(
				self::input(
					array(
						'label' => '',
						'stage' => '8',
						'value' => '101',
					)
				),
				7,
				true
			)['errors']
		);
		$this->assertSame(
			array( 'trigger_on' => 'invalid' ),
			PaymentPlan::validate(
				self::input(
					array(
						'trigger_on'   => 'date',
						'trigger_date' => '2027-01-15',
					)
				),
				7,
				false
			)['errors'],
			'No dates in the office template.'
		);
		$this->assertSame(
			array( 'trigger_date' => 'invalid' ),
			PaymentPlan::validate(
				self::input(
					array(
						'trigger_on'   => 'date',
						'trigger_date' => '2027-02-30',
					)
				),
				7,
				true
			)['errors']
		);
		$this->assertSame(
			array(
				'value' => 'invalid',
				'payee' => 'required',
				'days'  => 'invalid',
			),
			PaymentPlan::validate(
				self::input(
					array(
						'kind'   => 'fixed',
						'value'  => '0',
						'pay_to' => 'supplier',
						'payee'  => '',
						'days'   => '91',
					)
				),
				7,
				true
			)['errors']
		);
		$this->assertSame( array( 'kind' => 'invalid' ), PaymentPlan::validate( self::input( array( 'kind' => 'other' ) ), 7, true )['errors'] );
	}

	public function test_percent_parsing(): void {
		$this->assertSame( 1000, PaymentPlan::parse_percent( '10' ) );
		$this->assertSame( 1050, PaymentPlan::parse_percent( '10.5%' ) );
		$this->assertSame( 1, PaymentPlan::parse_percent( '0.01' ) );
		$this->assertSame( 10000, PaymentPlan::parse_percent( '100' ) );
		$this->assertNull( PaymentPlan::parse_percent( '0' ) );
		$this->assertNull( PaymentPlan::parse_percent( '100.01' ) );
		$this->assertNull( PaymentPlan::parse_percent( '1,5' ) );
		$this->assertNull( PaymentPlan::parse_percent( '-5' ) );
		$this->assertSame( array( '10', '12.5', '12.25', '0.01', '100' ), array_map( array( PaymentPlan::class, 'percent_text' ), array( 1000, 1250, 1225, 1, 10000 ) ), 'Shown as typed, never rounded.' );
	}

	public function test_totals_leave_cancelled_payments_out(): void {
		$totals = PaymentPlan::totals(
			array(
				array(
					'kind'    => 'percent',
					'amount'  => 0,
					'percent' => 1000,
					'status'  => 'sent',
				),
				array(
					'kind'    => 'percent',
					'amount'  => 0,
					'percent' => 3000,
					'status'  => 'planned',
				),
				array(
					'kind'    => 'fixed',
					'amount'  => 500000,
					'percent' => 0,
					'status'  => 'draft',
				),
				array(
					'kind'    => 'percent',
					'amount'  => 0,
					'percent' => 6000,
					'status'  => 'cancelled',
				),
			),
			10000000
		);

		$this->assertSame(
			array(
				'percent' => 4000,
				'fixed'   => 500000,
				'total'   => 4500000,
			),
			$totals
		);
	}
}
