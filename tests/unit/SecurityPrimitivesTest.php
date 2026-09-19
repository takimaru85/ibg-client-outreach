<?php
/**
 * Secrets, unsubscribe tokens, tracking signatures, email address formatting.
 *
 * @package IBG\Outreach
 */

declare( strict_types=1 );

namespace IBG\Outreach\Tests\Unit;

use IBG\Outreach\Analytics\Tracking;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Database;
use IBG\Outreach\Email\Email_Message;
use IBG\Outreach\Email\Merge_Context;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Queue\Queue_Repository;
use IBG\Outreach\Secrets;
use IBG\Outreach\Settings;
use IBG\Outreach\Signer;
use IBG\Outreach\Unsubscribe\Unsubscribe_Token;
use PHPUnit\Framework\TestCase;

final class SecurityPrimitivesTest extends TestCase {

	public function test_secrets_roundtrip_and_tamper_detection(): void {
		$plain = 'p@ss w0rd ünïcode';
		$enc   = Secrets::encrypt( $plain );

		self::assertTrue( Secrets::is_encrypted( $enc ) );
		self::assertSame( $plain, Secrets::decrypt( $enc ) );
		self::assertNotSame( $enc, Secrets::encrypt( $plain ), 'random IV' );
		self::assertSame( '', Secrets::decrypt( substr( $enc, 0, -4 ) . 'AAAA' ), 'tampered ciphertext' );
		self::assertSame( 'legacy', Secrets::decrypt( 'legacy' ), 'plain values pass through' );
		self::assertSame( '', Secrets::encrypt( '' ) );
	}

	public function test_unsubscribe_token_is_bound_to_contact_and_email(): void {
		$token = new Unsubscribe_Token();
		$t     = $token->create( 42, 'bob@acme.test' );

		self::assertMatchesRegularExpression( '/^42\.[a-f0-9]{40}$/', $t );
		list( $id, $sig ) = $token->parse( $t );
		self::assertSame( 42, $id );
		self::assertTrue( $token->verify( 42, 'bob@acme.test' , $sig ) );
		self::assertTrue( $token->verify( 42, 'BOB@acme.test ', $sig ), 'case/whitespace normalised' );
		self::assertFalse( $token->verify( 42, 'eve@acme.test', $sig ) );
		self::assertFalse( $token->verify( 43, 'bob@acme.test', $sig ) );
		self::assertNull( $token->parse( 'garbage' ) );
		self::assertNull( $token->parse( '42.' . str_repeat( 'z', 40 ) ) );
	}

	public function test_tracking_instrumentation_and_signatures(): void {
		$settings         = new Settings();
		$settings->values = array( 'track_opens' => true, 'track_clicks' => true );
		$signer           = new Signer();
		$tracking         = new Tracking( $signer, $settings, new Event_Repository(), new Contact_Repository(), new Queue_Repository(), new Database() );

		$c        = new Contact();
		$c->id    = 9;
		$c->email = 'x@y.test';
		$html     = '<html><body><a href="https://acme.test/page?a=1&amp;b=2">site</a> <a href="https://example.test/?ibg_unsubscribe=9.abc">unsub</a> <a href="mailto:a@b.c">mail</a></body></html>';

		$out = $tracking->instrument_html( $html, new Merge_Context( $c, 3, 77, false ) );
		self::assertStringContainsString( 'ibg_track=click', $out );
		self::assertStringContainsString( 'href="https://example.test/?ibg_unsubscribe=9.abc"', $out, 'unsubscribe link untouched' );
		self::assertStringContainsString( 'href="mailto:a@b.c"', $out );
		self::assertMatchesRegularExpression( '#<img [^>]*ibg_track=open[^>]*/></body>#', $out );

		self::assertSame( $html, $tracking->instrument_html( $html, new Merge_Context( $c, 3, 77, true ) ), 'test emails are never tracked' );
		self::assertSame( $html, $tracking->instrument_html( $html, new Merge_Context( $c, 3, 0, false ) ), 'no queue id, no tracking' );

		$settings->values = array( 'track_opens' => false, 'track_clicks' => false );
		self::assertSame( $html, $tracking->instrument_html( $html, new Merge_Context( $c, 3, 77, false ) ), 'disabled = untouched' );

		$url = 'https://acme.test/page?a=1&b=2';
		parse_str( (string) parse_url( $tracking->click_url( 77, 9, $url ), PHP_URL_QUERY ), $q );
		self::assertSame( $url, $q['u'] );
		self::assertTrue( $signer->verify( 'click|77|9', explode( '.', $q['t'] )[2] ) );
		self::assertTrue( $signer->verify( "url|77|9|{$url}", $q['s'] ) );
		self::assertFalse( $signer->verify( 'url|77|9|https://evil.test/', $q['s'] ), 'destination cannot be swapped' );
	}

	public function test_email_address_formatting_prevents_header_injection(): void {
		self::assertSame( '"Bob Ray" <bob@acme.test>', Email_Message::format_address( 'bob@acme.test', 'Bob Ray' ) );
		self::assertSame( 'bob@acme.test', Email_Message::format_address( 'bob@acme.test' ) );
		self::assertSame( '"BobBcc: eve@evil.test" <bob@acme.test>', Email_Message::format_address( 'bob@acme.test', "Bob\r\nBcc: eve@evil.test" ) );
		self::assertSame( '"Bob" <bob@acme.test>', Email_Message::format_address( 'bob@acme.test', '<Bob>' ) );
	}

	public function test_message_with_header_is_immutable(): void {
		$m1 = new Email_Message( 'a@b.test', 'A', 'S', '<p>x</p>', 'x' );
		$m2 = $m1->with_header( 'X-Test', "1\r\n" );
		self::assertSame( array(), $m1->get_headers() );
		self::assertSame( array( 'X-Test' => "1\r\n" ), $m2->get_headers(), 'sanitised by the provider, not the value object' );
		self::assertTrue( $m2->is_html() );
	}
}
