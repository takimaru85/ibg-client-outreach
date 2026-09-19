<?php
/**
 * Default provider: delivers through wp_mail().
 *
 * If an SMTP plugin has hooked wp_mail / phpmailer_init, mail is routed
 * through it automatically. Suitable for low volumes only.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email\Providers;

use IBG\Outreach\Email\Email_Message;
use IBG\Outreach\Email\Send_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_Mail_Provider
 */
final class WP_Mail_Provider implements Email_Provider {

	public const ID = 'wp_mail';

	/**
	 * Error captured from the wp_mail_failed action during the current send.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $last_error = null;

	/**
	 * Plain-text alternative body for the message currently being sent.
	 *
	 * @var string
	 */
	private string $pending_alt_body = '';

	/** @inheritDoc */
	public function get_id(): string {
		return self::ID;
	}

	/** @inheritDoc */
	public function get_name(): string {
		return __( 'WordPress default (wp_mail)', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_description(): string {
		return __( 'Sends through the wp_mail() function. If an SMTP plugin is active it will be used automatically. Suitable for low volumes; deliverability depends entirely on your host.', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function is_configured(): bool {
		return true;
	}

	/** @inheritDoc */
	public function get_settings_fields(): array {
		return array();
	}

	/** @inheritDoc */
	public function supports( string $feature ): bool {
		return false;
	}

	/** @inheritDoc */
	public function send( Email_Message $message ): Send_Result {
		$headers = array();

		if ( $message->is_html() ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$body      = $message->get_html_body();
		} else {
			$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			$body      = $message->get_text_body();
		}

		if ( '' !== $message->get_from_email() ) {
			$headers[] = 'From: ' . $message->get_formatted_from();
		}

		if ( '' !== $message->get_reply_to() ) {
			$headers[] = 'Reply-To: ' . Email_Message::format_address( $message->get_reply_to() );
		}

		foreach ( $message->get_headers() as $name => $value ) {
			$headers[] = self::sanitize_header_name( (string) $name ) . ': ' . self::sanitize_header_value( (string) $value );
		}

		$this->last_error       = null;
		$this->pending_alt_body = $message->is_html() ? $message->get_text_body() : '';

		add_action( 'wp_mail_failed', array( $this, 'capture_error' ) );
		add_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );

		try {
			$sent = wp_mail( $message->get_formatted_to(), $message->get_subject(), $body, $headers );
		} catch ( \Throwable $e ) {
			$sent             = false;
			$this->last_error = new \WP_Error( 'wp_mail_exception', $e->getMessage() );
		} finally {
			remove_action( 'wp_mail_failed', array( $this, 'capture_error' ) );
			remove_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );
			$this->pending_alt_body = '';
		}

		if ( $sent ) {
			return Send_Result::success();
		}

		$error = $this->last_error instanceof \WP_Error
			? $this->last_error->get_error_message()
			: __( 'wp_mail() returned false without an error message.', 'ibg-client-outreach' );

		return Send_Result::failure( $error );
	}

	/**
	 * Store the error raised by wp_mail().
	 *
	 * @param \WP_Error $error Error.
	 * @return void
	 */
	public function capture_error( \WP_Error $error ): void {
		$this->last_error = $error;
	}

	/**
	 * Attach the plain-text alternative so HTML mail is sent as multipart/alternative.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return void
	 */
	public function set_alt_body( $phpmailer ): void {
		if ( '' !== $this->pending_alt_body && is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
			$phpmailer->AltBody = $this->pending_alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Restrict a header name to token characters (RFC 7230).
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	private static function sanitize_header_name( string $name ): string {
		return (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $name );
	}

	/**
	 * Strip CR/LF from a header value to prevent header injection.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	private static function sanitize_header_value( string $value ): string {
		return trim( str_replace( array( "\r", "\n" ), '', $value ) );
	}
}
