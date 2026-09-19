<?php
/**
 * Reversible encryption for secrets stored in options (SMTP/API passwords).
 *
 * Keyed from the site's auth salts. Rotating the salts invalidates stored
 * secrets (they must be re-entered), which is the expected trade-off. A
 * constant defined in wp-config.php should always be preferred over storage.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Secrets
 */
final class Secrets {

	private const METHOD = 'aes-256-cbc';
	private const PREFIX = 'ibgenc1:';

	/**
	 * Whether encryption is available on this server.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( self::METHOD, openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypt for storage.
	 *
	 * @param string $plain Plain text.
	 * @return string Ciphertext with prefix, or the plain text when encryption is unavailable.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::is_available() ) {
			return $plain;
		}

		$iv     = random_bytes( openssl_cipher_iv_length( self::METHOD ) );
		$cipher = openssl_encrypt( $plain, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plain;
		}

		$mac = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );

		return self::PREFIX . base64_encode( $iv . $mac . $cipher );
	}

	/**
	 * Decrypt a stored value (returns non-encrypted values unchanged).
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored;
		}
		if ( ! self::is_available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::METHOD );
		if ( strlen( $raw ) < $iv_length + 32 ) {
			return '';
		}

		$iv     = substr( $raw, 0, $iv_length );
		$mac    = substr( $raw, $iv_length, 32 );
		$cipher = substr( $raw, $iv_length + 32 );

		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, self::key(), true ), $mac ) ) {
			return '';
		}

		$plain = openssl_decrypt( $cipher, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a stored value is encrypted.
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_encrypted( string $stored ): bool {
		return str_starts_with( $stored, self::PREFIX );
	}

	/**
	 * 32-byte key derived from the site salts.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|ibg-outreach', true );
	}
}
