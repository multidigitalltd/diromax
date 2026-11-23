<?php
/**
 * Integration settings: Postmark and Turnstile.
 *
 * @package Diromax\Portal
 */

declare( strict_types = 1 );

namespace Diromax\Portal\Support;

use Diromax\Portal\Plugin;
use Diromax\Portal\Service\Errors;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Postmark and Turnstile are set up from the plugin's settings screen
 * (spec 17.5, exception 6 in EXCEPTIONS.md).
 *
 * Secrets (the Postmark token and the Turnstile secret key) are sealed with
 * the site's encryption key (DX_PORTAL_KEY, which stays in wp-config.php)
 * before they reach the database, bound to their field. They are never
 * shown again, only "set". The webhook key is made by the plugin, shown
 * once, and only its SHA-256 is kept.
 *
 * A constant in wp-config.php still wins: its field is locked on the screen.
 * The encryption key, the documents folder and the emergency switch for the
 * sign-in code are never stored here.
 */
final class Integrations {

	/** Option holding the settings. */
	public const OPTION = 'dx_portal_integrations';

	/** Setting => [wp-config constant, is a secret]. */
	public const FIELDS = array(
		'token'            => array( 'DX_POSTMARK_TOKEN', true ),
		'from'             => array( 'DX_POSTMARK_FROM', false ),
		'from_name'        => array( 'DX_POSTMARK_FROM_NAME', false ),
		'stream'           => array( 'DX_POSTMARK_STREAM', false ),
		'inbound'          => array( 'DX_POSTMARK_INBOUND', false ),
		'turnstile_site'   => array( 'DX_TURNSTILE_SITE_KEY', false ),
		'turnstile_secret' => array( 'DX_TURNSTILE_SECRET_KEY', true ),
	);

	/** The webhook key's constant. */
	private const WEBHOOK_CONSTANT = 'DX_POSTMARK_WEBHOOK_KEY';

	/** Longest sender name, in characters. */
	public const MAX_FROM_NAME = 60;

	/** Shortest webhook key accepted from wp-config.php. */
	public const MIN_WEBHOOK_KEY = 32;

	/**
	 * A setting's value: wp-config.php first, then the settings screen.
	 *
	 * @param string $name Setting (a key of FIELDS).
	 * @return string '' when not set.
	 */
	public static function get( string $name ): string {
		$constant = self::constant( $name );

		return '' !== $constant ? $constant : self::stored( $name );
	}

	/**
	 * Is the setting fixed in wp-config.php (and so locked on the screen)?
	 *
	 * @param string $name Setting.
	 * @return bool
	 */
	public static function locked( string $name ): bool {
		return '' !== self::constant( $name );
	}

	/**
	 * Is the setting set, wherever?
	 *
	 * @param string $name Setting.
	 * @return bool
	 */
	public static function is_set( string $name ): bool {
		return '' !== self::get( $name );
	}

	/**
	 * A secret saved on the screen that the current encryption key cannot
	 * open (the key was replaced): it must be entered again.
	 *
	 * @param string $name Setting.
	 * @return bool
	 */
	public static function unreadable( string $name ): bool {
		$raw = self::option()[ $name ] ?? '';

		return self::is_secret( $name ) && '' !== $raw && '' === self::stored( $name );
	}

	/**
	 * Save the screen's input. An empty secret field keeps what is saved;
	 * "{name}_clear" removes it. Locked fields are ignored.
	 *
	 * @param array<string, string> $input Sanitized input.
	 * @return string[]|WP_Error Names of the settings that changed.
	 */
	public static function save( array $input ): array|WP_Error {
		$errors  = array();
		$values  = self::option();
		$changed = array();

		foreach ( self::FIELDS as $name => [ , $secret ] ) {
			if ( self::locked( $name ) ) {
				continue;
			}

			$value = trim( (string) ( $input[ $name ] ?? '' ) );
			$clear = '1' === ( $input[ $name . '_clear' ] ?? '' );

			if ( $secret && '' === $value && ! $clear ) {
				continue;
			}

			$problem = '' === $value ? '' : self::problem( $name, $value );

			if ( '' !== $problem ) {
				$errors[ $name ] = $problem;
				continue;
			}

			$new = $value;

			if ( $secret && '' !== $value ) {
				$store = Plugin::instance()->store;

				if ( ! $store->is_configured() ) {
					$errors[ $name ] = 'no_key';
					continue;
				}

				$new = $store->seal( $value, self::context( $name ) );
			}

			if ( ( $values[ $name ] ?? '' ) !== $new && ! ( $secret && '' !== $value && self::stored( $name ) === $value ) ) {
				$changed[] = $name;
			}

			$values[ $name ] = $new;
		}

		if ( array() !== $errors ) {
			return Errors::invalid( $errors );
		}

		update_option( self::OPTION, $values, false );

		return $changed;
	}

	/**
	 * SHA-256 of the webhook key: from wp-config.php when set there, else the
	 * one the plugin made. '' when there is none.
	 *
	 * @return string
	 */
	public static function webhook_hash(): string {
		$constant = defined( self::WEBHOOK_CONSTANT ) ? trim( (string) constant( self::WEBHOOK_CONSTANT ) ) : '';
		$constant = (string) apply_filters( 'dx_portal_mail_config', $constant, 'webhook_key' );

		if ( '' !== $constant ) {
			return strlen( $constant ) >= self::MIN_WEBHOOK_KEY ? hash( 'sha256', $constant ) : '';
		}

		$hash = (string) ( self::option()['webhook_hash'] ?? '' );

		return 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : '';
	}

	/**
	 * Is the webhook key fixed in wp-config.php?
	 *
	 * @return bool
	 */
	public static function webhook_locked(): bool {
		return defined( self::WEBHOOK_CONSTANT ) && '' !== trim( (string) constant( self::WEBHOOK_CONSTANT ) );
	}

	/**
	 * Make a new webhook key, keep only its hash, and return it to be shown
	 * once. The old key stops working at once.
	 *
	 * @return string 43 URL-safe characters.
	 */
	public static function new_webhook_key(): string {
		$key                    = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes.
		$values                 = self::option();
		$values['webhook_hash'] = hash( 'sha256', $key );
		update_option( self::OPTION, $values, false );

		return $key;
	}

	/**
	 * Why a value cannot be saved, '' when it can.
	 *
	 * @param string $name  Setting.
	 * @param string $value Value.
	 * @return string
	 */
	private static function problem( string $name, string $value ): string {
		$valid = match ( $name ) {
			'from', 'inbound' => false !== is_email( $value ),
			'stream'          => 1 === preg_match( '/^[a-z0-9_-]{1,50}$/', $value ),
			// A display name only: nothing that could open an address or a second header.
			'from_name'       => 1 === preg_match( '/^[^<>"\\\\,;@\p{Cc}]{1,' . self::MAX_FROM_NAME . '}$/u', $value ),
			default           => 1 === preg_match( '/^[A-Za-z0-9._-]{10,200}$/', $value ),
		};

		return $valid ? '' : 'invalid';
	}

	/**
	 * The value saved on the screen, opened when it is a secret.
	 *
	 * @param string $name Setting.
	 * @return string
	 */
	private static function stored( string $name ): string {
		$raw = (string) ( self::option()[ $name ] ?? '' );

		if ( '' === $raw || ! self::is_secret( $name ) ) {
			return $raw;
		}

		return (string) Plugin::instance()->store->unseal( $raw, self::context( $name ) );
	}

	/**
	 * The wp-config.php constant of a setting, '' when not defined.
	 *
	 * @param string $name Setting.
	 * @return string
	 */
	private static function constant( string $name ): string {
		$constant = self::FIELDS[ $name ][0] ?? '';

		return '' !== $constant && defined( $constant ) ? trim( (string) constant( $constant ) ) : '';
	}

	/**
	 * Is the setting a secret?
	 *
	 * @param string $name Setting.
	 * @return bool
	 */
	private static function is_secret( string $name ): bool {
		return (bool) ( self::FIELDS[ $name ][1] ?? false );
	}

	/**
	 * Encryption context of a secret: a value sealed for one field does not
	 * open as another.
	 *
	 * @param string $name Setting.
	 * @return string
	 */
	private static function context( string $name ): string {
		return 'integration|' . $name;
	}

	/**
	 * The saved settings.
	 *
	 * @return array<string, string>
	 */
	private static function option(): array {
		$values = get_option( self::OPTION, array() );

		return is_array( $values ) ? array_map( 'strval', array_filter( $values, 'is_scalar' ) ) : array();
	}
}
