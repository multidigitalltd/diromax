<?php
/**
 * E2E only: run from the command line against a throwaway WordPress.
 *
 * @package Diromax\Portal
 */

// phpcs:ignoreFile -- Test fixture script, never deployed.
// Dev seed: the worked example from spec section 5, through the plugin's services.
$_SERVER['HTTP_HOST'] = '127.0.0.1:8088';
require $argv[1] . '/wp-load.php';

use Diromax\Portal\Plugin;

add_filter( 'dx_portal_is_uploaded_file', '__return_true' );
$app = Plugin::instance();

$manager = wp_insert_user( array( 'user_login' => 'daniel', 'user_pass' => 'Manager-Dev-Pass-2026!', 'user_email' => 'daniel@example.org', 'display_name' => 'דניאל לוי', 'role' => 'dx_manager' ) );
update_user_meta( $manager, 'dx_portal_phone', '054-000-0000' );

$case = $app->case_service->create( array( 'title' => 'רח׳ הזית 14, קריית אונו', 'scope' => 'הוספת יחידת דיור על הגג', 'property_type' => 'דירת גג', 'track' => 'מימון מתוך הפרויקט', 'fee_terms' => '10% מהתקציב המאושר (48,000 ₪), ייפרע מהתמורה', 'signed_on' => '2026-07-03' ), $manager );

$app->case_service->save_budget( $case, array( 'architecture' => '55000', 'consultants' => '18000', 'fees' => '42000', 'contractor' => '290000', 'management' => '48000', 'reserve' => '27000' ), $manager );

$member = $app->case_service->add_member( $case, array( 'email' => 'cohen@example.org', 'name' => 'משפחת כהן', 'role' => 'owner', 'notify' => '1', 'invite' => '' ), $manager );

function dx_dev_pdf( string $title ): array {
	$path = tempnam( sys_get_temp_dir(), 'dxs' );
	file_put_contents( $path, "%PDF-1.4\n1 0 obj << /Type /Catalog /Title ({$title}) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n" );
	return array( 'name' => $title . '.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => 0, 'size' => filesize( $path ) );
}

$app->document_service->upload( $case, dx_dev_pdf( 'agreement' ), 'agreement', 'הסכם ניהול השבחה', true, $manager, false );

$e = fn( array $o ) => array_merge( array( 'type' => 'expense', 'status' => 'paid', 'vat_mode' => 'included', 'visible' => '1', 'notify' => '' ), $o );
$app->ledger_service->add( $case, $e( array( 'entry_date' => '2026-07-11', 'amount' => '4500', 'category' => 'consultants', 'payee' => 'מודד מוסמך', 'description' => 'מדידה ומפת מצב', 'funding' => 'client' ) ), $manager, dx_dev_pdf( 'survey-invoice' ) );
$app->ledger_service->add( $case, array( 'type' => 'payment', 'entry_date' => '2026-07-20', 'amount' => '60000', 'description' => 'העברה בנקאית לחשבון הפרויקט', 'method' => 'transfer', 'reference' => '448210', 'visible' => '1' ), $manager );
$app->ledger_service->add( $case, $e( array( 'entry_date' => '2026-08-02', 'amount' => '38000', 'category' => 'architecture', 'payee' => 'משרד אדריכלים', 'description' => 'תכנון אדריכלי, שלב א׳', 'funding' => 'project' ) ), $manager, dx_dev_pdf( 'architect-invoice' ) );
$app->ledger_service->add( $case, $e( array( 'entry_date' => '2026-09-15', 'amount' => '24000', 'category' => 'management', 'payee' => 'דירומקס', 'description' => 'שכר ניהול, שלב התכנון', 'funding' => 'proceeds', 'internal_note' => 'לפי סעיף 6 בהסכם' ) ), $manager );
$app->ledger_service->add( $case, $e( array( 'entry_date' => '2026-10-28', 'status' => 'committed', 'amount' => '45000', 'category' => 'contractor', 'payee' => 'קבלן ראשי בע״מ', 'description' => 'הסכם קבלן ראשי, מקדמה', 'funding' => 'project' ) ), $manager );
$app->ledger_service->add( $case, array( 'type' => 'request', 'entry_date' => '2026-11-05', 'due_date' => '2026-12-01', 'amount' => '40000', 'description' => 'מקדמה לשלב הביצוע', 'pay_to' => 'diromax', 'payment_details' => "בנק 00 · סניף 000 · חשבון 000000\nעל שם דירומקס", 'visible' => '1' ), $manager );

$upd = new Diromax\Portal\Service\UpdateService( $app );
$upd->publish( $case, array( 'title' => 'כל השכנים חתמו', 'body' => 'שש מתוך שש דירות בבניין חתמו על ההסכמה. המסמכים החתומים נמצאים בלשונית ההסכם והמסמכים.', 'stage' => '4', 'visible' => '1' ), array(), $manager );
$upd->publish( $case, array( 'title' => 'נחתם הסכם עם הקבלן', 'body' => 'השווינו שלוש הצעות על אותו כתב כמויות. ההצעה שנבחרה זולה ב-11% מהיקרה, והמחיר נעול ל-12 חודשים.', 'stage' => '', 'visible' => '1' ), array(), $manager );

// The last two, with notifications on: they will form one batched email.
$app->ledger_service->add( $case, $e( array( 'entry_date' => '2026-11-12', 'amount' => '12300', 'category' => 'fees', 'payee' => 'הוועדה המקומית לתכנון ובנייה קריית אונו', 'description' => 'אגרת בקשה להיתר בנייה', 'funding' => 'project', 'notify' => '1' ) ), $manager, dx_dev_pdf( 'permit-fee-receipt' ) );
$upd->publish( $case, array( 'title' => 'הבקשה להיתר הוגשה לוועדה המקומית', 'body' => 'הוועדה צפויה לדון בבקשה בתוך 45 עד 60 יום. עד אז נעדכן על כל דרישה או השלמה שתגיע. אין צורך בשום פעולה כרגע.', 'stage' => '5', 'visible' => '1', 'notify' => '1' ), array(), $manager );

// A hidden entry the client must never see.
$app->ledger_service->add( $case, $e( array( 'entry_date' => '2026-11-14', 'amount' => '999', 'category' => 'reserve', 'payee' => 'פנימי', 'description' => 'פריט פנימי מוסתר', 'funding' => 'project', 'visible' => '' ) ), $manager );

$app->notifier->process_case( $case->id, true );

// Legal pages the footer links (standard 15.6): privacy policy, terms, accessibility statement.
$privacy_page = (int) get_option( 'wp_page_for_privacy_policy' );
if ( $privacy_page ) {
	wp_update_post( array( 'ID' => $privacy_page, 'post_status' => 'publish', 'post_title' => 'מדיניות פרטיות' ) );
}
set_theme_mod( 'dx_terms_page', wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'תנאי שימוש', 'post_content' => 'תנאי השימוש באתר.' ) ) );
$a11y_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'הצהרת נגישות', 'post_content' => 'הצהרת הנגישות.' ) );
update_post_meta( $a11y_page, '_wp_page_template', 'page-templates/template-accessibility.php' );
delete_transient( 'dx_a11y_url' );

$fresh = $app->cases->find( $case->id );
printf( "manager=%d client=%d case=%d public=%s stage=%d\n", $manager, $member, $case->id, $fresh->public_id, $fresh->stage );
