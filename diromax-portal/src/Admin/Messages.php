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
		);

		if ( isset( $specific[ $field . ':' . $code ] ) ) {
			return $specific[ $field . ':' . $code ];
		}

		return match ( $code ) {
			'required' => __( 'שדה חובה.', 'diromax-portal' ),
			'too_long' => __( 'הטקסט ארוך מדי.', 'diromax-portal' ),
			default    => __( 'הערך לא תקין.', 'diromax-portal' ),
		};
	}
}
