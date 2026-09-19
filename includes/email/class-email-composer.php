<?php
/**
 * Composes a complete, compliant Email_Message from template content.
 *
 * Responsibilities: merge-tag rendering, mandatory footer with sender
 * identification and an unsubscribe link, HTML document wrapping, plain-text
 * alternative, List-Unsubscribe headers. Used for test emails now and by the
 * queue worker for campaigns.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

use IBG\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Email_Composer
 */
final class Email_Composer {

	/**
	 * Constructor.
	 *
	 * @param Merge_Tags $tags     Merge tags.
	 * @param Settings   $settings Settings.
	 */
	public function __construct(
		private readonly Merge_Tags $tags,
		private readonly Settings $settings
	) {}

	/**
	 * Compose a message.
	 *
	 * Args:
	 *   to_email (required), to_name, subject, body_html, body_text,
	 *   context (Merge_Context), from_name, from_email, reply_to,
	 *   headers (array), is_test (bool), include_footer (bool, default true).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Email_Message
	 */
	public function compose( array $args ): Email_Message {
		$ctx     = $args['context'] instanceof Merge_Context ? $args['context'] : Merge_Context::sample();
		$is_test = ! empty( $args['is_test'] ) || $ctx->is_test;

		$subject = $this->tags->render( (string) ( $args['subject'] ?? '' ), $ctx, Merge_Tags::FORMAT_SUBJECT );
		if ( $is_test ) {
			$subject = '[TEST] ' . $subject;
		}

		$body_html = $this->tags->render( (string) ( $args['body_html'] ?? '' ), $ctx, Merge_Tags::FORMAT_HTML );
		$body_text = trim( (string) ( $args['body_text'] ?? '' ) );
		$body_text = '' !== $body_text
			? $this->tags->render( $body_text, $ctx, Merge_Tags::FORMAT_TEXT )
			: Html_To_Text::convert( $body_html );

		$include_footer = ! array_key_exists( 'include_footer', $args ) || ! empty( $args['include_footer'] );

		$footer_html = '';
		$footer_text = '';
		if ( $include_footer ) {
			$footer_html = $this->render_footer_html( $ctx );
			$footer_text = $this->render_footer_text( $ctx );
		}

		$html = $this->wrap_html( $body_html, $footer_html, $subject );
		$text = trim( $body_text . ( '' !== $footer_text ? "\n\n--\n" . $footer_text : '' ) );

		$from_email = (string) ( $args['from_email'] ?? '' );
		$from_name  = (string) ( $args['from_name'] ?? '' );
		$reply_to   = (string) ( $args['reply_to'] ?? '' );

		if ( '' === $from_email ) {
			$from_email = (string) $this->settings->get( 'from_email', '' );
		}
		if ( '' === $from_name ) {
			$from_name = (string) $this->settings->get( 'from_name', '' );
		}
		if ( '' === $reply_to ) {
			$reply_to = (string) $this->settings->get( 'reply_to', '' );
		}

		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();

		// RFC 8058 one-click unsubscribe headers (Gmail/Yahoo bulk-sender requirement).
		if ( $ctx->has_real_contact() && ! $is_test ) {
			$url                              = $this->tags->render( '{{unsubscribe_url}}', $ctx, Merge_Tags::FORMAT_TEXT );
			$headers['List-Unsubscribe']      = '<' . $url . '>';
			$headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
		}

		if ( $is_test ) {
			$headers['X-IBG-Outreach'] = 'test';
		}

		/**
		 * Filter the composed message arguments before the Email_Message is built.
		 *
		 * @param array         $parts Keys: subject, html, text, from_email, from_name, reply_to, headers.
		 * @param Merge_Context $ctx   Context.
		 * @param array         $args  Original compose() args.
		 */
		$parts = apply_filters(
			'ibg_outreach_composed_email',
			array(
				'subject'    => $subject,
				'html'       => $html,
				'text'       => $text,
				'from_email' => $from_email,
				'from_name'  => $from_name,
				'reply_to'   => $reply_to,
				'headers'    => $headers,
			),
			$ctx,
			$args
		);

		return new Email_Message(
			(string) ( $args['to_email'] ?? '' ),
			(string) ( $args['to_name'] ?? '' ),
			(string) $parts['subject'],
			(string) $parts['html'],
			(string) $parts['text'],
			(string) $parts['from_email'],
			(string) $parts['from_name'],
			(string) $parts['reply_to'],
			(array) $parts['headers'],
			array(
				'campaign_id' => $ctx->campaign_id,
				'contact_id'  => $ctx->contact ? $ctx->contact->id : 0,
				'queue_id'    => $ctx->queue_id,
				'is_test'     => $is_test,
			)
		);
	}

	/**
	 * Footer HTML from settings, guaranteed to contain an unsubscribe link.
	 *
	 * @param Merge_Context $ctx Context.
	 * @return string
	 */
	private function render_footer_html( Merge_Context $ctx ): string {
		$footer = (string) $this->settings->get( 'email_footer', '' );

		if ( ! $this->tags->contains_any( $footer, array( 'unsubscribe_link', 'unsubscribe_url' ) ) ) {
			$footer = rtrim( $footer ) . "\n\n{{unsubscribe_text}} {{unsubscribe_link}}";
		}

		$html = $this->tags->render( $footer, $ctx, Merge_Tags::FORMAT_HTML );

		return wpautop( $html );
	}

	/**
	 * Footer plain text.
	 *
	 * @param Merge_Context $ctx Context.
	 * @return string
	 */
	private function render_footer_text( Merge_Context $ctx ): string {
		$footer = (string) $this->settings->get( 'email_footer', '' );

		if ( ! $this->tags->contains_any( $footer, array( 'unsubscribe_link', 'unsubscribe_url' ) ) ) {
			$footer = rtrim( $footer ) . "\n\n{{unsubscribe_text}} {{unsubscribe_url}}";
		}

		$text = $this->tags->render( $footer, $ctx, Merge_Tags::FORMAT_TEXT );

		return trim( Html_To_Text::convert( $text ) );
	}

	/**
	 * Wrap body + footer in a minimal HTML document unless the body already is one.
	 *
	 * @param string $body    Rendered body HTML.
	 * @param string $footer  Rendered footer HTML.
	 * @param string $subject Subject (for <title>).
	 * @return string
	 */
	private function wrap_html( string $body, string $footer, string $subject ): string {
		$footer_block = '' !== $footer
			? '<div class="ibg-footer" style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e5e5;font-size:12px;line-height:1.5;color:#646970;">' . $footer . '</div>'
			: '';

		if ( preg_match( '/<body\b/i', $body ) ) {
			// Full document supplied by the template: inject the footer before </body>.
			$html = preg_replace( '#</body>#i', $footer_block . '</body>', $body, 1 );
			return null === $html ? $body . $footer_block : $html;
		}

		$wrapper = '<!DOCTYPE html>'
			. '<html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '">'
			. '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f5;">'
			. '<div style="max-width:600px;margin:0 auto;padding:24px;background:#ffffff;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327;">'
			. '{{body}}{{footer}}'
			. '</div></body></html>';

		/**
		 * Filter the HTML wrapper. Must contain {{body}} and {{footer}} placeholders.
		 *
		 * @param string $wrapper Wrapper HTML.
		 */
		$wrapper = (string) apply_filters( 'ibg_outreach_email_wrapper', $wrapper );

		return str_replace( array( '{{body}}', '{{footer}}' ), array( $body, $footer_block ), $wrapper );
	}
}
