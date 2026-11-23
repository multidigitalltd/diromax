<?php
/**
 * Tests for the rules of site visits and findings (spec 23).
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Tests\Unit;

use Diromax\Portal\Domain\SiteVisit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Diromax\Portal\Domain\SiteVisit
 */
final class SiteVisitTest extends TestCase {

	public function test_a_visit_is_dated_not_after_today_and_has_a_summary(): void {
		$ok = SiteVisit::visit(
			array(
				'visit_on'  => '2027-03-01',
				'attendees' => ' דניאל לוי, הקבלן ',
				'summary'   => "יציקת תקרה.\nנמצאו שני ליקויים.",
				'visible'   => '1',
			),
			'2027-03-01'
		);

		$this->assertSame(
			array(
				'visit_on'  => '2027-03-01',
				'attendees' => 'דניאל לוי, הקבלן',
				'summary'   => "יציקת תקרה.\nנמצאו שני ליקויים.",
				'visible'   => true,
			),
			$ok['fields']
		);
		$this->assertSame(
			array(
				'visit_on' => 'future',
				'summary'  => 'required',
			),
			SiteVisit::visit(
				array(
					'visit_on' => '2027-03-02',
					'summary'  => ' ',
				),
				'2027-03-01'
			)['errors']
		);
		$this->assertSame(
			array( 'visit_on' => 'date' ),
			SiteVisit::visit(
				array(
					'visit_on' => '2027-02-30',
					'summary'  => 'x',
				),
				'2027-03-01'
			)['errors']
		);
	}

	public function test_a_finding_needs_a_title_and_a_known_severity(): void {
		$ok = SiteVisit::finding(
			array(
				'title'    => 'סדק בקיר המזרחי',
				'location' => 'חדר שינה',
				'severity' => 'high',
				'supplier' => 'קבלן השלד',
				'due_on'   => '2027-03-15',
			)
		);

		$this->assertSame( array( 'סדק בקיר המזרחי', 'high', '2027-03-15', false ), array( $ok['fields']['title'] ?? '', $ok['fields']['severity'] ?? '', $ok['fields']['due_on'] ?? '', $ok['fields']['visible'] ?? null ) );
		$no_date = SiteVisit::finding(
			array(
				'title'    => 'x',
				'severity' => 'low',
			)
		)['fields'];
		$this->assertNotNull( $no_date );
		$this->assertNull( $no_date['due_on'], 'No due date is allowed.' );
		$this->assertSame(
			array(
				'title'    => 'required',
				'severity' => 'invalid',
				'due_on'   => 'date',
			),
			SiteVisit::finding(
				array(
					'title'    => '',
					'severity' => 'urgent',
					'due_on'   => '15/03/2027',
				)
			)['errors']
		);
	}

	public function test_overdue_and_counts(): void {
		$findings = array(
			array(
				'status' => 'open',
				'due_on' => '2027-03-01',
			),
			array(
				'status' => 'open',
				'due_on' => '2027-03-05',
			),
			array(
				'status' => 'open',
				'due_on' => null,
			),
			array(
				'status' => 'fixed',
				'due_on' => '2027-02-01',
			),
		);

		$this->assertTrue( SiteVisit::is_overdue( $findings[0], '2027-03-02' ) );
		$this->assertFalse( SiteVisit::is_overdue( $findings[1], '2027-03-05' ), 'Due today is not late.' );
		$this->assertFalse( SiteVisit::is_overdue( $findings[3], '2027-03-02' ), 'A fixed finding is never late.' );
		$this->assertSame(
			array(
				'open'    => 3,
				'overdue' => 1,
				'fixed'   => 1,
			),
			SiteVisit::counts( $findings, '2027-03-02' )
		);
	}
}
