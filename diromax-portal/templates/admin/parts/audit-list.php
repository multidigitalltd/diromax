<?php
/**
 * Admin part: audit entries as a readable list.
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed> $view
 */

declare( strict_types = 1 );

use Diromax\Portal\Service\Labels;

defined( 'ABSPATH' ) || exit;

$entries = $view['recent'] ?? $view['audit'] ?? array();
$actions = array(
	'case.created'           => __( 'התיק נפתח', 'diromax-portal' ),
	'case.updated'           => __( 'פרטי התיק עודכנו', 'diromax-portal' ),
	'case.stage_changed'     => __( 'שלב התיק השתנה', 'diromax-portal' ),
	'case.status_changed'    => __( 'סטטוס התיק השתנה', 'diromax-portal' ),
	'member.added'           => __( 'לקוח נוסף', 'diromax-portal' ),
	'member.removed'         => __( 'לקוח הוסר', 'diromax-portal' ),
	'member.invited'         => __( 'נשלחה הזמנה', 'diromax-portal' ),
	'budget.updated'         => __( 'התקציב עודכן', 'diromax-portal' ),
	'ledger.created'         => __( 'נוספה תנועה', 'diromax-portal' ),
	'ledger.updated'         => __( 'עודכנה תנועה', 'diromax-portal' ),
	'ledger.voided'          => __( 'בוטלה תנועה', 'diromax-portal' ),
	'ledger.request_settled' => __( 'דרישת תשלום סומנה כשולמה', 'diromax-portal' ),
	'update.published'       => __( 'פורסם עדכון', 'diromax-portal' ),
	'document.uploaded'      => __( 'הועלה מסמך', 'diromax-portal' ),
	'document.downloaded'    => __( 'הורד מסמך', 'diromax-portal' ),
	'document.deleted'       => __( 'נמחק מסמך', 'diromax-portal' ),
	'document.visibility'    => __( 'שונתה נראות של מסמך', 'diromax-portal' ),
	'portal.case_viewed'     => __( 'הלקוח צפה בתיק', 'diromax-portal' ),
	'mail.sent_now'          => __( 'עדכונים נשלחו ידנית', 'diromax-portal' ),
	'mail.cancelled'         => __( 'שליחת עדכונים בוטלה', 'diromax-portal' ),
);

if ( array() === $entries ) {
	echo '<p>' . esc_html__( 'אין עדיין פעילות.', 'diromax-portal' ) . '</p>';
	return;
}
?>
<ul class="dxa-list">
	<?php foreach ( $entries as $entry ) : ?>
		<li>
			<strong><?php echo esc_html( $actions[ $entry['action'] ] ?? $entry['action'] ); ?></strong>
			<span class="dxa-muted"><?php echo esc_html( Labels::datetime( $entry['created_at'] ) . ' · ' . ( '' === $entry['user'] ? __( 'מערכת', 'diromax-portal' ) : $entry['user'] ) ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>
