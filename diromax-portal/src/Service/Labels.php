<?php
/**
 * Display labels.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Domain\BudgetTree;
use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Funding;
use Diromax\Portal\Domain\LedgerEntry;
use Diromax\Portal\Domain\DecisionStatus;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Domain\PayTo;
use Diromax\Portal\Domain\StageStatus;
use Diromax\Portal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one vocabulary shared by the admin screens, the portal and the emails,
 * so a term never reads differently in two places.
 */
final class Labels {

	/**
	 * Entry type, as a short tag.
	 *
	 * @param LedgerEntry $entry Entry.
	 * @return string
	 */
	public static function entry_tag( LedgerEntry $entry ): string {
		return match ( $entry->type ) {
			EntryType::Expense => EntryStatus::Committed === $entry->status ? __( 'התחייבות', 'diromax-portal' ) : __( 'הוצאה', 'diromax-portal' ),
			EntryType::Payment => __( 'תשלום', 'diromax-portal' ),
			EntryType::Refund  => __( 'החזר', 'diromax-portal' ),
			EntryType::Request => __( 'דרישת תשלום', 'diromax-portal' ),
		};
	}

	/**
	 * A ledger entry's budget line: "Section › Sub-section" from the case's
	 * budget; "Other" for none; the old settings label for a key the
	 * budget does not have.
	 *
	 * @param BudgetTree $tree The case's budget.
	 * @param string     $key  The entry's key.
	 * @return string
	 */
	public static function budget_line( BudgetTree $tree, string $key ): string {
		$label = '' === $key ? '' : $tree->label( $key );

		return '' !== $label ? $label : Settings::category_label( $key );
	}

	/**
	 * Entry types for a select.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			EntryType::Expense->value => __( 'הוצאה', 'diromax-portal' ),
			EntryType::Payment->value => __( 'תשלום מהלקוח', 'diromax-portal' ),
			EntryType::Request->value => __( 'דרישת תשלום', 'diromax-portal' ),
			EntryType::Refund->value  => __( 'החזר ללקוח', 'diromax-portal' ),
		);
	}

	/**
	 * Funding sources, as the client reads them.
	 *
	 * @return array<string, string>
	 */
	public static function funding(): array {
		return array(
			Funding::Client->value   => __( 'הלקוח ישירות', 'diromax-portal' ),
			Funding::Project->value  => __( 'חשבון הפרויקט', 'diromax-portal' ),
			Funding::Proceeds->value => __( 'מתוך התמורה', 'diromax-portal' ),
			Funding::Partner->value  => __( 'שותף משביח', 'diromax-portal' ),
		);
	}

	/**
	 * Payment methods.
	 *
	 * @return array<string, string>
	 */
	public static function methods(): array {
		return array(
			'transfer' => __( 'העברה בנקאית', 'diromax-portal' ),
			'check'    => __( 'צ׳ק', 'diromax-portal' ),
			'card'     => __( 'כרטיס אשראי', 'diromax-portal' ),
			'cash'     => __( 'מזומן', 'diromax-portal' ),
			'other'    => __( 'אחר', 'diromax-portal' ),
		);
	}

	/**
	 * Request addressee.
	 *
	 * @param PayTo|null $pay_to Addressee.
	 * @param string     $payee  Supplier name.
	 * @return string
	 */
	public static function pay_to( ?PayTo $pay_to, string $payee ): string {
		return PayTo::Supplier === $pay_to ? $payee : __( 'דירומקס (חשבון הפרויקט)', 'diromax-portal' );
	}

	/**
	 * Case statuses.
	 *
	 * @return array<string, string>
	 */
	public static function case_statuses(): array {
		return array(
			CaseStatus::Active->value => __( 'פעיל', 'diromax-portal' ),
			CaseStatus::OnHold->value => __( 'מושהה', 'diromax-portal' ),
			CaseStatus::Closed->value => __( 'סגור', 'diromax-portal' ),
		);
	}

	/**
	 * Stage statuses, in the order the editor offers them.
	 *
	 * @return array<string, string>
	 */
	public static function stage_statuses(): array {
		return array(
			StageStatus::Pending->value => __( 'טרם התחיל', 'diromax-portal' ),
			StageStatus::Active->value  => __( 'בתהליך', 'diromax-portal' ),
			StageStatus::Done->value    => __( 'הושלם', 'diromax-portal' ),
			StageStatus::Skipped->value => __( 'לא רלוונטי', 'diromax-portal' ),
		);
	}

	/**
	 * When a stage started or finished, e.g. "מאז 12.11.2026"; empty when
	 * it has no date for its status.
	 *
	 * @param array{status:string, started:string|null, done:string|null, due:string|null} $state One stage.
	 * @return string
	 */
	public static function stage_when( array $state ): string {
		if ( StageStatus::Active->value === $state['status'] && null !== $state['started'] ) {
			/* translators: %s: date. */
			return sprintf( __( 'מאז %s', 'diromax-portal' ), self::date( $state['started'] ) );
		}

		if ( StageStatus::Done->value === $state['status'] && null !== $state['done'] ) {
			/* translators: %s: date. */
			return sprintf( __( 'הושלם %s', 'diromax-portal' ), self::date( $state['done'] ) );
		}

		return '';
	}

	/**
	 * Member roles.
	 *
	 * @return array<string, string>
	 */
	public static function roles(): array {
		return array(
			MemberRole::Owner->value   => __( 'בעלים', 'diromax-portal' ),
			MemberRole::Partner->value => __( 'שותף / יורש', 'diromax-portal' ),
			MemberRole::Viewer->value  => __( 'צופה', 'diromax-portal' ),
		);
	}

	/**
	 * Email modes of a member.
	 *
	 * @return array<string, string>
	 */
	public static function notify_modes(): array {
		return array(
			NotifyMode::Instant->value => __( 'מייל על כל עדכון', 'diromax-portal' ),
			NotifyMode::Daily->value   => __( 'סיכום יומי', 'diromax-portal' ),
			NotifyMode::Off->value     => __( 'בלי מיילים', 'diromax-portal' ),
		);
	}

	/**
	 * Decision statuses.
	 *
	 * @return array<string, string>
	 */
	public static function decision_statuses(): array {
		return array(
			DecisionStatus::Pending->value   => __( 'ממתינה לתשובה', 'diromax-portal' ),
			DecisionStatus::Approved->value  => __( 'אושרה', 'diromax-portal' ),
			DecisionStatus::Declined->value  => __( 'נדחתה', 'diromax-portal' ),
			DecisionStatus::Withdrawn->value => __( 'בוטלה', 'diromax-portal' ),
		);
	}

	/**
	 * Readable names of audit actions, for activity lists.
	 *
	 * @return array<string, string>
	 */
	public static function audit_actions(): array {
		return array(
			'case.created'            => __( 'התיק נפתח', 'diromax-portal' ),
			'case.updated'            => __( 'פרטי התיק עודכנו', 'diromax-portal' ),
			'case.stage_changed'      => __( 'שלב התיק השתנה', 'diromax-portal' ),
			'case.status_changed'     => __( 'סטטוס התיק השתנה', 'diromax-portal' ),
			'member.added'            => __( 'לקוח נוסף', 'diromax-portal' ),
			'member.removed'          => __( 'לקוח הוסר', 'diromax-portal' ),
			'member.invited'          => __( 'נשלחה הזמנה', 'diromax-portal' ),
			'member.mode'             => __( 'העדפת המיילים של לקוח השתנתה', 'diromax-portal' ),
			'budget.updated'          => __( 'התקציב עודכן', 'diromax-portal' ),
			'ledger.created'          => __( 'נוספה תנועה', 'diromax-portal' ),
			'ledger.updated'          => __( 'עודכנה תנועה', 'diromax-portal' ),
			'ledger.voided'           => __( 'בוטלה תנועה', 'diromax-portal' ),
			'ledger.request_settled'  => __( 'דרישת תשלום סומנה כשולמה', 'diromax-portal' ),
			'reminder.sent'           => __( 'נשלחה תזכורת תשלום', 'diromax-portal' ),
			'update.published'        => __( 'פורסם עדכון', 'diromax-portal' ),
			'decision.created'        => __( 'נשלחה החלטה לאישור', 'diromax-portal' ),
			'decision.answered'       => __( 'הלקוח ענה על החלטה', 'diromax-portal' ),
			'decision.withdrawn'      => __( 'החלטה בוטלה', 'diromax-portal' ),
			'agreement.acknowledged'  => __( 'הלקוח אישר קריאת הסכם', 'diromax-portal' ),
			'document.uploaded'       => __( 'הועלה מסמך', 'diromax-portal' ),
			'document.downloaded'     => __( 'הורד מסמך', 'diromax-portal' ),
			'document.deleted'        => __( 'נמחק מסמך', 'diromax-portal' ),
			'document.visibility'     => __( 'שונתה נראות של מסמך', 'diromax-portal' ),
			'portal.case_viewed'      => __( 'הלקוח צפה בתיק', 'diromax-portal' ),
			'portal.signed_in'        => __( 'הלקוח נכנס לאזור האישי', 'diromax-portal' ),
			'portal.password_changed' => __( 'הלקוח עדכן סיסמה', 'diromax-portal' ),
			'mail.sent_now'           => __( 'עדכונים נשלחו ידנית', 'diromax-portal' ),
			'mail.cancelled'          => __( 'שליחת עדכונים בוטלה', 'diromax-portal' ),
			'task.added'              => __( 'נוספה משימה', 'diromax-portal' ),
			'task.done'               => __( 'משימה בוצעה', 'diromax-portal' ),
			'task.reopened'           => __( 'משימה חזרה לפתוחות', 'diromax-portal' ),
			'task.deleted'            => __( 'נמחקה משימה', 'diromax-portal' ),
			'docrequest.created'      => __( 'נשלחה בקשת מסמך ללקוח', 'diromax-portal' ),
			'docrequest.fulfilled'    => __( 'הלקוח העלה מסמך שהתבקש', 'diromax-portal' ),
			'docrequest.cancelled'    => __( 'בקשת מסמך בוטלה', 'diromax-portal' ),
			'document.client_upload'  => __( 'הלקוח העלה מסמך', 'diromax-portal' ),
			'signature.requested'     => __( 'נשלחה בקשת חתימה', 'diromax-portal' ),
			'signature.cancelled'     => __( 'בקשת חתימה בוטלה', 'diromax-portal' ),
			'signature.code_sent'     => __( 'נשלח קוד חתימה', 'diromax-portal' ),
			'signature.signed'        => __( 'מסמך נחתם', 'diromax-portal' ),
			'signature.viewed'        => __( 'נפתח אישור חתימה', 'diromax-portal' ),
			'plan.added'              => __( 'נוסף תשלום לתוכנית', 'diromax-portal' ),
			'plan.updated'            => __( 'עודכן תשלום בתוכנית', 'diromax-portal' ),
			'plan.deleted'            => __( 'נמחק תשלום מהתוכנית', 'diromax-portal' ),
			'plan.base'               => __( 'השתנה הבסיס של תוכנית התשלומים', 'diromax-portal' ),
			'plan.drafted'            => __( 'נוצרה טיוטה של דרישת תשלום', 'diromax-portal' ),
			'plan.sent'               => __( 'טיוטה אושרה ונשלחה כדרישת תשלום', 'diromax-portal' ),
			'plan.reopened'           => __( 'טיוטה חזרה לתוכנית', 'diromax-portal' ),
			'plan.cancelled'          => __( 'בוטל תשלום בתוכנית', 'diromax-portal' ),
		);
	}

	/**
	 * Document kinds.
	 *
	 * @return array<string, string>
	 */
	public static function document_kinds(): array {
		return array(
			'agreement' => __( 'הסכם', 'diromax-portal' ),
			'addendum'  => __( 'נספח להסכם', 'diromax-portal' ),
			'plan'      => __( 'תוכנית', 'diromax-portal' ),
			'permit'    => __( 'היתר ורישוי', 'diromax-portal' ),
			'contract'  => __( 'הסכם ספק', 'diromax-portal' ),
			'quote'     => __( 'הצעת מחיר', 'diromax-portal' ),
			'invoice'   => __( 'חשבונית', 'diromax-portal' ),
			'receipt'   => __( 'קבלה / אישור תשלום', 'diromax-portal' ),
			'photo'     => __( 'צילום', 'diromax-portal' ),
			'client'    => __( 'מהלקוח', 'diromax-portal' ),
			'other'     => __( 'אחר', 'diromax-portal' ),
		);
	}

	/**
	 * Kinds of meeting (spec 20.1).
	 *
	 * @return array<string, string>
	 */
	public static function meeting_kinds(): array {
		return array(
			'office' => __( 'פגישה במשרד', 'diromax-portal' ),
			'site'   => __( 'סיור בשטח', 'diromax-portal' ),
			'call'   => __( 'שיחת טלפון', 'diromax-portal' ),
			'video'  => __( 'שיחת וידאו', 'diromax-portal' ),
		);
	}

	/**
	 * When a meeting is, in the site's time zone: "יום שלישי, 24.11.2026, 09:30–11:00".
	 *
	 * @param string $utc     Start, UTC DATETIME.
	 * @param int    $minutes Length.
	 * @return string
	 */
	public static function meeting_when( string $utc, int $minutes ): string {
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );

		if ( false === $start ) {
			return '';
		}

		$start = $start->setTimezone( wp_timezone() );
		$days  = array( __( 'יום ראשון', 'diromax-portal' ), __( 'יום שני', 'diromax-portal' ), __( 'יום שלישי', 'diromax-portal' ), __( 'יום רביעי', 'diromax-portal' ), __( 'יום חמישי', 'diromax-portal' ), __( 'יום שישי', 'diromax-portal' ), __( 'שבת', 'diromax-portal' ) );

		return $days[ (int) $start->format( 'w' ) ] . ', ' . $start->format( 'd.m.Y' ) . ', ' . $start->format( 'H:i' ) . '–' . $start->modify( '+' . $minutes . ' minutes' )->format( 'H:i' );
	}

	/**
	 * Date for display: 12.11.2026.
	 *
	 * @param string|null $date Y-m-d or a DATETIME.
	 * @return string
	 */
	public static function date( ?string $date ): string {
		if ( null === $date || '' === $date ) {
			return '';
		}

		$parts = explode( '-', substr( $date, 0, 10 ) );

		return 3 === count( $parts ) ? $parts[2] . '.' . $parts[1] . '.' . $parts[0] : '';
	}

	/**
	 * Local date of a UTC DATETIME, e.g. 12.11.2026 14:32.
	 *
	 * @param string|null $utc UTC DATETIME.
	 * @return string
	 */
	public static function datetime( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '';
		}

		$time = strtotime( $utc . ' UTC' );

		$local = false === $time ? false : wp_date( 'd.m.Y H:i', $time );

		return false === $local ? '' : $local;
	}
}
