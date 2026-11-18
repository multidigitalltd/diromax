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

		$subject = 1 === $count
			? $items[0]->subject . ' · ' . $client_case->title
			/* translators: 1: number of updates, 2: case address. */
			: sprintf( __( '%1$d עדכונים חדשים בתיק %2$s', 'diromax-portal' ), $count, $client_case->title );

		$headline = 1 === $count
			? $items[0]->badge
			/* translators: %d: number of updates. */
			: sprintf( __( '%d עדכונים חדשים בתיק', 'diromax-portal' ), $count );

		$view = $this->base(
			$client_case,
			array(
				'preheader' => implode( ' · ', array_map( static fn( MailCard $item ): string => $item->title, array_slice( $items, 0, 3 ) ) ),
				'eyebrow'   => sprintf( /* translators: %s: case address. */ __( 'עדכון בתיק · %s', 'diromax-portal' ), $client_case->title ),
				'headline'  => $headline,
				'greeting'  => $name,
				'intro'     => 1 === $count
					? __( 'זה העדכון האחרון בתיק. כל הפרטים והמסמכים נמצאים באזור האישי.', 'diromax-portal' )
					: __( 'אלה העדכונים מהזמן האחרון. כל הפרטים והמסמכים נמצאים באזור האישי.', 'diromax-portal' ),
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

		return $this->message( $subject, $view );
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
