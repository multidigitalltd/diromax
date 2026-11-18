<?php
/**
 * Admin entry point.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Admin;

use Diromax\Portal\Plugin;
use Diromax\Portal\Security\Capabilities;
use Diromax\Portal\Service\Contact;

defined( 'ABSPATH' ) || exit;

/**
 * Menus, assets, notices and the manager phone field. Loaded in wp-admin only.
 */
final class Admin {

	/** Admin page slugs. */
	public const PAGE_CASES    = 'dx-cases';
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
				add_menu_page( __( 'תיקי לקוחות', 'diromax-portal' ), __( 'תיקי לקוחות', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_CASES, array( $pages, 'cases' ), 'dashicons-portfolio', 26 );
				add_submenu_page( self::PAGE_CASES, __( 'כל התיקים', 'diromax-portal' ), __( 'כל התיקים', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_CASES, array( $pages, 'cases' ) );
				add_submenu_page( self::PAGE_CASES, __( 'תיק חדש', 'diromax-portal' ), __( 'תיק חדש', 'diromax-portal' ), Capabilities::MANAGE_CASES, self::PAGE_NEW, array( $pages, 'new_case' ) );
				add_submenu_page( self::PAGE_CASES, __( 'הגדרות האזור האישי', 'diromax-portal' ), __( 'הגדרות', 'diromax-portal' ), Capabilities::MANAGE_SETTINGS, self::PAGE_SETTINGS, array( $pages, 'settings' ) );
			}
		);

		( new Actions( $this->app ) )->register();

		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );

		add_action( 'show_user_profile', array( $this, 'phone_field' ) );
		add_action( 'edit_user_profile', array( $this, 'phone_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_phone' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_phone' ) );
	}

	/**
	 * Admin URL of a case screen.
	 *
	 * @param int    $case_id Case.
	 * @param string $tab     Tab.
	 * @return string
	 */
	public static function case_url( int $case_id, string $tab = '' ): string {
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

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$css    = file_exists( DX_PORTAL_DIR . 'assets/css/admin' . $suffix . '.css' ) ? 'assets/css/admin' . $suffix . '.css' : 'assets/css/admin.css';
		$js     = file_exists( DX_PORTAL_DIR . 'assets/js/admin' . $suffix . '.js' ) ? 'assets/js/admin' . $suffix . '.js' : 'assets/js/admin.js';

		wp_enqueue_style( 'dx-portal-admin', DX_PORTAL_URL . $css, array(), DX_PORTAL_VERSION );
		wp_enqueue_script(
			'dx-portal-admin',
			DX_PORTAL_URL . $js,
			array(),
			DX_PORTAL_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Configuration problems that block a secure setup.
	 */
	public function notices(): void {
		$screen = get_current_screen();
		$user   = get_current_user_id();

		if ( null === $screen || ! self::is_plugin_screen( $screen->id ) || ! $this->app->policy->can_use_admin( $user ) ) {
			return;
		}

		if ( ! $this->app->store->is_configured() ) {
			self::notice( 'error', __( 'העלאת מסמכים חסומה: חסר מפתח ההצפנה DX_PORTAL_KEY ב-wp-config.php. ההוראות ב-README של התוסף.', 'diromax-portal' ) );
		}

		if ( ! class_exists( 'Two_Factor_Core' ) ) {
			self::notice( 'warning', __( 'תקן Multi Digital מחייב אימות דו-שלבי לכל מנהל. יש להתקין ולהפעיל את התוסף Two Factor.', 'diromax-portal' ) );
		} elseif ( ! \Two_Factor_Core::is_user_using_two_factor( $user ) ) {
			self::notice( 'warning', __( 'החשבון שלך עדיין בלי אימות דו-שלבי. אפשר להפעיל אותו בעמוד הפרופיל.', 'diromax-portal' ) );
		}

		$failed = $this->app->events->failed_count();

		if ( $failed > 0 ) {
			self::notice(
				'warning',
				1 === $failed
					? __( 'עדכון אחד ללקוח לא נשלח אחרי שלושה ניסיונות. כדאי לבדוק את הגדרות המייל של האתר.', 'diromax-portal' )
					/* translators: %d: number of notifications. */
					: sprintf( __( '%d עדכונים ללקוחות לא נשלחו אחרי שלושה ניסיונות. כדאי לבדוק את הגדרות המייל של האתר.', 'diromax-portal' ), $failed )
			);
		}
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
		return str_contains( $id, self::PAGE_CASES ) || str_contains( $id, self::PAGE_NEW ) || str_contains( $id, self::PAGE_SETTINGS );
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
