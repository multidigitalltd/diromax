<?php
/**
 * The email code on wp-login.php.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Security;

use Diromax\Portal\Plugin;
use Diromax\Portal\Service\LoginCodeService;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * After the password on wp-login.php is accepted, and before WordPress
 * creates a session, the sign-in stops: a code goes to the account's email
 * and wp-login.php?action=dx_code asks for it. Only a right code creates
 * the session (decision 16). The built-in check replaces the Two Factor
 * plugin.
 */
final class LoginGate {

	/** The wp-login.php action of the code screen. */
	public const ACTION = 'dx_code';

	/**
	 * Constructor.
	 *
	 * @param Plugin $app Object graph.
	 */
	public function __construct( private readonly Plugin $app ) {}

	/**
	 * Register the hooks.
	 */
	public function register(): void {
		add_filter( 'authenticate', array( $this, 'authenticate' ), 100, 3 );
		add_action( 'login_form_' . self::ACTION, array( $this, 'code_screen' ) );
		add_filter( 'login_message', array( self::class, 'expired_notice' ) );
	}

	/**
	 * After an ended challenge, say why the sign-in starts over.
	 *
	 * @param string $message Messages so far.
	 * @return string
	 */
	public static function expired_notice( $message ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
		$expired = isset( $_GET['dx_code'] ) && 'expired' === sanitize_key( wp_unslash( $_GET['dx_code'] ) );

		return (string) $message . ( $expired ? '<p class="message">' . esc_html__( 'הקוד פג או שהוזן שגוי יותר מדי פעמים. נכנסים שוב, ונשלח קוד חדש.', 'diromax-portal' ) . '</p>' : '' );
	}

	/**
	 * Late on authenticate: a user whose password passed on wp-login.php
	 * gets a code instead of a session. Other paths (XML-RPC, application
	 * passwords, the portal's own forms) are left alone.
	 *
	 * @param WP_User|WP_Error|null $user     Result so far.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public function authenticate( $user, $username, $password ) {
		unset( $username, $password );

		if ( ! $user instanceof WP_User || ! did_action( 'login_init' ) ) {
			return $user;
		}

		if ( ! LoginCodeService::enabled() ) {
			LoginCodeService::log_bypass( $user, 'wp-login' );
			return $user;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The core sign-in form has no nonce; the code step that follows carries the challenge token.
		$remember = ! empty( $_POST['rememberme'] );
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:enable

		$token = ( new LoginCodeService( $this->app ) )->start( $user, $remember, $redirect, 'wp-login' );

		if ( $token instanceof WP_Error ) {
			return $token;
		}

		$this->render( $token, null );
		exit;
	}

	/**
	 * The code screen (wp-login.php?action=dx_code): check the code, or send a new one.
	 */
	public function code_screen(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The challenge token is the proof; the code is checked against it.
		$token = isset( $_POST['dx_challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['dx_challenge'] ) ) : '';
		$code  = isset( $_POST['dx_code'] ) ? sanitize_text_field( wp_unslash( $_POST['dx_code'] ) ) : '';
		$again = isset( $_POST['dx_resend'] );
		// phpcs:enable

		$codes = new LoginCodeService( $this->app );

		if ( $again ) {
			$sent = $codes->resend( $token );
			$this->render( $token, true === $sent ? null : $sent, true === $sent );
			exit;
		}

		$result = $codes->verify( $token, $code );

		if ( $result instanceof WP_Error ) {
			if ( 'dx_code_wrong' === $result->get_error_code() ) {
				$this->render( $token, $result );
				exit;
			}

			wp_safe_redirect( add_query_arg( 'dx_code', 'expired', wp_login_url() ) );
			exit;
		}

		if ( 'wp-login' !== $result['via'] ) {
			// A challenge finishes where it started.
			wp_safe_redirect( add_query_arg( 'dx_code', 'expired', wp_login_url() ) );
			exit;
		}

		$user = $result['user'];
		wp_set_auth_cookie( $user->ID, $result['remember'], is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired so security plugins see the sign-in.

		$requested = $result['redirect'];
		$target    = apply_filters( 'login_redirect', '' === $requested ? admin_url() : $requested, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, so the usual landing page applies.

		wp_safe_redirect( is_string( $target ) && '' !== $target ? $target : admin_url() );
		exit;
	}

	/**
	 * The code form, in the sign-in screen's own frame.
	 *
	 * @param string        $token Challenge token.
	 * @param WP_Error|null $error What went wrong.
	 * @param bool          $sent  A new code was just sent.
	 */
	private function render( string $token, ?WP_Error $error, bool $sent = false ): void {
		$email = ( new LoginCodeService( $this->app ) )->masked_email( $token );

		if ( ! function_exists( 'login_header' ) ) {
			wp_die( esc_html__( 'פג תוקף הכניסה. נכנסים מחדש.', 'diromax-portal' ) );
		}

		$message = $sent || null === $error
			/* translators: %s: masked email address. */
			? '<p class="message">' . esc_html( sprintf( __( 'שלחנו קוד בן 6 ספרות אל %s. הוא תקף ל-10 דקות.', 'diromax-portal' ), $email ) ) . '</p>'
			: '';

		login_header( __( 'קוד כניסה', 'diromax-portal' ), $message, $error );
		?>
		<form name="dx_code_form" id="dx-code-form" action="<?php echo esc_url( add_query_arg( 'action', self::ACTION, wp_login_url() ) ); ?>" method="post">
			<input type="hidden" name="dx_challenge" value="<?php echo esc_attr( $token ); ?>">
			<p>
				<label for="dx-code"><?php esc_html_e( 'הקוד מהמייל', 'diromax-portal' ); ?></label>
				<input type="text" name="dx_code" id="dx-code" class="input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" dir="ltr" required autofocus<?php echo null !== $error ? ' aria-invalid="true" aria-describedby="login_error"' : ''; ?>>
			</p>
			<p class="submit">
				<input type="submit" id="dx-code-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'כניסה', 'diromax-portal' ); ?>">
			</p>
		</form>
		<form id="dx-code-resend" action="<?php echo esc_url( add_query_arg( 'action', self::ACTION, wp_login_url() ) ); ?>" method="post" style="margin-top:16px">
			<input type="hidden" name="dx_challenge" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="dx_resend" value="1">
			<p><button type="submit" class="button-link"><?php esc_html_e( 'לא הגיע? שליחת קוד חדש', 'diromax-portal' ); ?></button></p>
		</form>
		<?php
		login_footer( 'dx-code' );
	}
}
