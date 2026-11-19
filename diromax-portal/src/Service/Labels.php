<?php
/**
 * Display labels.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Domain\CaseStatus;
use Diromax\Portal\Domain\EntryStatus;
use Diromax\Portal\Domain\EntryType;
use Diromax\Portal\Domain\Funding;
use Diromax\Portal\Domain\LedgerEntry;
use Diromax\Portal\Domain\DecisionStatus;
use Diromax\Portal\Domain\MemberRole;
use Diromax\Portal\Domain\NotifyMode;
use Diromax\Portal\Domain\PayTo;

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
			'other'     => __( 'אחר', 'diromax-portal' ),
		);
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
