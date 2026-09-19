<?php
/**
 * Immutable email message value object passed to providers.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Class Email_Message
 */
final class Email_Message {

	/**
	 * Constructor.
	 *
	 * @param string                $to_email   Recipient address.
	 * @param string                $to_name    Recipient display name.
	 * @param string                $subject    Subject line.
	 * @param string                $html_body  HTML body (may be empty for plain-text mail).
	 * @param string                $text_body  Plain-text body / alternative.
	 * @param string                $from_email Sender address.
	 * @param string                $from_name  Sender display name.
	 * @param string                $reply_to   Reply-To address.
	 * @param array<string, string> $headers    Additional headers, name => value.
	 * @param array<string, mixed>  $context    Opaque context (campaign_id, contact_id, queue_id) for logging.
	 */
	public function __construct(
		private readonly string $to_email,
		private readonly string $to_name = '',
		private readonly string $subject = '',
		private readonly string $html_body = '',
		private readonly string $text_body = '',
		private readonly string $from_email = '',
		private readonly string $from_name = '',
		private readonly string $reply_to = '',
		private readonly array $headers = array(),
		private readonly array $context = array()
	) {}

	/** @return string */
	public function get_to_email(): string {
		return $this->to_email;
	}

	/** @return string */
	public function get_to_name(): string {
		return $this->to_name;
	}

	/** @return string */
	public function get_subject(): string {
		return $this->subject;
	}

	/** @return string */
	public function get_html_body(): string {
		return $this->html_body;
	}

	/** @return string */
	public function get_text_body(): string {
		return $this->text_body;
	}

	/** @return string */
	public function get_from_email(): string {
		return $this->from_email;
	}

	/** @return string */
	public function get_from_name(): string {
		return $this->from_name;
	}

	/** @return string */
	public function get_reply_to(): string {
		return $this->reply_to;
	}

	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}

	/** @return array<string, mixed> */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * Whether the message has an HTML part.
	 *
	 * @return bool
	 */
	public function is_html(): bool {
		return '' !== trim( $this->html_body );
	}

	/**
	 * Recipient formatted as "Name <email>" (RFC 5322).
	 *
	 * @return string
	 */
	public function get_formatted_to(): string {
		return self::format_address( $this->to_email, $this->to_name );
	}

	/**
	 * Sender formatted as "Name <email>".
	 *
	 * @return string
	 */
	public function get_formatted_from(): string {
		return self::format_address( $this->from_email, $this->from_name );
	}

	/**
	 * Return a copy with an extra header.
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return self
	 */
	public function with_header( string $name, string $value ): self {
		$headers          = $this->headers;
		$headers[ $name ] = $value;

		return new self(
			$this->to_email,
			$this->to_name,
			$this->subject,
			$this->html_body,
			$this->text_body,
			$this->from_email,
			$this->from_name,
			$this->reply_to,
			$headers,
			$this->context
		);
	}

	/**
	 * Format an address with an optional display name, stripping characters
	 * that could be used for header injection.
	 *
	 * @param string $email Address.
	 * @param string $name  Display name.
	 * @return string
	 */
	public static function format_address( string $email, string $name = '' ): string {
		$email = sanitize_email( $email );
		$name  = trim( str_replace( array( "\r", "\n", '<', '>', '"' ), '', $name ) );

		if ( '' === $name ) {
			return $email;
		}

		return sprintf( '"%s" <%s>', $name, $email );
	}
}
