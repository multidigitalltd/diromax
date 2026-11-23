<?php
/**
 * Validation messages.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the services' field error codes into sentences that say how to fix them.
 */
final class Messages {

	/**
	 * Message for a field error.
	 *
	 * @param string $field Field.
	 * @param string $code  Error code.
	 * @return string
	 */
	public static function field( string $field, string $code ): string {
		$specific = array(
			'amount:invalid'        => __( 'הסכום צריך להיות מספר חיובי, עם עד שתי ספרות אחרי הנקודה.', 'diromax-portal' ),
			'due_date:before_entry' => __( 'מועד התשלום לא יכול להיות לפני תאריך הדרישה.', 'diromax-portal' ),
			'entry_date:invalid'    => __( 'התאריך לא תקין. אפשר לבחור תאריך מלוח השנה.', 'diromax-portal' ),
			'email:staff_account'   => __( 'הכתובת שייכת לחשבון של הצוות. ללקוח צריך כתובת נפרדת.', 'diromax-portal' ),
			'email:invalid'         => __( 'כתובת הדוא״ל לא תקינה.', 'diromax-portal' ),
			'file:missing'          => __( 'לא נבחר קובץ, או שההעלאה נכשלה.', 'diromax-portal' ),
			'file:too_large'        => __( 'הקובץ גדול מ-20MB.', 'diromax-portal' ),
			'file:type'             => __( 'סוג הקובץ לא נתמך. אפשר להעלות PDF, JPG, PNG, WEBP, DOCX או XLSX.', 'diromax-portal' ),
			'file:rejected'         => __( 'הקובץ נדחה בבדיקת האבטחה.', 'diromax-portal' ),
			'entry:voided'          => __( 'התנועה בוטלה, ואי אפשר לשנות אותה.', 'diromax-portal' ),
			'entry:settled'         => __( 'דרישת התשלום כבר שולמה. כדי לתקן, מבטלים את התנועה שסגרה אותה.', 'diromax-portal' ),
			'files:too_many'        => __( 'אפשר לצרף עד 10 קבצים לעדכון.', 'diromax-portal' ),
			'files:storage'         => __( 'אי אפשר לשמור קבצים כרגע: מפתח ההצפנה חסר או השתנה (פרטים בהתראה האדומה). העדכון לא פורסם. אפשר לפרסם אותו בלי הקבצים.', 'diromax-portal' ),
			'files:missing'         => __( 'אחד הקבצים לא הגיע. העדכון לא פורסם; אפשר לבחור את הקבצים שוב.', 'diromax-portal' ),
			'files:too_large'       => __( 'אחד הקבצים גדול מ-20MB. העדכון לא פורסם.', 'diromax-portal' ),
			'files:type'            => __( 'אחד הקבצים מסוג שלא נתמך (אפשר PDF, JPG, PNG, WEBP, DOCX או XLSX). העדכון לא פורסם.', 'diromax-portal' ),
			'photos:missing'        => __( 'לא נבחרו צילומים, או שההעלאה נכשלה.', 'diromax-portal' ),
			'photos:too_many'       => __( 'אפשר להעלות עד 20 צילומים בפעם אחת.', 'diromax-portal' ),
			'photos:type'           => __( 'אף צילום לא התקבל: צריך JPG, PNG או WEBP עד 20MB.', 'diromax-portal' ),
			'photos:storage'        => __( 'אי אפשר לשמור צילומים כרגע: מפתח ההצפנה חסר או השתנה (פרטים בהתראה האדומה).', 'diromax-portal' ),
			'taken_on:invalid'      => __( 'התאריך לא תקין. אפשר לבחור תאריך מלוח השנה.', 'diromax-portal' ),
			'taken_on:future'       => __( 'תאריך הצילום לא יכול להיות בעתיד.', 'diromax-portal' ),
			'date:past'             => __( 'המועד כבר עבר. בוחרים תאריך ושעה בעתיד.', 'diromax-portal' ),
			'title:case_closed'     => __( 'התיק סגור, ואי אפשר לקבוע בו פגישות.', 'diromax-portal' ),
			'participants:invalid'  => __( 'אפשר להזמין רק לקוחות של התיק.', 'diromax-portal' ),
			'value:percent'         => __( 'האחוז צריך להיות בין 0.01 ל-100, עם עד שתי ספרות אחרי הנקודה.', 'diromax-portal' ),
			'value:invalid'         => __( 'הסכום צריך להיות מספר חיובי, עם עד שתי ספרות אחרי הנקודה.', 'diromax-portal' ),
			'trigger_on:invalid'    => __( 'בוחרים מתי התשלום מגיע: בסיום שלב, בתאריך, או ידני.', 'diromax-portal' ),
			'stage:invalid'         => __( 'בוחרים שלב מהרשימה.', 'diromax-portal' ),
			'trigger_date:invalid'  => __( 'התאריך לא תקין. אפשר לבחור תאריך מלוח השנה.', 'diromax-portal' ),
			'days:invalid'          => __( 'מספר הימים לתשלום: בין 0 ל-90.', 'diromax-portal' ),
			'payee:required'        => __( 'כשמשלמים לספק, כותבים את שם הספק.', 'diromax-portal' ),
			'label:case_closed'     => __( 'התיק סגור, ואי אפשר לשנות בו את תוכנית התשלומים.', 'diromax-portal' ),
			'base:invalid'          => __( 'סכום הבסיס צריך להיות מספר, או ריק כדי לחשב מסך התקציב.', 'diromax-portal' ),
			'start_on:no_weeks'     => __( 'עדיין לא נקבעו משכי שלבים בהגדרות, ולכן אין ממה לחשב.', 'diromax-portal' ),
			'label:too_many'        => __( 'הגעתם למספר אבני הדרך המרבי בתיק (60).', 'diromax-portal' ),
		);

		if ( isset( $specific[ $field . ':' . $code ] ) ) {
			return $specific[ $field . ':' . $code ];
		}

		return match ( $code ) {
			'required'  => __( 'שדה חובה.', 'diromax-portal' ),
			'too_long'  => __( 'הטקסט ארוך מדי.', 'diromax-portal' ),
			'in_use'    => __( 'יש תנועות בסעיף הזה, ולכן הוא נשאר. מעבירים את התנועות לסעיף אחר, ואז מוחקים.', 'diromax-portal' ),
			'duplicate' => __( 'כבר יש שורה בשם הזה באותו מקום. בוחרים שם אחר.', 'diromax-portal' ),
			'too_many'  => __( 'הגעתם למספר השורות המרבי כאן (40 סעיפים, 30 סעיפי משנה בסעיף).', 'diromax-portal' ),
			'date'      => __( 'התאריך לא תקין. אפשר לבחור תאריך מלוח השנה.', 'diromax-portal' ),
			'before_start' => __( 'הסיום לא יכול להיות לפני ההתחלה.', 'diromax-portal' ),
			default     => __( 'הערך לא תקין.', 'diromax-portal' ),
		};
	}
}
