<?php
/**
 * Admin entry point.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Plugin;
use Diromax\Portal\Portal\Router;
use Diromax\Portal\Security\Capabilities;
use Diromax\Portal\Service\Contact;
use Diromax\Portal\Service\LeadLink;
use Diromax\Portal\Service\LoginCodeService;
use Diromax\Portal\Support\AssetFile;

defined( 'ABSPATH' ) || exit;

/**
 * Menus, assets, notices and the manager phone field. Loaded in wp-admin only.
 */
final class Admin {

	/** Admin page slugs. */
	public const PAGE_DASHBOARD = 'dx-dashboard';

	public const PAGE_CASES    = 'dx-cases';
	public const PAGE_BOARD    = 'dx-board';
	public const PAGE_NEW      = 'dx-case-new';
	public const PAGE_SETTINGS = 'dx-portal-settings';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Register the admin hooks.
	 */
	public function register(): void {
		$pages = new Pages( $this->app );

		add_action(
			'admin_menu',
			static function () use ( $pages ): void {
				add_menu_page( __( 'תיקי לקוחות', 'diromax-portal' ), __( 'תיקי לקוחות', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_DASHBOARD, array( $pages, 'dashboard' ), 'dashicons-portfolio', 26 );
				add_submenu_page( self::PAGE_DASHBOARD, __( 'לוח בקרה', 'diromax-portal' ), __( 'לוח בקרה', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_DASHBOARD, array( $pages, 'dashboard' ) );
				add_submenu_page( self::PAGE_DASHBOARD, __( 'כל התיקים', 'diromax-portal' ), __( 'כל התיקים', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_CASES, array( $pages, 'cases' ) );
				add_submenu_page( self::PAGE_DASHBOARD, __( 'לוח שלבים', 'diromax-portal' ), __( 'לוח שלבים', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_BOARD, array( $pages, 'board' ) );
				add_submenu_page( self::PAGE_DASHBOARD, __( 'תיק חדש', 'diromax-portal' ), __( 'תיק חדש', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_NEW, array( $pages, 'new_case' ) );
				add_submenu_page( self::PAGE_DASHBOARD, __( 'הגדרות האזור האישי', 'diromax-portal' ), __( 'הגדרות', 'diromax-portal' ), Capabilities::MANAGE_SETTINGS, self::PAGE_SETTINGS, array( $pages, 'settings' ) );
			}
		);

		( new Actions( $this->app ) )->register();

		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'post_row_actions', array( new LeadLink( $this->app ), 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notices' ) );

		add_action( 'show_user_profile', array( $this, 'phone_field' ) );
		add_action( 'edit_user_profile', array( $this, 'phone_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_phone' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_phone' ) );
	}

	/**
	 * Render and redirect within the management app on the site instead of
	 * wp-admin. Set by the app's controller, and by the form handlers when
	 * the form was posted from the app.
	 *
	 * @var bool
	 */
	private static bool $front = false;

	/**
	 * Point the plugin's URLs at the management app (true) or wp-admin.
	 *
	 * @param bool $front App or wp-admin.
	 */
	public static function use_front( bool $front ): void {
		self::$front = $front;
	}

	/**
	 * Are the plugin's URLs pointing at the management app?
	 *
	 * @return bool
	 */
	public static function is_front(): bool {
		return self::$front;
	}

	/**
	 * URL of a plugin screen, in whichever interface the user is working in.
	 *
	 * @param string $page One of the PAGE_* slugs.
	 * @return string
	 */
	public static function page_url( string $page ): string {
		if ( self::$front ) {
			return Router::manage_url(
				match ( $page ) {
					self::PAGE_CASES    => 'cases',
					self::PAGE_BOARD    => 'board',
					self::PAGE_NEW      => 'new',
					self::PAGE_SETTINGS => 'settings',
					default             => 'dashboard',
				}
			);
		}

		return add_query_arg( 'page', $page, admin_url( 'admin.php' ) );
	}

	/**
	 * The signature certificate of a document or a decision (admin-post, with a nonce).
	 *
	 * @param int    $case_id Case.
	 * @param string $subject document or decision.
	 * @param int    $id      Subject.
	 * @return string
	 */
	public static function certificate_url( int $case_id, string $subject, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'dx_portal_certificate',
					'case_id' => $case_id,
					'subject' => $subject,
					'id'      => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'dx_portal_certificate_' . $case_id
		);
	}

	/**
	 * URL of a case screen, in whichever interface the user is working in.
	 *
	 * @param int    $case_id Case.
	 * @param string $tab     Tab.
	 * @return string
	 */
	public static function case_url( int $case_id, string $tab = '' ): string {
		if ( self::$front ) {
			return Router::manage_url( 'case', $case_id, $tab );
		}

		$args = array(
			'page' => self::PAGE_CASES,
			'case' => $case_id,
		);

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Assets on the plugin's screens only.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public function assets( string $hook ): void {
		if ( ! self::is_plugin_screen( $hook ) ) {
			return;
		}

		self::register_assets();
		wp_enqueue_style( 'dx-portal-admin' );
		wp_enqueue_script( 'dx-portal-admin' );
	}

	/**
	 * Register the screens' stylesheet (with the site's fonts) and script,
	 * for wp-admin and for the management app on the site.
	 */
	public static function register_assets(): void {
		$css    = AssetFile::path( 'admin', 'css' );
		$manage = AssetFile::path( 'manage', 'css' );
		$js     = AssetFile::path( 'admin', 'js' );

		wp_register_style( 'dx-portal-admin', AssetFile::url( $css ), array(), AssetFile::version( $css ) );
		wp_add_inline_style( 'dx-portal-admin', self::font_faces() );
		wp_register_style( 'dx-portal-manage', AssetFile::url( $manage ), array( 'dx-portal-admin' ), AssetFile::version( $manage ) );
		wp_register_script(
			'dx-portal-admin',
			AssetFile::url( $js ),
			array(),
			AssetFile::version( $js ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Body class that scopes the plugin's admin design to its own screens.
	 *
	 * @param string $classes Space-separated classes.
	 * @return string
	 */
	public function body_class( string $classes ): string {
		$screen = get_current_screen();

		return null !== $screen && self::is_plugin_screen( $screen->id ) ? $classes . ' dxa-screen' : $classes;
	}

	/**
	 * Preload links for the two fonts that paint above the fold (the text and
	 * the heading), as the theme does on the site. Without them a slow
	 * connection swaps the fonts late, and the text reflows (layout shift).
	 *
	 * @return string HTML, or '' without the theme's font files.
	 */
	public static function font_preloads(): string {
		$dir  = get_template_directory() . '/assets/fonts/';
		$uri  = get_template_directory_uri() . '/assets/fonts/';
		$html = '';

		foreach ( array(
			'heebo-hebrew.woff2'     => 'font/woff2',
			'anomalia-bold-aaa.woff' => 'font/woff',
		) as $file => $type ) {
			if ( is_readable( $dir . $file ) ) {
				$html .= '<link rel="preload" href="' . esc_url( $uri . $file ) . '" as="font" type="' . esc_attr( $type ) . '" crossorigin>' . "\n";
			}
		}

		return $html;
	}

	/**
	 * The site's own fonts, from the Diromax theme when it is installed,
	 * so the admin screens match the portal. Self-hosted, no third party.
	 *
	 * @return string CSS, or '' without the theme's font files.
	 */
	private static function font_faces(): string {
		$dir   = get_template_directory() . '/assets/fonts/';
		$uri   = get_template_directory_uri() . '/assets/fonts/';
		$latin = 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD';
		$faces = array(
			array( 'Anomalia', 'anomalia-demibold-aaa.woff', 'woff', '600 700', '' ),
			array( 'Anomalia', 'anomalia-bold-aaa.woff', 'woff', '800 900', '' ),
			array( 'Heebo', 'heebo-hebrew.woff2', 'woff2', '100 900', 'U+0307-0308, U+0590-05FF, U+200C-2010, U+20AA, U+25CC, U+FB1D-FB4F' ),
			array( 'Heebo', 'heebo-latin.woff2', 'woff2', '100 900', $latin ),
			array( 'Archivo', 'archivo-latin.woff2', 'woff2', '100 900', $latin ),
		);
		$css   = '';

		foreach ( $faces as [ $family, $file, $format, $weight, $range ] ) {
			if ( is_readable( $dir . $file ) ) {
				$css .= sprintf(
					"@font-face{font-family:'%1\$s';src:url('%2\$s') format('%3\$s');font-weight:%4\$s;font-display:swap;%5\$s}",
					$family,
					esc_url( $uri . $file ),
					$format,
					$weight,
					'' === $range ? '' : 'unicode-range:' . $range . ';'
				);
			}
		}

		return $css;
	}

	/**
	 * Configuration problems that block a secure setup, on the plugin's
	 * wp-admin screens.
	 */
	public function notices(): void {
		$screen = get_current_screen();

		if ( null === $screen || ! self::is_plugin_screen( $screen->id ) ) {
			return;
		}

		foreach ( $this->config_notices( get_current_user_id() ) as [ $type, $message ] ) {
			self::notice( $type, $message );
		}
	}

	/**
	 * The configuration notices for a staff user, as [type, message] pairs.
	 *
	 * @param int $user User.
	 * @return array<int, array{0:string, 1:string}>
	 */
	public function config_notices( int $user ): array {
		if ( ! $this->app->policy->can_use_admin( $user ) ) {
			return array();
		}

		$notices = array();
		$key     = $this->app->document_service->key_status();

		if ( 'missing' === $key ) {
			$notices[] = array( 'error', __( 'העלאת מסמכים חסומה: חסר מפתח ההצפנה DX_PORTAL_KEY ב-wp-config.php. ההוראות ב-README של התוסף.', 'diromax-portal' ) );
		} elseif ( 'changed' === $key ) {
			$notices[] = array( 'error', __( 'מפתח ההצפנה ב-wp-config.php השתנה, ומסמכים שהועלו עם המפתח הקודם לא ייפתחו. החזירו את המפתח הקודם מהעותק השמור. העלאת מסמכים חסומה עד אז, כדי שלא יתערבבו שני מפתחות.', 'diromax-portal' ) );
		}

		if ( ! LoginCodeService::enabled() ) {
			$notices[] = array( 'error', __( 'הקוד במייל בכניסה כבוי במתג החירום (DX_PORTAL_EMAIL_CODE_OFF ב-wp-config.php). כל כניסה עם סיסמה עוברת עכשיו בלי אימות נוסף. מסירים את השורה ברגע שהמייל עובד.', 'diromax-portal' ) );
		} elseif ( 'other' === self::two_factor_status( $user ) ) {
			$notices[] = array( 'info', __( 'קוד במייל בכל כניסה מובנה בתוסף. אפשר להשבית את Two Factor או את האימות הדו-שלבי של All-In-One Security, כדי שלא יתבקשו שני אימותים.', 'diromax-portal' ) );
		}

		$failed = $this->app->events->failed_count();

		if ( $failed > 0 ) {
			$notices[] = array(
				'warning',
				1 === $failed
					? __( 'עדכון אחד ללקוח לא נשלח אחרי שלושה ניסיונות. כדאי לבדוק את הגדרות המייל של האתר.', 'diromax-portal' )
					/* translators: %d: number of notifications. */
					: sprintf( __( '%d עדכונים ללקוחות לא נשלחו אחרי שלושה ניסיונות. כדאי לבדוק את הגדרות המייל של האתר.', 'diromax-portal' ), $failed ),
			);
		}

		return $notices;
	}

	/**
	 * The second sign-in step a staff user has: the built-in email code
	 * (decision 16), or another plugin's (Two Factor, or the two-factor
	 * module of All-In-One Security) on top of it.
	 *
	 * @param int $user User.
	 * @return string email (built-in code), other (another plugin's too) or off (emergency switch).
	 */
	public static function two_factor_status( int $user ): string {
		$aios  = $GLOBALS['simba_two_factor_authentication'] ?? null;
		$other = ( class_exists( 'Two_Factor_Core' ) && \Two_Factor_Core::is_user_using_two_factor( $user ) )
			|| ( is_object( $aios ) && method_exists( $aios, 'is_activated_for_user' ) && method_exists( $aios, 'is_activated_by_user' ) && $aios->is_activated_for_user( $user ) && $aios->is_activated_by_user( $user ) );

		return $other ? 'other' : ( LoginCodeService::enabled() ? 'email' : 'off' );
	}

	/**
	 * Phone shown to clients, on a staff member's profile.
	 *
	 * @param \WP_User $user Profile user.
	 */
	public function phone_field( \WP_User $user ): void {
		if ( ! $this->app->policy->can_use_admin( $user->ID ) ) {
			return;
		}

		$phone = (string) get_user_meta( $user->ID, Contact::PHONE_META, true );
		wp_nonce_field( 'dx_portal_phone_' . $user->ID, '_dx_portal_phone_nonce' );
		?>
		<h2><?php esc_html_e( 'אזור אישי ללקוחות', 'diromax-portal' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="dx_portal_phone"><?php esc_html_e( 'טלפון שמוצג ללקוחות', 'diromax-portal' ); ?></label></th>
				<td>
					<input type="tel" name="dx_portal_phone" id="dx_portal_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" dir="ltr" autocomplete="tel">
					<p class="description"><?php esc_html_e( 'מופיע בכרטיס מנהל הפרויקט באזור האישי ובמיילים. ריק: הטלפון הכללי של האתר.', 'diromax-portal' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the phone field.
	 *
	 * @param int $user_id Profile user.
	 */
	public function save_phone( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['_dx_portal_phone_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'dx_portal_phone_' . $user_id, '_dx_portal_phone_nonce' );

		$phone = isset( $_POST['dx_portal_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['dx_portal_phone'] ) ) : '';
		$phone = (string) preg_replace( '/[^0-9+\-\s]/', '', $phone );

		update_user_meta( $user_id, Contact::PHONE_META, mb_substr( $phone, 0, 20 ) );
	}

	/**
	 * Is this one of the plugin's screens?
	 *
	 * @param string $id Screen ID or hook suffix.
	 * @return bool
	 */
	private static function is_plugin_screen( string $id ): bool {
		foreach ( array( self::PAGE_DASHBOARD, self::PAGE_CASES, self::PAGE_BOARD, self::PAGE_NEW, self::PAGE_SETTINGS ) as $page ) {
			if ( str_contains( $id, $page ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Print a notice.
	 *
	 * @param string $type    error, warning or success.
	 * @param string $message Text.
	 */
	private static function notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}
}
