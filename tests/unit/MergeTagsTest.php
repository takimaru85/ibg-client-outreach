<?php
/**
 * Merge tag rendering and escaping.
 *
 * @package IBG\Outreach
 */

declare( strict_types=1 );

namespace IBG\Outreach\Tests\Unit;

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Email\Email_Composer;
use IBG\Outreach\Email\Html_To_Text;
use IBG\Outreach\Email\Merge_Context;
use IBG\Outreach\Email\Merge_Tags;
use IBG\Outreach\Settings;
use IBG\Outreach\Unsubscribe\Unsubscribe_Token;
use PHPUnit\Framework\TestCase;

final class MergeTagsTest extends TestCase {

	private Merge_Tags $tags;
	private Settings $settings;
	private Merge_Context $ctx;

	protected function setUp(): void {
		$this->settings         = new Settings();
		$this->settings->values = array(
			'business_name'     => 'IB Golden',
			'business_address'  => "1 Main St\nTown",
			'unsubscribe_text'  => 'No more?',
			'from_name'         => 'Ian',
			'from_email'        => 'ian@ibgolden.test',
			'email_footer'      => "{{business_name}}\n{{business_address}}",
			'consent_statement' => 'Business contact.',
		);
		$this->tags = new Merge_Tags( $this->settings, new Unsubscribe_Token() );

		$c             = new Contact();
		$c->id         = 42;
		$c->email      = 'bob@acme.test';
		$c->first_name = '';
		$c->company    = 'Acme <b>Dental</b>';
		$c->website    = 'https://www.acme.test/x';
		$this->ctx     = new Merge_Context( $c, 7 );
	}

	public function test_html_escapes_contact_values(): void {
		self::assertSame( 'Acme &lt;b&gt;Dental&lt;/b&gt;', $this->tags->render( '{{company}}', $this->ctx ) );
	}

	public function test_fallback_used_when_value_empty(): void {
		self::assertSame( 'Hi there', $this->tags->render( 'Hi {{first_name|there}}', $this->ctx ) );
		self::assertSame( 'Hi ', $this->tags->render( 'Hi {{first_name}}', $this->ctx ) );
	}

	public function test_text_format_is_raw(): void {
		self::assertSame( 'Acme <b>Dental</b>', $this->tags->render( '{{company}}', $this->ctx, Merge_Tags::FORMAT_TEXT ) );
	}

	public function test_multiline_business_address_html(): void {
		self::assertSame( "1 Main St<br />\nTown", $this->tags->render( '{{business_address}}', $this->ctx ) );
	}

	public function test_website_domain(): void {
		self::assertSame( 'acme.test', $this->tags->render( '{{website_domain}}', $this->ctx ) );
	}

	public function test_unknown_tags_are_removed_and_reported(): void {
		self::assertSame( 'x  y', $this->tags->render( 'x {{bogus}} y', $this->ctx ) );
		self::assertSame( array( 'nope', 'bogus' ), $this->tags->find_unknown( '{{first_name}} {{nope}} {{Bogus}}' ) );
	}

	public function test_tinymce_encoded_braces_in_href_are_decoded(): void {
		$out = $this->tags->render( '<a href="%7B%7Bunsubscribe_url%7D%7D">x</a>', $this->ctx );
		self::assertStringContainsString( 'ibg_unsubscribe=42.', $out );
	}

	public function test_unsubscribe_link_html_and_text(): void {
		$html = $this->tags->render( '{{unsubscribe_link}}', $this->ctx );
		self::assertMatchesRegularExpression( '#^<a href="https://example\.test/\?ibg_unsubscribe=42\.[a-f0-9]{40}&amp;c=7">Unsubscribe</a>$#', $html );
		$text = $this->tags->render( '{{unsubscribe_link}}', $this->ctx, Merge_Tags::FORMAT_TEXT );
		self::assertStringStartsWith( 'https://example.test/?ibg_unsubscribe=42.', $text );
	}

	public function test_sample_context_uses_test_url(): void {
		self::assertSame( 'https://example.test/?ibg_unsubscribe=test', $this->tags->render( '{{unsubscribe_url}}', Merge_Context::sample(), Merge_Tags::FORMAT_TEXT ) );
	}

	public function test_composer_guarantees_footer_and_headers(): void {
		$composer = new Email_Composer( $this->tags, $this->settings );
		$message  = $composer->compose(
			array(
				'to_email'  => 'bob@acme.test',
				'subject'   => 'Hi {{company}}',
				'body_html' => '<p>Hello {{first_name|there}}</p>',
				'context'   => $this->ctx,
			)
		);

		self::assertSame( 'Hi Acme <b>Dental</b>', $message->get_subject() );
		self::assertStringContainsString( 'ibg_unsubscribe=42.', $message->get_html_body() );
		self::assertStringContainsString( '<!DOCTYPE html>', $message->get_html_body() );
		self::assertStringContainsString( "Hello there\n\n--\nIB Golden", $message->get_text_body() );
		self::assertArrayHasKey( 'List-Unsubscribe', $message->get_headers() );
		self::assertSame( 'List-Unsubscribe=One-Click', $message->get_headers()['List-Unsubscribe-Post'] );
		self::assertSame( 'ian@ibgolden.test', $message->get_from_email() );
	}

	public function test_composer_test_mode_prefixes_and_omits_list_headers(): void {
		$composer = new Email_Composer( $this->tags, $this->settings );
		$message  = $composer->compose( array( 'to_email' => 'me@x.test', 'subject' => 'S', 'body_html' => '<p>b</p>', 'context' => $this->ctx, 'is_test' => true ) );
		self::assertSame( '[TEST] S', $message->get_subject() );
		self::assertArrayNotHasKey( 'List-Unsubscribe', $message->get_headers() );
		self::assertSame( 'test', $message->get_headers()['X-IBG-Outreach'] );
	}

	public function test_html_to_text(): void {
		$text = Html_To_Text::convert( '<html><head><style>p{}</style></head><body><h1>Hello</h1><p>Visit <a href="https://x.test">our site</a> or <a href="https://y.test">https://y.test</a>.</p><ul><li>One</li><li>Two &amp; three</li></ul><p>Bye<br>now</p></body></html>' );
		self::assertSame( "Hello\n\nVisit our site (https://x.test) or https://y.test.\n\n- One\n- Two & three\n\nBye\nnow", $text );
	}
}
