<?php
/**
 * HMAC signing for public URLs (tracking, and anything else that must be
 * tamper-proof without a session).
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Signer
 */
final class Signer {

	/**
	 * Cached secret.
	 *
	 * @var string
	 */
	private string $secret = '';

	/**
	 * Sign a payload.
	 *
	 * @param string $payload Payload.
	 * @return string 40 hex chars.
	 */
	public function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', $payload, $this->secret() ), 0, 40 );
	}

	/**
	 * Verify a signature.
	 *
	 * @param string $payload   Payload.
	 * @param string $signature Signature.
	 * @return bool
	 */
	public function verify( string $payload, string $signature ): bool {
		return 40 === strlen( $signature ) && hash_equals( $this->sign( $payload ), $signature );
	}

	/**
	 * Plugin secret (created on demand).
	 *
	 * @return string
	 */
	private function secret(): string {
		if ( '' === $this->secret ) {
			$secret = (string) get_option( Activator::OPTION_SECRET, '' );
			if ( '' === $secret ) {
				$secret = bin2hex( random_bytes( 32 ) );
				add_option( Activator::OPTION_SECRET, $secret, '', 'no' );
			}
			$this->secret = $secret;
		}
		return $this->secret;
	}
}
