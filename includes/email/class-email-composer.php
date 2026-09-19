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
		private readonly Settings $settings,
		private readonly ?\IBG\Outreach\Analytics\Tracking $tracking = null
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

		// Opt-in open pixel / click redirects: real campaign sends only (never tests or previews).
		if ( $this->tracking && ! $is_test ) {
			$html = $this->tracking->instrument_html( $html, $ctx );
		}

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
		$accent = Settings::sanitize_color( (string) $this->settings->get( 'email_accent_color', '' ) ) ?: '#1f3a5f';
		$font   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

		$footer_block = '' !== $footer
			? '<div class="ibg-footer">' . $this->inline_footer_styles( $footer, $accent ) . '</div>'
			: '';

		if ( preg_match( '/<body\b/i', $body ) ) {
			// Full document supplied by the template: inject the footer before </body>.
			$html = preg_replace( '#</body>#i', $footer_block . '</body>', $body, 1 );
			return null === $html ? $body . $footer_block : $html;
		}

		$body = $this->inline_body_styles( $body, $accent, $font );

		$business = (string) $this->settings->get( 'business_name', get_bloginfo( 'name' ) );
		$site     = (string) $this->settings->get( 'business_website', home_url( '/' ) );
		$logo     = (string) $this->settings->get( 'email_logo_url', '' );

		$brand = '' !== $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $business ) . '" height="40" style="display:block;height:40px;max-height:40px;width:auto;border:0;outline:none;text-decoration:none;" />'
			: '<span style="font-family:' . $font . ';font-size:18px;font-weight:700;letter-spacing:0.02em;color:#111827;text-decoration:none;">' . esc_html( $business ) . '</span>';
		$header = '' !== $site ? '<a href="' . esc_url( $site ) . '" style="text-decoration:none;color:#111827;">' . $brand . '</a>' : $brand;

		$wrapper = '<!DOCTYPE html>'
			. '<html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '" xmlns:o="urn:schemas-microsoft-com:office:office">'
			. '<head>'
			. '<meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="x-apple-disable-message-reformatting">'
			. '<meta name="color-scheme" content="light">'
			. '<title>' . esc_html( $subject ) . '</title>'
			. '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->'
			. '<style>'
			. 'body{margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}'
			. 'table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;}'
			. 'img{border:0;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}'
			. '@media only screen and (max-width:620px){.ibg-container{width:100%!important;}.ibg-card{padding:28px 22px!important;}}'
			. '</style>'
			. '</head>'
			. '<body style="margin:0;padding:0;background-color:#f3f4f6;">'
			. '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f3f4f6;">{{preheader}}</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">'
			. '<tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" class="ibg-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">'
			// Header.
			. '<tr><td align="left" style="padding:0 4px 20px;">{{header}}</td></tr>'
			// Accent bar + card.
			. '<tr><td style="background-color:' . $accent . ';height:4px;font-size:0;line-height:0;border-radius:8px 8px 0 0;">&nbsp;</td></tr>'
			. '<tr><td class="ibg-card" style="background-color:#ffffff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 8px 8px;padding:40px;font-family:' . $font . ';font-size:16px;line-height:1.65;color:#1f2937;">'
			. '{{body}}'
			. '</td></tr>'
			// Footer.
			. '<tr><td align="center" style="padding:28px 12px 0;font-family:' . $font . ';font-size:12px;line-height:1.6;color:#6b7280;">{{footer}}</td></tr>'
			. '</table>'
			. '</td></tr></table>'
			. '</body></html>';

		/**
		 * Filter the HTML wrapper. Must contain {{body}} and {{footer}} placeholders;
		 * {{header}} and {{preheader}} are optional.
		 *
		 * @param string $wrapper Wrapper HTML.
		 */
		$wrapper = (string) apply_filters( 'ibg_outreach_email_wrapper', $wrapper );

		$preheader = mb_substr( trim( wp_strip_all_tags( Html_To_Text::convert( $body ) ) ), 0, 120 );

		return str_replace(
			array( '{{body}}', '{{footer}}', '{{header}}', '{{preheader}}' ),
			array( $body, $footer_block, $header, esc_html( $preheader ) ),
			$wrapper
		);
	}

	/**
	 * Add inline styles to common body elements that carry none, so the message
	 * renders consistently in clients that strip <style> (Gmail, Outlook).
	 *
	 * @param string $html   Body HTML.
	 * @param string $accent Accent colour (hex).
	 * @param string $font   Font stack.
	 * @return string
	 */
	private function inline_body_styles( string $html, string $accent, string $font ): string {
		$rules = array(
			'p'  => 'margin:0 0 18px;',
			'h1' => 'margin:0 0 20px;font-size:26px;line-height:1.3;font-weight:700;color:#111827;',
			'h2' => 'margin:28px 0 14px;font-size:21px;line-height:1.35;font-weight:700;color:#111827;',
			'h3' => 'margin:24px 0 10px;font-size:17px;line-height:1.4;font-weight:700;color:#111827;',
			'ul' => 'margin:0 0 18px;padding-left:22px;',
			'ol' => 'margin:0 0 18px;padding-left:22px;',
			'li' => 'margin:0 0 6px;',
			'a'  => 'color:' . $accent . ';text-decoration:underline;',
			'hr' => 'border:0;border-top:1px solid #e5e7eb;margin:28px 0;',
			'blockquote' => 'margin:0 0 18px;padding:12px 18px;border-left:3px solid ' . $accent . ';background:#f9fafb;color:#374151;',
		);

		foreach ( $rules as $tag => $style ) {
			// Only tags without an existing style attribute.
			$html = (string) preg_replace( '/<' . $tag . '(?![a-z0-9])((?:(?!style=)[^>])*)>/i', '<' . $tag . '$1 style="' . $style . '">', $html );
		}

		// Images: responsive by default.
		$html = (string) preg_replace( '/<img(?![^>]*style=)([^>]*)>/i', '<img$1 style="max-width:100%;height:auto;display:block;">', $html );

		return $html;
	}

	/**
	 * Inline styles for the footer paragraphs and links.
	 *
	 * @param string $html   Footer HTML.
	 * @param string $accent Accent colour.
	 * @return string
	 */
	private function inline_footer_styles( string $html, string $accent ): string {
		$html = (string) preg_replace( '/<p(?![^>]*style=)([^>]*)>/i', '<p$1 style="margin:0 0 10px;">', $html );
		return (string) preg_replace( '/<a(?![^>]*style=)([^>]*)>/i', '<a$1 style="color:' . $accent . ';text-decoration:underline;">', $html );
	}
}
