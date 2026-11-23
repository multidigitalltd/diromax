<?php
/**
 * Email rendering.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Service;

use Diromax\Portal\Data\CaseRecord;
use Diromax\Portal\Domain\CaseSummary;
use Diromax\Portal\Domain\Money;
use Diromax\Portal\Domain\Text;
use Diromax\Portal\Portal\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the three emails from templates/emails (spec 8.3–8.4):
 * table layout, inline styles, RTL, a bulletproof button, and a plain-text
 * alternative derived from the same HTML.
 */
final class MailRenderer {

	/**
	 * The update email for one member.
	 *
	 * @param CaseRecord       $client_case    Case.
	 * @param MailCard[]       $items   Cards from MailItems.
	 * @param CaseSummary|null $summary Client summary, or null when amounts are hidden.
	 * @param string           $name    Recipient's name.
	 * @return MailMessage
	 */
	public function case_update( CaseRecord $client_case, array $items, ?CaseSummary $summary, string $name ): MailMessage {
		$count = count( $items );

		return $this->update_email(
			$client_case,
			$items,
			$summary,
			$name,
			array(
				'subject'  => 1 === $count
					? $items[0]->subject . ' · ' . $client_case->title
					/* translators: 1: number of updates, 2: case address. */
					: sprintf( __( '%1$d עדכונים חדשים בתיק %2$s', 'diromax-portal' ), $count, $client_case->title ),
				'headline' => 1 === $count
					? $items[0]->badge
					/* translators: %d: number of updates. */
					: sprintf( __( '%d עדכונים חדשים בתיק', 'diromax-portal' ), $count ),
				'intro'    => 1 === $count
					? __( 'זה העדכון האחרון בתיק. כל הפרטים והמסמכים נמצאים באזור האישי.', 'diromax-portal' )
					: __( 'אלה העדכונים מהזמן האחרון. כל הפרטים והמסמכים נמצאים באזור האישי.', 'diromax-portal' ),
			)
		);
	}

	/**
	 * The daily digest for one member (spec 15.3).
	 *
	 * @param CaseRecord       $client_case Case.
	 * @param MailCard[]       $items       Cards.
	 * @param CaseSummary|null $summary     Client summary, or null when amounts are hidden.
	 * @param string           $name        Recipient's name.
	 * @return MailMessage
	 */
	public function digest( CaseRecord $client_case, array $items, ?CaseSummary $summary, string $name ): MailMessage {
		$count = count( $items );

		return $this->update_email(
			$client_case,
			$items,
			$summary,
			$name,
			array(
				'subject'  => 1 === $count
					/* translators: %s: case address. */
					? sprintf( __( 'סיכום יומי: עדכון אחד בתיק %s', 'diromax-portal' ), $client_case->title )
					/* translators: 1: number of updates, 2: case address. */
					: sprintf( __( 'סיכום יומי: %1$d עדכונים בתיק %2$s', 'diromax-portal' ), $count, $client_case->title ),
				'headline' => __( 'הסיכום היומי שלך', 'diromax-portal' ),
				'intro'    => __( 'אלה העדכונים בתיק מאז הסיכום הקודם. בחרת לקבל מייל אחד ביום; אפשר לשנות את זה באזור האישי, בעמוד "החשבון שלי".', 'diromax-portal' ),
			)
		);
	}

	/**
	 * The monthly summary for one member (spec 15.4).
	 *
	 * @param CaseRecord       $client_case Case.
	 * @param MailCard[]       $items       Cards.
	 * @param CaseSummary|null $summary     Client summary, or null when amounts are hidden.
	 * @param string           $name        Recipient's name.
	 * @param string           $month       Month name and year.
	 * @return MailMessage
	 */
	public function monthly( CaseRecord $client_case, array $items, ?CaseSummary $summary, string $name, string $month ): MailMessage {
		return $this->update_email(
			$client_case,
			$items,
			$summary,
			$name,
			array(
				/* translators: 1: month and year, 2: case address. */
				'subject'  => sprintf( __( 'סיכום %1$s · %2$s', 'diromax-portal' ), $month, $client_case->title ),
				/* translators: %s: month and year. */
				'headline' => sprintf( __( 'סיכום %s', 'diromax-portal' ), $month ),
				'intro'    => __( 'כך נראה התיק בסוף החודש: מה קרה, איפה אנחנו עומדים ומה פתוח. כל הפרטים נמצאים באזור האישי.', 'diromax-portal' ),
			)
		);
	}

	/**
	 * A note to the project manager that a client acted.
	 *
	 * @param CaseRecord $client_case Case.
	 * @param MailCard   $card        What happened.
	 * @param string     $url         Admin screen to open.
	 * @return MailMessage
	 */
	public function manager_notice( CaseRecord $client_case, MailCard $card, string $url ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => $card->title,
				/* translators: 1: case number, 2: case address. */
				'eyebrow'   => sprintf( __( 'תיק %1$s · %2$s', 'diromax-portal' ), $client_case->number, $client_case->title ),
				'headline'  => $card->badge,
				'greeting'  => '',
				'intro'     => __( 'הודעה אוטומטית מהאזור האישי של הלקוחות.', 'diromax-portal' ),
				'body'      => 'case-update',
				'items'     => array( $card ),
				'stages'    => Stages::names(),
				'summary'   => null,
				'cta'       => array(
					'label' => __( 'לפתיחת התיק בלוח הניהול', 'diromax-portal' ),
					'url'   => $url,
				),
			)
		);

		return $this->message( $card->subject . ' · ' . $client_case->title, $view );
	}

	/**
	 * A client's email reply, forwarded in full to the project manager
	 * (spec 17.3). With a reason, it was not kept in the case and the
	 * manager is warned.
	 *
	 * @param CaseRecord|null                                                                                                                                                           $client_case Case, null when the address had none.
	 * @param array{name:string, email:string, subject:string, text:string, files:array<int, array{name:string, attached:bool, kept:bool}>, reason:string, url:string, channel?:string} $reply The reply; channel "portal" when it was written on the site (spec 18.1).
	 * @return MailMessage
	 */
	public function inbound( ?CaseRecord $client_case, array $reply ): MailMessage {
		$who     = '' !== $reply['name'] ? $reply['name'] : $reply['email'];
		$warning = match ( $reply['reason'] ) {
			''           => '',
			'closed'     => __( 'התיק סגור, ולכן ההודעה לא נשמרה בו.', 'diromax-portal' ),
			/* translators: %s: sender address. */
			'not_member' => sprintf( __( 'הכתובת %s אינה של אחד הלקוחות בתיק, ולכן ההודעה לא נשמרה בתיק.', 'diromax-portal' ), $reply['email'] ),
			/* translators: %s: sender address. */
			'unverified' => sprintf( __( 'לא הצלחנו לוודא שההודעה באמת נשלחה מהכתובת %s, ולכן היא לא נשמרה בתיק. לא פותחים קבצים או קישורים לפני שמוודאים מול הלקוח.', 'diromax-portal' ), $reply['email'] ),
			default      => __( 'ההודעה הגיעה לכתובת התשובות בלי סימון תיק תקין, ולכן לא נשמרה באף תיק.', 'diromax-portal' ),
		};

		$view = $this->base(
			null,
			array(
				'preheader' => '' !== $reply['subject'] ? $reply['subject'] : $who,
				'eyebrow'   => null === $client_case
					? __( 'הודעה לכתובת התשובות', 'diromax-portal' )
					/* translators: 1: case number, 2: case address. */
					: sprintf( __( 'תיק %1$s · %2$s', 'diromax-portal' ), $client_case->number, $client_case->title ),
				/* translators: %s: sender name. */
				'headline'  => '' === $warning ? sprintf( __( 'הודעה חדשה מ%s', 'diromax-portal' ), $who ) : __( 'הודעה לבדיקה', 'diromax-portal' ),
				'greeting'  => '',
				'intro'     => match ( true ) {
					'' !== $warning                          => __( 'התקבלה הודעה לכתובת התשובות של האזור האישי.', 'diromax-portal' ),
					'portal' === ( $reply['channel'] ?? '' ) => __( 'הלקוח כתב לך מהאזור האישי. ההודעה שמורה בתיק, בלשונית „הודעות”. אפשר לענות מהתיק, או להשיב למייל הזה.', 'diromax-portal' ),
					default                                  => __( 'התקבלה תשובה למייל מהאזור האישי. ההודעה נשמרה בתיק, בלשונית „הודעות”. כדי לענות ללקוח, משיבים למייל הזה.', 'diromax-portal' ),
				},
				'body'      => 'message',
				'warning'   => $warning,
				'reply'     => $reply,
				'cta'       => '' === $reply['url'] || '' !== $warning ? null : array(
					'label' => __( 'להודעות בתיק', 'diromax-portal' ),
					'url'   => $reply['url'],
				),
			)
		);

		$subject = '' === $warning
			/* translators: 1: sender name, 2: case address. */
			? sprintf( __( 'הודעה מ%1$s · %2$s', 'diromax-portal' ), $who, null === $client_case ? '' : $client_case->title )
			/* translators: %s: sender address. */
			: sprintf( __( 'לבדיקה: הודעה מ-%s', 'diromax-portal' ), $reply['email'] ) . ( null === $client_case ? '' : ' · ' . $client_case->title );

		return $this->message( $subject, $view );
	}

	/**
	 * The project manager's reply, to a client of the case (spec 18.1).
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $name        Recipient's name.
	 * @param string     $manager     Who wrote it.
	 * @param string     $text        The reply.
	 * @param string     $url         The conversation in the portal.
	 * @return MailMessage
	 */
	public function staff_reply( CaseRecord $client_case, string $name, string $manager, string $text, string $url ): MailMessage {
		$view = $this->base(
			$client_case,
			array(
				'preheader' => mb_substr( Text::line( $text ), 0, 120 ),
				/* translators: 1: case number, 2: case address. */
				'eyebrow'   => sprintf( __( 'תיק %1$s · %2$s', 'diromax-portal' ), $client_case->number, $client_case->title ),
				/* translators: %s: project manager name. */
				'headline'  => sprintf( __( 'תשובה מ%s', 'diromax-portal' ), $manager ),
				'greeting'  => $name,
				'intro'     => __( 'מנהל הפרויקט כתב לך בתיק. ההודעה המלאה כאן, וגם בלשונית „הודעות” באזור האישי.', 'diromax-portal' ),
				'body'      => 'message',
				'warning'   => '',
				'reply'     => array(
					'name'    => $manager,
					'email'   => '',
					'subject' => '',
					'text'    => $text,
					'files'   => array(),
				),
				'cta'       => array(
					'label' => __( 'לשיחה באזור האישי', 'diromax-portal' ),
					'url'   => $url,
				),
			)
		);

		/* translators: %s: case address. */
		return $this->message( sprintf( __( 'תשובה ממנהל הפרויקט · %s', 'diromax-portal' ), $client_case->title ), $view );
	}

	/**
	 * A meeting: invitation, change, cancellation or reminder, with the
	 * calendar file attached by the sender (spec 20.1).
	 *
	 * @param CaseRecord $client_case Case.
	 * @param string     $name        Recipient's name.
	 * @param MailCard   $card        The meeting.
	 * @param string     $kind        invite, update, cancel, remind_tomorrow or remind_today.
	 * @param string     $url         The case in the portal.
	 * @return MailMessage
	 */
	public function meeting( CaseRecord $client_case, string $name, MailCard $card, string $kind, string $url ): MailMessage {
		$intro = match ( $kind ) {
			'update'          => __( 'פרטי הפגישה השתנו. הקובץ המצורף מעדכן אותה ביומן שלך.', 'diromax-portal' ),
			'cancel'          => __( 'הפגישה בוטלה. הקובץ המצורף מסיר אותה מהיומן שלך.', 'diromax-portal' ),
			'remind_tomorrow' => __( 'תזכורת: הפגישה מחר.', 'diromax-portal' ),
			'remind_today'    => __( 'תזכורת: הפגישה היום.', 'diromax-portal' ),
			default           => __( 'הוזמנת לפגישה בתיק. כדי להוסיף אותה ליומן, פותחים את הקובץ המצורף.', 'diromax-portal' ),
		};
		$view = $this->base(
			$client_case,
			array(
				'preheader' => $card->title,
				/* translators: 1: case number, 2: case address. */
				'eyebrow'   => sprintf( __( 'תיק %1$s · %2$s', 'diromax-portal' ), $client_case->number, $client_case->title ),
				'headline'  => $card->badge,
				'greeting'  => $name,
				'intro'     => $intro,
				'body'      => 'case-update',
				'items'     => array( $card ),
				'stages'    => Stages::names(),
				'summary'   => null,
				'cta'       => array(
					'label' => __( 'לתיק באזור האישי', 'diromax-portal' ),
					'url'   => $url,
				),
			)
		);

		return $this->message( $card->subject . ' · ' . $client_case->title, $view );
	}

	/**
	 * The staff morning summary.
	 *
	 * @param string     $name  Recipient's name.
	 * @param MailCard[] $cards Tasks and late stages.
	 * @param string     $url   Dashboard.
	 * @return MailMessage
	 */
	public function morning( string $name, array $cards, string $url ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => $cards[0]->title ?? '',
				'eyebrow'   => __( 'תיקי לקוחות · סיכום בוקר', 'diromax-portal' ),
				'headline'  => __( 'מה מחכה לך היום', 'diromax-portal' ),
				'greeting'  => $name,
				'intro'     => __( 'המשימות שלך שהגיע מועדן, הפגישות של היום ומחר, והשלבים שעברו את היעד בתיקים שלך.', 'diromax-portal' ),
				'body'      => 'case-update',
				'items'     => $cards,
				'stages'    => Stages::names(),
				'summary'   => null,
				'cta'       => array(
					'label' => __( 'ללוח הבקרה', 'diromax-portal' ),
					'url'   => $url,
				),
			)
		);

		return $this->message( __( 'סיכום בוקר · תיקי לקוחות', 'diromax-portal' ), $view );
	}

	/**
	 * A request to sign a document, with the signing link.
	 *
	 * @param string     $name        Signer's name.
	 * @param CaseRecord $client_case Case.
	 * @param string     $title       Document title.
	 * @param string     $url         Signing link.
	 * @param string     $manager     Project manager's name.
	 * @return MailMessage
	 */
	public function sign_request( string $name, CaseRecord $client_case, string $title, string $url, string $manager ): MailMessage {
		$view = $this->base(
			null,
			array(
				/* translators: %d: days. */
				'preheader' => sprintf( __( 'הקישור תקף ל-%d יום.', 'diromax-portal' ), SignatureService::LINK_DAYS ),
				'eyebrow'   => $client_case->title,
				'headline'  => __( 'מסמך לחתימתך', 'diromax-portal' ),
				'greeting'  => $name,
				/* translators: 1: document title, 2: project manager. */
				'intro'     => sprintf( __( 'נשלח אליך לחתימה המסמך "%1$s"%2$s. בכפתור למטה אפשר לקרוא את המסמך ולחתום עליו מהטלפון או מהמחשב. לפני החתימה נשלח קוד לכתובת המייל הזו.', 'diromax-portal' ), $title, '' === $manager ? '' : sprintf( /* translators: %s: name. */ __( ' על ידי %s מדירומקס', 'diromax-portal' ), $manager ) ),
				'body'      => 'notice',
				/* translators: %d: days. */
				'notice'    => sprintf( __( 'הקישור אישי, תקף ל-%d יום ולחתימה אחת. לא ציפית למסמך הזה? אפשר להתעלם מהמייל.', 'diromax-portal' ), SignatureService::LINK_DAYS ),
				'cta'       => array(
					'label' => __( 'לקריאה ולחתימה', 'diromax-portal' ),
					'url'   => $url,
				),
			)
		);

		/* translators: %s: document title. */
		return $this->message( sprintf( __( 'מסמך לחתימתך: %s · דירומקס', 'diromax-portal' ), $title ), $view );
	}

	/**
	 * The one-time code that confirms the signer's address.
	 *
	 * @param string $name Signer's name.
	 * @param string $code 6 digits.
	 * @return MailMessage
	 */
	public function sign_code( string $name, string $code ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => __( 'הקוד תקף ל-15 דקות.', 'diromax-portal' ),
				'eyebrow'   => __( 'חתימה על מסמך', 'diromax-portal' ),
				/* translators: %s: code. */
				'headline'  => sprintf( __( 'קוד החתימה: %s', 'diromax-portal' ), $code ),
				'greeting'  => $name,
				'intro'     => __( 'מקלידים את הקוד בעמוד החתימה. הוא תקף ל-15 דקות.', 'diromax-portal' ),
				'body'      => 'notice',
				'notice'    => __( 'לא ביקשת לחתום? אל תמסור את הקוד לאף אחד, ואפשר להתעלם מהמייל.', 'diromax-portal' ),
			)
		);

		return $this->message( __( 'קוד לחתימה על מסמך · דירומקס', 'diromax-portal' ), $view );
	}

	/**
	 * Confirmation to someone who signed through a link.
	 *
	 * @param string $name   Signer's name.
	 * @param string $title  Document title.
	 * @param string $sha256 Fingerprint of the signed file.
	 * @param string $when   Date and time.
	 * @return MailMessage
	 */
	public function signed_copy( string $name, string $title, string $sha256, string $when ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => $title,
				'eyebrow'   => __( 'חתימה על מסמך', 'diromax-portal' ),
				'headline'  => __( 'החתימה התקבלה', 'diromax-portal' ),
				'greeting'  => $name,
				/* translators: 1: document title, 2: date and time. */
				'intro'     => sprintf( __( 'חתמת על "%1$s" ב-%2$s. תודה.', 'diromax-portal' ), $title, $when ),
				'body'      => 'notice',
				/* translators: %s: SHA-256. */
				'notice'    => sprintf( __( 'טביעת האצבע (SHA-256) של הקובץ שנחתם: %s. היא מזהה בדיוק את הגרסה שעליה חתמת.', 'diromax-portal' ), $sha256 ),
			)
		);

		/* translators: %s: document title. */
		return $this->message( sprintf( __( 'החתימה התקבלה: %s · דירומקס', 'diromax-portal' ), $title ), $view );
	}

	/**
	 * The one-time sign-in code.
	 *
	 * @param string $name Recipient's name.
	 * @param string $code 6 digits.
	 * @return MailMessage
	 */
	public function login_code( string $name, string $code ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => __( 'הקוד תקף ל-10 דקות.', 'diromax-portal' ),
				'eyebrow'   => __( 'כניסה מאובטחת', 'diromax-portal' ),
				/* translators: %s: code. */
				'headline'  => sprintf( __( 'קוד הכניסה: %s', 'diromax-portal' ), $code ),
				'greeting'  => $name,
				'intro'     => __( 'מקלידים את הקוד במסך הכניסה כדי להשלים את הכניסה. הוא תקף ל-10 דקות ולכניסה אחת.', 'diromax-portal' ),
				'body'      => 'notice',
				'notice'    => __( 'לא ניסית להיכנס? מישהו הקליד את הסיסמה שלך. אל תמסור את הקוד לאף אחד, והחלף את הסיסמה.', 'diromax-portal' ),
			)
		);

		/* translators: %s: code. */
		return $this->message( sprintf( __( 'קוד כניסה: %s · דירומקס', 'diromax-portal' ), $code ), $view );
	}

	/**
	 * A test email from the settings screen.
	 *
	 * @param string $name     Recipient's name.
	 * @param bool   $postmark Sent through Postmark.
	 * @return MailMessage
	 */
	public function test_mail( string $name, bool $postmark ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => __( 'בדיקת שליחת מיילים מהאתר.', 'diromax-portal' ),
				'eyebrow'   => __( 'הגדרות האזור האישי', 'diromax-portal' ),
				'headline'  => __( 'מייל בדיקה', 'diromax-portal' ),
				'greeting'  => $name,
				'intro'     => $postmark
					? __( 'המייל הזה יצא דרך Postmark. אם הוא הגיע לתיבה הראשית, השליחה מוגדרת נכון.', 'diromax-portal' )
					: __( 'המייל הזה יצא בדרך הרגילה של האתר, כי Postmark עדיין לא מוגדר.', 'diromax-portal' ),
				'body'      => 'notice',
				'notice'    => __( 'אין צורך להשיב למייל הזה.', 'diromax-portal' ),
			)
		);

		return $this->message( __( 'מייל בדיקה · דירומקס', 'diromax-portal' ), $view );
	}

	/**
	 * Security notice after a password was set or changed.
	 *
	 * @param string $name Recipient's name.
	 * @return MailMessage
	 */
	public function password_changed( string $name ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => __( 'הסיסמה לאזור האישי עודכנה.', 'diromax-portal' ),
				'eyebrow'   => __( 'האזור האישי · אבטחה', 'diromax-portal' ),
				'headline'  => __( 'הסיסמה עודכנה', 'diromax-portal' ),
				'greeting'  => $name,
				'intro'     => __( 'הסיסמה לאזור האישי של דירומקס עודכנה עכשיו, וכל החיבורים האחרים לחשבון נותקו.', 'diromax-portal' ),
				'body'      => 'notice',
				'notice'    => __( 'לא אתה עדכנת? היכנס מיד לאזור האישי עם קישור כניסה למייל, החלף את הסיסמה, ועדכן את מנהל הפרויקט שלך.', 'diromax-portal' ),
				'cta'       => array(
					'label' => __( 'לעמוד הכניסה', 'diromax-portal' ),
					'url'   => Router::login_url(),
				),
			)
		);

		return $this->message( __( 'הסיסמה לאזור האישי עודכנה · דירומקס', 'diromax-portal' ), $view );
	}

	/**
	 * The sign-in link email.
	 *
	 * @param string $name Recipient's name.
	 * @param string $url  One-time link.
	 * @return MailMessage
	 */
	public function login_link( string $name, string $url ): MailMessage {
		$view = $this->base(
			null,
			array(
				'preheader' => __( 'הקישור תקף ל-15 דקות ולשימוש אחד.', 'diromax-portal' ),
				'eyebrow'   => __( 'האזור האישי', 'diromax-portal' ),
				'headline'  => __( 'קישור כניסה לאזור האישי', 'diromax-portal' ),
				'greeting'  => $name,
				'intro'     => __( 'ביקשת להיכנס לאזור האישי של דירומקס. הכפתור למטה מכניס אותך ישירות, בלי סיסמה. הקישור תקף ל-15 דקות ולשימוש אחד.', 'diromax-portal' ),
				'body'      => 'notice',
				'notice'    => __( 'לא ביקשת להיכנס? אפשר להתעלם מהמייל הזה. אף אחד לא נכנס לחשבון בלי הקישור.', 'diromax-portal' ),
				'cta'       => array(
					'label' => __( 'כניסה לאזור האישי', 'diromax-portal' ),
					'url'   => $url,
				),
			)
		);

		return $this->message( __( 'קישור כניסה לאזור האישי · דירומקס', 'diromax-portal' ), $view );
	}

	/**
	 * The invitation email.
	 *
	 * @param string     $name Recipient's name.
	 * @param CaseRecord $client_case Case.
	 * @param string     $url  One-time link.
	 * @return MailMessage
	 */
	public function invitation( string $name, CaseRecord $client_case, string $url ): MailMessage {
		$view = $this->base(
			$client_case,
			array(
				'preheader' => __( 'כל מה שקורה בתיק, במקום אחד.', 'diromax-portal' ),
				'eyebrow'   => $client_case->title,
				'headline'  => __( 'פתחנו לך אזור אישי', 'diromax-portal' ),
				'greeting'  => $name,
				'intro'     => __( 'מהיום אפשר לראות בכל רגע את מצב התיק: ההסכם, התקציב, כל הוצאה וכל תשלום, המסמכים ועדכוני ההתקדמות. בכל פעם שנוסיף משהו, יגיע אליך מייל כמו זה.', 'diromax-portal' ),
				'body'      => 'notice',
				'notice'    => __( 'הקישור תקף ל-72 שעות. אחר כך אפשר תמיד לבקש קישור כניסה חדש מעמוד הכניסה לאזור האישי.', 'diromax-portal' ),
				'cta'       => array(
					'label' => __( 'כניסה לאזור האישי', 'diromax-portal' ),
					'url'   => $url,
				),
			)
		);

		/* translators: %s: case address. */
		return $this->message( sprintf( __( 'הוזמנת לאזור האישי של דירומקס · %s', 'diromax-portal' ), $client_case->title ), $view );
	}

	/**
	 * Strip HTML into a readable plain-text alternative.
	 *
	 * @param string $html Email HTML.
	 * @return string
	 */
	public static function to_text( string $html ): string {
		$html = (string) preg_replace( '#<(head|style)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<div class="dx-preheader".*?</div>#is', '', $html );
		$html = (string) preg_replace( '#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2: $1', $html );
		$html = (string) preg_replace( '#<br\s*/?>|</(p|h1|h2|h3|tr|li)>#i', "\n", $html );
		$html = (string) preg_replace( '#</t[dh]>#i', '  ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = implode( "\n", array_map( 'trim', explode( "\n", $text ) ) );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * An email built from cards, with the money block and the button to the case.
	 *
	 * @param CaseRecord                                           $client_case Case.
	 * @param MailCard[]                                           $items       Cards.
	 * @param CaseSummary|null                                     $summary     Client summary, or null.
	 * @param string                                               $name        Recipient's name.
	 * @param array{subject:string, headline:string, intro:string} $copy  Texts.
	 * @return MailMessage
	 */
	private function update_email( CaseRecord $client_case, array $items, ?CaseSummary $summary, string $name, array $copy ): MailMessage {
		$view = $this->base(
			$client_case,
			array(
				'preheader' => implode( ' · ', array_map( static fn( MailCard $item ): string => $item->title, array_slice( $items, 0, 3 ) ) ),
				'eyebrow'   => sprintf( /* translators: %s: case address. */ __( 'עדכון בתיק · %s', 'diromax-portal' ), $client_case->title ),
				'headline'  => $copy['headline'],
				'greeting'  => $name,
				'intro'     => $copy['intro'],
				'body'      => 'case-update',
				'items'     => $items,
				'stages'    => Stages::names(),
				'summary'   => null === $summary ? null : self::summary_view( $summary ),
				'cta'       => array(
					'label' => __( 'לצפייה בתיק המלא', 'diromax-portal' ),
					'url'   => Router::case_url( $client_case ),
				),
			)
		);

		return $this->message( $copy['subject'], $view );
	}

	/**
	 * Fields every email shares.
	 *
	 * @param CaseRecord|null      $client_case Case, for the manager card.
	 * @param array<string, mixed> $view Email-specific fields.
	 * @return array<string, mixed>
	 */
	private function base( ?CaseRecord $client_case, array $view ): array {
		return array_merge(
			array(
				'logo'      => Contact::logo_url(),
				'site_url'  => home_url( '/' ),
				'site_host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'portal'    => Router::portal_url(),
				'manager'   => null === $client_case ? null : Contact::manager( $client_case->manager_id ),
				'case'      => $client_case?->title,
				'into_case' => InboundService::enabled(),
			),
			$view
		);
	}

	/**
	 * Render the layout and derive the text part.
	 *
	 * @param string               $subject Subject.
	 * @param array<string, mixed> $view    View model.
	 * @return MailMessage
	 */
	private function message( string $subject, array $view ): MailMessage {
		$html = self::render( $view );

		return new MailMessage( $subject, $html, self::to_text( $html ) );
	}

	/**
	 * Render templates/emails/layout.php with the view model.
	 *
	 * @param array<string, mixed> $view View model.
	 * @return string
	 */
	private static function render( array $view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Read by the included template.
		ob_start();
		include DX_PORTAL_DIR . 'templates/emails/layout.php';

		return (string) ob_get_clean();
	}

	/**
	 * Money block of the email.
	 *
	 * @param CaseSummary $summary Client summary.
	 * @return array{cost:string, budget:string, share:string, share_bps:int, balance:string|null, committed:string|null, due:string|null}
	 */
	private static function summary_view( CaseSummary $summary ): array {
		return array(
			'cost'      => Money::format( $summary->cost_to_date ),
			'budget'    => $summary->budget > 0 ? Money::format( $summary->budget ) : '',
			'share'     => Money::percent( $summary->utilization_bps() ),
			'share_bps' => min( 10000, $summary->utilization_bps() ),
			'balance'   => $summary->has_project_account ? Money::format( $summary->project_balance ) : null,
			'committed' => $summary->committed > 0 ? Money::format( $summary->committed ) : null,
			'due'       => $summary->open_requests > 0 ? Money::format( $summary->open_requests ) : null,
		);
	}
}
