<?php
/**
 * A sample case for trying the system on a fresh install.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the worked example from the spec (section 5) as a real case,
 * through the same services a project manager uses, so every screen has
 * something to show: a budget, paid expenses, a commitment, an open and an
 * overdue payment request, progress updates and a decision for the client.
 *
 * The case has no clients, so no email goes out. Dates are relative to
 * today. The title starts with "תיק לדוגמה". It is removed like any other
 * case: closed, then deleted by an administrator.
 */
final class DemoCase {

	/** Title prefix that marks a sample case. */
	public const PREFIX = 'תיק לדוגמה · ';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Create the sample case, managed by the actor.
	 *
	 * @param int $actor Staff user.
	 * @return CaseRecord|WP_Error
	 */
	public function create( int $actor ): CaseRecord|WP_Error {
		if ( ! $this->app->policy->can_use_admin( $actor ) ) {
			return Errors::forbidden();
		}

		$client_case = $this->app->case_service->create(
			array(
				'title'         => self::PREFIX . __( 'רח׳ הזית 14, קריית אונו', 'diromax-portal' ),
				'scope'         => __( 'הוספת יחידת דיור על הגג', 'diromax-portal' ),
				'property_type' => __( 'דירת גג', 'diromax-portal' ),
				'track'         => __( 'מימון מתוך הפרויקט', 'diromax-portal' ),
				'fee_terms'     => __( '10% מהתקציב המאושר (48,000 ₪), ייפרע מהתמורה', 'diromax-portal' ),
				'signed_on'     => $this->day( -130 ),
				'manager_id'    => (string) $actor,
			),
			$actor
		);

		if ( $client_case instanceof WP_Error ) {
			return $client_case;
		}

		$steps = array(
			fn() => $this->app->case_service->save_budget(
				$client_case,
				array(
					'architecture' => '55000',
					'consultants'  => '18000',
					'fees'         => '42000',
					'contractor'   => '290000',
					'management'   => '48000',
					'reserve'      => '27000',
				),
				$actor
			),
			...array_map( fn( array $entry ) => fn() => $this->app->ledger_service->add( $client_case, $entry, $actor ), $this->entries() ),
			...array_map( fn( array $update ) => fn() => ( new UpdateService( $this->app ) )->publish( $client_case, $update, array(), $actor ), $this->updates() ),
			fn() => ( new DecisionService( $this->app ) )->create(
				$client_case,
				array(
					'title'    => __( 'בחירת ספק חלונות', 'diromax-portal' ),
					'body'     => __( 'קיבלנו שלוש הצעות על אותו מפרט. ההצעה המומלצת: אלומיניום בגוון אפור, אספקה בתוך 6 שבועות, אחריות 10 שנים.', 'diromax-portal' ),
					'amount'   => '18500',
					'due_date' => $this->day( 7 ),
					'notify'   => '',
				),
				$actor
			),
		);

		foreach ( $steps as $step ) {
			$result = $step();

			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		return $this->app->cases->find( $client_case->id ) ?? Errors::storage();
	}

	/**
	 * Is this a sample case?
	 *
	 * @param CaseRecord $client_case Case.
	 * @return bool
	 */
	public static function is_demo( CaseRecord $client_case ): bool {
		return str_starts_with( $client_case->title, self::PREFIX );
	}

	/**
	 * Ledger entries, oldest first.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function entries(): array {
		$expense = fn( array $fields ): array => array_merge(
			array(
				'type'     => 'expense',
				'status'   => 'paid',
				'vat_mode' => 'included',
				'visible'  => '1',
				'notify'   => '',
			),
			$fields
		);

		return array(
			$expense(
				array(
					'entry_date'  => $this->day( -125 ),
					'amount'      => '4500',
					'category'    => 'consultants',
					'payee'       => __( 'מודד מוסמך', 'diromax-portal' ),
					'description' => __( 'מדידה ומפת מצב', 'diromax-portal' ),
					'funding'     => 'client',
				)
			),
			array(
				'type'        => 'payment',
				'entry_date'  => $this->day( -118 ),
				'amount'      => '60000',
				'description' => __( 'העברה בנקאית לחשבון הפרויקט', 'diromax-portal' ),
				'method'      => 'transfer',
				'reference'   => '448210',
				'visible'     => '1',
				'notify'      => '',
			),
			$expense(
				array(
					'entry_date'  => $this->day( -105 ),
					'amount'      => '38000',
					'category'    => 'architecture',
					'payee'       => __( 'משרד אדריכלים', 'diromax-portal' ),
					'description' => __( 'תכנון אדריכלי, שלב א׳', 'diromax-portal' ),
					'funding'     => 'project',
				)
			),
			$expense(
				array(
					'entry_date'    => $this->day( -62 ),
					'amount'        => '24000',
					'category'      => 'management',
					'payee'         => __( 'דירומקס', 'diromax-portal' ),
					'description'   => __( 'שכר ניהול, שלב התכנון', 'diromax-portal' ),
					'funding'       => 'proceeds',
					'internal_note' => __( 'לפי סעיף 6 בהסכם', 'diromax-portal' ),
				)
			),
			$expense(
				array(
					'entry_date'  => $this->day( -21 ),
					'status'      => 'committed',
					'amount'      => '45000',
					'category'    => 'contractor',
					'payee'       => __( 'קבלן ראשי בע״מ', 'diromax-portal' ),
					'description' => __( 'הסכם קבלן ראשי, מקדמה', 'diromax-portal' ),
					'funding'     => 'project',
				)
			),
			array(
				'type'            => 'request',
				'entry_date'      => $this->day( -18 ),
				'due_date'        => $this->day( -4 ),
				'amount'          => '6500',
				'description'     => __( 'השלמת אגרות לוועדה', 'diromax-portal' ),
				'pay_to'          => 'diromax',
				'payment_details' => __( 'פרטי החשבון של דירומקס, כפי שמופיעים בהגדרות', 'diromax-portal' ),
				'visible'         => '1',
				'notify'          => '',
			),
			$expense(
				array(
					'entry_date'  => $this->day( -8 ),
					'amount'      => '12300',
					'category'    => 'fees',
					'payee'       => __( 'הוועדה המקומית לתכנון ובנייה', 'diromax-portal' ),
					'description' => __( 'אגרת בקשה להיתר בנייה', 'diromax-portal' ),
					'funding'     => 'project',
				)
			),
			array(
				'type'            => 'request',
				'entry_date'      => $this->day( -2 ),
				'due_date'        => $this->day( 12 ),
				'amount'          => '40000',
				'description'     => __( 'מקדמה לשלב הביצוע', 'diromax-portal' ),
				'pay_to'          => 'diromax',
				'payment_details' => __( 'פרטי החשבון של דירומקס, כפי שמופיעים בהגדרות', 'diromax-portal' ),
				'visible'         => '1',
				'notify'          => '',
			),
		);
	}

	/**
	 * Progress updates, oldest first. They move the case to stage 5 and then
	 * start stage 6 alongside it, so the sample shows two stages in progress.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function updates(): array {
		return array(
			array(
				'title'   => __( 'כל השכנים חתמו', 'diromax-portal' ),
				'body'    => __( 'שש מתוך שש דירות בבניין חתמו על ההסכמה. המסמכים החתומים נמצאים בלשונית ההסכם והמסמכים.', 'diromax-portal' ),
				'stage'   => '4',
				'visible' => '1',
				'notify'  => '',
			),
			array(
				'title'   => __( 'הבקשה להיתר הוגשה לוועדה המקומית', 'diromax-portal' ),
				'body'    => __( 'הוועדה צפויה לדון בבקשה בתוך 45 עד 60 יום. עד אז נעדכן על כל דרישה או השלמה שתגיע. אין צורך בשום פעולה כרגע.', 'diromax-portal' ),
				'stage'   => '5',
				'visible' => '1',
				'notify'  => '',
			),
			array(
				'title'      => __( 'נחתם הסכם עם הקבלן', 'diromax-portal' ),
				'body'       => __( 'במקביל להיתר התחלנו בהכנות לביצוע: השווינו שלוש הצעות על אותו כתב כמויות. ההצעה שנבחרה זולה ב-11% מהיקרה, והמחיר נעול ל-12 חודשים.', 'diromax-portal' ),
				'stage'      => '6',
				'stage_mode' => 'parallel',
				'visible'    => '1',
				'notify'     => '',
			),
		);
	}

	/**
	 * A date relative to today.
	 *
	 * @param int $days Days from today, negative for the past.
	 * @return string Y-m-d.
	 */
	private function day( int $days ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $this->app->clock->today() . ' ' . sprintf( '%+d', $days ) . ' days UTC' ) );
	}
}
