<?php
/**
 * Merge tag registry and renderer.
 *
 * Syntax: {{tag}} or {{tag|fallback text}}. Values are escaped according to
 * the output format so a contact's company name can never inject HTML.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

use IBG\Outreach\Settings;
use IBG\Outreach\Unsubscribe\Unsubscribe_Token;

defined( 'ABSPATH' ) || exit;

/**
 * Class Merge_Tags
 */
final class Merge_Tags {

	public const FORMAT_HTML    = 'html';
	public const FORMAT_TEXT    = 'text';
	public const FORMAT_SUBJECT = 'subject';

	public const TYPE_TEXT      = 'text';
	public const TYPE_MULTILINE = 'multiline';
	public const TYPE_URL       = 'url';
	public const TYPE_HTML      = 'html';

	/**
	 * Matches {{tag}} and {{tag|fallback}}.
	 */
	private const PATTERN = '/\{\{\s*([a-z0-9_]+)\s*(?:\|\s*([^}]*?)\s*)?\}\}/i';

	/**
	 * Registered tags.
	 *
	 * @var array<string, array{label:string, group:string, type:string, resolve:callable}>
	 */
	private array $tags = array();

	/**
	 * Whether core tags and extension tags have been registered.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Unsubscribe_Token $token    Unsubscribe token/URL builder.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Unsubscribe_Token $token
	) {}

	/**
	 * Register a tag.
	 *
	 * @param string   $tag     Tag name (lowercase, underscores).
	 * @param string   $label   Label for the editor legend.
	 * @param callable $resolve fn( Merge_Context $ctx, string $format ): string.
	 * @param string   $type    One of the TYPE_* constants (controls escaping).
	 * @param string   $group   Group for the legend: contact|business|links|other.
	 * @return void
	 */
	public function register( string $tag, string $label, callable $resolve, string $type = self::TYPE_TEXT, string $group = 'other' ): void {
		$tag = strtolower( preg_replace( '/[^a-z0-9_]/i', '', $tag ) );
		if ( '' === $tag ) {
			return;
		}
		$this->tags[ $tag ] = array(
			'label'   => $label,
			'group'   => $group,
			'type'    => $type,
			'resolve' => $resolve,
		);
	}

	/**
	 * All tags, grouped: group => tag => label.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_groups(): array {
		$this->load();
		$groups = array();
		foreach ( $this->tags as $tag => $def ) {
			$groups[ $def['group'] ][ $tag ] = $def['label'];
		}
		return $groups;
	}

	/**
	 * Whether a tag exists.
	 *
	 * @param string $tag Tag.
	 * @return bool
	 */
	public function has( string $tag ): bool {
		$this->load();
		return isset( $this->tags[ strtolower( $tag ) ] );
	}

	/**
	 * Tags used in content that are not registered.
	 *
	 * @param string $content Content.
	 * @return string[]
	 */
	public function find_unknown( string $content ): array {
		$this->load();
		$unknown = array();
		if ( preg_match_all( self::PATTERN, self::decode_encoded_tags( $content ), $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tag = strtolower( $tag );
				if ( ! isset( $this->tags[ $tag ] ) ) {
					$unknown[ $tag ] = $tag;
				}
			}
		}
		return array_values( $unknown );
	}

	/**
	 * Whether content contains any of the given tags.
	 *
	 * @param string   $content Content.
	 * @param string[] $tags    Tag names.
	 * @return bool
	 */
	public function contains_any( string $content, array $tags ): bool {
		if ( ! preg_match_all( self::PATTERN, self::decode_encoded_tags( $content ), $matches ) ) {
			return false;
		}
		$used = array_map( 'strtolower', $matches[1] );
		return ! empty( array_intersect( $used, array_map( 'strtolower', $tags ) ) );
	}

	/**
	 * Replace tags in content.
	 *
	 * @param string        $content Content with tags.
	 * @param Merge_Context $ctx     Context.
	 * @param string        $format  html|text|subject.
	 * @return string
	 */
	public function render( string $content, Merge_Context $ctx, string $format = self::FORMAT_HTML ): string {
		$this->load();

		$content = self::decode_encoded_tags( $content );

		return (string) preg_replace_callback(
			self::PATTERN,
			function ( array $m ) use ( $ctx, $format ): string {
				$tag      = strtolower( $m[1] );
				$fallback = isset( $m[2] ) ? trim( $m[2] ) : '';

				if ( ! isset( $this->tags[ $tag ] ) ) {
					return '';
				}

				$def   = $this->tags[ $tag ];
				$value = (string) ( $def['resolve'] )( $ctx, $format );

				if ( '' === trim( $value ) ) {
					return self::escape( $fallback, self::TYPE_TEXT, $format );
				}

				return self::escape( $value, $def['type'], $format );
			},
			$content
		);
	}

	/**
	 * Escape a resolved value for the output format.
	 *
	 * @param string $value  Value.
	 * @param string $type   Tag type.
	 * @param string $format Output format.
	 * @return string
	 */
	private static function escape( string $value, string $type, string $format ): string {
		if ( self::FORMAT_HTML !== $format ) {
			// Plain text and subject: strip any markup a resolver may have produced.
			return self::TYPE_HTML === $type ? wp_strip_all_tags( $value ) : str_replace( array( "\r", "\n" ), self::FORMAT_SUBJECT === $format ? ' ' : "\n", $value );
		}

		switch ( $type ) {
			case self::TYPE_HTML:
				return $value; // Resolver is responsible for escaping.
			case self::TYPE_URL:
				return esc_url( $value );
			case self::TYPE_MULTILINE:
				return nl2br( esc_html( $value ) );
			default:
				return esc_html( $value );
		}
	}

	/**
	 * TinyMCE URL-encodes braces inside href attributes; undo that so tags in
	 * links keep working.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private static function decode_encoded_tags( string $content ): string {
		return (string) preg_replace_callback(
			'/%7B%7B([^%]*?)%7D%7D/i',
			static fn( array $m ): string => '{{' . str_ireplace( '%7C', '|', $m[1] ) . '}}',
			$content
		);
	}

	/**
	 * Register built-in tags and let extensions add theirs.
	 *
	 * @return void
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		$contact_fields = array(
			'first_name' => __( 'First name', 'ibg-client-outreach' ),
			'last_name'  => __( 'Last name', 'ibg-client-outreach' ),
			'full_name'  => __( 'Full name', 'ibg-client-outreach' ),
			'company'    => __( 'Company', 'ibg-client-outreach' ),
			'email'      => __( 'Email', 'ibg-client-outreach' ),
			'phone'      => __( 'Phone', 'ibg-client-outreach' ),
			'country'    => __( 'Country', 'ibg-client-outreach' ),
			'industry'   => __( 'Industry', 'ibg-client-outreach' ),
		);
		foreach ( $contact_fields as $field => $label ) {
			$this->register(
				$field,
				$label,
				static fn( Merge_Context $ctx ): string => $ctx->contact ? (string) $ctx->contact->{$field} : '',
				self::TYPE_TEXT,
				'contact'
			);
		}
		$this->register(
			'website',
			__( 'Website', 'ibg-client-outreach' ),
			static fn( Merge_Context $ctx ): string => $ctx->contact ? (string) $ctx->contact->website : '',
			self::TYPE_URL,
			'contact'
		);
		$this->register(
			'website_domain',
			__( 'Website (domain only)', 'ibg-client-outreach' ),
			static function ( Merge_Context $ctx ): string {
				$host = $ctx->contact ? (string) wp_parse_url( (string) $ctx->contact->website, PHP_URL_HOST ) : '';
				return (string) preg_replace( '/^www\./i', '', $host );
			},
			self::TYPE_TEXT,
			'contact'
		);

		$business = array(
			'business_name'     => array( __( 'Business name', 'ibg-client-outreach' ), self::TYPE_TEXT ),
			'business_website'  => array( __( 'Business website', 'ibg-client-outreach' ), self::TYPE_URL ),
			'business_address'  => array( __( 'Business address', 'ibg-client-outreach' ), self::TYPE_MULTILINE ),
			'consent_statement' => array( __( 'Why you are receiving this', 'ibg-client-outreach' ), self::TYPE_MULTILINE ),
			'unsubscribe_text'  => array( __( 'Unsubscribe text', 'ibg-client-outreach' ), self::TYPE_TEXT ),
			'privacy_policy_url' => array( __( 'Privacy policy URL', 'ibg-client-outreach' ), self::TYPE_URL ),
			'from_name'         => array( __( 'Sender name', 'ibg-client-outreach' ), self::TYPE_TEXT ),
		);
		foreach ( $business as $key => list( $label, $type ) ) {
			$this->register(
				$key,
				$label,
				fn(): string => (string) $this->settings->get( $key, '' ),
				$type,
				'business'
			);
		}

		$this->register(
			'unsubscribe_url',
			__( 'Unsubscribe URL', 'ibg-client-outreach' ),
			fn( Merge_Context $ctx ): string => $this->unsubscribe_url( $ctx ),
			self::TYPE_URL,
			'links'
		);
		$this->register(
			'unsubscribe_link',
			__( 'Unsubscribe link', 'ibg-client-outreach' ),
			function ( Merge_Context $ctx, string $format ): string {
				$url = $this->unsubscribe_url( $ctx );
				if ( self::FORMAT_HTML !== $format ) {
					return $url;
				}
				return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Unsubscribe', 'ibg-client-outreach' ) );
			},
			self::TYPE_HTML,
			'links'
		);

		$this->register(
			'today',
			__( "Today's date", 'ibg-client-outreach' ),
			static fn(): string => (string) wp_date( (string) get_option( 'date_format' ) ),
			self::TYPE_TEXT,
			'other'
		);

		/**
		 * Register additional merge tags.
		 *
		 * @param Merge_Tags $tags Registry.
		 */
		do_action( 'ibg_outreach_register_merge_tags', $this );
	}

	/**
	 * Unsubscribe URL for a context.
	 *
	 * @param Merge_Context $ctx Context.
	 * @return string
	 */
	private function unsubscribe_url( Merge_Context $ctx ): string {
		if ( ! $ctx->has_real_contact() ) {
			return $this->token->get_test_url();
		}
		$args = $ctx->campaign_id > 0 ? array( 'c' => $ctx->campaign_id ) : array();
		return $this->token->get_url( $ctx->contact->id, $ctx->contact->email, $args );
	}
}
