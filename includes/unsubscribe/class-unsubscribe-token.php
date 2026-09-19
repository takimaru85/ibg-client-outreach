<?php
/**
 * Signed unsubscribe tokens and URLs.
 *
 * Token format: "{contact_id}.{hmac}". The HMAC covers the contact id and
 * the normalised email, so a link stops working if the address changes.
 * The public endpoint that consumes the token is registered in the
 * Unsubscribe module (Phase 8).
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Unsubscribe;

use IBG\Outreach\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Unsubscribe_Token
 */
final class Unsubscribe_Token {

	public const QUERY_VAR  = 'ibg_unsubscribe';
	public const TEST_TOKEN = 'test';

	/**
	 * Signing secret (lazy-loaded).
	 *
	 * @var string
	 */
	private string $secret = '';

	/**
	 * Build a token for a contact.
	 *
	 * @param int    $contact_id Contact id.
	 * @param string $email      Normalised email.
	 * @return string
	 */
	public function create( int $contact_id, string $email ): string {
		return $contact_id . '.' . $this->signature( $contact_id, $email );
	}

	/**
	 * Parse a token into its contact id (signature not yet verified).
	 *
	 * @param string $token Token.
	 * @return array{0:int,1:string}|null [contact_id, signature] or null when malformed.
	 */
	public function parse( string $token ): ?array {
		if ( ! preg_match( '/^(\d{1,20})\.([a-f0-9]{40})$/', $token, $m ) ) {
			return null;
		}
		return array( (int) $m[1], $m[2] );
	}

	/**
	 * Verify a signature against a contact id and email.
	 *
	 * @param int    $contact_id Contact id.
	 * @param string $email      Normalised email of that contact (from the DB, not the request).
	 * @param string $signature  Signature from the token.
	 * @return bool
	 */
	public function verify( int $contact_id, string $email, string $signature ): bool {
		return hash_equals( $this->signature( $contact_id, $email ), $signature );
	}

	/**
	 * Public unsubscribe URL for a contact.
	 *
	 * @param int                  $contact_id Contact id.
	 * @param string               $email      Normalised email.
	 * @param array<string, mixed> $args       Extra query args (e.g. campaign id).
	 * @return string
	 */
	public function get_url( int $contact_id, string $email, array $args = array() ): string {
		$args = array_merge( array( self::QUERY_VAR => $this->create( $contact_id, $email ) ), $args );
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * URL used in test/preview emails; the endpoint explains it is a test.
	 *
	 * @return string
	 */
	public function get_test_url(): string {
		return add_query_arg( self::QUERY_VAR, self::TEST_TOKEN, home_url( '/' ) );
	}

	/**
	 * HMAC for a contact id + email.
	 *
	 * @param int    $contact_id Contact id.
	 * @param string $email      Email.
	 * @return string 40 hex chars.
	 */
	private function signature( int $contact_id, string $email ): string {
		return substr( hash_hmac( 'sha256', $contact_id . '|' . strtolower( trim( $email ) ) . '|unsubscribe', $this->secret() ), 0, 40 );
	}

	/**
	 * Plugin secret, created on demand if activation did not run.
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
